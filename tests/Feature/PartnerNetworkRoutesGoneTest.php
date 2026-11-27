<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The Partner Network is gone (26 Nov 2026; Docs/agentic-crm/briefs/MARKET-SIGNALS-PARTNER-REMOVAL.md): `php artisan route:list --json` holds no `partners` route of the Portfolio and no
 * controller of the registry, while the Product Portfolio routes, the match routes and the LMS partner feature (training trainers, vendors and integrations: a different one) are still routed.
 */
class PartnerNetworkRoutesGoneTest extends TestCase
{
    /** @return list<array{method: string, uri: string, action: string}> */
    private function routes(): array
    {
        $this->assertSame(0, Artisan::call('route:list', ['--json' => true]));
        $routes = json_decode(Artisan::output(), true);
        $this->assertIsArray($routes);
        $this->assertNotSame([], $routes);

        return array_map(fn (array $r) => ['method' => (string) $r['method'], 'uri' => (string) $r['uri'], 'action' => (string) $r['action']], $routes);
    }

    public function test_no_portfolio_route_is_left_for_partners(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => preg_match('#^api/portfolio/partners(/|$)#', $r['uri']) === 1));

        $this->assertSame([], array_map(fn (array $r) => $r['method'] . ' ' . $r['uri'], $left), 'no partners route under /api/portfolio');
    }

    public function test_no_route_of_the_portfolio_controllers_or_anywhere_is_named_partners(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => preg_match('#(^|/)partners(/|$)#', $r['uri']) === 1));

        $this->assertSame([], array_map(fn (array $r) => $r['method'] . ' ' . $r['uri'], $left), 'no route has a partners path segment at all');
    }

    public function test_no_route_points_at_the_registry_controller(): void
    {
        $left = array_values(array_filter($this->routes(), fn (array $r) => str_contains($r['action'], 'Portfolio\\PartnerController')));

        $this->assertSame([], array_map(fn (array $r) => $r['method'] . ' ' . $r['uri'], $left));
        // The file, not class_exists(): a Composer classmap that was built before the removal still lists the class and would make the check a warning.
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/Portfolio/PartnerController.php'));
        $this->assertFileDoesNotExist(app_path('Domain/Portfolio/Partner.php'));
    }

    public function test_the_old_addresses_are_not_routed_any_more(): void
    {
        foreach (['/api/portfolio/partners', '/api/portfolio/partners/stats', '/api/portfolio/partners/1'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
    }

    public function test_the_portfolio_the_match_and_the_lms_partner_routes_are_still_routed(): void
    {
        $uris = array_column($this->routes(), 'uri');

        foreach (['api/portfolio/offers', 'api/portfolio/offers/{id}', 'api/portfolio/stats', 'api/portfolio/taxonomy', 'api/portfolio/products', 'api/portfolio/offers/{id}/readiness', 'api/portfolio/import'] as $stays) {
            $this->assertContains($stays, $uris, "{$stays} stays");
        }
        foreach (['api/signals/{id}/opportunity-matches', 'api/signals/opportunities/{id}/opportunity-matches', 'api/signals/ingestion/findings/{id}/opportunity-matches', 'api/signals/opportunity-matches', 'api/signals/matches/action'] as $stays) {
            $this->assertContains($stays, $uris, "{$stays} stays");
        }
        foreach (['trainers', 'vendors', 'integrations'] as $lms) {
            $this->assertContains("api/lms/governance/{$lms}", $uris, "the LMS {$lms} (LmsPartnerController) are a different feature and stay");
            $this->assertContains("api/lms/governance/{$lms}/{id}", $uris);
        }
    }
}
