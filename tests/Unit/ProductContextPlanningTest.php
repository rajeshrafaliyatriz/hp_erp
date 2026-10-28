<?php

namespace Tests\Unit;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpScorer;
use App\Domain\Signals\Products\ProductQueryBuilder;
use App\Domain\Signals\Products\ResearchContextReport;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Planner-level regression: a change to a SAVED product or ICP value changes the searches, the research prompt or the qualification outcome.
 * Pure (unsaved models, no database, no provider).
 */
class ProductContextPlanningTest extends TestCase
{
    private function offer(array $over = []): ProductOffer
    {
        return new ProductOffer($over + [
            'offer_id' => 'MY-01', 'name' => 'Payroll Cloud', 'parent_product' => 'G2G', 'what_it_does' => 'Monthly payroll and statutory compliance',
            'modules_components' => 'Payroll engine, compliance reports', 'needs_solved' => ['Payroll errors every month'], 'primary_segments' => ['SME'],
            'trigger_signals' => ['expansion'],
        ]);
    }

    private function icp(array $over = []): array
    {
        return $over + [
            'label' => 'Payroll for SMEs', 'industry_label' => 'small manufacturers', 'regions' => ['Gujarat'], 'buyer_types' => ['manufacturer'],
            'parts' => ['payroll' => 'monthly payroll'], 'must_have' => [['attribute' => 'staff', 'op' => '>=', 'value' => 50, 'label' => 'under 50 staff', 'prompt' => 'fewer than 50 staff']],
            'disqualifiers' => [['label' => 'trader', 'phrases' => ['trading company']]],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'payroll', 'decay' => 90, 'pain' => 'a new plant needs payroll', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'a', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant'], ['id' => 'b', 'source' => 'tender', 'topic' => 'general', 'days' => 90, 'q' => 'tender payroll']],
            'per_run' => ['catalogue' => 2, 'accounts' => 0], 'decay_by_trigger_type' => ['tender' => null, 'default' => 90],
        ];
    }

    private function queries(ProductOffer $o, array $icp): string
    {
        return json_encode(array_column((new ProductQueryBuilder())->forOffer($o, new IcpPack('p', $icp)), 'q'));
    }

    // ── searches follow the saved product and ICP ─────────────────────────────

    public function test_the_child_product_searches_change_with_the_saved_offer_and_the_icp(): void
    {
        $base = $this->queries($this->offer(), $this->icp());
        $this->assertStringContainsString('Gujarat', $base);
        // The offer's own target customers win; with none saved the ICP's ideal customer stands in.
        $this->assertStringContainsString('small manufacturers', $this->queries($this->offer(['primary_segments' => []]), $this->icp()));

        // Searches are written in the buyer's words: the problem first, then the product name; the description and features are only a last resort
        // (they feed the research prompt and the fit check instead), so changing them alone changes no search.
        $this->assertSame($base, $this->queries($this->offer(['what_it_does' => 'Applicant tracking for hospitals', 'modules_components' => 'ATS']), $this->icp()), 'description/features alone');
        $noProblem = ['needs_solved' => []];
        $this->assertNotSame($this->queries($this->offer($noProblem), $this->icp()), $this->queries($this->offer($noProblem + ['name' => 'Recruit Cloud']), $this->icp()), 'product name');
        $this->assertNotSame($this->queries($this->offer($noProblem + ['name' => '']), $this->icp()), $this->queries($this->offer($noProblem + ['name' => '', 'modules_components' => 'Applicant tracking']), $this->icp()), 'features when nothing else is saved');
        $this->assertNotSame($base, $this->queries($this->offer(['trigger_signals' => ['funding_procurement']]), $this->icp()), 'buying event');
        $this->assertNotSame($base, $this->queries($this->offer(['needs_solved' => ['Slow recruitment']]), $this->icp()), 'problems solved');
        $this->assertNotSame($base, $this->queries($this->offer(), $this->icp(['regions' => ['Maharashtra']])), 'region');
        $this->assertStringContainsString('Maharashtra', $this->queries($this->offer(), $this->icp(['regions' => ['Maharashtra']])));
        $this->assertNotSame($this->queries($this->offer(['primary_segments' => []]), $this->icp()), $this->queries($this->offer(['primary_segments' => []]), $this->icp(['industry_label' => 'private hospitals'])), 'ideal customer');
        $this->assertNotSame($base, $this->queries($this->offer(['primary_segments' => ['K12-PVT']]), $this->icp()), 'target customers saved on the offer');
    }

    public function test_an_offer_with_nothing_stored_gets_no_searches_and_nothing_is_invented(): void
    {
        $empty = new ProductOffer(['offer_id' => 'X-1', 'name' => '', 'parent_product' => 'G2G']);
        $this->assertSame([], (new ProductQueryBuilder())->forOffer($empty, new IcpPack('p', $this->icp())));
    }

    public function test_the_catalogue_window_is_drawn_from_the_saved_icp_queries_only(): void
    {
        $a = array_column((new IcpPack('p', $this->icp()))->catalogueForRun(Carbon::create(2026, 10, 1)), 'id');
        $changed = $this->icp(['queries' => [['id' => 'z', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'something else']]]);
        $b = array_column((new IcpPack('p', $changed))->catalogueForRun(Carbon::create(2026, 10, 1)), 'id');
        $this->assertSame(['z'], $b);
        $this->assertNotContains('z', $a);
    }

    // ── the research prompt follows the saved ICP ─────────────────────────────

    public function test_the_prompt_changes_with_region_trigger_exclusion_must_have_and_guidance(): void
    {
        $base = (new IcpPack('p', $this->icp()))->promptBlock();
        $this->assertStringContainsString('Gujarat', $base);
        $this->assertStringContainsString('a new plant needs payroll', $base);
        $this->assertStringContainsString('trader', $base);
        $this->assertStringContainsString('fewer than 50 staff', $base);

        $kerala = (new IcpPack('p', $this->icp(['regions' => ['Kerala']])))->promptBlock();
        $this->assertStringContainsString('Kerala', $kerala);
        $this->assertStringNotContainsString('Gujarat', $kerala);

        $trig = (new IcpPack('p', $this->icp(['triggers' => ['tender_open' => ['source' => 'tender', 'part' => 'payroll', 'decay' => null, 'pain' => 'a payroll tender is open', 'phrases' => ['tender']]]])))->promptBlock();
        $this->assertStringContainsString('a payroll tender is open', $trig);
        $this->assertStringNotContainsString('a new plant needs payroll', $trig);

        $this->assertStringContainsString('Only mid-size firms', (new IcpPack('p', $this->icp(['guidance' => 'Only mid-size firms'])))->promptBlock());
    }

    // ── qualification follows the saved ICP ───────────────────────────────────

    public function test_exclusions_freshness_and_must_haves_change_the_qualification_outcome(): void
    {
        $pack = new IcpPack('p', $this->icp());
        $this->assertSame('trader', $pack->disqualifier('Acme trading company opens a plant'));
        $this->assertNull((new IcpPack('p', $this->icp(['disqualifiers' => []])))->disqualifier('Acme trading company opens a plant'));

        $this->assertSame(90, $pack->decayDays('other'));
        $this->assertSame(30, (new IcpPack('p', $this->icp(['decay_by_trigger_type' => ['default' => 30]])))->decayDays('other'));

        $scorer = new IcpScorer();
        $small = ['name' => 'Small Works', 'attributes' => ['staff' => 20]];
        $this->assertSame('disqualified', $scorer->score($small, $this->icp())['status']);
        $this->assertNotSame('disqualified', $scorer->score($small, $this->icp(['must_have' => []]))['status']);
    }

    // ── context report, isolation and company profile ─────────────────────────

    public function test_two_products_with_different_icps_never_share_a_plan(): void
    {
        $a = new IcpPack('a', $this->icp());
        $b = new IcpPack('b', $this->icp(['regions' => ['Kerala'], 'label' => 'Hospital ATS']));
        $this->assertNotSame($a->promptBlock(), $b->promptBlock());
        $this->assertNotSame($this->queries($this->offer(), $this->icp()), $this->queries($this->offer(), $this->icp(['regions' => ['Kerala']])));
    }

    public function test_every_saved_field_has_a_documented_stage_and_unused_ones_say_so(): void
    {
        $fields = [...array_keys(ResearchContextReport::PRODUCT_FIELDS), ...array_keys(ResearchContextReport::ICP_FIELDS), ...array_keys(ResearchContextReport::KEPT_FOR_PEOPLE)];
        foreach ($fields as $f) {
            $this->assertArrayHasKey($f, ResearchContextReport::STAGES, $f);
            $this->assertNotSame([], ResearchContextReport::STAGES[$f]);
        }
        foreach (['pricing_model', 'typical_deal_band', 'deployment_model', 'delivery_owner', 'notes', 'company_profile'] as $f) {
            $this->assertSame(['none'], ResearchContextReport::STAGES[$f], "{$f} is not sent to research");
        }
        $r = ResearchContextReport::build(['key' => 'x', 'label' => 'X', 'offers' => [['pricing_model' => null, 'notes' => 'internal']], 'icp' => null], $this->icp(), null, false);
        $byField = array_column($r['kept_for_people'], 'saved', 'field');
        $this->assertFalse($byField['pricing_model'], 'a missing price stays unknown, never invented');
        $this->assertTrue($byField['notes']);
        $this->assertFalse($byField['company_profile']);
    }

    public function test_the_research_context_is_read_per_organisation_only(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Products/ProductCatalog.php');
        $a = strpos($src, 'public function researchContext');
        $body = substr($src, $a, 3200);
        $this->assertStringContainsString('childOffers($tenantId, $key)', $body);
        $this->assertStringContainsString("ProductProfile::where('sub_institute_id', \$tenantId)", $body);
        $this->assertStringContainsString("where('sub_institute_id', \$tenantId)->where('id', \$profileId)", $body);
    }
}
