<?php

namespace Tests\Feature;

use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * THE CANARY SWEEP (S15 follow-up, 24 Nov 2026; the owner's "deeper, not bigger" check). Organisation A (1) is seeded with a Product, a Sub-product, an About and a catalogue
 * item whose names, and the Sub-product's About text, carry a unique string (CANARY-A-7f3k); organisation B (2) with its own (CANARY-B-9x2q); and the LEFTOVERS of the old sharing
 * (a Product of organisation 0 with a Sub-product, an About with no organisation) with a third (CANARY-GHOST-4m1z). Then EVERY GET route the router has for the Product Master
 * (api/product-master), the Portfolio (api/portfolio), the Signals (api/signals) and the CRM catalogue (api/crm/products) is called as B, and as A, with A's real ids in every
 * `{id}` and A's keys in every `{key}`, and with each canary as the search text (q, search, query, term, text, name) and as the product / offer filter:
 *
 *   - A's canary, the leftovers' canary and A's ids appear in NO response body of B (nor the leftovers' in A's);
 *   - a by-id route that answers A with 200 answers B with 404, never 403 and never 200;
 *   - it is not vacuous: A sees its own canary on the list, the options, the lines, the picker, the Portfolio offers and the CRM catalogue, on its own by-id routes and in the Ask
 *     tools, B sees its own, and the owner of the built-ins sees them where another organisation does not.
 *
 * The list of routes is DERIVED from the router (the way tests/Support/ProductRouteMap.php and its scan read it), and every route whose controller lives in the Product Master
 * namespace is checked to be in it, so a route added tomorrow is swept tomorrow. A route parameter the sweep cannot resolve fails the test: it must be taught here.
 *
 * The ids of the three tables are set apart (A from 7001, the leftovers from 8001, B from 9001) so that an id of A cannot be a row of B by coincidence, in any table: a 404 for
 * B is then proof of a refusal, not of a missing row.
 */
class ProductCanarySweepTest extends TestCase
{
    use BuildsProductReadWorld;

    private const CANARY_A = 'CANARY-A-7f3k';

    private const CANARY_B = 'CANARY-B-9x2q';

    private const CANARY_GHOST = 'CANARY-GHOST-4m1z';

    private const PREFIXES = ['api/product-master', 'api/portfolio', 'api/signals', 'api/crm/products'];

    /** @var array{product: int, sub: int, item: int, offer: int, crm: int, key: string, code: string} */
    private array $a;

    /** @var array{product: int, sub: int, item: int, offer: int, crm: int, key: string, code: string} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->buildReadWorld();
        // the table the Portfolio's readiness log reads (this world does not build it): a route that fails for want of a table would prove nothing
        (require base_path('database/migrations/2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers.php'))->up();
        config(['product_isolation.builtin_owner_tenants' => [self::T]]);   // A owns the built-in Products, B does not: production's shape (organisation 6 owns them)

        $this->setIds(7000);
        $this->a = $this->seedOrganisation(self::T, self::CANARY_A, 'canarya', 'CA1', self::ADMIN);
        $this->setIds(8000);
        $ghost = $this->master(['sub_institute_id' => 0, 'product_key' => 'canaryghost', 'kind' => 'product', 'name' => self::CANARY_GHOST . ' Suite', 'origin' => 'builtin', 'signal_key' => 'canaryghost', 'portfolio_name' => self::CANARY_GHOST . ' Suite']);
        $about = (int) DB::table('g2g_product_offers')->insertGetId([
            'sub_institute_id' => null, 'offer_id' => 'CG1', 'name' => self::CANARY_GHOST . ' One', 'parent_product' => self::CANARY_GHOST . ' Suite', 'readiness_status' => 'Prototype',
            'deployment_model' => 'SaaS (multi-tenant)', 'typical_deal_band' => 'To fill', 'what_it_does' => self::CANARY_GHOST . ' explains itself', 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
        $this->master(['sub_institute_id' => 0, 'product_key' => 'canaryghost/cg1', 'kind' => 'sub_product', 'name' => self::CANARY_GHOST . ' One', 'parent_id' => $ghost, 'offer_row_id' => $about, 'origin' => 'portfolio']);
        $this->setIds(9000);
        $this->b = $this->seedOrganisation(self::T2, self::CANARY_B, 'canaryb', 'CB1', self::ADMIN2);
    }

    /** Sets the next id of the three tables the sweep keeps apart (sqlite's own counter). */
    private function setIds(int $last): void
    {
        foreach (['g2g_product_master', 'g2g_product_offers', 'crm_products'] as $table) {
            DB::statement('DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
            DB::statement('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $last]);
        }
    }

    /** @return array{product: int, sub: int, item: int, offer: int, crm: int, key: string, code: string} one organisation's rows, made through the real doors */
    private function seedOrganisation(int $tenant, string $canary, string $key, string $code, int $profile): array
    {
        $h = $this->headers($this->h($tenant, $profile));
        $product = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => "{$canary} Suite", 'key' => $key], $h)->assertStatus(201)->json('data.id');
        $offer = (int) $this->postJson('/api/portfolio/offers', [
            'offer_id' => $code, 'name' => "{$canary} One", 'parent_product' => "{$canary} Suite", 'readiness_status' => 'Prototype', 'what_it_does' => "{$canary} explains itself",
        ], $h)->assertStatus(201)->json('data.id');
        $crm = $this->crmCreate("{$canary} Kit", 'product', $tenant, $profile);

        return ['product' => $product, 'sub' => (int) $this->masterOfOffer($offer, $tenant)->id, 'item' => (int) $this->masterOfCrm($crm, $tenant)->id, 'offer' => $offer, 'crm' => $crm, 'key' => $key, 'code' => $code];
    }

    // ------------------------------------------------------------------ the routes, from the router

    /**
     * @return array<string, list<string>> uri => its parameter names, for every GET route of the families the sweep covers
     */
    private function routes(): array
    {
        $routes = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            $in = false;
            foreach (self::PREFIXES as $prefix) {
                $in = $in || $uri === $prefix || str_starts_with($uri, $prefix . '/');
            }
            $in = $in || (str_starts_with($uri, 'api/crm/') && str_contains($uri, '/products'));
            if ($in) {
                $routes[$uri] = $route->parameterNames();
            }
        }
        ksort($routes);

        return $routes;
    }

    /** @return list<string|int> what a route parameter may hold for the sweep: A's real ids and keys, by what the route is about */
    private function candidates(string $uri, string $param, array $a): array
    {
        $master = [$a['product'], $a['sub'], $a['item']];
        $keys = [$a['key'], 'scholarclone', $a['code']];
        if (str_starts_with($uri, 'api/product-master/by-crm')) {
            return [$a['crm']];
        }
        if (str_starts_with($uri, 'api/product-master')) {
            return $master;
        }
        if (str_starts_with($uri, 'api/crm/')) {
            return [$a['crm'], $a['product']];
        }
        if (str_starts_with($uri, 'api/portfolio/offers')) {
            return [$a['offer']];
        }
        if (in_array($param, ['key', 'product', 'productKey', 'product_key', 'slug'], true)) {
            return $keys;
        }

        return [...$master, $a['offer'], $a['crm'], ...$keys];   // an id of something else (a run, a partner, a signal): any id of A's, and A's keys
    }

    /**
     * @param array<string, list<string>> $routes
     * @return list<array{uri: string, url: string, byId: bool}> every URL of the sweep for ONE canary as the search text
     */
    private function urls(array $routes, array $a, string $canary): array
    {
        $query = http_build_query(['q' => $canary, 'search' => $canary, 'query' => $canary, 'term' => $canary, 'text' => $canary, 'name' => $canary,
            'product' => $a['key'], 'productKey' => $a['key'], 'product_key' => $a['key'], 'offer_id' => $a['code'], 'productId' => $a['product'], 'product_master_id' => $a['product'], 'per' => 100, 'status' => 'all', 'itemType' => 'product']);
        $out = [];
        foreach ($routes as $uri => $params) {
            if ($params === []) {
                $out[] = ['uri' => $uri, 'url' => '/' . $uri . '?' . $query, 'byId' => false];

                continue;
            }
            // every candidate of the first parameter; the others take their first candidate (a route with two parameters is rare)
            foreach ($this->candidates($uri, $params[0], $a) as $value) {
                $path = $uri;
                foreach ($params as $i => $param) {
                    $path = preg_replace('/\{' . preg_quote($param, '/') . '\??\}/', rawurlencode((string) ($i === 0 ? $value : $this->candidates($uri, $param, $a)[0])), $path, 1);
                }
                $out[] = ['uri' => $uri, 'url' => '/' . $path . '?' . $query, 'byId' => true];
            }
        }

        return $out;
    }

    /** The text of a response, also of one that is streamed (the exports). */
    private function bodyOf(\Illuminate\Testing\TestResponse $response): string
    {
        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
    }

    /** @return list<int> every id of an organisation's seeded rows */
    private function ids(array $o): array
    {
        return array_values(array_unique([$o['product'], $o['sub'], $o['item'], $o['offer'], $o['crm']]));
    }

    /** @param list<int> $ids */
    private function hasId(string $body, array $ids, string $url): ?int
    {
        foreach ($ids as $id) {
            if (str_contains((string) strtok($url, '?'), (string) $id)) {
                continue;   // the id the request itself named may be echoed in a refusal; every other id of the organisation may not appear
            }
            if (preg_match('/(?<![0-9])' . $id . '(?![0-9])/', $body) === 1) {
                return $id;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ the sweep

    public function test_the_routes_come_from_the_router_and_cover_every_product_master_route(): void
    {
        $routes = $this->routes();

        foreach (['api/product-master/list', 'api/product-master/options', 'api/product-master/lines', 'api/product-master/picker', 'api/product-master/{id}', 'api/product-master/{id}/usage',
            'api/portfolio/offers', 'api/portfolio/offers/{id}', 'api/portfolio/products', 'api/portfolio/products/{key}', 'api/crm/products', 'api/crm/products/{id}'] as $uri) {
            $this->assertArrayHasKey($uri, $routes, "{$uri} is swept");
        }
        // every GET route whose controller is in the Product Master namespace is in the list, wherever it is registered
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && str_contains((string) $route->getActionName(), 'Controllers\\Api\\ProductMaster\\')) {
                $this->assertArrayHasKey($route->uri(), $routes, $route->uri() . ' (' . $route->getActionName() . ') is a Product Master route and is not swept');
            }
        }
        $this->assertGreaterThan(40, count($routes), 'the Product Master, the Portfolio, the Signals and the catalogue: dozens of GET routes');
        // a parameter the sweep cannot resolve is a failure of the sweep, not a route it skipped
        foreach ($routes as $uri => $params) {
            foreach ($params as $param) {
                $this->assertNotEmpty($this->candidates($uri, $param, $this->a), "{$uri}: no candidate for {{$param}}");
            }
        }
    }

    public function test_no_response_to_either_organisation_holds_a_canary_or_an_id_of_the_other_nor_of_the_leftovers_and_a_by_id_route_answers_404(): void
    {
        $routes = $this->routes();
        $headers = [self::T => $this->as(self::ADMIN), self::T2 => $this->as(self::ADMIN2)];
        $own = [self::T => ['canary' => self::CANARY_A, 'o' => $this->a], self::T2 => ['canary' => self::CANARY_B, 'o' => $this->b]];
        $leaks = [];
        $byIdPairs = 0;
        $serverErrors = [];
        $sawOwn = [self::T => [], self::T2 => []];
        $requests = 0;

        // Three searches: for A's rows, for B's, and for the leftovers' (each with A's keys and ids as the filters, then B's). The search text is the canary WITHOUT its last
        // character, so a response that merely echoes the search shows a text that is not the canary, and the canary itself is only ever there when a row of that name is.
        $passes = [
            [substr(self::CANARY_A, 0, -1), $this->a],
            [substr(self::CANARY_B, 0, -1), $this->b],
            [substr(self::CANARY_GHOST, 0, -1), $this->a],
            [substr(self::CANARY_GHOST, 0, -1), $this->b],
        ];
        foreach ($passes as [$text, $filters]) {
            $owner = $filters === $this->a ? self::T : self::T2;
            $other = $owner === self::T ? self::T2 : self::T;
            foreach ($this->urls($routes, $filters, $text) as $u) {
                $response = [$owner => $this->getJson($u['url'], $headers[$owner]), $other => $this->getJson($u['url'], $headers[$other])];
                $requests += 2;
                foreach ([self::T, self::T2] as $reader) {
                    $body = $this->bodyOf($response[$reader]);
                    $theirs = $reader === self::T ? $own[self::T2] : $own[self::T];
                    // nobody reads the other organisation's canary, an id of the other organisation's rows, or the leftovers' canary
                    foreach ([$theirs['canary'], self::CANARY_GHOST] as $needle) {
                        if (str_contains($body, $needle)) {
                            $leaks[] = "organisation {$reader} read {$needle} on {$u['url']}";
                        }
                    }
                    if (($id = $this->hasId($body, $this->ids($theirs['o']), $u['url'])) !== null) {
                        $leaks[] = "organisation {$reader} read the id {$id} of organisation " . ($reader === self::T ? self::T2 : self::T) . " on {$u['url']}";
                    }
                    if (str_contains($body, $own[$reader]['canary'])) {
                        $sawOwn[$reader][$u['uri']] = true;
                    }
                    if ($response[$reader]->getStatusCode() >= 500 && (str_starts_with($u['uri'], 'api/product-master') || str_starts_with($u['uri'], 'api/portfolio/offers') || str_starts_with($u['uri'], 'api/portfolio/products'))) {
                        $serverErrors[] = "{$response[$reader]->getStatusCode()} {$u['url']}";
                    }
                }
                // a by-id route that answers the owner of the id answers the other organisation 404: not 200, not 403
                if ($u['byId'] && $response[$owner]->getStatusCode() === 200 && (str_starts_with($u['uri'], 'api/product-master') || str_starts_with($u['uri'], 'api/portfolio') || str_starts_with($u['uri'], 'api/crm/products'))) {
                    $byIdPairs++;
                    if ($response[$other]->getStatusCode() !== 404) {
                        $leaks[] = "organisation {$other} got {$response[$other]->getStatusCode()} (not 404) on {$u['url']}, where organisation {$owner} got 200";
                    }
                }
            }
        }

        $this->assertSame([], array_slice($leaks, 0, 20), count($leaks) . ' leak(s) in ' . $requests . ' requests');
        $this->assertSame([], array_slice($serverErrors, 0, 10), 'no Product Master or Portfolio read fails');
        // it is not vacuous: each owner DOES see its own canary, on the routes that list, search and open products
        foreach ([self::T, self::T2] as $tenant) {
            foreach (['api/product-master/list', 'api/product-master/options', 'api/product-master/lines', 'api/portfolio/offers', 'api/crm/products', 'api/product-master/{id}', 'api/portfolio/offers/{id}'] as $uri) {
                $this->assertArrayHasKey($uri, $sawOwn[$tenant], "organisation {$tenant} sees its own canary on {$uri}");
            }
        }
        $this->assertGreaterThanOrEqual(10, $byIdPairs, 'by-id answers that were 200 for the owner and 404 for the other organisation');
        $this->assertGreaterThan(1000, $requests, 'the sweep is a sweep');
    }

    public function test_the_ask_tools_show_organisation_b_nothing_of_a_and_the_owner_its_own(): void
    {
        $callerOf = fn (int $tenant, int $profile): AskCaller => new AskCaller($tenant, (int) $this->h($tenant, $profile)['_user'], $profile);
        $a = $callerOf(self::T, self::ADMIN);
        $b = $callerOf(self::T2, self::ADMIN2);
        $registry = new AskToolRegistry;
        $run = function (AskCaller $caller, string $tool, array $args) use ($registry): string {
            try {
                $t = $registry->find($tool);
                $r = $t->run($caller, $t->validate($caller, $args));

                return (string) json_encode([$r->status, array_map(fn ($row) => [$row->line(), $row->url ?? null, $row->id ?? null, $row->data ?? null], $r->rows)]);
            } catch (\Throwable $e) {
                return 'refused: ' . $e->getMessage();
            }
        };
        $calls = [
            ['list_products', []],
            ['search_records', ['type' => 'product', 'text' => self::CANARY_A]],
            ['search_records', ['type' => 'product', 'text' => 'Suite']],
            ['get_record', ['type' => 'product', 'id' => $this->a['product']]],
            ['get_record', ['type' => 'product', 'id' => $this->a['sub']]],
            ['list_signals', ['product_key' => $this->a['key']]],
            ['list_signals', []],
        ];

        $ownerSaw = false;
        foreach ($calls as [$tool, $args]) {
            $asB = $run($b, $tool, $args);
            $asA = $run($a, $tool, $args);
            $this->assertStringNotContainsString(self::CANARY_A, $asB, "B asked {$tool}");
            $this->assertStringNotContainsString(self::CANARY_GHOST, $asB);
            $this->assertStringNotContainsString(self::CANARY_GHOST, $asA, 'nobody is shown a leftover');
            $this->assertStringNotContainsString(self::CANARY_B, $asA, "A asked {$tool}");
            $ownerSaw = $ownerSaw || str_contains($asA, self::CANARY_A);
        }
        $this->assertTrue($ownerSaw, 'the owner of the canary reads it through the Ask tools: the sweep is not vacuous');
        $this->assertStringContainsString(self::CANARY_B, $run($b, 'list_products', []));
        $this->assertStringContainsString(self::CANARY_A, $run($a, 'list_products', []));
    }

    public function test_the_owner_of_the_built_in_products_sees_them_and_another_organisation_does_not(): void
    {
        $names = fn (int $profile): array => array_column($this->getJson('/api/portfolio/products', $this->as($profile))->assertOk()->json('data.products'), 'key');

        $this->assertContains('scholarclone', $names(self::ADMIN));
        $this->assertNotContains('scholarclone', $names(self::ADMIN2));
        $this->assertSame(['canaryb'], $names(self::ADMIN2));
    }
}
