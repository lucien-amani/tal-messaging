<?php
declare(strict_types=1);

namespace App\Support;

/** Montants en micro-unités entières (1 000 000 = 1,00). Aucun calcul en virgule flottante. */
final class Money
{
    public const SCALE = 1000000;

    /** Convertit « 25.50 » ou « 25,5 » en micro-unités ; null si invalide (6 décimales maximum). */
    public static function parse(mixed $v): ?int
    {
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v) && !is_float($v)) {
            return null;
        }
        $s = str_replace([' ', ','], ['', '.'], is_float($v) ? number_format($v, 6, '.', '') : trim($v));
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '', 6, '0');
    }

    public static function format(int $micro, string $currency = 'USD'): string
    {
        $neg = $micro < 0;
        $abs = abs($micro);
        $whole = intdiv($abs, self::SCALE);
        $frac = rtrim(str_pad((string) ($abs % self::SCALE), 6, '0', STR_PAD_LEFT), '0');
        $frac = str_pad($frac, 2, '0');
        return ($neg ? '-' : '') . number_format($whole, 0, ',', ' ') . ',' . $frac . ' ' . $currency;
    }
}
