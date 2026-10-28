<?php

namespace Tests\Unit;

use App\Domain\Signals\Run\RunAccounting;
use PHPUnit\Framework\TestCase;

/** Where a requested region goes: request -> validation -> job -> researcher (plan, prompt, qualification) -> accounting. Source-level checks, no database. */
class RegionPlumbingTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_the_api_validates_the_region_before_anything_starts_and_hands_it_to_the_job(): void
    {
        $c = $this->src('app/Http/Controllers/Api/Signals/OpportunityController.php');
        $this->assertStringContainsString("RegionScope::fromInput(\$request->input('regions'))", $c);
        $this->assertStringContainsString("'code' => 'invalid_region'", $c);
        // the region is checked before the run row is created, so an invalid request starts nothing
        $this->assertLessThan(strpos($c, 'ResearchRun::create(['), strpos($c, "RegionScope::fromInput(\$request->input('regions'))"));
        $this->assertStringContainsString('$productKey, $region?->selections(), $offerId)', $c, 'the NORMALISED selections go to the job, not the raw input');
        $this->assertStringNotContainsString("input('sub_institute_id'", $c);
    }

    public function test_the_job_carries_the_region_to_the_researcher(): void
    {
        $j = $this->src('app/Jobs/RunOpportunityResearchJob.php');
        $this->assertStringContainsString('public readonly ?array $regions = null,', $j);
        $this->assertStringContainsString('$this->productKey, $this->regions, $this->offerId)', $j);
    }

    public function test_the_researcher_aims_the_plan_and_the_prompt_at_the_region_but_qualifies_with_the_original_pack(): void
    {
        $r = $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString('$pack->withRegionScope($this->regionScope)', $r);
        $this->assertStringContainsString('->plan($tenantId, $planPack, null, $this->ctx->key)', $r);
        $this->assertStringContainsString('$this->messages($profile, $sources, $planPack)', $r);
        // validation and account linking still use the saved ICP's pack
        $this->assertStringContainsString('$this->validate((array) ($result[\'data\'][\'opportunities\'] ?? []), $sources, $profile, $pack)', $r);
        $this->assertStringContainsString('$this->linkAccounts($tenantId, $pack,', $r);
    }

    public function test_a_candidate_is_rejected_when_the_source_does_not_name_the_requested_place_and_the_check_is_per_run(): void
    {
        $r = $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("\$this->regionScope->evidence(\$location, (string) \$support['norm'])", $r);
        $this->assertStringContainsString("return \$this->reject('outside_region');", $r);
        $this->assertStringContainsString("'outside_region' => 'region_not_stated_in_source'", $r);
        // reset at the start of every run: one run's region never reaches the next
        $a = strpos($r, 'QualificationGate::forgetOwnProductFits();');
        $this->assertStringContainsString('$this->regionScope = null;', substr($r, $a, 200));
        // an invalid region is ignored, never guessed
        $this->assertStringContainsString('catch (\\App\\Domain\\Signals\\Support\\RegionInvalid)', $r);
    }

    public function test_the_signal_keeps_what_its_source_actually_showed_about_the_region(): void
    {
        $r = $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("'region' => \$regionEvidence === null ? null : ['requested'", $r);
        $this->assertStringContainsString("'basis' => \$regionEvidence['basis']", $r);
    }

    public function test_the_run_reports_the_region_it_was_limited_to_and_all_regions_reports_none(): void
    {
        $base = ['executed' => 3, 'failed' => 0];
        $with = RunAccounting::build($base + ['region' => ['label' => 'Surat, Gujarat, India', 'places' => 1, 'outside' => 2, 'unconfirmed' => 0, 'confirmed' => 1]], [], ['sources' => 0, 'candidates' => 0]);
        $this->assertSame('Surat, Gujarat, India', $with['region']['label']);
        $this->assertSame(2, $with['region']['outside']);
        $this->assertNull(RunAccounting::build($base, [], ['sources' => 0, 'candidates' => 0])['region'], 'All Regions: no region recorded');
    }

    public function test_the_planner_limits_named_accounts_to_a_requested_state_or_city_only(): void
    {
        $p = $this->src('app/Domain/Signals/Icp/IcpResearchPlanner.php');
        $this->assertStringContainsString("\$cfg['region_scope']['selections']", $p);
        $this->assertStringContainsString("count(array_filter(\$places, fn (\$s) => \$s['city'] === null && \$s['state'] === null)) === 0", $p, 'a country-only request does not filter accounts');
        $this->assertStringContainsString("where('sub_institute_id', \$tenantId)", $p, 'still organisation-scoped');
    }

    public function test_the_request_budget_settings_are_untouched(): void
    {
        $cfg = $this->src('config/signals.php');
        $this->assertStringContainsString("'max_queries' => (int) env('SIGNALS_RESEARCH_MAX_QUERIES', 14)", $cfg);
        $this->assertStringContainsString("'fetch_pages' => (int) env('SIGNALS_RESEARCH_FETCH_PAGES', 8)", $cfg);
        $this->assertStringContainsString("'max_opportunities' => (int) env('SIGNALS_RESEARCH_MAX_OPPORTUNITIES', 15)", $cfg);
        $this->assertStringContainsString("'ai_attempts' => (int) env('SIGNALS_AI_ATTEMPTS', 2)", $cfg);
    }
}
