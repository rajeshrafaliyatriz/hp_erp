<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductRemovalWorld;
use Tests\TestCase;

/**
 * Organisation B must never reach organisation A's Products through the three new routes (S15 follow-up, 24 Nov 2026; contract section 4: "every id is looked up with
 * ProductMaster::visibleTo($tenant) so another organisation's id answers 404"):
 *
 *   GET  /api/product-master/{id}/removal
 *   POST /api/product-master/move          (the ids in `subProductIds`, and the id in `toProductId`)
 *   POST /api/product-master/{id}/remove   (with every body a Product and a Sub-product take)
 *
 * Each real id of the other organisation is used, in both directions (A reaching for B and B for A), together with ids of the caller's own where a mixed list is possible.
 * The answer is a 404 and the same one an id nobody holds gets, every store of BOTH organisations is read back and is exactly as it was, and no history line is written.
 */
class ProductRemovalCrossTenantTest extends TestCase
{
    use BuildsProductRemovalWorld;

    private const T = 1;

    private const T2 = 2;

    /** @var array<string, array<string, int>> organisation => the ids of its records */
    private array $org = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductRemovalWorld();
        foreach ([self::T => ['Alpha', 'alpha', 'A'], self::T2 => ['Beta', 'beta', 'B']] as $tenant => [$label, $key, $letter]) {
            $product = $this->makeProduct("{$label} Cloud", "{$key}_cloud", $tenant);
            $spare = $this->makeProduct("{$label} Spare", "{$key}_spare", $tenant);
            $sold = $this->makeSold($product, "{$letter}1", "{$label} sold", $tenant);
            $about = $this->makeSub($product, "{$letter}2", "{$label} about", $tenant);
            $item = $this->makeItem($product, "{$label} kit", 'product', $tenant);
            $this->org[$tenant] = ['product' => $product, 'spare' => $spare, 'sold' => $sold['master'], 'about' => $about['master'], 'item' => $item['master']];
        }
    }

    /** @return array<string, array{0: int, 1: int}> a caller and the organisation whose ids it reaches for */
    public static function bothDirections(): array
    {
        return ['B reaching for A' => [self::ADMIN2, self::T], 'A reaching for B' => [self::ADMIN, self::T2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothDirections')]
    public function test_the_preview_of_every_record_of_the_other_organisation_is_a_404(int $caller, int $theirs): void
    {
        $before = $this->snapshot();

        foreach ($this->org[$theirs] as $name => $id) {
            $this->getJson("/api/product-master/{$id}/removal", $this->who($caller))->assertStatus(404)->assertJsonPath('status', 0);
        }
        // and the answer is the one an id nobody holds gets
        $nobody = $this->getJson('/api/product-master/987654/removal', $this->who($caller));
        $foreign = $this->getJson('/api/product-master/' . $this->org[$theirs]['product'] . '/removal', $this->who($caller));
        $this->assertSame($nobody->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($nobody->json('message'), $foreign->json('message'), 'the message does not say whether the id is somebody else\'s or nobody\'s');

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->events->lines);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothDirections')]
    public function test_the_move_of_a_sub_product_of_the_other_organisation_is_a_404_alone_and_in_a_list_with_the_callers_own(int $caller, int $theirs): void
    {
        $mine = $caller === self::ADMIN ? self::T : self::T2;
        $before = $this->snapshot();

        foreach (['sold', 'about', 'item'] as $kind) {
            // to a Product of the caller's own
            $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[$theirs][$kind]], 'toProductId' => $this->org[$mine]['spare']], $this->who($caller))->assertStatus(404)->assertJsonPath('status', 0);
            // in a list with one of the caller's own: nothing moves, not even the caller's
            $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[$mine]['about'], $this->org[$theirs][$kind]], 'toProductId' => $this->org[$mine]['spare']], $this->who($caller))->assertStatus(404);
            // to a Product of THEIRS
            $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[$theirs][$kind]], 'toProductId' => $this->org[$theirs]['spare']], $this->who($caller))->assertStatus(404);
        }

        $this->assertEquals($before, $this->snapshot(), 'a store of either organisation');
        $this->assertSame([], $this->events->lines);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothDirections')]
    public function test_the_move_to_a_product_of_the_other_organisation_is_a_404_and_the_callers_own_sub_products_do_not_move(int $caller, int $theirs): void
    {
        $mine = $caller === self::ADMIN ? self::T : self::T2;
        $before = $this->snapshot();

        foreach (['product', 'spare'] as $theirProduct) {
            $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[$mine]['sold'], $this->org[$mine]['about'], $this->org[$mine]['item']], 'toProductId' => $this->org[$theirs][$theirProduct]], $this->who($caller))
                ->assertStatus(404)->assertJsonPath('status', 0);
        }
        // a Sub-product of theirs as the target is theirs too
        $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[$mine]['about']], 'toProductId' => $this->org[$theirs]['sold']], $this->who($caller))->assertStatus(404);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($this->org[$mine]['product'], (int) $this->masterRow($this->org[$mine]['about'])->parent_id);
        $this->assertSame([], $this->events->lines);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothDirections')]
    public function test_the_remove_of_every_record_of_the_other_organisation_is_a_404_with_every_body_it_takes(int $caller, int $theirs): void
    {
        $mine = $caller === self::ADMIN ? self::T : self::T2;
        $before = $this->snapshot();

        foreach ($this->org[$theirs] as $id) {
            foreach ([[], ['subProducts' => 'delete'], ['subProducts' => 'move', 'toProductId' => $this->org[$theirs]['spare']], ['subProducts' => 'move', 'toProductId' => $this->org[$mine]['spare']]] as $body) {
                $this->postJson("/api/product-master/{$id}/remove", $body, $this->who($caller))->assertStatus(404)->assertJsonPath('status', 0);
            }
        }
        // the caller's own Product, asked to hand its Sub-products to a Product of the other organisation: a 404 and the Product is still there with all of them
        $this->postJson('/api/product-master/' . $this->org[$mine]['product'] . '/remove', ['subProducts' => 'move', 'toProductId' => $this->org[$theirs]['product']], $this->who($caller))->assertStatus(404);

        $this->assertEquals($before, $this->snapshot(), 'a store of either organisation');
        $this->assertNotNull($this->masterRow($this->org[$mine]['product']));
        $this->assertSame([], $this->events->lines);
    }

    public function test_the_same_ids_work_for_their_own_organisation_so_the_404_is_about_whose_they_are(): void
    {
        $this->getJson('/api/product-master/' . $this->org[self::T]['about'] . '/removal', $this->who(self::ADMIN2))->assertStatus(404);
        $this->getJson('/api/product-master/' . $this->org[self::T]['about'] . '/removal', $this->who(self::ADMIN))->assertOk()->assertJsonPath('data.canDelete', true);
        $this->postJson('/api/product-master/move', ['subProductIds' => [$this->org[self::T]['about']], 'toProductId' => $this->org[self::T]['spare']], $this->who(self::ADMIN))->assertOk();
        $this->postJson('/api/product-master/' . $this->org[self::T]['about'] . '/remove', [], $this->who(self::ADMIN))->assertOk();
        $this->assertNotNull(DB::table('g2g_product_master')->where('id', $this->org[self::T2]['about'])->first(), 'and the other organisation\'s record with the same shape is untouched');
    }

    public function test_the_domain_class_refuses_a_row_of_another_organisation_even_when_it_is_handed_the_row_directly(): void
    {
        $theirs = [\App\Domain\ProductMaster\ProductMaster::query()->findOrFail($this->org[self::T]['about']), \App\Domain\ProductMaster\ProductMaster::query()->findOrFail($this->org[self::T]['product'])];
        $identity = ['user' => (object) ['id' => 99, 'user_profile_id' => self::ADMIN2], 'user_id' => 99, 'sub_institute_id' => self::T2];
        $before = $this->snapshot();

        foreach ($theirs as $row) {
            foreach ([
                fn () => (new \App\Domain\ProductMaster\ProductRemoval($identity))->preview($row),
                fn () => (new \App\Domain\ProductMaster\ProductRemoval($identity))->remove($row, ['subProducts' => 'delete']),
            ] as $call) {
                try {
                    $call();
                    $this->fail('a row of another organisation was served');
                } catch (\App\Domain\ProductMaster\ProductRemovalRefused $e) {
                    $this->assertSame(404, $e->status);
                }
            }
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->events->lines);
    }
}
