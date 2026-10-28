<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\CompanyAlias;
use App\Domain\Signals\Market\ImportRejection;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\OpportunityEvent;
use App\Domain\Signals\Market\ScanLog;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The structured demand-side import: gates, dedup, history, tenant isolation, permissions.
 * Runs on in-memory sqlite; no network and no AI is involved.
 */
class MarketImportTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    private const MIGRATIONS = [
        '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
        '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
        '2026_09_30_200000_add_research_sources_and_signal_kind',
        '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
        '2026_09_30_230000_add_claude_feed_and_linking_fields',
        '2026_10_01_130000_add_stage_to_g2g_research_runs',
        '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });

        foreach (self::MIGRATIONS as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Carbon::setTestNow('2026-10-05 10:00:00');

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function rec(array $over = []): array
    {
        return array_replace_recursive([
            'buyer' => ['name' => 'Example State Department of School Education', 'type' => 'government', 'state' => 'Exampleland'],
            'trigger_type' => 'tender', 'trigger_summary' => 'Tender for a teacher capability assessment platform',
            'what_happened' => 'The department published a tender.', 'source_url' => 'https://example.invalid/tenders/1',
            'reference_no' => 'DSE/2026/001', 'event_date' => '2026-10-01', 'expires_at' => '2026-10-12',
            'claim_level' => 'confirmed', 'fetch_level' => 'full_document', 'business_fit' => 'g2g',
            'candidate_need_codes' => ['N01', 'N02'], 'what_we_could_sell' => 'Capability gap assessment',
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 4, 'urgency' => 4, 'potential_value' => 4, 'evidence_quality' => 4, 'reasoning' => 'r'],
        ], $over);
    }

    private function importer(): MarketImporter
    {
        return app(MarketImporter::class);
    }

    // ── import, mapping, history ─────────────────────────────────────────────

    public function test_an_accepted_record_becomes_a_graded_opportunity_with_a_buyer_and_history(): void
    {
        $out = $this->importer()->import(self::T1, [$this->rec()], ['source_label' => 'daily-agent']);

        $this->assertSame(1, $out['accepted']);

        $o = CompanyOpportunity::sole();
        $this->assertSame(self::T1, (int) $o->sub_institute_id);
        $this->assertNull($o->research_run_id, 'an imported signal has no web-research run');
        $this->assertSame('New', $o->review_status);
        $this->assertSame('NEW', $o->pipeline_status);
        $this->assertSame('tender', $o->trigger_type);
        $this->assertSame('project', $o->category, 'trigger types map onto the existing categories');
        $this->assertSame('confirmed', $o->claim_level);
        $this->assertSame('High', $o->priority, '28/35 is High');
        $this->assertSame(28, (int) $o->score_total);
        $this->assertSame(4, (int) $o->score_urgency);
        $this->assertSame(['N01', 'N02'], $o->candidate_need_codes);
        $this->assertSame('2026-10-12', $o->expires_at->toDateString());
        $this->assertSame('immediate_action', $o->feed_section, 'a tender closing in under 14 days');
        $this->assertSame('Relevant Requirement Found', $o->qualification);
        $this->assertSame('daily-agent', $o->import_source);
        $this->assertFalse($o->is_sample);

        $company = Company::sole();
        $this->assertSame('government', $company->type);
        $this->assertSame('Exampleland', $company->state);
        $this->assertSame($company->id, (int) $o->company_id);
        $this->assertSame(1, CompanyAlias::count());

        $this->assertSame(['created'], OpportunityEvent::pluck('event_type')->all());

        $log = ScanLog::sole();
        $this->assertSame([1, 1, 0, 0, 0], [$log->records_received, $log->records_accepted, $log->records_rejected, $log->records_duplicate, $log->records_updated]);
        $this->assertSame('daily-agent', $log->source_label);
    }

    public function test_rejected_records_are_kept_with_their_payload_and_reason(): void
    {
        $bad = $this->rec(['trigger_summary' => '']);
        unset($bad['trigger_summary']);

        $out = $this->importer()->import(self::T1, [$bad, 'not-an-object', $this->rec()]);

        $this->assertSame([1, 2], [$out['accepted'], $out['rejected']]);
        $this->assertSame(1, CompanyOpportunity::count());

        $rows = ImportRejection::orderBy('row_index')->get();
        $this->assertSame(['missing_trigger', 'invalid_record'], $rows->pluck('reason_code')->all());
        $this->assertSame([0, 1], $rows->pluck('row_index')->all());
        $this->assertSame('DSE/2026/001', $rows[0]->payload['reference_no'], 'the rejected record is stored so it can be fixed and re-sent');
        $this->assertSame(2, (int) ScanLog::sole()->records_rejected);
    }

    public function test_a_snippet_only_source_is_stored_as_inference_with_the_reason_recorded(): void
    {
        $this->importer()->import(self::T1, [$this->rec(['fetch_level' => 'search_snippet'])]);

        $o = CompanyOpportunity::sole();
        $this->assertSame('inference', $o->claim_level);
        $this->assertSame('Needs Verification', $o->qualification);
        $this->assertStringContainsString('search_snippet', $o->evidence_note);
        $this->assertContains('evidence_downgraded', OpportunityEvent::pluck('event_type')->all());
        $this->assertSame(1, ScanLog::sole()->notes['evidence_downgrades']);
    }

    // ── dedup / update ───────────────────────────────────────────────────────

    public function test_re_importing_an_unchanged_signal_is_a_duplicate_and_never_re_flagged(): void
    {
        $this->importer()->import(self::T1, [$this->rec()]);
        CompanyOpportunity::sole()->update(['review_status' => 'Reviewed', 'pipeline_status' => 'ENGAGED']);

        $out = $this->importer()->import(self::T1, [$this->rec()]);

        $this->assertSame([0, 0, 1], [$out['accepted'], $out['updated'], $out['duplicate']]);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame('Reviewed', CompanyOpportunity::sole()->review_status, 'a duplicate must not reset a human decision');
        $this->assertSame(1, OpportunityEvent::count());
    }

    public function test_an_advanced_signal_updates_in_place_records_history_and_keeps_human_status(): void
    {
        $this->importer()->import(self::T1, [$this->rec()]);
        CompanyOpportunity::sole()->update(['review_status' => 'Follow-up', 'pipeline_status' => 'ENGAGED']);

        // The deadline is extended and the evidence is better: an amendment.
        $out = $this->importer()->import(self::T1, [$this->rec(['expires_at' => '2026-11-20', 'event_date' => '2026-10-04'])]);

        $this->assertSame([0, 1, 0], [$out['accepted'], $out['updated'], $out['duplicate']]);
        $this->assertSame(1, CompanyOpportunity::count());

        $o = CompanyOpportunity::sole();
        $this->assertSame('2026-11-20', $o->expires_at->toDateString());
        $this->assertSame('2026-10-04', $o->event_date->toDateString());
        $this->assertSame('Follow-up', $o->review_status);
        $this->assertSame('ENGAGED', $o->pipeline_status);

        $update = OpportunityEvent::where('event_type', 'updated')->sole();
        $this->assertSame(['2026-10-12', '2026-11-20'], $update->changes['expires_at']);
        $this->assertEqualsCanonicalizing(['expires_at', 'event_date'], $out['results'][0]['changes']);
    }

    public function test_an_older_or_weaker_repeat_is_a_duplicate(): void
    {
        $this->importer()->import(self::T1, [$this->rec()]);

        $out = $this->importer()->import(self::T1, [$this->rec(['event_date' => '2026-09-01', 'claim_level' => 'hypothesis', 'expires_at' => null])]);

        $this->assertSame(1, $out['duplicate']);
    }

    // ── buyer resolution ─────────────────────────────────────────────────────

    public function test_an_alias_resolves_to_the_same_buyer_and_a_near_match_is_only_suggested(): void
    {
        $this->importer()->import(self::T1, [$this->rec(['buyer' => ['aliases' => ['Example DSE']]])]);

        $out = $this->importer()->import(self::T1, [
            $this->rec(['buyer' => ['name' => 'Example DSE'], 'reference_no' => 'DSE/2026/002', 'source_url' => 'https://example.invalid/tenders/2']),
            $this->rec(['buyer' => ['name' => 'Example State Dept of School Education.'], 'reference_no' => 'DSE/2026/003', 'source_url' => 'https://example.invalid/tenders/3']),
        ]);

        $this->assertSame(1, Company::count(), 'the alias and the punctuation/abbreviation variant both resolve to the existing buyer');
        $this->assertSame(3, CompanyOpportunity::count());
        $this->assertSame(2, $out['accepted']);
    }

    public function test_a_close_but_different_name_creates_a_new_buyer_and_reports_a_suggestion(): void
    {
        $this->importer()->import(self::T1, [$this->rec()]);

        $this->importer()->import(self::T1, [$this->rec(['buyer' => ['name' => 'Example State Department of School Educations'], 'reference_no' => 'X/9', 'source_url' => 'https://example.invalid/x/9'])]);

        $this->assertSame(2, Company::count(), 'never auto-merged');
        $suggestions = ScanLog::orderByDesc('id')->first()->notes['buyer_suggestions'];
        $this->assertCount(1, $suggestions);
        $this->assertSame('Example State Department of School Education', $suggestions[0]['name']);
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    // ── endpoint ─────────────────────────────────────────────────────────────

    // ── command + migration ──────────────────────────────────────────────────

    public function test_the_command_imports_the_sample_file_and_dry_run_writes_nothing(): void
    {
        $sample = base_path('docs/samples/market-signals.sample.json');

        $this->assertSame(0, Artisan::call('signals:import-market', ['path' => $sample, '--tenant' => self::T1, '--dry-run' => true]));
        $this->assertSame(0, CompanyOpportunity::count());

        $this->assertSame(0, Artisan::call('signals:import-market', ['path' => $sample, '--tenant' => self::T1]));
        $this->assertSame(2, CompanyOpportunity::count(), 'two valid sample records; the third is rejected');
        $this->assertSame(1, ImportRejection::count());
        $this->assertSame([true, true], CompanyOpportunity::pluck('is_sample')->all(), 'sample rows are marked so feeds can exclude them');
        $this->assertStringContainsString('rejected', Artisan::output());

        $this->assertNotSame(0, Artisan::call('signals:import-market', ['path' => $sample]), 'a tenant is required');
    }

    public function test_the_migration_is_safe_to_run_twice_and_leaves_existing_rows_alone(): void
    {
        $company = Company::create(['sub_institute_id' => self::T1, 'name' => 'Legacy Co', 'normalized_name' => 'legacy co']);
        DB::table('g2g_company_opportunities')->insert([
            'sub_institute_id' => self::T1, 'company_id' => $company->id, 'research_run_id' => 5, 'title' => 'Legacy', 'category' => 'expansion',
            'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a', 'priority' => 'High',
            'qualification' => 'Monitoring', 'confidence' => 'Low', 'sources' => '[]', 'fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require base_path('database/migrations/2026_10_08_100000_extend_g2g_signals_for_market_import.php'))->up();

        $legacy = CompanyOpportunity::sole();
        $this->assertSame('Legacy', $legacy->title);
        $this->assertSame(5, (int) $legacy->research_run_id);
        $this->assertSame('New', $legacy->review_status);
        $this->assertSame('NEW', $legacy->pipeline_status);
        $this->assertSame('foundation', DB::table('g2g_ingestion_sources')->getConnection()->getSchemaBuilder()->hasColumn('g2g_ingestion_sources', 'intent') ? 'foundation' : 'missing');
    }
    // ── first_discovered_at must survive updates (ON UPDATE current_timestamp() hazard) ──

    public function test_an_importer_update_assigns_first_discovered_at_explicitly_and_keeps_its_value(): void
    {
        $this->importer()->import(self::T1, [$this->rec()]);
        DB::table('g2g_company_opportunities')->update(['first_discovered_at' => '2026-09-01 08:00:00']);

        $updates = [];
        DB::listen(function ($q) use (&$updates) {
            if (stripos($q->sql, 'update') === 0 && str_contains($q->sql, 'g2g_company_opportunities')) {
                $updates[] = $q->sql;
            }
        });

        $out = $this->importer()->import(self::T1, [$this->rec(['expires_at' => '2026-11-20'])]);

        $this->assertSame(1, $out['updated']);
        $this->assertCount(1, $updates, 'one UPDATE statement for the opportunity');
        // MySQL/MariaDB reset an ON UPDATE timestamp unless the UPDATE assigns the column, so the
        // statement must name it. Eloquent alone would drop an unchanged attribute from the SQL.
        $this->assertMatchesRegularExpression('/first_discovered_at\W+=\W+first_discovered_at/', $updates[0]);
        $this->assertSame('2026-09-01 08:00:00', DB::table('g2g_company_opportunities')->value('first_discovered_at'));
    }

    public function test_the_protect_migration_is_a_no_op_off_mysql_and_safe_to_repeat(): void
    {
        $m = require base_path('database/migrations/2026_10_08_110000_protect_first_discovered_at_on_opportunities.php');

        $m->up();
        $m->up();

        $this->assertTrue(Schema::hasTable('g2g_company_opportunities'));
    }

    /**
     * The real guard. It needs MySQL/MariaDB (sqlite has no ON UPDATE), so it is skipped on the
     * in-memory test database and run by hand against the local copy:
     *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=hp_erp_local \
     *   php artisan test --filter=first_discovered_at_survives_a_real
     */
    public function test_first_discovered_at_survives_a_real_value_change_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL/MariaDB: sqlite has no ON UPDATE CURRENT_TIMESTAMP.');
        }

        $this->importer()->import(self::T1, [$this->rec()]);
        DB::table('g2g_company_opportunities')->update(['first_discovered_at' => '2026-09-01 08:00:00']);
        // A plain value change, the same shape as a review/dismiss click.
        DB::table('g2g_company_opportunities')->update(['review_status' => 'Reviewed']);

        $this->assertSame('2026-09-01 08:00:00', DB::table('g2g_company_opportunities')->value('first_discovered_at'));
    }
}
