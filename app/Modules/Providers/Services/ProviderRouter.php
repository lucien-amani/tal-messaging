<?php
declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Core\Config;
use App\Core\Database;
use App\Messaging\Providers\ProviderInterface;
use App\Messaging\Providers\SimulatorProvider;

/**
 * Router : liste ordonnée des fournisseurs à essayer pour un pays (priorité croissante, bascule sur le suivant en cas de panne).
 * Sans fournisseur activé ET configuré (ou avec MESSAGING_PROVIDER=simulator), le simulateur répond : aucun SMS réel.
 */
final class ProviderRouter
{
    /** @var array{at:int,rows:array<int,array<string,mixed>>}|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<int,array<string,mixed>> fournisseurs réels utilisables (activés + configurés) */
    private static function usable(): array
    {
        if (self::$cache === null || self::$cache['at'] < time() - 20) {
            $rows = [];
            foreach (Database::select('SELECT * FROM providers WHERE enabled = 1 ORDER BY priority, id') as $r) {
                if (isset(Config::get('providers.catalog')[$r['code']]) && ProviderService::isConfigured($r)) {
                    $rows[] = $r;
                }
            }
            self::$cache = ['at' => time(), 'rows' => $rows];
        }
        return self::$cache['rows'];
    }

    public static function forced(): bool
    {
        return (string) Config::get('messaging.provider', 'auto') === 'simulator';
    }

    /** @return array<int,ProviderInterface> */
    public static function chain(?string $country): array
    {
        $chain = [];
        if (!self::forced()) {
            foreach (self::usable() as $r) {
                $cc = $r['countries'] ? explode(',', (string) $r['countries']) : [];
                if ($cc && $country && !in_array(strtoupper($country), $cc, true)) {
                    continue;
                }
                $chain[] = ProviderService::instance($r);
            }
        }
        return $chain ?: [new SimulatorProvider()];
    }

    public static function isSimulation(): bool
    {
        return self::forced() || self::usable() === [];
    }

    /** @return array{simulation:bool,forced:bool,active:array<int,string>} */
    public static function mode(): array
    {
        return ['simulation' => self::isSimulation(), 'forced' => self::forced(), 'active' => array_map(static fn(array $r): string => (string) $r['code'], self::forced() ? [] : self::usable())];
    }
}
