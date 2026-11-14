<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Contacts\Repositories\GroupRepository;
use App\Modules\Contacts\Repositories\SegmentRepository;
use App\Modules\Contacts\Support\ContactQuery;
use App\Support\PhoneNumber;

/**
 * Transforme « numéros saisis + groupes + segments » en liste de destinataires dédupliquée.
 * Tout est borné à l'organisation cible ; les contacts bloqués (désinscrits) sont exclus.
 */
final class RecipientResolver
{
    /**
     * @param array<string,mixed> $in numbers (texte), group_ids, segment_ids
     * @param ?int $limit plafond de destinataires (par défaut celui du Quick Send)
     * @return array{recipients:array<string,array<string,mixed>>,invalid:array<int,array{raw:string,reason:string}>,blocked:int,duplicates:int}
     */
    public static function resolve(int $orgId, array $in, ?int $limit = null): array
    {
        $limit ??= (int) Config::get('messaging.max_recipients_per_send');
        $recipients = [];
        $invalid = [];
        $blocked = 0;
        $dups = 0;

        $add = static function (array $r) use (&$recipients, &$dups): void {
            if (isset($recipients[$r['phone']])) {
                $dups++;
                // un contact connu l'emporte sur un simple numéro saisi (prénom, variables)
                if ($recipients[$r['phone']]['contact_id'] === null && $r['contact_id'] !== null) {
                    $recipients[$r['phone']] = $r;
                }
                return;
            }
            $recipients[$r['phone']] = $r;
        };

        // 1. Numéros saisis
        $typed = [];
        foreach (preg_split('/[\r\n,;]+/', (string) ($in['numbers'] ?? '')) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (count($typed) + count($invalid) >= $limit * 2) {
                throw self::tooMany($limit);
            }
            $n = PhoneNumber::normalize($raw);
            if (!$n['ok']) {
                $invalid[] = ['raw' => mb_substr($raw, 0, 40), 'reason' => PhoneNumber::reasonLabel((string) $n['reason'])];
                continue;
            }
            $typed[(string) $n['e164']][] = $n;
        }
        $known = self::contactsByPhone($orgId, array_keys($typed));
        foreach ($typed as $phone => $list) {
            $n = $list[0];
            $dups += count($list) - 1;
            $c = $known[$phone] ?? null;
            if ($c !== null && $c['status'] === 'blocked') {
                $blocked++;
                continue;
            }
            $add($c !== null ? self::fromContact($c) : ['phone' => $phone, 'contact_id' => null, 'country' => $n['country'], 'operator' => $n['operator'], 'vars' => ['phone' => $phone]]);
        }

        // 2. Groupes
        foreach (self::ids($in['group_ids'] ?? null) as $gid) {
            $g = (new GroupRepository())->find($gid);
            if ($g === null || (int) $g['organization_id'] !== $orgId) {
                throw ApiException::validation(['group_ids' => ['Groupe introuvable.']]);
            }
            $rows = Database::select(
                'SELECT c.* FROM contacts c JOIN contact_group_members m ON m.contact_id = c.id
                 WHERE m.group_id = ? AND c.organization_id = ? AND c.deleted_at IS NULL LIMIT ' . ($limit + 1),
                [$gid, $orgId]
            );
            self::feed($rows, $add, $blocked);
            self::guard($recipients, $limit);
        }

        // 3. Segments
        foreach (self::ids($in['segment_ids'] ?? null) as $sid) {
            $s = (new SegmentRepository())->find($sid);
            if ($s === null || (int) $s['organization_id'] !== $orgId) {
                throw ApiException::validation(['segment_ids' => ['Segment introuvable.']]);
            }
            $rules = ContactQuery::normalizeRules(is_string($s['rules']) ? json_decode($s['rules'], true) : $s['rules']);
            [$fs, $fp] = ContactQuery::compile($rules);
            $rows = Database::select(
                "SELECT c.* FROM contacts c WHERE c.organization_id = ? AND c.deleted_at IS NULL AND ($fs) LIMIT " . ($limit + 1),
                array_merge([$orgId], $fp)
            );
            self::feed($rows, $add, $blocked);
            self::guard($recipients, $limit);
        }

        self::guard($recipients, $limit);
        return ['recipients' => $recipients, 'invalid' => $invalid, 'blocked' => $blocked, 'duplicates' => $dups];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function feed(array $rows, callable $add, int &$blocked): void
    {
        foreach ($rows as $c) {
            if ($c['status'] === 'blocked') {
                $blocked++;
                continue;
            }
            $add(self::fromContact($c));
        }
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function fromContact(array $c): array
    {
        $vars = [
            'first_name' => (string) ($c['first_name'] ?? ''), 'last_name' => (string) ($c['last_name'] ?? ''),
            'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')), 'phone' => (string) $c['phone_e164'], 'email' => (string) ($c['email'] ?? ''),
        ];
        $attrs = is_string($c['attributes'] ?? null) ? json_decode($c['attributes'], true) : ($c['attributes'] ?? null);
        foreach (is_array($attrs) ? $attrs : [] as $k => $v) {
            $key = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) $k));
            if (is_scalar($v) && !isset($vars[$key])) {
                $vars[$key] = (string) $v;
            }
        }
        return ['phone' => (string) $c['phone_e164'], 'contact_id' => (int) $c['id'], 'country' => $c['country'], 'operator' => $c['operator'], 'vars' => $vars];
    }

    /** @param array<int,string> $phones @return array<string,array<string,mixed>> */
    private static function contactsByPhone(int $orgId, array $phones): array
    {
        $out = [];
        foreach (array_chunk($phones, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Database::select("SELECT * FROM contacts WHERE organization_id = ? AND deleted_at IS NULL AND phone_e164 IN ($in)", array_merge([$orgId], $chunk)) as $c) {
                $out[(string) $c['phone_e164']] = $c;
            }
        }
        return $out;
    }

    /** @return array<int,int> */
    private static function ids(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $ids = [];
        foreach ($v as $x) {
            $i = filter_var($x, FILTER_VALIDATE_INT);
            if ($i !== false && $i > 0) {
                $ids[$i] = $i;
            }
        }
        return array_slice(array_values($ids), 0, 20);
    }

    /** @param array<string,mixed> $recipients */
    private static function guard(array $recipients, int $limit): void
    {
        if (count($recipients) > $limit) {
            throw self::tooMany($limit);
        }
    }

    private static function tooMany(int $limit): ApiException
    {
        return new ApiException('RECIPIENTS_LIMIT', 'Cet envoi est limité à ' . number_format($limit, 0, ',', ' ') . ' destinataires. Découpez la liste en plusieurs envois.', 422);
    }
}
