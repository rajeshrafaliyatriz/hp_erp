<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\Signals\Backfill\BackfillBlocked;
use App\Domain\Signals\Backfill\DateStatusBackfill;
use App\Domain\Signals\Backfill\ProductBackfill;
use App\Domain\Signals\Backfill\ReviewsBackfill;
use App\Domain\Signals\Opportunities\DateStatus;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Slice 2 data backfills: `signals:backfill-date-status`, `signals:backfill-reviews` and `signals:backfill-product-master`
 * (Docs/agentic-crm/slices/S02-contract.md, "Backfill commands"). The signals table, the history table and the Product Master come from the
 * real migration files run on the in-memory sqlite database.
 */
class SignalsBackfillTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    /** The tenant id of a Product Master row shared by every organisation. */
    private const SHARED = 0;

    private string $scratch;

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
            '2026_12_08_090000_create_g2g_signal_reviews_table', '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        ProductCatalog::forgetCustom();
        $this->scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'signals-backfill-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->scratch)) {
            foreach (glob($this->scratch . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->scratch);
        }
        parent::tearDown();
    }

    private function signal(array $o = []): int
    {
        return (int) DB::table('g2g_company_opportunities')->insertGetId($o + [
            'sub_institute_id' => self::A, 'company_id' => 1, 'research_run_id' => 1, 'title' => 'A signal', 'category' => 'cat',
            'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'r', 'priority' => 'High',
            'qualification' => 'q', 'confidence' => 'High', 'review_status' => 'New', 'sources' => '[]', 'fingerprint' => hash('sha256', uniqid('', true)),
            'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
        ]);
    }

    private function snapshot(string $table = 'g2g_company_opportunities'): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function dateStatusOf(int $id): ?string
    {
        return DB::table('g2g_company_opportunities')->where('id', $id)->value('date_status');
    }

    // ================= date status =================

    /** @return array<string,int> */
    private function seedDates(): array
    {
        $ids = [];
        $ids['event'] = $this->signal(['event_date' => '2026-10-12', 'source_published_at' => '2026-10-03', 'assessment' => json_encode(['evidence' => ['date_status' => 'event_date_stated']])]);
        $ids['source'] = $this->signal(['source_published_at' => '2026-10-03', 'assessment' => json_encode(['evidence' => ['date_status' => 'undated']])]);
        $ids['none'] = $this->signal(['assessment' => '{not json']);
        $ids['zero'] = $this->signal(['event_date' => '0000-00-00', 'source_published_at' => '0000-00-00', 'assessment' => json_encode(['buying_intent' => ['level' => 'x']])]);
        $ids['sample'] = $this->signal(['event_date' => '2026-10-12', 'is_sample' => 1]);
        $ids['differs'] = $this->signal(['event_date' => '2026-10-12', 'date_status' => 'undated']);
        $ids['matches'] = $this->signal(['event_date' => '2026-10-12', 'date_status' => 'event_date_stated']);
        $ids['other'] = $this->signal(['sub_institute_id' => self::B, 'event_date' => '2026-10-12']);

        return $ids;
    }

    public function test_date_status_dry_run_counts_by_status_and_writes_nothing(): void
    {
        $this->seedDates();
        $before = $this->snapshot();

        $r = (new DateStatusBackfill)->run(self::A, false);

        $this->assertSame($before, $this->snapshot(), 'a dry run writes nothing');
        $this->assertFalse($r['apply']);
        $this->assertSame(6, $r['rows'], 'six non-sample signals');
        $this->assertSame(1, $r['samples_skipped']);
        $this->assertSame(['event_date_stated' => 3, 'source_date_only' => 1, 'undated' => 2], $r['classified'], 'a zero date counts as no date');
        $this->assertSame(['event_date_stated' => 1, 'source_date_only' => 1, 'undated' => 2], $r['fill']);
        $this->assertSame(2, $r['already_filled']);
        $this->assertSame(0, $r['written']);
    }

    public function test_date_status_apply_fills_only_empty_values_and_never_touches_samples_other_organisations_or_updated_at(): void
    {
        $ids = $this->seedDates();
        $before = collect($this->snapshot())->keyBy('id');

        $r = (new DateStatusBackfill)->run(self::A, true);

        $this->assertSame(4, $r['written']);
        $this->assertSame('event_date_stated', $this->dateStatusOf($ids['event']), 'dated by its event');
        $this->assertSame('source_date_only', $this->dateStatusOf($ids['source']), 'dated by its source only');
        $this->assertSame('undated', $this->dateStatusOf($ids['none']));
        $this->assertSame('undated', $this->dateStatusOf($ids['zero']), 'a zero date is not a date');
        $this->assertNull($this->dateStatusOf($ids['sample']), 'a sample signal is left out');
        $this->assertSame('undated', $this->dateStatusOf($ids['differs']), 'a value already there is never overwritten, even when the columns now say otherwise');
        $this->assertSame('event_date_stated', $this->dateStatusOf($ids['matches']));
        $this->assertNull($this->dateStatusOf($ids['other']), "another organisation's signal is not touched");

        // Everything but date_status stays byte for byte as it was.
        foreach ($this->snapshot() as $row) {
            $was = $before[$row['id']];
            unset($row['date_status'], $was['date_status']);
            $this->assertSame($was, $row, "row {$row['id']} changed in a column other than date_status");
        }
    }

    public function test_date_status_uses_the_same_rule_as_the_feed(): void
    {
        $ids = $this->seedDates();
        $this->signal(['event_date' => '2026-10-12', 'source_published_at' => '2026-10-03']);
        (new DateStatusBackfill)->run(self::A, true);

        foreach (['event', 'source', 'none', 'zero'] as $kind) {
            $row = DB::table('g2g_company_opportunities')->where('id', $ids[$kind])->first();
            $this->assertSame(DateStatus::status($row->event_date, $row->source_published_at), $row->date_status, $kind);
        }
        $both = DB::table('g2g_company_opportunities')->where('event_date', '2026-10-12')->where('source_published_at', '2026-10-03')->where('sub_institute_id', self::A)->get();
        $this->assertTrue($both->isNotEmpty());
        $this->assertSame(['event_date_stated'], $both->pluck('date_status')->unique()->values()->all(), 'the event date wins over the source date');
    }

    public function test_date_status_second_run_changes_nothing(): void
    {
        $this->seedDates();
        (new DateStatusBackfill)->run(self::A, true);
        $after = $this->snapshot();

        $r = (new DateStatusBackfill)->run(self::A, true);

        $this->assertSame($after, $this->snapshot());
        $this->assertSame(0, $r['written']);
        $this->assertSame(array_fill_keys(DateStatus::ALL, 0), $r['fill'], 'nothing is left to fill');
    }

    public function test_date_status_cross_checks_the_stored_assessment_in_php(): void
    {
        $ids = $this->seedDates();

        $a = (new DateStatusBackfill)->run(self::A, false)['assessment'];

        $this->assertSame(2, $a['checked'], 'two signals carry evidence.date_status');
        $this->assertSame(1, $a['disagree']);
        $this->assertSame([['id' => $ids['source'], 'assessment' => 'undated', 'columns' => 'source_date_only']], $a['examples']);
        $this->assertSame(1, $a['unreadable'], 'a broken JSON note is counted, not guessed');
    }

    public function test_date_status_lists_at_most_ten_ids_but_counts_all(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->signal(['event_date' => '2026-10-12', 'assessment' => json_encode(['evidence' => ['date_status' => 'undated']]), 'date_status' => 'source_date_only']);
        }

        $r = (new DateStatusBackfill)->run(self::A, false);

        $this->assertSame(12, $r['assessment']['disagree']);
        $this->assertCount(10, $r['assessment']['examples']);
        $this->assertSame(12, $r['filled_differs']['count']);
        $this->assertCount(10, $r['filled_differs']['ids']);
    }

    public function test_date_status_failure_rolls_back_the_whole_organisation(): void
    {
        $this->signal(['event_date' => '2026-10-12']);
        $this->signal(['event_date' => '2026-10-12']);
        $third = $this->signal(['event_date' => '2026-10-12']);
        DB::unprepared("CREATE TRIGGER stop_here BEFORE UPDATE OF date_status ON g2g_company_opportunities WHEN NEW.id = {$third} BEGIN SELECT RAISE(ABORT, 'stop'); END");

        try {
            (new DateStatusBackfill)->run(self::A, true);
            $this->fail('the failing write must surface');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('g2g_company_opportunities')->whereNotNull('date_status')->count(), 'the first two writes were rolled back with the third');
    }

    public function test_date_status_apply_is_blocked_without_its_column_but_a_dry_run_still_counts(): void
    {
        $this->signal(['event_date' => '2026-10-12']);
        $this->migration('2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities')->down();

        $dry = (new DateStatusBackfill)->run(self::A, false);
        $this->assertSame(1, $dry['fill']['event_date_stated']);
        $this->assertNotEmpty($dry['issues']);

        $this->expectException(BackfillBlocked::class);
        (new DateStatusBackfill)->run(self::A, true);
    }

    public function test_date_status_command_is_a_dry_run_by_default_and_apply_needs_one_organisation(): void
    {
        $ids = $this->seedDates();
        $before = $this->snapshot();

        $this->artisan('signals:backfill-date-status')
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('Organisation 1')->expectsOutputToContain('Organisation 2')
            ->expectsOutputToContain('Dry run: nothing was written')->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());

        $this->artisan('signals:backfill-date-status', ['--apply' => true])->assertExitCode(2);
        $this->artisan('signals:backfill-date-status', ['--tenant' => 'x', '--apply' => true])->assertExitCode(2);
        $this->artisan('signals:backfill-date-status', ['--tenant' => '0', '--apply' => true])->assertExitCode(2);
        $this->artisan('signals:backfill-date-status', ['--tenant' => self::A, '--database' => 'no_such_connection', '--apply' => true])->assertExitCode(2);
        $this->assertSame($before, $this->snapshot(), 'a refused command writes nothing');

        $this->artisan('signals:backfill-date-status', ['--tenant' => self::A, '--apply' => true, '--database' => 'sqlite'])
            ->expectsOutputToContain('APPLY')->expectsOutputToContain('stored assessment disagrees')->expectsOutputToContain("id {$ids['source']}")->assertExitCode(0);
        $this->assertSame('source_date_only', $this->dateStatusOf($ids['source']));
        $this->assertNull($this->dateStatusOf($ids['other']));
    }

    public function test_date_status_command_saves_the_printed_summary(): void
    {
        $this->seedDates();
        $path = $this->scratch . DIRECTORY_SEPARATOR . 'summary.txt';

        $this->artisan('signals:backfill-date-status', ['--tenant' => self::A, '--summary' => $path])->assertExitCode(0);

        $text = (string) file_get_contents($path);
        $this->assertStringContainsString('DRY RUN', $text);
        $this->assertStringContainsString('Organisation 1', $text);
        $this->assertStringContainsString('event_date_stated', $text);
    }

    public function test_date_status_command_fails_cleanly_when_the_table_has_no_column_to_fill(): void
    {
        $this->signal(['event_date' => '2026-10-12']);
        $this->migration('2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities')->down();

        $this->artisan('signals:backfill-date-status', ['--tenant' => self::A, '--apply' => true])
            ->expectsOutputToContain('run its migration first')->assertExitCode(1);
    }

    // ================= reviews =================

    /** @return array<string,int> */
    private function seedDecisions(): array
    {
        $ids = [];
        $ids['reviewed'] = $this->signal(['review_status' => 'Reviewed', 'reviewed_by' => 3, 'reviewed_at' => '2026-10-01 09:00:00']);
        $ids['dismissed'] = $this->signal(['review_status' => 'Dismissed', 'reviewed_by' => 4, 'reviewed_at' => '2026-10-02 10:00:00', 'review_notes' => 'not now']);
        $ids['followup'] = $this->signal(['review_status' => 'Follow-up', 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => '2026-10-05 12:00:00']);
        $ids['new'] = $this->signal(['review_status' => 'New', 'reviewed_at' => '2026-10-03 11:00:00']);
        $ids['nodate'] = $this->signal(['review_status' => 'Dismissed', 'reviewed_at' => null, 'updated_at' => null]);
        $ids['unknown'] = $this->signal(['review_status' => 'Contacted', 'reviewed_at' => '2026-10-04 11:00:00']);
        $ids['other'] = $this->signal(['sub_institute_id' => self::B, 'review_status' => 'Reviewed', 'reviewed_by' => 9, 'reviewed_at' => '2026-10-01 09:00:00']);

        return $ids;
    }

    private function history(): array
    {
        return DB::table('g2g_signal_reviews')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_reviews_dry_run_counts_and_writes_nothing(): void
    {
        $this->seedDecisions();

        $r = (new ReviewsBackfill)->run(self::A, false);

        $this->assertSame([], $this->history());
        $this->assertSame(5, $r['decided'], 'every signal that is not New: five');
        $this->assertSame(3, $r['to_create']);
        $this->assertSame(0, $r['already_there']);
        $this->assertSame(1, $r['used_updated_at']);
        $this->assertSame(['reviewed' => 1, 'dismissed' => 1, 'follow_up' => 1], $r['by_decision']);
        $this->assertSame(1, $r['unmapped']['count']);
        $this->assertSame(1, $r['no_date']['count']);
    }

    public function test_reviews_apply_writes_one_honest_row_per_decided_signal_for_that_organisation_only(): void
    {
        $ids = $this->seedDecisions();

        (new ReviewsBackfill)->run(self::A, true);

        $rows = collect($this->history())->keyBy('opportunity_id');
        $this->assertCount(3, $rows, 'New, unknown-status, no-date and other-organisation signals get nothing');

        $this->assertEquals([
            'sub_institute_id' => self::A, 'decision' => 'reviewed', 'reason_code' => null, 'note' => null, 'from_status' => 'New', 'to_status' => 'Reviewed',
            'via' => 'backfill', 'decided_by' => 3, 'decided_at' => '2026-10-01 09:00:00',
        ], collect($rows[$ids['reviewed']])->only(['sub_institute_id', 'decision', 'reason_code', 'note', 'from_status', 'to_status', 'via', 'decided_by', 'decided_at'])->all());
        $this->assertSame('dismissed', $rows[$ids['dismissed']]['decision']);
        $this->assertSame('Dismissed', $rows[$ids['dismissed']]['to_status']);
        $this->assertNull($rows[$ids['dismissed']]['note'], 'the old review note is not copied: it is not a reason');
        $this->assertSame('follow_up', $rows[$ids['followup']]['decision']);
        $this->assertSame('Follow-up', $rows[$ids['followup']]['to_status']);
        $this->assertNull($rows[$ids['followup']]['decided_by']);
        $this->assertSame('2026-10-05 12:00:00', $rows[$ids['followup']]['decided_at'], 'no reviewed_at: updated_at is used');
        $this->assertFalse($rows->has($ids['other']));
    }

    public function test_reviews_second_run_changes_nothing_even_after_a_fallback_signal_was_touched(): void
    {
        $ids = $this->seedDecisions();
        (new ReviewsBackfill)->run(self::A, true);
        $after = $this->history();

        $r = (new ReviewsBackfill)->run(self::A, true);
        $this->assertSame($after, $this->history());
        $this->assertSame(0, $r['to_create']);
        $this->assertSame(3, $r['already_there']);

        // updated_at moves whenever the signal is touched; the fallback date moves with it, but the row must not be made twice.
        DB::table('g2g_company_opportunities')->where('id', $ids['followup'])->update(['updated_at' => '2026-11-01 08:00:00']);
        (new ReviewsBackfill)->run(self::A, true);
        $this->assertSame($after, $this->history());
    }

    public function test_reviews_does_not_duplicate_a_row_another_writer_made_for_the_same_decision(): void
    {
        $ids = $this->seedDecisions();
        DB::table('g2g_signal_reviews')->insert([
            'sub_institute_id' => self::A, 'opportunity_id' => $ids['reviewed'], 'decision' => 'reviewed', 'reason_code' => null, 'note' => 'by hand',
            'from_status' => 'New', 'to_status' => 'Reviewed', 'via' => 'signal_review', 'decided_by' => 3, 'decided_at' => '2026-10-01 09:00:00', 'created_at' => now(),
        ]);

        $r = (new ReviewsBackfill)->run(self::A, true);

        $this->assertSame(1, $r['already_there']);
        $this->assertSame(1, DB::table('g2g_signal_reviews')->where('opportunity_id', $ids['reviewed'])->count());
    }

    public function test_reviews_failure_rolls_back_the_whole_organisation(): void
    {
        $ids = $this->seedDecisions();
        DB::unprepared("CREATE TRIGGER stop_here BEFORE INSERT ON g2g_signal_reviews WHEN NEW.opportunity_id = {$ids['followup']} BEGIN SELECT RAISE(ABORT, 'stop'); END");

        try {
            (new ReviewsBackfill)->run(self::A, true);
            $this->fail('the failing insert must surface');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $this->history(), 'the rows written before the failure were rolled back');
    }

    public function test_reviews_apply_is_blocked_without_the_history_table(): void
    {
        $this->seedDecisions();
        Schema::drop('g2g_signal_reviews');

        $dry = (new ReviewsBackfill)->run(self::A, false);
        $this->assertSame(3, $dry['to_create'], 'a dry run still counts');
        $this->assertNotEmpty($dry['issues']);

        $this->expectException(BackfillBlocked::class);
        (new ReviewsBackfill)->run(self::A, true);
    }

    public function test_reviews_command_dry_run_apply_and_summary(): void
    {
        $this->seedDecisions();
        $path = $this->scratch . DIRECTORY_SEPARATOR . 'reviews.txt';

        $this->artisan('signals:backfill-reviews')->expectsOutputToContain('DRY RUN')->expectsOutputToContain('Organisation 2')->assertExitCode(0);
        $this->assertSame([], $this->history());
        $this->artisan('signals:backfill-reviews', ['--apply' => true])->assertExitCode(2);

        $this->artisan('signals:backfill-reviews', ['--tenant' => self::A, '--apply' => true, '--summary' => $path])
            ->expectsOutputToContain('decided_at taken from the signal')->expectsOutputToContain('have no reviewed_at')->assertExitCode(0);
        $this->assertCount(3, $this->history());
        $this->assertStringContainsString('history rows created', (string) file_get_contents($path));

        $this->artisan('signals:backfill-reviews', ['--tenant' => self::A, '--apply' => true])->expectsOutputToContain('Done')->assertExitCode(0);
        $this->assertCount(3, $this->history());
    }

    // ================= product =================

    private function seedProducts(): void
    {
        $offer = fn (?int $t, string $id, string $name, string $parent) => DB::table('g2g_product_offers')->insert(['sub_institute_id' => $t, 'offer_id' => $id, 'name' => $name, 'parent_product' => $parent, 'readiness_status' => 'Prototype']);
        $offer(self::A, 'P1', 'Scholar One', 'Scholar K-12');
        $offer(self::A, 'P2', 'Scholar Core', 'Scholar K-12');
        $offer(self::B, 'P1', 'Other organisation Scholar One', 'Scholar K-12');
        (new ProductMasterBackfill)->run(self::A, true);
        (new ProductMasterBackfill)->run(self::B, true);
    }

    private function pm(int $tenant, string $key): int
    {
        return (int) ProductMaster::where('sub_institute_id', $tenant)->where('product_key', $key)->value('id');
    }

    private function product(int $id): ?int
    {
        $v = DB::table('g2g_company_opportunities')->where('id', $id)->value('product_master_id');

        return $v === null ? null : (int) $v;
    }

    /** @return array<string,int> */
    private function seedProductSignals(): array
    {
        $ids = [];
        $ids['offer'] = $this->signal(['relevant_offer_id' => 'P1']);
        $ids['key'] = $this->signal(['product_key' => 'scholarclone']);
        $ids['fit'] = $this->signal(['business_fit' => 'eb']);
        $ids['agree'] = $this->signal(['relevant_offer_id' => 'P1', 'business_fit' => 'scholar']);
        $ids['conflict'] = $this->signal(['relevant_offer_id' => 'P1', 'business_fit' => 'eb']);
        $ids['badkey'] = $this->signal(['product_key' => 'no_such_product', 'business_fit' => 'g2g']);
        $ids['empty'] = $this->signal([]);
        $ids['multiple'] = $this->signal(['business_fit' => 'multiple']);
        $ids['title'] = $this->signal(['title' => 'ScholarClone for a school in Pune', 'observed_event' => 'wants ScholarClone']);
        $ids['set'] = $this->signal(['business_fit' => 'g2g', 'product_master_id' => 999]);
        $ids['foreign_offer'] = $this->signal(['relevant_offer_id' => 'P2', 'sub_institute_id' => self::B]);
        $ids['other'] = $this->signal(['sub_institute_id' => self::B, 'relevant_offer_id' => 'P1']);

        return $ids;
    }

    public function test_product_dry_run_counts_each_path_and_writes_nothing(): void
    {
        $this->seedProducts();
        $this->seedProductSignals();
        $before = $this->snapshot();

        $r = (new ProductBackfill)->run(self::A, false);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $r['already_set']);
        $this->assertSame(9, $r['without_product']);
        $this->assertSame(['relevant_offer_id' => 2, 'product_key' => 1, 'business_fit' => 3], $r['resolved'], 'offer: offer, agree. key: key. fit: fit, conflict (the product wins), badkey');
        $this->assertSame(['no_values' => 2, 'no_match' => 1], $r['unresolved'], 'empty and the title-only signal have no values; "multiple" has a value nothing matches');
        $this->assertSame(1, $r['offer_unused'], 'the conflicting offer id was set aside');
        $this->assertSame(0, $r['written']);
    }

    public function test_product_apply_resolves_by_offer_then_key_then_fit_and_never_by_name(): void
    {
        $this->seedProducts();
        $ids = $this->seedProductSignals();

        $r = (new ProductBackfill)->run(self::A, true);

        $this->assertSame(6, $r['written']);
        $this->assertSame($this->pm(self::A, 'scholarclone/p1'), $this->product($ids['offer']), 'the offer gives the sub-product');
        $this->assertSame($this->pm(self::A, 'scholarclone/p1'), $this->product($ids['agree']), 'an offer of the same product wins over the product value');
        $this->assertSame($this->pm(self::SHARED, 'scholarclone'), $this->product($ids['key']));
        $this->assertSame($this->pm(self::SHARED, 'enterprise_brain'), $this->product($ids['fit']));
        $this->assertSame($this->pm(self::SHARED, 'enterprise_brain'), $this->product($ids['conflict']), 'an offer of a different product loses to the product value');
        $this->assertSame($this->pm(self::SHARED, 'g2g'), $this->product($ids['badkey']), 'an unknown product key falls through to business_fit');
        $this->assertNull($this->product($ids['empty']));
        $this->assertNull($this->product($ids['multiple']), '"multiple" reaches no product');
        $this->assertNull($this->product($ids['title']), 'a product named in the text is never matched');
        $this->assertSame(999, $this->product($ids['set']), 'a value already there is never overwritten');
        $this->assertNull($this->product($ids['other']), "another organisation's signal is not touched");
        $this->assertNull($this->product($ids['foreign_offer']));
    }

    public function test_product_resolves_inside_the_signals_own_organisation_only(): void
    {
        $this->seedProducts();
        $ids = $this->seedProductSignals();

        $r = (new ProductBackfill)->run(self::B, true);

        $this->assertSame($this->pm(self::B, 'scholarclone/p1'), $this->product($ids['other']), "organisation 2's offer P1 is its own sub-product, not organisation 1's");
        $this->assertNotSame($this->pm(self::A, 'scholarclone/p1'), $this->product($ids['other']));
        $this->assertNull($this->product($ids['foreign_offer']), "organisation 1's offer P2 does not exist for organisation 2");
        $this->assertSame(1, $r['unresolved']['no_match']);
        $this->assertNull($this->product($ids['offer']), "organisation 1's signal is not touched by organisation 2's run");
    }

    public function test_product_second_run_changes_nothing(): void
    {
        $this->seedProducts();
        $this->seedProductSignals();
        (new ProductBackfill)->run(self::A, true);
        $after = $this->snapshot();

        $r = (new ProductBackfill)->run(self::A, true);

        $this->assertSame($after, $this->snapshot());
        $this->assertSame(0, $r['written']);
        $this->assertSame(array_fill_keys(ProductBackfill::PATHS, 0), $r['resolved'], 'the only rows left are the ones nothing could resolve');
        $this->assertSame(3, $r['without_product']);
    }

    public function test_product_skips_a_source_column_a_host_does_not_have(): void
    {
        $this->seedProducts();
        Schema::drop('g2g_company_opportunities');
        Schema::create('g2g_company_opportunities', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('business_fit', 20)->nullable();
            $t->unsignedBigInteger('product_master_id')->nullable();
        });
        DB::table('g2g_company_opportunities')->insert([['sub_institute_id' => self::A, 'business_fit' => 'eb'], ['sub_institute_id' => self::A, 'business_fit' => null]]);

        $r = (new ProductBackfill)->run(self::A, true);

        $this->assertSame(1, $r['resolved']['business_fit']);
        $this->assertSame(1, $r['unresolved']['no_values']);
        $this->assertCount(2, $r['issues'], 'product_key and relevant_offer_id are reported as skipped');
    }

    public function test_product_apply_is_blocked_without_the_column_or_the_product_master(): void
    {
        $this->seedProducts();
        $this->signal(['business_fit' => 'eb']);

        Schema::table('g2g_company_opportunities', function ($t) {
            $t->dropIndex('g2g_opp_tenant_pm');
            $t->dropColumn('product_master_id');
        });
        $dry = (new ProductBackfill)->run(self::A, false);
        $this->assertSame(1, $dry['resolved']['business_fit'], 'a dry run still counts');
        try {
            (new ProductBackfill)->run(self::A, true);
            $this->fail('apply needs the column');
        } catch (BackfillBlocked $e) {
            $this->assertStringContainsString('product_master_id', $e->getMessage());
        }

        Schema::drop('g2g_product_master');
        $this->expectException(BackfillBlocked::class);
        (new ProductBackfill)->run(self::A, false);
    }

    public function test_product_command_dry_run_apply_and_summary(): void
    {
        $this->seedProducts();
        $ids = $this->seedProductSignals();
        $before = $this->snapshot();
        $path = $this->scratch . DIRECTORY_SEPARATOR . 'product.txt';

        $this->artisan('signals:backfill-product-master')->expectsOutputToContain('DRY RUN')->expectsOutputToContain('relevant_offer_id')
            ->expectsOutputToContain('no Product Master row matches')->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
        $this->artisan('signals:backfill-product-master', ['--apply' => true])->assertExitCode(2);

        $this->artisan('signals:backfill-product-master', ['--tenant' => self::A, '--apply' => true, '--summary' => $path])
            ->expectsOutputToContain('offer id that was not used')->assertExitCode(0);
        $this->assertSame($this->pm(self::A, 'scholarclone/p1'), $this->product($ids['offer']));
        $this->assertStringContainsString('product set, by the value that decided it', (string) file_get_contents($path));
    }

    public function test_the_commands_are_registered(): void
    {
        $all = array_keys(\Illuminate\Support\Facades\Artisan::all());

        foreach (['signals:backfill-date-status', 'signals:backfill-reviews', 'signals:backfill-product-master', 'signals:grant-review'] as $name) {
            $this->assertContains($name, $all);
        }
    }

    /**
     * Artisan keeps one command per name, so two classes with the same signature silently shadow each other: the first version of the product
     * backfill took `signals:backfill-product` from the older command that fills a run's product, and nothing failed until a test of the older one ran.
     * Artisan::all() cannot show that (it is keyed by name), so the signatures are read from the source.
     */
    public function test_no_two_commands_share_a_name(): void
    {
        $owners = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/\$signature\s*=\s*([\'"])\s*([A-Za-z0-9:_-]+)/', (string) file_get_contents($file->getPathname()), $m)) {
                $owners[$m[2]][] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }

        $this->assertNotEmpty($owners, 'the scan must find the commands it is meant to guard');
        $this->assertArrayHasKey('signals:backfill-product', $owners, 'the older run-product backfill must still own its name');
        $clashes = array_filter($owners, fn (array $files) => count($files) > 1);
        $this->assertSame([], $clashes, 'two commands share a name: ' . json_encode($clashes));
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }
}
