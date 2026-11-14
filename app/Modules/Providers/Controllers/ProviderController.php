<?php
declare(strict_types=1);

namespace App\Modules\Providers\Controllers;

use App\Controllers\ModuleBase;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Providers\Services\ProviderRouter;
use App\Modules\Providers\Services\ProviderService;
use App\Modules\Providers\Services\WebhookService;

final class ProviderController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'providers/index', [
            'title' => 'Fournisseurs', 'section' => 'providers', 'scripts' => ['js/ui.js', 'js/providers.js'],
            'countries' => array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries')),
        ]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        return Response::data(['mode' => ProviderRouter::mode(), 'providers' => ProviderService::all()]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $request->attributes['audit_no_input'] = true; // jamais de secret dans le journal d'audit
        $p = ProviderService::save($params['code'], $request->body);
        $this->audit($request, $params['code']);
        return Response::data($p + ['mode' => ProviderRouter::mode()]);
    }

    /** @param array<string,string> $params */
    public function test(Request $request, array $params): Response
    {
        $p = ProviderService::test($params['code']);
        $this->audit($request, $params['code']);
        return Response::data($p);
    }

    /** @param array<string,string> $params */
    public function rotate(Request $request, array $params): Response
    {
        $p = ProviderService::rotateWebhook($params['code']);
        $this->audit($request, $params['code']);
        return Response::data($p);
    }

    /** Point d'entrée public des accusés de réception : sans session, sans CSRF, secret dans l'URL. @param array<string,string> $params */
    public function webhook(Request $request, array $params): Response
    {
        $n = WebhookService::handle($params['code'], $params['secret'], $request->body);
        if ($n < 0) {
            throw ApiException::notFound();
        }
        return Response::data(['ok' => true, 'updated' => $n]);
    }

    private function audit(Request $request, string $code): void
    {
        $request->attributes['audit_no_input'] = true;
        $request->attributes['audit_resource_type'] = 'provider';
        $request->attributes['audit_org'] = null;
    }
}
