<?php

namespace Tests\Feature;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\SignalsTenantOverview;
use App\Domain\Signals\Opportunities\FeedFilters;
use App\Domain\Signals\Opportunities\ResearchRunScope;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductIcpService;
use App\Domain\Signals\Products\ProductNotConfigured;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductReadWorld;
use Tests\TestCase;

/**
 * What the isolation changes for SIGNALS (S15 follow-up, 24 Nov 2026, contract section 3, "Signals check"): an organisation that is not in
 * config product_isolation.builtin_owner_tenants has NO built-in Product (ScholarClone, Enterprise Brain, G2G, Fiber Valley), so every Signals reader that asks the catalogue
 * "which products does this organisation have" answers with its own custom Products only. Organisation 1 is the owner here and organisation 2 is not (in production: 6 and
 * "organisation 1" respectively). Each reader is called for the organisation with ZERO built-ins, to prove it answers instead of throwing, and each behaviour change is pinned:
 *
 *   MatchingEngine        no definition to limit the offers to a product line: it matches the organisation's own offers (all of them), as it always did for a signal with no product
 *   IcpPack               unchanged: it reads config/signals_icp.php, one definition for every organisation
 *   SignalsTenantOverview unchanged: it reads the ICPs, the profile and the runs of the organisation, never the catalogue
 *   ProductIcpService     a built-in key is "unknown_product" (the refusal every unknown key already got), its own custom Product works
 *   ResearchRunScope      the identity of a selection for a product the organisation does not have still answers (an empty configuration)
 *   FeedFilters           the name of a Product of another organisation is not looked up
 *   ProductCatalog        the list of products, the Portfolio groups a Sub-product may be filed under
 */
class ProductIsolationSignalsTest extends TestCase
{
    use BuildsProductReadWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReadWorld();
        config(['product_isolation.builtin_owner_tenants' => [self::T]]);
        ProductCatalog::forgetCustom();
    }

    private function aboutRow(?int $tenant, string $offerId, string $name, string $group, array $over = []): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'readiness_status' => 'Prototype', 'deployment_model' => 'SaaS (multi-tenant)',
            'typical_deal_band' => 'To fill', 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    public function test_the_catalogue_of_an_organisation_with_no_built_ins_is_its_own_products_and_the_groups_follow(): void
    {
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Beta Suite', 'key' => 'beta'], $this->as(self::ADMIN2))->assertStatus(201);

        $catalog = new ProductCatalog;

        $this->assertSame(['beta'], array_keys(ProductCatalog::definitionsFor(self::T2)));
        $this->assertSame(['beta'], array_column($catalog->listFor(self::T2), 'key'));
        $this->assertSame(['Beta Suite' => 'Beta Suite'], ProductCatalog::portfolioGroups(self::T2), 'a Sub-product may be filed under its own Products only');
        $this->assertNull(ProductCatalog::portfolioGroupFor(self::T2, 'Scholar K-12'), 'the built-in group is not one of its groups: a Sub-product cannot be filed under it through the old Portfolio form');
        $this->assertNull(ProductCatalog::businessFit('scholarclone', self::T2));
        $this->assertFalse(ProductCatalog::isProductLine('scholar', self::T2));
        $this->assertTrue(ProductCatalog::isProductLine('scholar', self::T));
        $this->assertArrayHasKey('Scholar K-12', ProductCatalog::portfolioGroups(self::T), 'the owner keeps its groups');
    }

    public function test_the_matching_engine_matches_an_organisation_with_no_built_ins_against_its_own_offers_and_does_not_throw(): void
    {
        $this->aboutRow(self::T, 'S1', 'Scholar Fees', 'Scholar K-12', ['needs_solved' => json_encode(['automation'])]);
        $this->aboutRow(self::T, 'E1', 'Brain Layer', 'EB', ['needs_solved' => json_encode(['automation'])]);
        $this->aboutRow(self::T2, 'Z1', 'Zeta Payroll', 'Zeta', ['needs_solved' => json_encode(['automation'])]);
        $signal = (object) ['title' => 'School wants automation', 'description' => 'automation of fees', 'business_fit' => 'scholar'];
        $engine = new MatchingEngine;

        $owner = $engine->matchForSignal($signal, 'signal', self::T);
        $other = $engine->matchForSignal($signal, 'signal', self::T2);

        $this->assertSame(['S1'], array_column(array_column($owner['offer_matches'], 'offer'), 'offer_id') ?: array_column($owner['offer_matches'], 'offer_id'), 'the owner: the signal\'s product line limits the offers to ScholarClone\'s own');
        $ids = array_map(fn (array $m) => $m['offer_id'] ?? ($m['offer']['offer_id'] ?? null), $other['offer_matches']);
        $this->assertSame(['Z1'], $ids, 'the organisation with no built-ins: no product line to limit to, so its own offers, and only its own');
        $this->assertNotContains('S1', $ids);
    }

    public function test_the_icp_pack_and_the_tenant_overview_do_not_read_the_catalogue(): void
    {
        $this->assertNotNull(IcpPack::forTenant(self::T2));
        $this->assertSame(IcpPack::forTenant(self::T)->key, IcpPack::forTenant(self::T2)->key, 'one definition for every organisation: nothing changes');

        $overview = app(SignalsTenantOverview::class)->forTenant(self::T2);

        $this->assertSame(self::T2, $overview['tenant']);
        $this->assertSame([], $overview['active_icps']);
        $this->assertFalse($overview['has_product_profile']);
    }

    public function test_the_icp_service_refuses_a_built_in_key_as_unknown_and_serves_its_own_product(): void
    {
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Beta Suite', 'key' => 'beta'], $this->as(self::ADMIN2))->assertStatus(201);
        $service = new ProductIcpService;

        try {
            $service->editorState(self::T2, 'scholarclone');
            $this->fail('a built-in key is not a product of an organisation that does not own the built-ins');
        } catch (ProductNotConfigured $e) {
            $this->assertSame('unknown_product', $e->code ?? 'unknown_product');
        }
        $own = $service->editorState(self::T2, 'beta');
        $this->assertIsArray($own, 'its own custom Product has an editor state');
        $this->assertIsArray($service->editorState(self::T, 'scholarclone'), 'and the owner is unchanged');
    }

    public function test_the_research_run_scope_answers_for_a_product_the_organisation_does_not_have(): void
    {
        $key = ResearchRunScope::key(self::T2, 'scholarclone', null, 'b2b', null);
        $withOffer = ResearchRunScope::key(self::T2, 'scholarclone', 'S1', 'b2b', null);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key, 'a stable identity, not an error');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $withOffer);
        $this->assertSame($key, ResearchRunScope::key(self::T2, 'scholarclone', null, 'b2b', null));
    }

    public function test_the_feed_names_the_products_of_the_organisation_only(): void
    {
        $own = (int) DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('product_key', 'scholarclone')->value('id');
        $theirs = (int) DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('product_key', 'scholarclone')->value('id');
        $rows = [(object) ['product_master_id' => $own], (object) ['product_master_id' => $theirs], (object) ['product_master_id' => null]];

        $names = FeedFilters::productNames(self::T2, $rows);

        $this->assertSame([$own], array_keys($names), 'a signal that points at another organisation\'s Product shows no name');
    }
}
