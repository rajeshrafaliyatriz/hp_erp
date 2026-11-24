<?php

namespace Tests\Security;

use App\Http\Middleware\authMiddleware;
use App\Http\Middleware\RequireAnyAccess;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireProfile;
use App\Http\Middleware\RequireTwoFactorEnrolment;
use App\Http\Middleware\TouchTokenActivity;
use App\Http\Middleware\VerifyCsrfTokenExceptApi;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The tests of the other classes mount each caller by CLASS NAME. These check the PRODUCTION WIRING through the real router that bootstrap/app.php built:
 * that the aliases the existing routes use really are the middleware the A-1 fix protects, and that the `api` and `web` groups really contain the two
 * middlewares that used to provision tokens before any route middleware ran.
 */
class ProductionWiringTest extends TokenTestCase
{
    private function router(): Router
    {
        return $this->app->make('router');
    }

    public function test_the_route_aliases_map_to_the_middleware_classes_these_tests_exercise(): void
    {
        $aliases = $this->router()->getMiddleware();

        $this->assertSame(RequireApiToken::class, $aliases['api.token'] ?? null);
        $this->assertSame(RequireProfile::class, $aliases['profile'] ?? null);
        $this->assertSame(RequireAnyAccess::class, $aliases['anyaccess'] ?? null);
        $this->assertSame(authMiddleware::class, $aliases['auth'] ?? null);
    }

    public function test_the_api_and_web_groups_contain_the_touch_and_two_factor_middlewares_in_that_order(): void
    {
        $groups = $this->router()->getMiddlewareGroups();

        foreach (['api', 'web'] as $group) {
            $this->assertArrayHasKey($group, $groups);
            $this->assertContains(TouchTokenActivity::class, $groups[$group], "$group group has TouchTokenActivity");
            $this->assertContains(RequireTwoFactorEnrolment::class, $groups[$group], "$group group has RequireTwoFactorEnrolment");
            $this->assertLessThan(
                array_search(RequireTwoFactorEnrolment::class, $groups[$group], true),
                array_search(TouchTokenActivity::class, $groups[$group], true),
                "$group group: TouchTokenActivity runs before RequireTwoFactorEnrolment",
            );
        }
        // The web group's CSRF middleware is the token-aware replacement.
        $this->assertContains(VerifyCsrfTokenExceptApi::class, $groups['web']);
    }

    /** A REAL existing route (from routes/api.php) that is protected by `api.token`: its gathered middleware, resolved by the router, contains the class. */
    public function test_a_real_existing_api_token_route_resolves_to_the_protected_middleware_stack(): void
    {
        $router = $this->router();
        $candidate = null;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), 'api/') && in_array('api.token', $route->gatherMiddleware(), true) && ! str_contains($route->uri(), '__a1')) {
                $candidate = $route;
                break;
            }
        }

        $this->assertNotNull($candidate, 'routes/api.php has at least one api.token route');
        $resolved = $router->gatherRouteMiddleware($candidate);

        $this->assertContains(RequireApiToken::class, $resolved, 'route ' . $candidate->uri() . ' resolves api.token to RequireApiToken');
        $this->assertContains(TouchTokenActivity::class, $resolved, 'and carries the api group\'s TouchTokenActivity');
        $this->assertContains(RequireTwoFactorEnrolment::class, $resolved, 'and the api group\'s RequireTwoFactorEnrolment');
    }

    /** The same for a route behind `profile:` and one behind `anyaccess:`, if the application has them. */
    public function test_real_profile_and_anyaccess_routes_resolve_to_their_classes(): void
    {
        $router = $this->router();
        $found = ['profile' => null, 'anyaccess' => null];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $declared) {
                if (! is_string($declared)) {
                    continue;
                }
                foreach (array_keys($found) as $alias) {
                    if ($found[$alias] === null && str_starts_with($declared, $alias . ':') && ! str_contains($route->uri(), '__a1')) {
                        $found[$alias] = $route;
                    }
                }
            }
        }

        foreach (['profile' => RequireProfile::class, 'anyaccess' => RequireAnyAccess::class] as $alias => $class) {
            if ($found[$alias] === null) {
                $this->markTestIncomplete("no real route uses the `$alias:` alias in this application");
            }
            $this->assertNotEmpty(array_filter($router->gatherRouteMiddleware($found[$alias]), static fn ($m) => is_string($m) && str_starts_with($m, $class)));
        }
    }

    /** The Intelligence routes do NOT sit in the api group (so they never pass through the groups' token-provisioning-era middlewares). */
    public function test_the_intelligence_routes_do_not_carry_the_api_group(): void
    {
        $checked = 0;
        foreach ($this->router()->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/intelligence')) {
                $resolved = $this->router()->gatherRouteMiddleware($route);
                $this->assertNotContains(RequireApiToken::class, $resolved);
                $this->assertNotContains(TouchTokenActivity::class, $resolved, 'not in the api group');
                $this->assertNotContains(RequireTwoFactorEnrolment::class, $resolved, 'not in the api group');
                $this->assertContains(\App\Http\Middleware\IntelligenceIdentity::class, $resolved);
                $checked++;
            }
        }
        $this->assertSame(3, $checked, 'overview, insights and detectors were all inspected');
    }
}
