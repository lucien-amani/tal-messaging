<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = $request->header('x-csrf-token') ?? (is_string($request->body['_csrf'] ?? null) ? $request->body['_csrf'] : '');
            if (!hash_equals(Session::csrfToken(), (string) $sent)) {
                \App\Support\Logger::error('CSRF refusé', [
                    'cookie_recu' => isset($_COOKIE[Session::NAME]),
                    'cookies_noms' => implode(',', array_keys($_COOKIE)),
                    'session_id' => substr(hash('sha256', session_id()), 0, 8),
                    'jeton_recu' => $sent === '' ? 'vide' : substr((string) $sent, 0, 6),
                    'jeton_session' => substr(Session::csrfToken(), 0, 6),
                    'chemin' => $request->path,
                    'host' => $_SERVER['HTTP_HOST'] ?? '',
                ]);
                throw ApiException::forbidden('Jeton CSRF invalide ou expiré. Rechargez la page.', 'CSRF_INVALID');
            }
        }
        return $next($request);
    }
}
