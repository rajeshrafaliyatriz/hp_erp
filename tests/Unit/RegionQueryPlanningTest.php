<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Products\ResearchContextReport;
use App\Domain\Signals\Support\RegionScope;
use Tests\TestCase;

/**
 * A region request against the REAL built-in ScholarClone queries: nothing tied to another place stays in, generic queries stay, nothing is
 * added, nothing is counted twice, and the saved ICP is not changed. No network, no database.
 */
class RegionQueryPlanningTest extends TestCase
{
    private function pack(): IcpPack
    {
        return new IcpPack('scholar_k12', config('signals_icp.scholar_k12'));
    }

    private function scope(array $in): RegionScope
    {
        return RegionScope::fromInput($in);
    }

    /** @return list<string> */
    private function terms(): array
    {
        return array_merge(config('signals_regions.scholar.terms'), ['Gujarat', 'Maharashtra']);
    }

    private function names(string $q, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $q) === 1;
    }

    public function test_a_country_or_other_state_request_leaves_no_query_tied_to_gujarat_or_maharashtra(): void
    {
        foreach ([[['country' => 'India']], [['country' => 'India', 'state' => 'Kerala']], [['country' => 'India', 'state' => 'Tamil Nadu', 'city' => 'Chennai']]] as $in) {
            $scoped = $this->pack()->withRegionScope($this->scope($in));
            foreach ($scoped->config()['queries'] as $q) {
                foreach ($this->terms() as $t) {
                    $this->assertFalse($this->names($q['q'], $t), "{$q['id']} still names {$t} for " . json_encode($in));
                }
                $this->assertDoesNotMatchRegularExpression('/[\x{0A80}-\x{0AFF}]/u', $q['q'], "{$q['id']} is still in Gujarati");
            }
        }
    }

    public function test_generic_queries_stay_and_the_number_of_queries_can_only_go_down(): void
    {
        $orig = $this->pack()->config()['queries'];
        $scoped = $this->pack()->withRegionScope($this->scope([['country' => 'India']]))->config()['queries'];
        $this->assertLessThanOrEqual(count($orig), count($scoped));
        $ids = array_column($scoped, 'id');
        $this->assertSame([], array_diff($ids, array_column($orig, 'id')), 'nothing is added');
        foreach (['k12-tender-sms', 'k12-tender-class', 'k12-li-ask-erp', 'k12-forum-ask', 'k12-sem-excel', 'k12-sem-career'] as $generic) {
            $this->assertContains($generic, $ids, "{$generic} is a generic query and stays");
        }
        $this->assertStringContainsString('"school management system"', $scoped[array_search('k12-tender-sms', $ids, true)]['q']);
    }

    public function test_queries_written_for_gujarat_are_kept_only_when_the_request_covers_gujarat(): void
    {
        $has = fn (array $in) => in_array('k12-gu-news-campus', array_column($this->pack()->withRegionScope($this->scope($in))->config()['queries'], 'id'), true);
        $this->assertTrue($has([['country' => 'India', 'state' => 'Gujarat']]));
        $this->assertTrue($has([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']]));
        $this->assertFalse($has([['country' => 'India']]), 'a country-only request is not a Gujarat request');
        $this->assertFalse($has([['country' => 'India', 'state' => 'Maharashtra']]));
        $this->assertTrue($has([['country' => 'India', 'state' => 'Kerala'], ['country' => 'India', 'state' => 'Gujarat']]));
    }

    public function test_a_city_request_puts_the_city_in_every_query_and_the_requested_scope_is_not_evidence(): void
    {
        $scoped = $this->pack()->withRegionScope($this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']]));
        foreach ($scoped->config()['queries'] as $q) {
            $this->assertTrue($this->names($q['q'], 'Surat'), $q['id']);
        }
        // the plan only AIMS the search: whether a source really is in Surat is decided by the source text (RegionScope::evidence)
        $this->assertSame('outside', $this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']])->evidence('Surat', 'A school announced a new campus.')['status']);
    }

    public function test_queries_that_become_identical_are_kept_once(): void
    {
        $cfg = ['product_line' => 'scholar', 'regions' => ['Gujarat'], 'queries' => [
            ['id' => 'a', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'school "new campus" Gujarat'],
            ['id' => 'b', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'school "new campus" Surat OR Pune'],
            ['id' => 'c', 'source' => 'tender', 'topic' => 'general', 'days' => 30, 'q' => 'school "new campus" Gujarat'],
        ]];
        $q = (new IcpPack('x', $cfg))->withRegionScope($this->scope([['country' => 'India', 'state' => 'Kerala']]))->config()['queries'];
        $this->assertSame(['a', 'c'], array_column($q, 'id'), 'a and b are the same search now; c is a different source kind');
    }

    public function test_the_saved_region_rule_no_longer_contradicts_the_request_in_the_prompt_but_stays_for_qualification(): void
    {
        $pack = $this->pack();
        $this->assertNotEmpty(array_filter($pack->config()['must_have'], fn ($r) => ($r['op'] ?? '') === 'in_regions'));
        $scoped = $pack->withRegionScope($this->scope([['country' => 'India']]));
        $this->assertEmpty(array_filter($scoped->config()['must_have'], fn ($r) => ($r['op'] ?? '') === 'in_regions'));
        $this->assertStringNotContainsString('outside the target regions', $scoped->promptBlock());
        $this->assertStringContainsString('REGION FOCUS', $scoped->promptBlock());
        $this->assertStringContainsString('outside the target regions', $pack->promptBlock(), 'the original (qualification) pack is unchanged');
    }

    public function test_budgets_and_saved_regions_are_untouched_by_a_request(): void
    {
        $pack = $this->pack();
        $before = $pack->config();
        $scoped = $pack->withRegionScope($this->scope([['country' => 'India']]));
        $this->assertSame($before, $pack->config(), 'building the copy changes nothing');
        $this->assertSame($before['per_run'], $scoped->config()['per_run']);
        $this->assertSame(['Gujarat', 'Dadra and Nagar Haveli and Daman and Diu', 'Maharashtra'], $pack->config()['regions']);
    }

    public function test_all_regions_is_the_existing_plan(): void
    {
        $this->assertNull(RegionScope::fromInput([]));
        $this->assertSame(27, count($this->pack()->config()['queries']), 'the built-in set is as it was');
    }

    public function test_the_place_metadata_refers_to_real_queries_and_is_outside_the_saved_icp_schema(): void
    {
        $ids = array_column(config('signals_icp.scholar_k12.queries'), 'id');
        foreach (array_keys(config('signals_regions.scholar.only_for')) as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertArrayNotHasKey('region_terms', config('signals_icp.scholar_k12'), 'nothing was added to the ICP definition an administrator edits');
    }

    // ── the pre-run description and the run record ───────────────────────────

    public function test_the_context_report_says_where_the_region_comes_from(): void
    {
        $product = ['key' => 'scholarclone', 'label' => 'ScholarClone', 'offers' => [], 'icp' => ['source' => 'template', 'name' => 'ScholarClone K-12 Bundle', 'profile_id' => null]];
        $cfg = ['regions' => ['Gujarat', 'Maharashtra'], 'industry_label' => 'schools'];

        $req = ResearchContextReport::build($product, $cfg, null, false, $this->scope([['country' => 'India']]))['region'];
        $this->assertSame('request', $req['source']);
        $this->assertSame('India', $req['label']);
        $this->assertSame(['Gujarat', 'Maharashtra'], $req['saved_regions'], 'saved regions are reported separately, never as the selection');

        $saved = ResearchContextReport::build($product, $cfg)['region'];
        $this->assertSame('saved ICP', $saved['source']);
        $this->assertStringContainsString('No region was chosen', $saved['note']);

        $none = ResearchContextReport::build($product, ['industry_label' => 'schools'])['region'];
        $this->assertSame('All Regions', $none['source']);
    }

    public function test_the_run_records_its_effective_region_in_its_first_message_and_a_sanitized_log_line(): void
    {
        $c = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Signals/OpportunityController.php');
        $this->assertStringContainsString("'Queued. Waiting for a worker to start the run…' . (\$subName !== null ? ' Sub-product: ' . \$subName . ' (' . \$offerId . '), using its own ICP.' : '') . \$this->regionNote(\$region, \$product)", $c);
        $this->assertStringContainsString("Log::info('signals.run_region'", $c);
        $log = substr($c, strpos($c, "Log::info('signals.run_region'"), 420);
        foreach (['token', 'password', 'secret', 'Authorization', '$request->all', 'page_text'] as $never) {
            $this->assertStringNotContainsString($never, $log);
        }
        $this->assertStringContainsString("'region_source' => \$region !== null ? 'request'", $c);
        $this->assertStringContainsString("\$this->diag['region'] = ['source' => 'request'", file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php'));
        $p = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Portfolio/ProductController.php');
        $this->assertStringContainsString("RegionScope::fromInput(is_string(\$raw) ? json_decode(\$raw, true) : \$raw)", $p);
        $this->assertStringContainsString("'code' => 'invalid_region'", $p);
    }
}
