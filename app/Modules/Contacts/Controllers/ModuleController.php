<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Controllers\ModuleBase;
use App\Core\Request;
use App\Core\Response;

/** Base des contrôleurs Contacts (organisation cible, pagination, CSV : voir ModuleBase). */
abstract class ModuleController extends ModuleBase
{
    /** @param array<string,mixed> $data */
    protected function render(Request $request, string $template, array $data): Response
    {
        return parent::render($request, $template, $data + ['scripts' => ['js/ui.js', 'js/' . ($data['section'] ?? 'contacts') . '.js']]);
    }
}
