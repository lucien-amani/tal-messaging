<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Controllers\ModuleBase;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Api\Services\ApiKeyService;
use App\Tenancy\TenantContext;

/** Developer Center : gestion des clés API par les clients (et supervision par l'équipe TAL). */
final class ApiKeyController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'developer/index', ['title' => 'API Developer Center', 'section' => 'developer', 'scripts' => ['js/ui.js', 'js/developer.js'], 'scopes' => ApiKeyService::SCOPES]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $ctx = TenantContext::current();
        return Response::data(ApiKeyService::list($ctx->global ? null : (int) $ctx->organizationId));
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params): Response
    {
        $request->attributes['audit_no_input'] = true;
        $org = $this->targetOrg($request);
        $r = ApiKeyService::create($org, (string) ($request->body['name'] ?? ''), (array) ($request->body['scopes'] ?? []), (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'api_key';
        $request->attributes['audit_resource_id'] = $r['row']['id'];
        $request->attributes['audit_org'] = $org;
        return Response::data($r, 201);
    }

    /** @param array<string,string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $ctx = TenantContext::current();
        ApiKeyService::revoke((int) $params['id'], $ctx->global ? null : (int) $ctx->organizationId);
        $request->attributes['audit_resource_type'] = 'api_key';
        $request->attributes['audit_resource_id'] = (int) $params['id'];
        return Response::data(['revoked' => true]);
    }
}
