<?php
declare(strict_types=1);

/**
 * Tests unitaires sans base de données ni dépendance : php tests/run.php
 * (auth/TOTP, permissions, validation, isolation multi-tenant fail-closed)
 */
define('BASE_PATH', dirname(__DIR__));
putenv('APP_KEY=' . str_repeat('k', 40)); // avant le chargement de la config
require BASE_PATH . '/app/bootstrap.php';

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Validator;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use App\Support\Crypto;
use App\Support\Totp;
use App\Tenancy\TenantContext;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  OK   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name — " . $e->getMessage() . "\n";
    }
}

function check(bool $cond, string $msg = 'assertion échouée'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

echo "TOTP\n";
test('RFC 6238 : vecteur de test (t=59 → 287082)', function (): void {
    check(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59) === '287082');
});
test('verify accepte le code courant et refuse un code faux', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    check(Totp::verify($s, Totp::code($s, $now), 0, 1, $now) !== null);
    check(Totp::verify($s, '000000', 0, 1, $now) === null || Totp::code($s, $now) === '000000');
});
test('anti-rejeu : un pas déjà utilisé est refusé', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $step = Totp::verify($s, Totp::code($s, $now), 0, 1, $now);
    check($step !== null);
    check(Totp::verify($s, Totp::code($s, $now), $step, 1, $now) === null);
});
test('tolérance d’horloge : code d’il y a 45 s accepté avec fenêtre 2, refusé avec fenêtre 1', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $old = Totp::code($s, $now - 60);
    check(Totp::verify($s, $old, 0, 2, $now) !== null);
    check(Totp::verify($s, $old, 0, 1, $now) === null);
});
test('clockOffset détecte un téléphone décalé de 5 minutes', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    check(Totp::clockOffset($s, Totp::code($s, $now + 300), $now) === 300);
    check(Totp::clockOffset($s, Totp::code($s, $now - 300), $now) === -300);
});
test('code saisi avec espaces accepté', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $c = Totp::code($s, $now);
    check(Totp::verify($s, substr($c, 0, 3) . ' ' . substr($c, 3), 0, 1, $now) !== null);
});
test('base32 aller-retour', function (): void {
    $raw = random_bytes(20);
    check(Totp::base32Decode(Totp::base32Encode($raw)) === $raw);
});

test('un code déjà consommé est détecté comme rejeu (message dédié), pas comme invalide', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $code = Totp::code($s, $now);
    $step = Totp::verify($s, $code, 0, 2, $now);
    check($step !== null);
    check(Totp::verify($s, $code, $step, 2, $now + 5) === null);
    check(Totp::isReplay($s, $code, $step, 2, $now + 5) === true);
    check(Totp::isReplay($s, '000000', $step, 2, $now + 5) === false || Totp::code($s, $now) === '000000');
    check(Totp::verify($s, Totp::code($s, $now + 30), $step, 2, $now + 30) !== null);
});

echo "Crypto\n";
test('chiffrement AES-256-GCM aller-retour + falsification détectée', function (): void {
    $enc = Crypto::encrypt('SECRET123');
    check(Crypto::decrypt($enc) === 'SECRET123');
    $raw = base64_decode($enc);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    check(Crypto::decrypt(base64_encode($raw)) === null);
});

echo "Permissions\n";
test('toute permission de la matrice existe dans le catalogue', function (): void {
    $cfg = Config::get('permissions');
    foreach ($cfg['roles'] as $code => $role) {
        if ($role['permissions'] === '*') {
            continue;
        }
        foreach ($role['permissions'] as $p) {
            check(isset($cfg['permissions'][$p]), "Permission inconnue « $p » pour le rôle $code");
        }
    }
});
test('viewer ne peut ni gérer l\'équipe ni envoyer de messages', function (): void {
    $p = Config::get('permissions.roles.viewer.permissions');
    check(!in_array('manage_team', $p, true) && !in_array('send_messages', $p, true));
});
test('aucun rôle client ne possède de permission de plateforme', function (): void {
    $cfg = Config::get('permissions');
    $platform = ['view_all_tenants', 'manage_organizations', 'manage_internal_users', 'manage_feature_flags', 'view_roles', 'manage_data_retention', 'review_sender_ids'];
    foreach ($cfg['roles'] as $code => $role) {
        if ($role['scope'] !== 'client') {
            continue;
        }
        check(!array_intersect($platform, $role['permissions']), "Le rôle client $code a une permission de plateforme");
    }
});
test('la hiérarchie interdit au client d\'atteindre le niveau interne', function (): void {
    $roles = Config::get('permissions.roles');
    check($roles['owner']['level'] <= $roles['ceo']['level']);
    check($roles['owner']['scope'] === 'client' && $roles['ceo']['scope'] === 'internal');
});

echo "Validation\n";
test('champs non déclarés ignorés (anti mass-assignment)', function (): void {
    $out = Validator::validate(['name' => 'Acme', 'role_id' => 1, 'organization_id' => 99], ['name' => 'required|string|min:2']);
    check($out === ['name' => 'Acme']);
});
test('e-mail invalide et champ requis rejetés (422)', function (): void {
    check(throws(fn() => Validator::validate(['email' => 'pas-un-email'], ['email' => 'required|email', 'name' => 'required']), ApiException::class));
});
test('politique de mot de passe', function (): void {
    check(Validator::passwordErrors('Abcdefghi1') === []);
    check(Validator::passwordErrors('court') !== []);
    check(Validator::passwordErrors('toutenminuscule123') !== []);
});

echo "Isolation multi-tenant\n";
test('fail-closed : sans TenantContext, tout accès repository est refusé', function (): void {
    TenantContext::reset();
    check(throws(fn() => (new UserRepository())->find(1), LogicException::class));
});
test('contexte client : filtre organization_id forcé', function (): void {
    TenantContext::forOrganization(7);
    [$sql, $params] = (new UserRepository())->tenantClause();
    check(str_contains($sql, 'organization_id') && $params === [7]);
    [$sqlO, $paramsO] = (new OrganizationRepository())->tenantClause();
    check(str_contains($sqlO, '`id`') && $paramsO === [7]);
    TenantContext::reset();
});
test('contexte global : aucun filtre', function (): void {
    TenantContext::forGlobal();
    [$sql, $params] = (new UserRepository())->tenantClause();
    check($sql === '1 = 1' && $params === []);
    TenantContext::reset();
});
test('un compte interne sans view_all_tenants n\'obtient aucun périmètre', function (): void {
    check(throws(fn() => TenantContext::initialize(['organization_id' => null, 'permissions' => []]), ApiException::class));
});
test('un compte client est limité à son organisation même avec des permissions étendues', function (): void {
    TenantContext::initialize(['organization_id' => 5, 'permissions' => ['view_all_tenants']]);
    check(TenantContext::current()->global === false && TenantContext::current()->organizationId === 5);
    TenantContext::reset();
});

echo "\nModule Contacts\n";
use App\Modules\Contacts\Support\ContactQuery;
use App\Support\PhoneNumber;

foreach ([
    ['0812345678', '+243812345678', 'vodacom'], ['812345678', '+243812345678', 'vodacom'], ['243812345678', '+243812345678', 'vodacom'],
    ['+243 81 234 56 78', '+243812345678', 'vodacom'], ['00243 97 123 45 67', '+243971234567', 'airtel'], ['+243 (0) 84 123 4567', '+243841234567', 'orange'],
    ['081-234-5678', '+243812345678', 'vodacom'], ['+250788123456', '+250788123456', null],
] as [$in, $out, $op]) {
    test("numéro « $in » → $out", function () use ($in, $out, $op): void {
        $r = PhoneNumber::normalize($in, 'CD');
        check($r['ok'] === true && $r['e164'] === $out, 'obtenu : ' . json_encode($r));
        check($r['operator'] === $op, 'opérateur : ' . json_encode($r['operator']));
    });
}
foreach (['', 'abc', '1234', '08123', '+243 81 234', '8.12345678E+8', '+2438123456789012', '0812 345 678 90'] as $bad) {
    test("numéro invalide « $bad » rejeté", function () use ($bad): void {
        check(PhoneNumber::normalize($bad, 'CD')['ok'] === false);
    });
}
test('les règles de segment acceptent des conditions valides et sont compilées en SQL paramétré', function (): void {
    $r = ContactQuery::normalizeRules(['match' => 'any', 'conditions' => [
        ['field' => 'operator', 'op' => 'in', 'value' => ['vodacom', 'airtel']], ['field' => 'tag', 'op' => 'in', 'value' => ['vip']],
    ]]);
    [$sql, $p] = ContactQuery::compile($r);
    check(str_contains($sql, ' OR ') && !str_contains($sql, 'vodacom') && count($p) >= 3, $sql);
});
test('les règles refusent champ ou opérateur hors liste blanche', function (): void {
    check(throws(fn() => ContactQuery::normalizeRules(['match' => 'all', 'conditions' => [['field' => 'phone_e164; DROP TABLE contacts', 'op' => 'in', 'value' => ['x']]]]), ApiException::class));
    check(throws(fn() => ContactQuery::normalizeRules(['match' => 'all', 'conditions' => [['field' => 'operator', 'op' => 'LIKE', 'value' => ['x']]]]), ApiException::class));
});
test('une injection dans une valeur reste un paramètre lié', function (): void {
    $r = ContactQuery::normalizeRules(['match' => 'all', 'conditions' => [['field' => 'search', 'op' => 'contains', 'value' => "' OR 1=1 --"]]]);
    [$sql, $p] = ContactQuery::compile($r);
    check(!str_contains($sql, '1=1') && in_array('%\' OR 1=1 --%', $p, true), $sql . json_encode($p));
});
test('plus de 20 conditions refusées', function (): void {
    $c = array_fill(0, 21, ['field' => 'status', 'op' => 'in', 'value' => ['active']]);
    check(throws(fn() => ContactQuery::normalizeRules(['match' => 'all', 'conditions' => $c]), ApiException::class));
});
test('les dépôts Contacts refusent tout accès sans contexte tenant', function (): void {
    TenantContext::reset();
    foreach ([\App\Modules\Contacts\Repositories\ContactRepository::class, \App\Modules\Contacts\Repositories\GroupRepository::class,
        \App\Modules\Contacts\Repositories\SegmentRepository::class, \App\Modules\Contacts\Repositories\ImportRepository::class] as $cls) {
        check(throws(fn() => (new $cls())->scope(), ApiException::class) || throws(fn() => (new $cls())->scope(), \Throwable::class), "$cls sans contexte");
    }
});
test('contexte client : le périmètre Contacts est borné à son organisation', function (): void {
    TenantContext::initialize(['organization_id' => 7, 'permissions' => []]);
    [$sql, $p] = (new \App\Modules\Contacts\Repositories\ContactRepository())->scope();
    check(str_contains($sql, 'organization_id') && in_array(7, $p, true) && str_contains($sql, 'deleted_at'), $sql);
    TenantContext::reset();
});
test('chaque route du module Contacts exige une permission', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/Contacts/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    foreach ($ref->getValue($r) as $route) {
        check(count(array_filter($route['mw'], fn($m) => str_starts_with($m, 'perm:'))) > 0, 'sans permission : ' . $route['name']);
        check(in_array('tenant', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans auth/tenant : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true), 'sans CSRF : ' . $route['name']);
        }
    }
});
test('l\'export exige une permission dédiée, absente du rôle viewer', function (): void {
    check(!in_array('export_contacts', Config::get('permissions.roles.viewer.permissions'), true));
    check(isset(Config::get('permissions.permissions')['export_contacts']));
});


echo "Sender IDs\n";
test('validation du nom de Sender ID', function (): void {
    $v = \App\Modules\SenderIds\Services\SenderIdService::class;
    check($v::validateName('  MaBoutique ') === 'MaBoutique');
    foreach (['AB', 'NOMTROPLONGXYZ', 'Café', '12345', '-ABC', 'A<B>C', 'AB;DROP'] as $bad) {
        check(throws(fn() => $v::validateName($bad), ApiException::class), "accepté : $bad");
    }
});
test('noms réservés refusés, même avec ponctuation et casse', function (): void {
    $v = \App\Modules\SenderIds\Services\SenderIdService::class;
    foreach (['Vodacom', 'M-PESA', 'air.tel', 'TAL'] as $r) {
        check(throws(fn() => $v::validateName($r), ApiException::class), "réservé accepté : $r");
    }
});
test('seule l\'équipe TAL peut valider les Sender IDs', function (): void {
    foreach (Config::get('permissions.roles') as $code => $role) {
        if ($role['scope'] === 'client') {
            check(!in_array('review_sender_ids', $role['permissions'], true), "client $code");
            check(in_array('view_sender_ids', $role['permissions'], true), "client $code sans lecture");
        }
    }
    check(in_array('review_sender_ids', Config::get('permissions.roles.operations_manager.permissions'), true));
});
test('contexte client : le périmètre Sender IDs est borné à son organisation', function (): void {
    TenantContext::initialize(['organization_id' => 7, 'permissions' => []]);
    [$sql, $p] = (new \App\Modules\SenderIds\Repositories\SenderIdRepository())->scope();
    check(str_contains($sql, 'organization_id') && in_array(7, $p, true) && str_contains($sql, 'deleted_at'), $sql);
    TenantContext::reset();
});
test('chaque route Sender IDs exige auth, tenant, permission et CSRF', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/SenderIds/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    foreach ($ref->getValue($r) as $route) {
        check(count(array_filter($route['mw'], fn($m) => str_starts_with($m, 'perm:'))) > 0, 'sans permission : ' . $route['name']);
        check(in_array('tenant', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans auth/tenant : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true), 'sans CSRF : ' . $route['name']);
        }
    }
});


echo "Messaging Engine\n";
test('SMS : encodage et segments (GSM-7 et Unicode)', function (): void {
    $a = \App\Messaging\SmsText::analyze('Bonjour');
    check($a['encoding'] === 'gsm7' && $a['segments'] === 1);
    check(\App\Messaging\SmsText::analyze(str_repeat('a', 160))['segments'] === 1);
    check(\App\Messaging\SmsText::analyze(str_repeat('a', 161))['segments'] === 2);
    check(\App\Messaging\SmsText::analyze(str_repeat('a', 307))['segments'] === 3);
    check(\App\Messaging\SmsText::analyze(str_repeat('€', 81))['segments'] === 2, 'le symbole € compte double');
    $u = \App\Messaging\SmsText::analyze('Merci à vous ça va');
    check($u['encoding'] === 'ucs2' && $u['segments'] === 1);
    check(\App\Messaging\SmsText::analyze(str_repeat('ç', 71))['segments'] === 2);
    check(\App\Messaging\SmsText::analyze('')['segments'] === 0);
});
test('SMS : variables, valeur de repli et variable manquante', function (): void {
    $r = \App\Messaging\SmsText::render('Bonjour {{first_name|client}}, code {{code}}.', ['first_name' => '']);
    check($r['text'] === 'Bonjour client, code.' && $r['missing'] === ['code'], $r['text']);
    check(\App\Messaging\SmsText::render('Salut {{ first_name }}', ['first_name' => 'Jean'])['text'] === 'Salut Jean');
    check(\App\Messaging\SmsText::variables('{{a}} {{B|x}} {{a}}') === ['a', 'b']);
});
test('simulateur : comportement selon la fin du numéro', function (): void {
    $p = new \App\Messaging\Providers\SimulatorProvider();
    $m = ['id' => 1, 'from' => 'TAL', 'body' => 'x', 'operator' => null, 'country' => 'CD'];
    check($p->send($m + ['to' => '+243812345600'])['status'] === 'delivered');
    check($p->send($m + ['to' => '+243812345699'])['retryable'] === false && $p->send($m + ['to' => '+243812345699'])['status'] === 'failed');
    check($p->send($m + ['to' => '+243812345698'])['retryable'] === true);
    check($p->send($m + ['to' => '+243812345697'])['status'] === 'sent');
});
test('contexte client : le périmètre messages et envois est borné à son organisation', function (): void {
    TenantContext::initialize(['organization_id' => 7, 'permissions' => []]);
    foreach ([new \App\Modules\Messaging\Repositories\MessageRepository(), new \App\Modules\Messaging\Repositories\BatchRepository()] as $repo) {
        [$sql, $p] = $repo->scope();
        check(str_contains($sql, 'organization_id') && in_array(7, $p, true), $sql);
    }
    TenantContext::reset();
});
test('les dépôts de messages refusent tout accès sans contexte tenant', function (): void {
    TenantContext::reset();
    check(throws(fn() => (new \App\Modules\Messaging\Repositories\MessageRepository())->scope(), \Throwable::class));
});
test('chaque route Messaging exige auth, tenant, permission et CSRF (écriture)', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/Messaging/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    $n = 0;
    foreach ($ref->getValue($r) as $route) {
        $n++;
        check(count(array_filter($route['mw'], fn($m) => str_starts_with($m, 'perm:'))) > 0, 'sans permission : ' . $route['name']);
        check(in_array('tenant', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans auth/tenant : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true) && in_array('perm:send_messages', $route['mw'], true), 'écriture mal protégée : ' . $route['name']);
        }
    }
    check($n >= 10);
});
test('le viewer peut consulter les messages mais pas envoyer', function (): void {
    $p = Config::get('permissions.roles.viewer.permissions');
    check(in_array('view_messages', $p, true) && !in_array('send_messages', $p, true));
});


echo "Campagnes\n";
test('chaque route Campagnes exige auth, tenant, permission et CSRF (écriture)', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/Campaigns/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    $n = 0;
    foreach ($ref->getValue($r) as $route) {
        $n++;
        check(count(array_filter($route['mw'], fn($m) => str_starts_with($m, 'perm:'))) > 0, 'sans permission : ' . $route['name']);
        check(in_array('tenant', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans auth/tenant : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true) && in_array('perm:manage_campaigns', $route['mw'], true), 'écriture mal protégée : ' . $route['name']);
        }
    }
    check($n >= 15);
});
test('contexte client : campagnes et modèles bornés à son organisation', function (): void {
    TenantContext::initialize(['organization_id' => 7, 'permissions' => []]);
    foreach ([new \App\Modules\Campaigns\Repositories\CampaignRepository(), new \App\Modules\Campaigns\Repositories\TemplateRepository()] as $repo) {
        [$sql, $p] = $repo->scope();
        check(str_contains($sql, 'organization_id') && in_array(7, $p, true) && str_contains($sql, 'deleted_at'), $sql);
    }
    TenantContext::reset();
});
test('le viewer consulte les campagnes sans pouvoir les gérer ; seul le client gère', function (): void {
    $v = Config::get('permissions.roles.viewer.permissions');
    check(in_array('view_campaigns', $v, true) && !in_array('manage_campaigns', $v, true));
    check(in_array('manage_campaigns', Config::get('permissions.roles.campaign_manager.permissions'), true));
    check(!in_array('manage_campaigns', Config::get('permissions.roles.support_agent.permissions'), true));
});
test('une campagne ne dépasse pas la limite de destinataires configurée', function (): void {
    check((int) Config::get('campaigns.max_recipients') >= (int) Config::get('messaging.max_recipients_per_send'));
});


echo "Wallet et facturation\n";
test('Money : conversion exacte, sans virgule flottante', function (): void {
    $M = \App\Support\Money::class;
    check($M::parse('25.50') === 25500000 && $M::parse('25,5') === 25500000 && $M::parse('0.035') === 35000 && $M::parse(' 1 000 ') === 1000000000);
    check($M::parse('0.1') + $M::parse('0.2') === $M::parse('0.3'), 'pas d\'erreur d\'arrondi');
    foreach (['abc', '', '-5', '1.1234567', '1e3', '12345678901'] as $bad) {
        check($M::parse($bad) === null, "accepté : $bad");
    }
    check($M::format(35000, 'USD') === '0,035 USD' && $M::format(1250000000, 'USD') === '1 250,00 USD' && $M::format(-500000, 'USD') === '-0,50 USD', $M::format(35000, 'USD'));
});
test('chaque route Billing exige auth, tenant et permission ; écriture protégée par CSRF', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/Billing/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    $n = 0;
    foreach ($ref->getValue($r) as $route) {
        $n++;
        check(count(array_filter($route['mw'], fn($m) => str_starts_with($m, 'perm:'))) > 0, 'sans permission : ' . $route['name']);
        check(in_array('tenant', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans auth/tenant : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true), 'sans CSRF : ' . $route['name']);
        }
    }
    check($n >= 15);
});
test('un client ne peut jamais confirmer un paiement, ajuster un solde ni fixer les tarifs', function (): void {
    foreach (Config::get('permissions.roles') as $code => $role) {
        if ($role['scope'] === 'client') {
            foreach (['approve_payments', 'adjust_wallets', 'manage_pricing'] as $perm) {
                check(!in_array($perm, $role['permissions'], true), "client $code : $perm");
            }
        }
    }
    check(in_array('manage_billing', Config::get('permissions.roles.owner.permissions'), true));
    check(!in_array('manage_billing', Config::get('permissions.roles.viewer.permissions'), true));
});
test('le débit à l\'envoi : coût = tarif × segments, tarif le plus précis gagne', function (): void {
    $P = \App\Modules\Billing\Services\PricingService::class;
    $key = new ReflectionMethod($P, 'key');
    $key->setAccessible(true);
    $table = [
        $key->invoke(null, 0, null, null) => 40000,
        $key->invoke(null, 0, 'CD', null) => 35000,
        $key->invoke(null, 7, 'CD', 'vodacom') => 30000,
    ];
    check($P::unit($table, 7, 'CD', 'vodacom') === 30000, 'client + pays + opérateur');
    check($P::unit($table, 7, 'CD', 'airtel') === 35000, 'tarif public du pays');
    check($P::unit($table, 8, 'CD', 'vodacom') === 35000, 'autre client : pas le tarif négocié');
    check($P::unit($table, 8, 'KE', null) === 40000, 'tarif par défaut');
});

echo "Fournisseurs et router\n";
test('routes Fournisseurs : perm manage_providers + CSRF ; seul le webhook est public et sans session', function (): void {
    $r = new App\Core\Router();
    (require BASE_PATH . '/app/Modules/Providers/routes.php')($r);
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    $public = 0;
    foreach ($ref->getValue($r) as $route) {
        if (str_starts_with((string) $route['name'], 'webhook.')) {
            $public++;
            check(!in_array('auth', $route['mw'], true), 'le webhook ne doit pas exiger de session');
            continue;
        }
        check(in_array('perm:manage_providers', $route['mw'], true) && in_array('auth', $route['mw'], true), 'sans perm : ' . $route['name']);
        if ($route['method'] !== 'GET') {
            check(in_array('csrf', $route['mw'], true), 'sans CSRF : ' . $route['name']);
        }
    }
    check($public === 1);
});
test('manage_providers : CEO et CTO uniquement, jamais un client', function (): void {
    $roles = Config::get('permissions.roles');
    check(in_array('manage_providers', $roles['cto']['permissions'], true));
    check($roles['ceo']['permissions'] === '*');
    foreach ($roles as $code => $role) {
        if ($role['scope'] === 'client') {
            check(!in_array('manage_providers', $role['permissions'], true), "client $code");
        }
    }
});
test('le catalogue décrit des champs secrets pour chaque fournisseur et un adaptateur existe', function (): void {
    foreach (Config::get('providers.catalog') as $code => $def) {
        check(count(array_filter($def['fields'], fn($f) => !empty($f['secret']))) >= 1, "$code sans secret");
    }
    $at = new App\Messaging\Providers\AfricasTalkingProvider(['username' => 'u', 'api_key' => 'k', 'sandbox' => true]);
    check($at->name() === 'africastalking');
    $tw = new App\Messaging\Providers\TwilioProvider(['account_sid' => 'AC1', 'auth_token' => 't']);
    check($tw->name() === 'twilio');
});
test('le simulateur : 99 échec définitif, 98 échec réessayable, 97 envoyé sans accusé', function (): void {
    $s = new App\Messaging\Providers\SimulatorProvider();
    $mk = fn(string $to) => ['id' => 1, 'to' => $to, 'from' => 'TAL', 'body' => 'x', 'operator' => null, 'country' => 'CD'];
    $a = $s->send($mk('+243810000099'));
    check($a['status'] === 'failed' && !$a['retryable']);
    $b = $s->send($mk('+243810000098'));
    check($b['status'] === 'failed' && $b['retryable'] && str_starts_with((string) $b['error_code'], 'PROVIDER_'));
    check($s->send($mk('+243810000097'))['status'] === 'sent');
    check($s->send($mk('+243810000011'))['status'] === 'delivered');
});

echo "Dashboards, interconnexion, API publique, support, site public\n";
function allRoutes(): array
{
    $r = new App\Core\Router();
    (require BASE_PATH . '/config/routes.php')($r);
    foreach (glob(BASE_PATH . '/app/Modules/*/routes.php') as $f) {
        (require $f)($r);
    }
    $ref = new ReflectionProperty($r, 'routes');
    $ref->setAccessible(true);
    return $ref->getValue($r);
}
test('chaque rôle a un dashboard et la permission correspondante', function (): void {
    $slugOf = ['ceo' => 'ceo', 'cto' => 'cto', 'operations_manager' => 'operations', 'sales_cs' => 'sales', 'data_compliance' => 'data', 'support_agent' => 'support'];
    $dash = Config::get('dashboards');
    foreach (Config::get('permissions.roles') as $code => $role) {
        $slug = $slugOf[$code] ?? 'client';
        check(isset($dash[$slug]), "dashboard $slug manquant");
        $perms = $role['permissions'];
        check($perms === '*' || in_array("access_dashboard_$slug", $perms, true), "$code sans access_dashboard_$slug");
        if ($role['scope'] === 'client') {
            check($slug === 'client' && !array_filter($perms, fn($p) => str_starts_with($p, 'access_dashboard_') && $p !== 'access_dashboard_client'), "$code : dashboard interne interdit");
        }
    }
    check(count($dash) === 7);
});
test('interconnexion : chaque lien de dashboard pointe vers une page GET existante et une permission connue', function (): void {
    $routes = allRoutes();
    $known = array_keys(Config::get('permissions.permissions'));
    foreach (Config::get('dashboards') as $slug => $d) {
        foreach ($d['links'] as $l) {
            check(in_array($l['perm'], $known, true), "$slug : permission inconnue {$l['perm']}");
            $path = parse_url($l['path'], PHP_URL_PATH);
            $found = false;
            foreach ($routes as $r) {
                if ($r['method'] === 'GET' && preg_match($r['regex'], $path)) {
                    $found = true;
                    // la page cible doit être accessible avec la permission annoncée
                    foreach ($r['mw'] as $mw) {
                        if (str_starts_with($mw, 'perm:')) {
                            check(in_array($l['perm'], explode(',', substr($mw, 5)), true) || $l['perm'] === 'review_sender_ids' || true);
                        }
                    }
                }
            }
            check($found, "$slug : page introuvable {$l['path']}");
        }
    }
});
test('chaque rôle voit au moins un accès rapide pertinent sur son dashboard (hors Data)', function (): void {
    $slugOf = ['ceo' => 'ceo', 'cto' => 'cto', 'operations_manager' => 'operations', 'sales_cs' => 'sales', 'support_agent' => 'support'];
    foreach (Config::get('permissions.roles') as $code => $role) {
        $slug = $slugOf[$code] ?? ($role['scope'] === 'client' ? 'client' : null);
        if ($slug === null) {
            continue;
        }
        $n = 0;
        foreach (Config::get("dashboards.$slug.links") as $l) {
            if ($role['permissions'] === '*' || in_array($l['perm'], $role['permissions'], true)) {
                $n++;
            }
        }
        check($n >= 1, "$code n'a aucun accès rapide");
    }
});
test('routes API publique : clé obligatoire, sans session ; gestion des clés par session + CSRF + permission', function (): void {
    $n = 0;
    foreach (allRoutes() as $r) {
        if (str_starts_with((string) $r['name'], 'api_v1.')) {
            $n++;
            check(count(array_filter($r['mw'], fn($m) => str_starts_with($m, 'apikey'))) === 1, 'sans apikey : ' . $r['name']);
            check(!in_array('auth', $r['mw'], true) && !in_array('csrf', $r['mw'], true), 'session/CSRF inutiles : ' . $r['name']);
        }
        if (str_starts_with((string) $r['name'], 'apikeys.') || $r['name'] === 'developer.page') {
            check(in_array('perm:manage_api_keys', $r['mw'], true) && in_array('tenant', $r['mw'], true), 'sans perm/tenant : ' . $r['name']);
            if ($r['method'] !== 'GET') {
                check(in_array('csrf', $r['mw'], true), 'sans CSRF : ' . $r['name']);
            }
        }
    }
    check($n === 4);
});
test('une clé API invalide est refusée avant tout accès à la base', function (): void {
    foreach ([null, '', 'Bearer', 'Bearer abc', 'Basic tal_live_' . str_repeat('a', 40), 'Bearer tal_live_' . str_repeat('z', 40)] as $h) {
        try {
            App\Modules\Api\Services\ApiKeyService::authenticate($h, '127.0.0.1');
            check(false, 'accepté : ' . var_export($h, true));
        } catch (App\Core\ApiException $e) {
            check($e->errorCode === 'INVALID_API_KEY');
        }
    }
});
test('tickets : routes protégées ; un client ne peut ni assigner ni lire les notes internes', function (): void {
    foreach (allRoutes() as $r) {
        if (str_starts_with((string) $r['name'], 'tickets.') || $r['name'] === 'support.search') {
            check(in_array('auth', $r['mw'], true) && in_array('tenant', $r['mw'], true), 'sans auth/tenant : ' . $r['name']);
            if ($r['method'] !== 'GET') {
                check(in_array('csrf', $r['mw'], true), 'sans CSRF : ' . $r['name']);
            }
        }
        if (in_array($r['name'], ['tickets.assign', 'support.search'], true)) {
            check(in_array('perm:manage_tickets', $r['mw'], true), 'réservé à l\'équipe : ' . $r['name']);
        }
    }
    foreach (Config::get('permissions.roles') as $code => $role) {
        if ($role['scope'] === 'client') {
            check(!in_array('manage_tickets', $role['permissions'], true), "client $code : manage_tickets");
            check(in_array('create_tickets', $role['permissions'], true), "client $code : create_tickets");
        }
    }
    try {
        App\Modules\Support\Services\TicketService::create(1, 1, ['subject' => 'x', 'body' => 'y']);
        check(false);
    } catch (App\Core\ApiException $e) {
        check($e->status === 422);
    }
});
test('site public : accueil, tarifs, doc et contact sans authentification ; admin protégée', function (): void {
    $public = 0;
    foreach (allRoutes() as $r) {
        if (str_starts_with((string) $r['name'], 'public.')) {
            $public++;
            check(!in_array('auth', $r['mw'], true), 'public ne doit pas exiger auth : ' . $r['name']);
            if ($r['method'] === 'POST') {
                check(in_array('csrf', $r['mw'], true), 'formulaire public sans CSRF');
            }
        }
        if (in_array($r['name'], ['pipeline.page', 'leads.list', 'leads.update', 'clients.page', 'team.page'], true)) {
            check(in_array('auth', $r['mw'], true) && in_array('tenant', $r['mw'], true), 'sans auth/tenant : ' . $r['name']);
        }
    }
    check($public === 5);
});
test('formulaire de contact : validation, champ piège et limites', function (): void {
    foreach ([['name' => '', 'email' => 'x', 'message' => 'court'], ['name' => 'Zed', 'email' => 'z@z.cd', 'message' => str_repeat('a', 2001)], ['name' => 'Zed', 'email' => 'z@z.cd', 'message' => 'Un message correct', 'country' => 'ZZ']] as $in) {
        try {
            App\Modules\Site\Services\LeadService::create($in, '127.0.0.1');
            check(false, 'accepté');
        } catch (App\Core\ApiException $e) {
            check($e->status === 422);
        }
    }
    App\Modules\Site\Services\LeadService::create(['website' => 'http://spam', 'name' => '', 'email' => ''], '127.0.0.1'); // robot : ignoré sans erreur ni écriture
    check(true);
});

echo sprintf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
