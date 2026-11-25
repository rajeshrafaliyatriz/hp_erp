<?php

namespace Tests\Feature\Intelligence;

use App\Http\Middleware\IntelligenceIdentity;
use App\Http\Middleware\IntelligenceTokenAuthenticator;
use App\Http\Middleware\SanctumIntelligenceTokenAuthenticator;
use Illuminate\Routing\Route;
use Tests\Intelligence\IntelligenceTestCase;

/** Walks every route under api/intelligence: each must be GET-only and carry IntelligenceIdentity, so a route added later cannot be public or write. */
class IntelligenceRouteSweepTest extends IntelligenceTestCase
{
    /** @return list<Route> */
    private function intelligenceRoutes(): array
    {
        $routes = [];
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/intelligence')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function test_the_expected_three_routes_exist(): void
    {
        $uris = array_map(fn (Route $r) => $r->uri(), $this->intelligenceRoutes());
        sort($uris);

        $this->assertSame(['api/intelligence/detectors', 'api/intelligence/insights', 'api/intelligence/overview'], $uris);
    }

    public function test_every_intelligence_route_is_get_only_and_authenticated_and_throttled(): void
    {
        $routes = $this->intelligenceRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $this->assertSame(['GET'], $methods, $route->uri() . ' must be read-only');
            $middleware = $this->app['router']->gatherRouteMiddleware($route);
            $this->assertContains(IntelligenceIdentity::class, $middleware, $route->uri() . ' must authenticate through IntelligenceIdentity');
            $declared = array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $route->gatherMiddleware());
            $this->assertNotEmpty(array_filter($declared, fn ($m) => str_starts_with($m, 'throttle')), $route->uri() . ' must be throttled');
            $throttleAt = array_search('throttle:30,1', $declared, true);
            $identityAt = array_search(IntelligenceIdentity::class, $declared, true);
            $this->assertNotFalse($throttleAt, $route->uri() . ' must use throttle:30,1');
            $this->assertNotFalse($identityAt);
            $this->assertLessThan($identityAt, $throttleAt, $route->uri() . ': the throttle must run BEFORE authentication, or invalid-token traffic is never limited');
        }
    }

    public function test_the_api_group_is_deliberately_not_applied(): void
    {
        // The `api` group's TouchTokenActivity / RequireTwoFactorEnrolment call the provisioning token resolver (see routes/intelligence.php).
        foreach ($this->intelligenceRoutes() as $route) {
            $middleware = $this->app['router']->gatherRouteMiddleware($route);
            $this->assertNotContains(\App\Http\Middleware\TouchTokenActivity::class, $middleware, $route->uri());
            $this->assertNotContains(\App\Http\Middleware\RequireTwoFactorEnrolment::class, $middleware, $route->uri());
            $this->assertNotContains('api.token', $middleware, $route->uri() . ' must not use the provisioning RequireApiToken');
        }
    }

    public function test_the_default_token_authenticator_is_the_direct_sanctum_lookup_and_refuses_provisioned_rows(): void
    {
        $this->assertFalse($this->app->bound(IntelligenceTokenAuthenticator::class), 'nothing may rebind the authenticator in production');

        $source = file_get_contents((new \ReflectionClass(SanctumIntelligenceTokenAuthenticator::class))->getFileName());
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);   // ignore the explanatory comments
        // It keeps its own direct Sanctum lookup (it must never call the shared resolver's lookup)...
        $this->assertStringNotContainsString('PersonalAccessTokenResolver::findToken', $code);
        $this->assertStringContainsString('PersonalAccessToken::findToken', $code);
        // ...and, because rows the removed provisioning code created may still exist, it refuses that name itself (behaviour is tested in
        // tests/Security: IntelligenceRealAuthenticatorTest and GroupZFollowUpTest).
        $this->assertStringContainsString('PersonalAccessTokenResolver::PROVISIONED_TOKEN_NAME', $code);

        $middlewareSource = file_get_contents((new \ReflectionClass(IntelligenceIdentity::class))->getFileName());
        $this->assertStringNotContainsString('PersonalAccessTokenResolver::', preg_replace('~/\*.*?\*/~s', '', $middlewareSource));
        $this->assertStringNotContainsString('->input(', $middlewareSource, 'the middleware must not read request input');
    }

    public function test_the_controller_reads_only_the_allow_listed_query_keys(): void
    {
        $source = file_get_contents((new \ReflectionClass(\App\Http\Controllers\Api\Intelligence\IntelligenceController::class))->getFileName());
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        $this->assertStringNotContainsString('->input(', $code);
        $this->assertStringNotContainsString('->all()', $code);
        $this->assertStringNotContainsString('sub_institute_id', $code);
        $this->assertStringNotContainsString("header(", $code);
    }
}
