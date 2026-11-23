<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\Support\ProductRouteMap;
use Tests\TestCase;

/**
 * The route guard of every Product door (slice 15, the audit's guard H2, Docs/agentic-crm/_evidence/s15/products-parity-audit-1-fields-endpoints-2026-11-13.md lines 391 to 401;
 * built by track WPA2-H2, 23 Nov 2026). It reads the router and Tests\Support\ProductRouteMap, needs no database and takes milliseconds.
 *
 * The five families (api/crm/products, api/product-master, api/portfolio/offers, api/portfolio/import, api/signals/icp/products) are one map, each route with where it goes. The
 * rights of the Product Master's routes stay in ProductMasterRouteMapTest (P2-E1); this file does not repeat that table, it only requires every one of them to name it as a test.
 *
 * It fails when:
 *   (1) a live route is not in the map              "route X is new, say where it goes"
 *   (2) a map entry has no live route, or the router's handler is not the one the map names
 *   (3) an entry is malformed: a `home` outside the allowed set, a `why` under 30 characters, a `step` that is not in the plan's list, a `replacedBy` on a route that is not replaced
 *   (4) a `tests` entry is not a file under tests/ that contains the route's path or its handler's method name (so "tested by" cannot rot), or the route has no test and no `debt`
 *   (5) `replaced` with status `live` and no such route in the router, or status `planned` while that route already exists (a stale plan)
 * and, only when PARITY_FINAL=1 (P5 and the last run before any push), while any entry is `planned` or carries a `debt`.
 */
class ProductRouteMapScanTest extends TestCase
{
    /** The two files that hold the whole map: they name every route, so they can never count as a route's test. */
    private const MAP_FILES = ['Support/ProductRouteMap.php', 'Feature/ProductRouteMapScanTest.php'];

    /** @return array<string, Route> "METHOD uri" => its route, for every route in the five families (HEAD and OPTIONS removed, as ProductMasterRouteMapTest does) */
    private function live(): array
    {
        $live = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! self::inScope($route->uri())) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $live[$method . ' ' . $route->uri()] = $route;
            }
        }
        ksort($live);

        return $live;
    }

    private static function inScope(string $uri): bool
    {
        foreach (ProductRouteMap::PREFIXES as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** ControllerShortName@method, as the map spells it. */
    private static function handlerOf(Route $route): string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return $action;
        }
        [$class, $method] = explode('@', $action, 2);

        return class_basename($class) . '@' . $method;
    }

    /** The route's path as a test spells it: a {parameter} may be a number, `{$id}` or `' . $id . '`; a path that ends in a name must not run on into another word, slash or dash. */
    private static function pathRegex(string $uri): string
    {
        $regex = '';
        foreach (preg_split('/(\{[^}]+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            $regex .= str_starts_with($part, '{') ? '[^\n]{1,48}?' : preg_quote($part, '#');
        }

        return '#' . $regex . (str_ends_with($uri, '}') ? '' : '(?![\w\/-])') . '#';
    }

    /** Whether a test source names the route: its path, or its handler's method with the controller's name. */
    private static function names(string $source, string $key, string $handler): bool
    {
        $uri = explode(' ', $key, 2)[1];
        if (preg_match(self::pathRegex($uri), $source) === 1) {
            return true;
        }
        [$controller, $method] = explode('@', $handler, 2);

        return str_contains($source, $controller) && preg_match('/\b' . preg_quote($method, '/') . '\b/', $source) === 1;
    }

    // ---- failure modes (1) and (2): the map and the router agree ----

    public function test_every_live_route_of_the_five_families_is_in_the_map_and_every_entry_is_a_live_route(): void
    {
        $live = $this->live();
        $mapped = ProductRouteMap::map();

        $this->assertGreaterThanOrEqual(40, count($live), 'the scan found almost no route: the prefixes or the router changed, so it would pass by looking at nothing');
        foreach (array_keys($live) as $name) {
            $this->assertArrayHasKey($name, $mapped, "route {$name} is new, say where it goes: add it to Tests\\Support\\ProductRouteMap (home, handler, step, why, tests)");
        }
        foreach ($mapped as $name => $entry) {
            $this->assertArrayHasKey($name, $live, "the map names {$name} but the router has no such route in the five families (renamed, removed or a typo)");
            $this->assertSame($entry['handler'], self::handlerOf($live[$name]), "{$name}: the map names another handler than the router");
        }
        $this->assertSame(count($live), count($mapped));
    }

    // ---- failure mode (3): an entry is well formed ----

    public function test_each_entry_has_an_allowed_home_a_reason_a_plan_step_and_the_keys_its_home_needs(): void
    {
        foreach (ProductRouteMap::map() as $name => $entry) {
            $this->assertContains($entry['home'] ?? null, ProductRouteMap::HOMES, "{$name}: `home` is not one of " . implode(', ', ProductRouteMap::HOMES));
            $this->assertGreaterThanOrEqual(30, strlen((string) ($entry['why'] ?? '')), "{$name}: `why` must be a sentence of 30 characters or more");
            $this->assertContains($entry['step'] ?? null, ProductRouteMap::STEPS, "{$name}: `step` is not a step of the plan");
            $this->assertMatchesRegularExpression('/^\w+Controller@\w+$/', (string) ($entry['handler'] ?? ''), "{$name}: `handler` must be ControllerShortName@method");
            $this->assertIsArray($entry['tests'] ?? null, "{$name}: `tests` must be a list of files");
            if ($entry['home'] === 'replaced') {
                $this->assertMatchesRegularExpression('/^(GET|POST|PUT|PATCH|DELETE) api\//', (string) ($entry['replacedBy'] ?? ''), "{$name}: a replaced route names the route that replaces it, as \"METHOD uri\"");
                $this->assertContains($entry['status'] ?? null, ProductRouteMap::STATUSES, "{$name}: a replaced route says whether its replacement is live or planned");
            } else {
                $this->assertArrayNotHasKey('replacedBy', $entry, "{$name}: only a replaced route has a `replacedBy`");
                $this->assertArrayNotHasKey('status', $entry, "{$name}: only a replaced route has a `status`");
            }
            if (isset($entry['debt'])) {
                $this->assertGreaterThanOrEqual(10, strlen($entry['debt']), "{$name}: say what is not proved");
            }
        }
    }

    // ---- failure mode (4): "tested by" cannot rot ----

    public function test_each_tests_entry_is_a_file_that_names_the_route_and_a_route_with_no_test_carries_a_debt(): void
    {
        foreach (ProductRouteMap::map() as $name => $entry) {
            if ($entry['tests'] === []) {
                $this->assertNotEmpty($entry['debt'] ?? null, "{$name}: no test names this route, so the entry must say so in `debt`");
            }
            foreach ($entry['tests'] as $file) {
                $this->assertNotContains($file, self::MAP_FILES, "{$name}: the map and its scan name every route, so they cannot be its test");
                $path = base_path('tests/' . $file);
                $this->assertFileExists($path, "{$name}: `tests` names {$file}, which is not a file under tests/");
                $this->assertTrue(self::names((string) file_get_contents($path), $name, $entry['handler']), "{$name}: {$file} does not name the route's path or its handler ({$entry['handler']})");
            }
        }
    }

    public function test_every_route_of_the_product_master_names_the_file_that_holds_its_rights(): void
    {
        foreach (ProductRouteMap::map() as $name => $entry) {
            if (str_contains($name, ' api/product-master')) {
                $this->assertContains('Feature/ProductMasterRouteMapTest.php', $entry['tests'], "{$name}: its rights are pinned in ProductMasterRouteMapTest, name it");
            }
        }
    }

    public function test_the_nine_routes_that_no_test_called_before_are_named_by_the_smoke_file(): void
    {
        $nine = [
            'GET api/crm/products/{id}/bundle-items', 'POST api/crm/products/{id}/bundle-items', 'DELETE api/crm/products/{id}/bundle-items/{rowId}',
            'POST api/crm/products/{id}/image', 'GET api/crm/products/export', 'GET api/crm/products/duplicates', 'POST api/crm/products/bulk/assign',
            'POST api/portfolio/offers/{id}/readiness', 'GET api/portfolio/offers/{id}/readiness-log',
        ];
        $map = ProductRouteMap::map();
        foreach ($nine as $name) {
            $this->assertArrayHasKey($name, $map);
            $this->assertContains(ProductRouteMap::SMOKE, $map[$name]['tests'], "{$name}: audit C found no test for it; tests/" . ProductRouteMap::SMOKE . ' holds its smoke test');
        }
        $this->assertFileExists(base_path('tests/' . ProductRouteMap::SMOKE));
    }

    // ---- failure mode (5): a replacement is real, a plan is not stale ----

    public function test_a_replaced_route_names_a_replacement_that_exists_when_it_says_live_and_does_not_when_it_says_planned(): void
    {
        $live = $this->live();
        $every = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $every[$method . ' ' . $route->uri()] = true;
            }
        }
        $replaced = 0;
        foreach (ProductRouteMap::map() as $name => $entry) {
            if ($entry['home'] !== 'replaced') {
                continue;
            }
            $replaced++;
            $this->assertNotSame($name, $entry['replacedBy'], "{$name}: a route cannot replace itself");
            if ($entry['status'] === 'live') {
                $this->assertArrayHasKey($entry['replacedBy'], $every, "{$name}: its replacement {$entry['replacedBy']} is no route in the router, so it is `planned`, not `live`");
            } else {
                $this->assertArrayNotHasKey($entry['replacedBy'], $every, "{$name}: its replacement {$entry['replacedBy']} exists already, so the plan is stale: set `status` to `live`");
            }
        }
        $this->assertGreaterThan(0, $replaced, 'no route is marked replaced: the check would pass by looking at nothing');
        $this->assertNotEmpty($live);
    }

    // ---- notes that must stay true ----

    public function test_the_archive_and_restore_notes_name_the_judge_and_the_judge_exists(): void
    {
        $this->assertTrue(method_exists(\App\Domain\ProductMaster\ProductWriter::class, 'setStatus'), 'the archive and restore notes name ProductWriter::setStatus');
        $map = ProductRouteMap::map();
        foreach (['POST api/product-master/{id}/restore', 'POST api/product-master/{id}/archive'] as $name) {
            $this->assertStringContainsString('ProductWriter::setStatus', $map[$name]['why'], "{$name}: it asks for no right at the route; the note must say who judges instead");
            $this->assertStringContainsString('section', $map[$name]['why'], "{$name}: the note must say it is judged by section");
        }
    }

    // ---- debt is printed, and the handover refuses it ----

    public function test_the_debt_is_printed(): void
    {
        $debt = [];
        foreach (ProductRouteMap::map() as $name => $entry) {
            if (isset($entry['debt'])) {
                $debt[] = "  {$name}: {$entry['debt']}";
            }
            if (($entry['status'] ?? null) === 'planned') {
                $debt[] = "  {$name}: replacement {$entry['replacedBy']} is still planned";
            }
        }
        if ($debt !== []) {
            fwrite(STDERR, "\nProduct route map, open items (the handover test fails on them under PARITY_FINAL=1):\n" . implode("\n", $debt) . "\n");
        }
        $this->assertIsArray($debt);
    }

    public function test_nothing_is_planned_and_no_debt_remains(): void
    {
        if (getenv('PARITY_FINAL') !== '1') {
            $this->markTestSkipped('Handover test: set PARITY_FINAL=1 (P5 and the last run before any push) to fail on every `planned` replacement and every `debt`.');
        }
        foreach (ProductRouteMap::map() as $name => $entry) {
            $this->assertNotSame('planned', $entry['status'] ?? null, "{$name}: its replacement is still planned");
            $this->assertArrayNotHasKey('debt', $entry, "{$name}: " . ($entry['debt'] ?? ''));
        }
    }
}
