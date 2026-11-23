<?php

namespace Tests\Feature;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Opportunities\ResearchRunScope;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductIcpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * An archived Sub-product leaves use (slice 15, P2-E2a, step 7; audit finding G-E1).
 *
 * `ProductWriter::setStatus` writes only `g2g_product_master.status`; every reader of the About store (the matching engine, the research scope, ProductCatalog, the ICP offer
 * lists, `/portfolio/products` and the old Portfolio screens) reads offers through ONE scope, `ProductOffer::scopeForTenant`, which now also asks "and not archived in the
 * master". An offer with no master row (a legacy row) stays visible. Restoring brings the Sub-product back everywhere. The offer row itself is untouched: the writer, the
 * unified screen and the Portfolio's own edit door still reach an archived Sub-product's About by its row number.
 */
class ArchivedSubProductLeavesUseTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    private const PROBE = 'ZZ-ARCH-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    private function admin(): array
    {
        return $this->headers($this->h());
    }

    /** @return array{offer: int, master: int} a Sub-product made through the real Portfolio door, with its master row */
    private function probe(string $offerId = self::PROBE): array
    {
        $offer = $this->offerCreate($offerId, 'Archive probe', 'Scholar K-12');

        return ['offer' => $offer, 'master' => (int) $this->masterOfOffer($offer)->id];
    }

    private function archive(int $master): void
    {
        $this->postJson("/api/product-master/{$master}/archive", [], $this->admin())->assertOk();
    }

    private function restore(int $master): void
    {
        $this->postJson("/api/product-master/{$master}/restore", [], $this->admin())->assertOk();
    }

    /** @return list<string> */
    private function visibleIds(?int $tenant = self::T): array
    {
        return ProductOffer::query()->forTenant($tenant)->orderBy('offer_id')->pluck('offer_id')->map(fn ($v) => (string) $v)->all();
    }

    public function test_the_scope_hides_an_archived_sub_product_and_restoring_brings_it_back(): void
    {
        $f = $this->probe();
        $this->offerCreate('ZZ-KEEP', 'Control', 'Scholar K-12');
        $this->assertContains(self::PROBE, $this->visibleIds());

        $this->archive($f['master']);
        $this->assertNotContains(self::PROBE, $this->visibleIds(), 'archived: out of the scope');
        $this->assertContains('ZZ-KEEP', $this->visibleIds(), 'a Sub-product that was never archived is unchanged');

        $this->restore($f['master']);
        $this->assertContains(self::PROBE, $this->visibleIds(), 'restored: back in the scope');
    }

    public function test_an_offer_with_no_master_row_stays_visible_and_so_does_a_row_in_any_other_status(): void
    {
        $f = $this->probe();
        DB::table('g2g_product_master')->where('id', $f['master'])->delete();
        $legacy = (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'ZZ-LEGACY', 'name' => 'Legacy', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        $other = $this->probe('ZZ-OTHER');
        DB::table('g2g_product_master')->where('id', $other['master'])->update(['status' => 'draft']);

        $ids = $this->visibleIds();

        $this->assertContains(self::PROBE, $ids, 'no master row: a legacy row is never hidden');
        $this->assertContains('ZZ-LEGACY', $ids);
        $this->assertContains('ZZ-OTHER', $ids, 'only the archived status hides an offer');
        $this->assertGreaterThan(0, $legacy);
    }

    public function test_a_shared_offer_is_hidden_only_when_its_own_master_row_is_archived(): void
    {
        $shared = (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => null, 'offer_id' => 'ZZ-SHARED', 'name' => 'Shared', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        $this->assertContains('ZZ-SHARED', $this->visibleIds(self::T));
        $this->assertContains('ZZ-SHARED', $this->visibleIds(self::T2));

        DB::table('g2g_product_master')->insert(['sub_institute_id' => 0, 'product_key' => 'scholarclone/zz-shared', 'kind' => 'sub_product', 'name' => 'Shared', 'status' => 'archived', 'origin' => 'portfolio', 'offer_row_id' => $shared, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotContains('ZZ-SHARED', $this->visibleIds(self::T));
        $this->assertNotContains('ZZ-SHARED', $this->visibleIds(self::T2));
    }

    public function test_the_old_portfolio_list_and_stats_and_detail_follow_the_archive(): void
    {
        $f = $this->probe();
        $this->offerCreate('ZZ-KEEP', 'Control', 'Scholar K-12');
        $total = (int) $this->getJson('/api/portfolio/stats', $this->admin())->assertOk()->json('data.total_offers');

        $this->archive($f['master']);

        $list = $this->getJson('/api/portfolio/offers?per_page=100', $this->admin())->assertOk()->getContent();
        $this->assertStringNotContainsString(self::PROBE, $list);
        $this->assertStringContainsString('ZZ-KEEP', $list);
        $this->assertSame($total - 1, (int) $this->getJson('/api/portfolio/stats', $this->admin())->json('data.total_offers'));
        $this->getJson('/api/portfolio/offers/' . self::PROBE, $this->admin())->assertStatus(404);

        $this->restore($f['master']);
        $this->assertStringContainsString(self::PROBE, $this->getJson('/api/portfolio/offers?per_page=100', $this->admin())->getContent());
        $this->getJson('/api/portfolio/offers/' . self::PROBE, $this->admin())->assertOk();
    }

    public function test_portfolio_products_no_longer_lists_an_archived_sub_product_and_lists_it_again_after_a_restore(): void
    {
        $f = $this->probe();
        $this->assertStringContainsString(self::PROBE, $this->getJson('/api/portfolio/products/scholarclone', $this->admin())->assertOk()->getContent());

        $this->archive($f['master']);
        $this->assertStringNotContainsString(self::PROBE, $this->getJson('/api/portfolio/products/scholarclone', $this->admin())->assertOk()->getContent());
        $this->assertStringNotContainsString(self::PROBE, $this->getJson('/api/portfolio/products', $this->admin())->assertOk()->getContent());

        $this->restore($f['master']);
        $this->assertStringContainsString(self::PROBE, $this->getJson('/api/portfolio/products/scholarclone', $this->admin())->getContent());
    }

    public function test_the_research_scope_and_the_research_offers_drop_an_archived_sub_product_and_take_it_back(): void
    {
        $f = $this->probe();
        $catalog = new ProductCatalog();
        $before = ResearchRunScope::key(self::T, 'scholarclone', null, 'b2b', null);
        $this->assertContains(self::PROBE, $catalog->researchOffers(self::T, 'scholarclone')->pluck('offer_id')->all());

        $this->archive($f['master']);
        $this->assertNotContains(self::PROBE, $catalog->researchOffers(self::T, 'scholarclone')->pluck('offer_id')->all(), 'not offered to a research run');
        $this->assertNotSame($before, ResearchRunScope::key(self::T, 'scholarclone', null, 'b2b', null), 'the scope of a run no longer holds it');

        $this->restore($f['master']);
        $this->assertSame($before, ResearchRunScope::key(self::T, 'scholarclone', null, 'b2b', null), 'restoring brings the very same scope back');
    }

    public function test_the_icp_offer_list_refuses_an_archived_sub_product_as_it_refuses_any_unknown_one(): void
    {
        $f = $this->probe();
        $service = new ProductIcpService();
        $problem = fn (): array => array_values(array_filter($service->check(self::T, 'scholarclone', ['offer_ids' => [self::PROBE]])['problems'], fn ($p) => str_contains((string) $p, self::PROBE)));

        $this->assertSame([], $problem(), 'listed: no problem');
        $this->archive($f['master']);
        $this->assertCount(1, $problem(), 'archived: not a product in the Portfolio any more');
        $this->restore($f['master']);
        $this->assertSame([], $problem());
    }

    public function test_the_matching_engine_no_longer_scopes_to_an_archived_sub_product(): void
    {
        $f = $this->probe();
        $scoped = function (): array {
            $m = new \ReflectionMethod(MatchingEngine::class, 'scopedOffers');
            $m->setAccessible(true);

            return collect($m->invoke(new MatchingEngine(), self::T, (object) ['business_fit' => '']))->pluck('offer_id')->map(fn ($v) => (string) $v)->all();
        };

        $this->assertContains(self::PROBE, $scoped());
        $this->archive($f['master']);
        $this->assertNotContains(self::PROBE, $scoped(), 'a signal is not matched to an archived Sub-product');
        $this->restore($f['master']);
        $this->assertContains(self::PROBE, $scoped());
    }

    public function test_the_unified_screens_and_the_writer_still_reach_an_archived_sub_products_about(): void
    {
        $f = $this->probe();
        $this->archive($f['master']);

        $show = $this->getJson("/api/product-master/{$f['master']}", $this->admin())->assertOk();
        $this->assertSame(self::PROBE, $show->json('data.offer.offerId'), 'the record page still shows the About of an archived Sub-product');
        $this->putJson("/api/product-master/{$f['master']}", ['about' => ['notes' => 'edited while archived']], $this->admin())->assertOk();
        $this->assertSame('edited while archived', DB::table('g2g_product_offers')->where('id', $f['offer'])->value('notes'));
        $this->putJson("/api/portfolio/offers/{$f['offer']}", ['notes' => 'edited by the old door'], $this->admin())->assertOk();
    }

    public function test_an_archived_sub_product_cannot_be_named_often_sold_together_and_a_host_without_the_master_table_is_unchanged(): void
    {
        $f = $this->probe();
        $other = $this->offerCreate('ZZ-TWO', 'Two', 'Scholar K-12');
        $this->putJson("/api/portfolio/offers/{$other}", ['bundles_with' => [self::PROBE]], $this->admin())->assertOk();
        $this->archive($f['master']);

        $this->putJson("/api/portfolio/offers/{$other}", ['bundles_with' => [self::PROBE]], $this->admin())->assertStatus(422)->assertJsonStructure(['errors' => ['bundles_with.0']]);

        Schema::drop('g2g_product_master');
        $this->assertContains(self::PROBE, $this->visibleIds(), 'no master table: nothing can be archived, nothing is hidden');
    }
}
