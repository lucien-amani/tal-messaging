<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

/** Exige AU MOINS UNE des permissions listées (alias `any:a,b`). */
final class AnyPermissionMiddleware implements MiddlewareInterface
{
    /** @param array<int,string> $anyOf */
    public function __construct(private readonly array $anyOf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        foreach ($this->anyOf as $perm) {
            if ($request->can($perm)) {
                return $next($request);
            }
        }
        throw ApiException::forbidden('Permission requise : ' . implode(' ou ', $this->anyOf), 'PERMISSION_DENIED');
    }
}
