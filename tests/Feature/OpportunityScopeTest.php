<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\ResearchRunScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An opportunity keeps the scope it was found for (product, sub-product, customer model, ICP profile and version, region) ON the record, and is
 * recognised by that scope, not by a hash of the run's whole configuration. Runs on the in-memory sqlite database.
 */
class OpportunityScopeTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function makeRun(array $o = []): ResearchRun
    {
        return ResearchRun::create($o + [
            'sub_institute_id' => self::T, 'report_date' => '2026-10-20', 'trigger' => 'manual', 'status' => 'success', 'stage' => 'completed',
            'product_key' => 'enterprise_brain', 'customer_model' => 'b2g', 'offer_id' => 'EB-01', 'region_scope' => '[{"country":"India","state":"Gujarat","city":null}]',
            'icp_profile_id' => 7, 'icp_version' => 3, 'scope_key' => str_repeat('a', 64), 'started_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $o */
    private function record(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Gujarat Mills Ltd', 'type' => 'enterprise', 'state' => 'Gujarat'],
            'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new plant near Surat.',
            'what_happened' => 'Gujarat Mills Ltd is opening a new plant near Surat.', 'event_date' => '2026-10-10',
            'source_url' => 'https://news.example.com/mills-plant', 'source_title' => 'Mills opens plant',
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet', 'business_fit' => 'eb',
        ];
    }

    /** @param list<array<string, mixed>> $records @return array<string, mixed> */
    private function import(array $records, ?ResearchRun $run): array
    {
        return app(MarketImporter::class)->import(self::T, $records, ['source_label' => 'test'] + ($run ? ['research_run_id' => $run->id] : []));
    }

    public function test_a_new_opportunity_keeps_product_offer_model_icp_and_region_of_its_run(): void
    {
        $run = $this->makeRun();
        $this->import([$this->record()], $run);

        $o = CompanyOpportunity::sole();
        $this->assertSame(['enterprise_brain', 'EB-01', 'b2g', 7, 3], [$o->product_key, $o->offer_id, $o->customer_model, (int) $o->icp_profile_id, (int) $o->icp_version]);
        $this->assertSame($run->region_scope, $o->region_scope);
    }

    public function test_an_opportunity_without_a_run_is_left_unassigned_not_guessed(): void
    {
        $this->import([$this->record()], null);

        $o = CompanyOpportunity::sole();
        $this->assertNull($o->customer_model);
        $this->assertNull($o->product_key);
        $this->assertNull($o->offer_id);
    }

    public function test_a_run_that_recorded_no_customer_model_gives_its_opportunities_no_scope(): void
    {
        $run = $this->makeRun(['customer_model' => null, 'offer_id' => null]);
        $this->import([$this->record()], $run);

        $this->assertNull(CompanyOpportunity::sole()->customer_model, 'an older run has no recorded scope to copy');
    }

    public function test_editing_the_icp_does_not_turn_a_refound_event_into_a_second_opportunity(): void
    {
        // Same selection (product, offer, model, region); the configuration hash differs because the ICP was edited between the two runs.
        $this->import([$this->record()], $this->makeRun(['scope_key' => str_repeat('a', 64)]));
        $second = $this->import([$this->record()], $this->makeRun(['scope_key' => str_repeat('b', 64), 'icp_version' => 4]));

        $this->assertSame(1, CompanyOpportunity::count(), 'still one opportunity');
        $this->assertSame(0, $second['accepted']);
    }

    public function test_the_same_event_under_another_customer_model_or_offer_is_its_own_opportunity(): void
    {
        $this->import([$this->record()], $this->makeRun());
        $this->import([$this->record()], $this->makeRun(['customer_model' => 'b2b', 'scope_key' => str_repeat('c', 64)]));
        $this->import([$this->record()], $this->makeRun(['offer_id' => 'EB-02', 'scope_key' => str_repeat('d', 64)]));

        $this->assertSame(3, CompanyOpportunity::count());
        $this->assertEqualsCanonicalizing(['b2g', 'b2b', 'b2g'], CompanyOpportunity::pluck('customer_model')->all());
    }

    public function test_a_record_saved_with_the_old_hash_fingerprint_is_still_recognised(): void
    {
        $run = $this->makeRun();
        $this->import([$this->record()], $run);
        // Make the stored row look like one saved before scope was kept: the old fingerprint formula (with the configuration hash), no scope columns.
        $o = CompanyOpportunity::sole();
        $old = hash('sha256', implode('|', [self::T, $o->company_id, 'expansion', 'news.example.com/mills-plant', 'eb', $run->scope_key]));
        DB::table('g2g_company_opportunities')->where('id', $o->id)->update(['fingerprint' => $old, 'customer_model' => null, 'product_key' => null, 'offer_id' => null, 'region_scope' => null]);

        $again = $this->import([$this->record()], $run);

        $this->assertSame(1, CompanyOpportunity::count(), 'the old row is matched, not duplicated');
        $this->assertSame(0, $again['accepted']);
    }

    public function test_the_region_text_is_the_same_whatever_order_the_places_were_picked_in(): void
    {
        $a = ['country' => 'India', 'state' => 'Gujarat', 'city' => null];
        $b = ['country' => 'India', 'state' => 'Goa', 'city' => null];

        $this->assertSame(ResearchRunScope::regionJson([$a, $b]), ResearchRunScope::regionJson([$b, $a]));
        $this->assertNull(ResearchRunScope::regionJson(null));
        $this->assertNull(ResearchRunScope::regionJson([]));
    }

    public function test_the_migration_backfills_only_from_runs_that_recorded_a_scope(): void
    {
        $scoped = $this->makeRun();
        $old = $this->makeRun(['customer_model' => null, 'offer_id' => null]);
        foreach ([$scoped, $old] as $i => $run) {
            DB::table('g2g_company_opportunities')->insert(['sub_institute_id' => self::T, 'company_id' => 1, 'research_run_id' => $run->id, 'title' => "t{$i}", 'category' => 'project',
                'sources' => '[]', 'fingerprint' => "fp{$i}", 'observed_event' => 'x', 'why_indicates_need' => 'x', 'product_fit' => 'x', 'recommended_action' => 'x', 'priority' => 'Low', 'qualification' => 'Qualified',
                'confidence' => 'Low', 'review_status' => 'New', 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        (require base_path('database/migrations/2026_12_07_090000_add_scope_to_g2g_company_opportunities.php'))->up();

        $rows = DB::table('g2g_company_opportunities')->orderBy('id')->get();
        $this->assertSame('b2g', $rows[0]->customer_model);
        $this->assertSame('EB-01', $rows[0]->offer_id);
        $this->assertNull($rows[1]->customer_model, 'a run with no recorded scope leaves its opportunities unassigned');
        $this->assertSame(2, DB::table('g2g_company_opportunities')->count(), 'nothing deleted');
    }

    public function test_an_expired_tender_is_rejected_and_an_open_one_is_not(): void
    {
        $gate = new QualificationGate();
        $out = $gate->evaluate($this->record(['trigger_type' => 'tender', 'closing_date' => '2026-10-01', 'reference_no' => 'T-1']));
        $this->assertFalse($out['ok']);
        $this->assertSame('expired_tender', $out['code']);

        $open = $gate->evaluate($this->record(['trigger_type' => 'tender', 'closing_date' => '2026-11-30', 'reference_no' => 'T-2']));
        $this->assertTrue($open['ok'], $open['reason'] ?? '');
    }

    private function insertRaw(string $fp): void
    {
        DB::table('g2g_company_opportunities')->insert(['sub_institute_id' => self::T, 'company_id' => 1, 'title' => 't', 'category' => 'project', 'sources' => '[]', 'fingerprint' => $fp,
            'observed_event' => 'x', 'why_indicates_need' => 'x', 'product_fit' => 'x', 'recommended_action' => 'x', 'priority' => 'Low', 'qualification' => 'Qualified',
            'confidence' => 'Low', 'review_status' => 'New', 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_uniqueness_migration_adds_the_index_when_there_are_no_duplicates_and_then_the_database_refuses_one(): void
    {
        $this->insertRaw('one');
        (require base_path('database/migrations/2026_12_07_100000_add_uniqueness_to_signal_opportunities.php'))->up();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertRaw('one');
    }

    public function test_the_uniqueness_migration_fails_without_changing_anything_when_duplicates_exist_and_runs_once_they_are_resolved(): void
    {
        $this->insertRaw('dup');
        $this->insertRaw('dup');
        $migration = require base_path('database/migrations/2026_12_07_100000_add_uniqueness_to_signal_opportunities.php');

        try {
            $migration->up();
            $this->fail('the migration must refuse while duplicates exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
            $this->assertStringContainsString('g2g_company_opportunities', $e->getMessage());
        }
        $this->assertSame(2, DB::table('g2g_company_opportunities')->count(), 'nothing deleted or merged');
        $this->insertRaw('dup');   // still allowed: no index was added to any table
        $this->assertSame(3, DB::table('g2g_company_opportunities')->count());

        // Resolved by hand, the same migration then applies.
        DB::table('g2g_company_opportunities')->where('fingerprint', 'dup')->orderByDesc('id')->limit(2)->delete();
        $migration->up();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertRaw('dup');
    }

    public function test_promoting_to_crm_is_one_locked_transaction_that_rechecks_for_the_lead(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/Signals/OpportunityController.php'));
        $body = substr($src, (int) strpos($src, 'public function promoteToCrm'), 2200);
        $lookup = "->where('source_company_opportunity_id', \$id)->first('id')";

        $this->assertStringContainsString('DB::transaction(', $body);
        $this->assertStringContainsString('lockForUpdate()', $body);
        $this->assertLessThan(strpos($body, 'createLeadFor('), strpos($body, $lookup), 'the existing lead is looked for INSIDE the lock, before one is made');
        $this->assertLessThan(strpos($body, $lookup), strpos($body, 'lockForUpdate()'), 'the lock comes first');
    }
}
