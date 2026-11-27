<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * The route map of the Product Master (slice 15, P2-E1; the start of the audit's guard H2, Docs/agentic-crm/_evidence/s15/products-parity-audit-1-fields-endpoints-2026-11-13.md).
 *
 * Every route under `api/product-master` is listed here with the rights its route asks for and, for a route that asks only for a token, the gate that lives INSIDE the
 * controller. The test reads the router, so a route added (or one removed, or one whose middleware changed) without this map fails with its name and says what to decide:
 * which right the route needs, and where a priced or Sell-related answer is held back.
 *
 * Why it matters: on 13 Nov 2026 the reads were re-gated from "the Products row's view right" to "a signed-in user of the organisation", so HR can open the Portfolio. That
 * is safe only while every answer that carries a price, a cost, a commission, a SKU or a Sell history is held back inside the controller (ProductReadRights), and this map is
 * where that promise is written next to the route. The behaviour of each gate is proved in ProductReadApiTest, ProductListApiTest, ProductHistoryApiTest and
 * ProductUsageApiTest.
 *
 * `rights`   the platformright:<link>,<action> middleware the route must carry, exactly, in order
 * `gate`     for a route that asks only for a token: where its Sell data is held back, in words (30 characters or more), or `none` when nothing priced can be in the answer
 */
class ProductMasterRouteMapTest extends TestCase
{
    private const PRODUCTS = '/module/crm/sales/products';

    /** The Sales Kit menu row (27 Nov 2026): the rights of the kit's eight routes are its own, not the Products row's. */
    private const KIT = '/portfolio/sales-kit';

    /** @return array<string, array{rights: list<string>, gate: string}> "METHOD uri" => its rights and its gate */
    private static function map(): array
    {
        $view = 'platformright:' . self::PRODUCTS . ',view';
        $edit = 'platformright:' . self::PRODUCTS . ',edit';
        $kitView = 'platformright:' . self::KIT . ',view';
        $kitAdd = 'platformright:' . self::KIT . ',add';
        $kitEdit = 'platformright:' . self::KIT . ',edit';
        $kitDelete = 'platformright:' . self::KIT . ',delete';

        return [
            // ---- reads: a signed-in user of the organisation; Sell data held back inside the controller (ProductReadRights) ----
            'GET api/product-master' => ['rights' => [], 'gate' => 'the Sell identifiers (catalogue id and type) of an item go only to a caller who may view that type'],
            'GET api/product-master/list' => ['rights' => [], 'gate' => 'sku, category, qtyInStock, unitPrice and currency only for a caller who may view the row\'s type; not searchable or sortable either'],
            'GET api/product-master/lines' => ['rights' => [], 'gate' => 'none'],
            'GET api/product-master/options' => ['rights' => [], 'gate' => 'none'],
            'GET api/product-master/meta' => ['rights' => [], 'gate' => 'tax rates are listed only to someone who can write the catalogue; the flags carry no data'],
            'GET api/product-master/picker' => ['rights' => [], 'gate' => '403 unless the caller may view the Sell section of every type asked for (it lists prices)'],
            'GET api/product-master/by-crm/{crmId}' => ['rights' => [], 'gate' => 'none'],
            'GET api/product-master/{id}' => ['rights' => [], 'gate' => 'sell and the drawer\'s crmProduct are filled only for a caller who may view the record\'s type, null otherwise'],
            'GET api/product-master/{id}/sell' => ['rights' => [], 'gate' => '403 unless the caller may view the Sell section of the record\'s type'],
            'GET api/product-master/{id}/history' => ['rights' => [], 'gate' => 'no line of the Sell store, and none that touches a price field, for a caller who may not view Sell'],
            'GET api/product-master/{id}/usage' => ['rights' => [], 'gate' => '403 unless the caller may view the Sell section of the record\'s type'],
            // ---- writes: the writer judges each section (About, ICP, Sell) itself ----
            'POST api/product-master' => ['rights' => [], 'gate' => 'ProductWriter refuses each section the caller has no right on with a 403 that names it, and writes nothing'],
            'PUT api/product-master/{id}' => ['rights' => [], 'gate' => 'ProductWriter refuses each section the caller has no right on with a 403 that names it, and writes nothing'],
            // ---- archive and restore ----
            // CHANGED ON PURPOSE (slice 15, WPA1, D-E1): they used to keep the Products row's edit and delete right at the route, so HR could not archive a Sub-product whose About it
            // owns. They are judged by SECTION now, like create and update: the route asks only for a token and ProductWriter::setStatus decides. ProductArchiveBySectionTest holds the matrix.
            'POST api/product-master/{id}/restore' => ['rights' => [], 'gate' => 'ProductWriter::setStatus judges by section: an administrator for a Product, the Portfolio right for a record with an About, the edit right of its type for a Sell-only record'],
            'POST api/product-master/{id}/archive' => ['rights' => [], 'gate' => 'ProductWriter::setStatus judges by section: an administrator for a Product, the Portfolio right for a record with an About, the delete right of its type for a Sell-only record'],
            // ---- delete and move (the follow-up of 24 Nov 2026): the same middleware as archive, the rights judged per record inside the domain classes ----
            'GET api/product-master/{id}/removal' => ['rights' => [], 'gate' => 'ProductRemoval judges per record like the delete itself: an administrator for a Product, the Portfolio right and the delete right of its Sell type for a Sub-product; a 403 otherwise, and nothing is written'],
            'POST api/product-master/move' => ['rights' => [], 'gate' => 'ProductMover judges every record before the first write: the Portfolio edit right for a record with an About, the edit right of its type for a Sell-only record; one refusal moves none'],
            'POST api/product-master/{id}/remove' => ['rights' => [], 'gate' => 'ProductRemoval judges per record like archive with the delete action: an administrator for a Product, the Portfolio right and the delete right of its Sell type for a Sub-product'],
            // ---- the pair review: the Products row's rights, and administrators only inside the controller ----
            'GET api/product-master/matches' => ['rights' => [$view], 'gate' => 'ProductMatchController refuses anybody who is not an administrator with a 403'],
            'POST api/product-master/matches/decide' => ['rights' => [$edit], 'gate' => 'ProductMatchController refuses anybody who is not an administrator with a 403'],
            // ---- the Sales Kit of a Product (27 Nov 2026): the rights of the "Sales Kit" menu row, set in Role and Permissions; ProductKit scopes every answer to the organisation of the token ----
            'GET api/product-master/{id}/kit' => ['rights' => [$kitView], 'gate' => 'ProductKit reads only the items of the organisation named by the token, and a product of another organisation is a 404'],
            'GET api/product-master/{id}/kit/library-search' => ['rights' => [$kitAdd], 'gate' => 'DocumentAccess::visibleTo: only library documents the caller may see, in the organisation named by the token'],
            'POST api/product-master/{id}/kit/files' => ['rights' => [$kitAdd], 'gate' => 'ProductKit writes the library document, its history and its link and the kit row in one transaction, in the organisation named by the token'],
            'POST api/product-master/{id}/kit/links' => ['rights' => [$kitAdd], 'gate' => 'KitKinds::urlProblem accepts only an https address or a page inside the app; the organisation comes from the token'],
            'POST api/product-master/{id}/kit/from-library' => ['rights' => [$kitAdd], 'gate' => 'DocumentAccess::canView, and only the owner of a document may share it with the organization (makeShared)'],
            'GET api/product-master/{id}/kit/{itemId}/download' => ['rights' => [$kitView], 'gate' => 'the item is looked up in the organisation named by the token and in the product first; a link item or a deleted document is a 404'],
            'PATCH api/product-master/{id}/kit/{itemId}' => ['rights' => [$kitEdit], 'gate' => 'the item is looked up in the organisation named by the token and in the product first, so an item id of another organisation is a 404'],
            'DELETE api/product-master/{id}/kit/{itemId}' => ['rights' => [$kitDelete], 'gate' => 'the item is looked up in the organisation named by the token and in the product first; the library document itself stays'],
        ];
    }

    /** @return array<string, Route> */
    private function live(): array
    {
        $live = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'api/product-master' || str_starts_with($route->uri(), 'api/product-master/')) {
                foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                    $live[$method . ' ' . $route->uri()] = $route;
                }
            }
        }
        ksort($live);

        return $live;
    }

    public function test_every_route_of_the_product_master_is_in_the_map_and_every_entry_of_the_map_is_a_route(): void
    {
        $live = array_keys($this->live());
        $mapped = array_keys(self::map());
        sort($mapped);

        $this->assertSame([], array_values(array_diff($live, $mapped)), 'a route with no entry in the map: decide which right it needs and where its Sell data is held back, then add it to ProductMasterRouteMapTest::map()');
        $this->assertSame([], array_values(array_diff($mapped, $live)), 'an entry in the map that is no route (renamed, removed or a typo)');
        $this->assertCount(28, $live, 'the Product Master has 28 routes (11 reads, 2 writes, restore and archive, the pair review, the preview, move and remove of a delete, and the eight of the Sales Kit)');
    }

    public function test_each_route_carries_the_token_check_and_exactly_the_rights_the_map_says(): void
    {
        foreach ($this->live() as $name => $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('api.token', $middleware, "{$name} must ask for a signed-in token");
            $rights = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'platformright:')));
            $this->assertSame(self::map()[$name]['rights'], $rights, "{$name}: the rights its route asks for");
        }
    }

    public function test_a_route_that_asks_for_no_right_says_in_words_where_its_sell_data_is_held_back(): void
    {
        foreach (self::map() as $name => $entry) {
            $this->assertNotSame('', trim($entry['gate']), "{$name} needs a gate note");
            if ($entry['gate'] !== 'none') {
                $this->assertGreaterThanOrEqual(30, strlen($entry['gate']), "{$name}: say where the Sell data is held back, in a sentence a reviewer can disagree with");
            }
        }
        // The reads that can carry something priced must not be marked `none`.
        foreach (['GET api/product-master/list', 'GET api/product-master/picker', 'GET api/product-master/{id}', 'GET api/product-master/{id}/sell', 'GET api/product-master/{id}/history', 'GET api/product-master/{id}/usage'] as $name) {
            $this->assertNotSame('none', self::map()[$name]['gate'], "{$name} can carry a price: it must name its gate");
        }
    }

    public function test_the_reads_are_get_routes_and_no_read_asks_for_the_products_row_any_more(): void
    {
        foreach ($this->live() as $name => $route) {
            if (str_starts_with($name, 'GET ') && ! str_contains($name, '/matches') && ! str_contains($name, '/kit')) {
                $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'platformright:'))), "{$name}: a read is open to a signed-in user; its Sell data is gated inside the controller");
            }
        }
    }

    public function test_the_eight_sales_kit_routes_ask_for_the_sales_kit_row_and_never_for_the_products_row_and_every_action_is_covered(): void
    {
        $kit = array_filter($this->live(), fn ($route, $name) => str_contains($name, '/{id}/kit'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(8, $kit);

        $actions = [];
        foreach ($kit as $name => $route) {
            $rights = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'platformright:')));
            $this->assertCount(1, $rights, "{$name}: exactly one right, on the Sales Kit row");
            $this->assertStringStartsWith('platformright:' . self::KIT . ',', $rights[0], "{$name}: the Sales Kit row, not the Products row");
            $this->assertStringNotContainsString(self::PRODUCTS, $rights[0]);
            $this->assertContains('api.token', $route->gatherMiddleware());
            $actions[] = substr($rights[0], strlen('platformright:' . self::KIT . ','));
        }
        $this->assertSame(['add' => 4, 'delete' => 1, 'edit' => 1, 'view' => 2], (function (array $c) { ksort($c); return $c; })(array_count_values($actions)), 'which action each route asks for: view twice, add four times, edit once, delete once');
        $this->assertSame(\App\Domain\ProductMaster\Kit\KitRights::LINK, self::KIT, 'the meta rights of the screen and the routes read the same menu row');
    }
}
