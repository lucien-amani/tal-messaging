<?php
declare(strict_types=1);

namespace App\Modules\Site\Controllers;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Site\Services\LeadService;
use App\Services\AuthService;
use App\Support\Money;

/** Site public : accueil, tarifs, documentation API, contact. Sans compte requis. */
final class PublicController extends Controller
{
    private function page(string $tpl, array $data): Response
    {
        $user = AuthService::currentUser();
        return $this->view($tpl, $data + ['indexable' => true, 'logged' => $user !== null, 'scripts' => []], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function home(Request $request, array $params = []): Response
    {
        if (AuthService::currentUser() !== null) {
            return Response::redirect('/dashboard');
        }
        return $this->page('public/home', ['title' => 'Plateforme SMS pour les entreprises en Afrique', 'description' => 'Envoyez des SMS à grande échelle, par interface ou par API : campagnes, contacts, sender IDs, wallet prépayé.', 'nav' => 'home']);
    }

    /** @param array<string,string> $params */
    public function pricing(Request $request, array $params = []): Response
    {
        $rows = Database::select('SELECT country, operator, currency, price_micro FROM pricing_rules WHERE organization_id IS NULL AND active = 1 ORDER BY country IS NULL, country, operator');
        $names = array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries'));
        $out = array_map(static fn(array $r): array => [
            'scope' => $r['country'] ? ($names[$r['country']] ?? $r['country']) . ($r['operator'] ? ' · ' . ucfirst((string) $r['operator']) : '') : 'Autres destinations',
            'price' => Money::format((int) $r['price_micro'], (string) $r['currency']),
        ], $rows);
        return $this->page('public/pricing', ['title' => 'Tarifs', 'description' => 'Tarifs SMS par segment, prépayés, sans abonnement.', 'nav' => 'pricing', 'rules' => $out]);
    }

    /** @param array<string,string> $params */
    public function docs(Request $request, array $params = []): Response
    {
        return $this->page('public/docs', ['title' => 'Documentation API', 'description' => 'API REST pour envoyer des SMS et suivre leur livraison.', 'nav' => 'docs']);
    }

    /** @param array<string,string> $params */
    public function contact(Request $request, array $params = []): Response
    {
        return $this->page('public/contact', [
            'title' => 'Contact', 'description' => 'Parlez à notre équipe commerciale.', 'nav' => 'contact', 'scripts' => ['js/contact.js'],
            'countries' => array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries')), 'volumes' => LeadService::VOLUMES,
        ]);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params): Response
    {
        LeadService::create($request->body, $request->ip);
        return Response::data(['received' => true], 201);
    }
}
