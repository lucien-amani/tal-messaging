<?php
declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;

/** Tarifs par SMS (par segment). Le tarif le plus précis l'emporte ; un tarif client prime sur le tarif public. */
final class PricingService
{
    /**
     * Charge en mémoire les tarifs actifs applicables à une organisation (une seule requête pour tout un envoi).
     * @return array<string,int> clé « org|pays|opérateur » → prix par segment
     */
    public static function table(int $orgId, string $currency): array
    {
        $t = [];
        foreach (Database::select(
            'SELECT organization_id, country, operator, price_micro FROM pricing_rules WHERE active = 1 AND currency = ? AND (organization_id IS NULL OR organization_id = ?)',
            [$currency, $orgId]
        ) as $r) {
            $t[self::key((int) ($r['organization_id'] ?? 0), $r['country'], $r['operator'])] = (int) $r['price_micro'];
        }
        return $t;
    }

    /** @param array<string,int> $table */
    public static function unit(array $table, int $orgId, ?string $country, ?string $operator): ?int
    {
        $c = $country ? strtoupper($country) : null;
        $o = $operator ? strtolower($operator) : null;
        foreach ([$orgId, 0] as $scope) {
            foreach ([[$c, $o], [$c, null], [null, null]] as [$cc, $oo]) {
                if (($cc !== null || $oo === null) && isset($table[self::key($scope, $cc, $oo)])) {
                    return $table[self::key($scope, $cc, $oo)];
                }
            }
        }
        return null;
    }

    /** Liste des tarifs visibles par un client : les siens (négociés) et les tarifs publics non remplacés. @return array<int,array<string,mixed>> */
    public static function effective(int $orgId, string $currency): array
    {
        $rows = Database::select(
            'SELECT id, organization_id, country, operator, currency, price_micro FROM pricing_rules
             WHERE active = 1 AND currency = ? AND (organization_id IS NULL OR organization_id = ?) ORDER BY country IS NULL DESC, country, operator IS NOT NULL, operator',
            [$currency, $orgId]
        );
        $custom = [];
        foreach ($rows as $r) {
            if ($r['organization_id'] !== null) {
                $custom[self::key(1, $r['country'], $r['operator'])] = true;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if ($r['organization_id'] === null && isset($custom[self::key(1, $r['country'], $r['operator'])])) {
                continue;
            }
            $out[] = ['country' => $r['country'], 'operator' => $r['operator'], 'currency' => $r['currency'], 'price_micro' => (int) $r['price_micro'], 'custom' => $r['organization_id'] !== null];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> gestion interne */
    public static function all(?int $orgId): array
    {
        $sql = 'SELECT p.id, p.organization_id, o.name AS organization_name, p.country, p.operator, p.currency, p.price_micro, p.active, p.updated_at
                FROM pricing_rules p LEFT JOIN organizations o ON o.id = p.organization_id';
        $params = [];
        if ($orgId !== null) {
            $sql .= ' WHERE p.organization_id = ? OR p.organization_id IS NULL';
            $params[] = $orgId;
        }
        return array_map(static function (array $r): array {
            $r['price_micro'] = (int) $r['price_micro'];
            $r['active'] = (bool) $r['active'];
            return $r;
        }, Database::select($sql . ' ORDER BY p.organization_id IS NOT NULL, o.name, p.country IS NOT NULL, p.country, p.operator', $params));
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function save(?int $id, array $in, int $actorId): array
    {
        $country = isset($in['country']) && $in['country'] !== '' ? strtoupper((string) $in['country']) : null;
        $operator = isset($in['operator']) && $in['operator'] !== '' ? strtolower((string) $in['operator']) : null;
        $currency = strtoupper((string) ($in['currency'] ?? Config::get('billing.default_currency')));
        $org = isset($in['organization_id']) && $in['organization_id'] !== '' && $in['organization_id'] !== null ? (int) $in['organization_id'] : null;
        $price = \App\Support\Money::parse($in['price'] ?? null);
        if ($price === null || $price <= 0) {
            throw ApiException::validation(['price' => ['Prix invalide (exemple : 0.035).']]);
        }
        $countries = (array) Config::get('countries.countries');
        $currencies = (array) Config::get('billing.currencies');
        if ($country !== null && !isset($countries[$country])) {
            throw ApiException::validation(['country' => ['Pays inconnu.']]);
        }
        if ($operator !== null && $country === null) {
            throw ApiException::validation(['operator' => ['Un tarif par opérateur doit préciser le pays.']]);
        }
        if (!isset($currencies[$currency])) {
            throw ApiException::validation(['currency' => ['Devise non prise en charge.']]);
        }
        if ($org !== null && Database::first('SELECT id FROM organizations WHERE id = ?', [$org]) === null) {
            throw ApiException::validation(['organization_id' => ['Organisation introuvable.']]);
        }
        $active = isset($in['active']) ? (int) (bool) $in['active'] : 1;
        try {
            if ($id === null) {
                Database::execute('INSERT INTO pricing_rules (organization_id, country, operator, currency, price_micro, active, created_by) VALUES (?,?,?,?,?,?,?)',
                    [$org, $country, $operator, $currency, $price, $active, $actorId]);
                $id = Database::lastInsertId();
            } else {
                if (Database::first('SELECT id FROM pricing_rules WHERE id = ?', [$id]) === null) {
                    throw ApiException::notFound('Tarif introuvable.');
                }
                Database::execute('UPDATE pricing_rules SET organization_id = ?, country = ?, operator = ?, currency = ?, price_micro = ?, active = ? WHERE id = ?',
                    [$org, $country, $operator, $currency, $price, $active, $id]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ApiException::conflict('Un tarif existe déjà pour cette combinaison (client, pays, opérateur, devise).', 'PRICING_EXISTS');
            }
            throw $e;
        }
        return Database::first('SELECT id, organization_id, country, operator, currency, price_micro, active FROM pricing_rules WHERE id = ?', [$id]) ?? [];
    }

    public static function delete(int $id): void
    {
        if (Database::execute('DELETE FROM pricing_rules WHERE id = ?', [$id]) === 0) {
            throw ApiException::notFound('Tarif introuvable.');
        }
    }

    private static function key(int $org, ?string $country, ?string $operator): string
    {
        return $org . '|' . ($country ?? '*') . '|' . ($operator ?? '*');
    }
}
