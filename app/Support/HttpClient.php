<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Env;

/** Client HTTP minimal (cURL), vérification TLS toujours active, délais bornés. */
final class HttpClient
{
    /**
     * @param array<int,string> $headers
     * @return array{status:int,body:string,error:?string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $basicAuth = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'L’extension PHP cURL n’est pas activée (extension=curl dans php.ini).'];
        }
        if (!str_starts_with($url, 'https://')) {
            return ['status' => 0, 'body' => '', 'error' => 'Seules les URL HTTPS sont autorisées.'];
        }
        $ch = curl_init($url);
        $opt = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) \App\Core\Config::get('providers.http_timeout', 15), CURLOPT_CONNECTTIMEOUT => (int) \App\Core\Config::get('providers.http_connect_timeout', 8),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
        ];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = $body;
        }
        if ($basicAuth !== null) {
            $opt[CURLOPT_USERPWD] = $basicAuth;
        }
        if (($ca = Env::get('CURL_CA_BUNDLE')) && is_file($ca)) {
            $opt[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opt);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno === 60 || $errno === 77) {
            $err = 'Certificats TLS introuvables : définissez CURL_CA_BUNDLE dans .env (chemin de cacert.pem) ou curl.cainfo dans php.ini.';
        }
        return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
    }
}
