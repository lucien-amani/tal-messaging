<?php
declare(strict_types=1);

namespace App\Modules\Site\Controllers;

use App\Controllers\ModuleBase;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Site\Services\LeadService;

/** Pipeline commercial : prospects venus du site public (équipe TAL uniquement). */
final class PipelineController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'platform/pipeline', ['title' => 'Pipeline', 'section' => 'pipeline', 'scripts' => ['js/ui.js', 'js/pipeline.js'], 'statuses' => LeadService::STATUSES]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        return Response::data(LeadService::list(is_string($s = $request->query('status')) ? $s : null));
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $request->attributes['audit_resource_type'] = 'lead';
        $request->attributes['audit_resource_id'] = (int) $params['id'];
        $request->attributes['audit_no_input'] = true;
        return Response::data(LeadService::update((int) $params['id'], $request->body, (int) $request->user()['id']));
    }
}
