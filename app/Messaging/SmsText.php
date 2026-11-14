<?php
declare(strict_types=1);

namespace App\Messaging;

/**
 * Analyse d'un SMS : encodage (GSM 03.38 ou Unicode), longueur et nombre de segments facturables,
 * et personnalisation {{prénom}} avec valeur de repli : {{first_name|client}}.
 */
final class SmsText
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXT = "^{}\\[~]|€\f";

    /** @return array{encoding:string,length:int,segments:int,per_segment:int,remaining:int} */
    public static function analyze(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $units = 0;
        $gsm = true;
        foreach ($chars as $c) {
            if (str_contains(self::GSM_BASIC, $c)) {
                $units++;
            } elseif (str_contains(self::GSM_EXT, $c)) {
                $units += 2;
            } else {
                $gsm = false;
                break;
            }
        }
        if ($gsm) {
            [$single, $multi, $enc] = [160, 153, 'gsm7'];
        } else {
            // UCS-2 : les caractères hors plan de base comptent double (UTF-16)
            $units = 0;
            foreach ($chars as $c) {
                $units += strlen($c) >= 4 ? 2 : 1;
            }
            [$single, $multi, $enc] = [70, 67, 'ucs2'];
        }
        $segments = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));
        $cap = $segments <= 1 ? $single : $segments * $multi;
        return ['encoding' => $enc, 'length' => $units, 'segments' => $segments, 'per_segment' => $segments <= 1 ? $single : $multi, 'remaining' => max(0, $cap - $units)];
    }

    /**
     * @param array<string,string|null> $vars
     * @return array{text:string,missing:array<int,string>}
     */
    public static function render(string $template, array $vars): array
    {
        $missing = [];
        $text = (string) preg_replace_callback('/\{\{\s*([a-z0-9_]{1,40})\s*(?:\|([^{}]{0,60}))?\}\}/i', static function (array $m) use ($vars, &$missing): string {
            $key = strtolower($m[1]);
            $v = isset($vars[$key]) ? trim((string) $vars[$key]) : '';
            if ($v !== '') {
                return $v;
            }
            if (isset($m[2]) && trim($m[2]) !== '') {
                return trim($m[2]);
            }
            $missing[$key] = $key;
            return '';
        }, $template);
        // Évite « Bonjour , » quand une variable est vide
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/ +([,.;:!?])/', '$1', $text);
        return ['text' => trim($text), 'missing' => array_values($missing)];
    }

    /** @return array<int,string> variables utilisées dans le modèle */
    public static function variables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]{1,40})\s*(?:\|[^{}]{0,60})?\}\}/i', $template, $m);
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }
}
