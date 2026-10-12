<?php

namespace Tests\Feature;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\OpportunityMatch;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Offer matching for imported signals (code boost, segment bonus, readiness reported but never
 * scored), the evidence-based feed section, and the audited admin-only readiness confirmation.
 */
class MarketMatchingTest extends TestCase
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
        '2026_10_08_100000_extend_g2g_signals_for_market_import',
        '2026_10_08_110000_protect_first_discovered_at_on_opportunities',
        '2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers',
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
            ['id' => 3, 'name' => 'HR Manager', 'role_key' => 'hr_manager'],
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

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken, '_uid' => $id];
    }

    private function auth(array $h): array
    {
        return ['Authorization' => $h['Authorization']];
    }

    private function offer(string $id, array $needs, array $segments = ['GOV-STATE'], bool $confirmed = false, string $status = 'Built – no production client', int $tenant = self::T1): ProductOffer
    {
        return ProductOffer::create([
            'sub_institute_id' => $tenant, 'offer_id' => $id, 'name' => "Offer {$id}", 'parent_product' => 'G2G',
            'needs_solved' => $needs, 'primary_segments' => $segments, 'trigger_signals' => [],
            'readiness_status' => $status, 'readiness_confirmed' => $confirmed,
        ]);
    }

    private function rec(array $over = []): array
    {
        return array_replace_recursive([
            'buyer' => ['name' => 'Example State Department', 'type' => 'government', 'state' => 'Exampleland'],
            'trigger_type' => 'tender', 'trigger_summary' => 'Procurement of a staff platform',
            'source_url' => 'https://example.invalid/t/1', 'reference_no' => 'R/1', 'event_date' => '2026-10-01', 'expires_at' => '2026-10-20',
            'claim_level' => 'confirmed', 'fetch_level' => 'full_document',
            'candidate_need_codes' => ['N01', 'N02'], 'buyer_segment' => 'GOV-STATE',
        ], $over);
    }

    /** @return array<string, array<string, mixed>> offer_id => match result */
    private function match(CompanyOpportunity $o): array
    {
        $out = [];
        foreach (app(MatchingEngine::class)->matchForSignal($o, 'opportunity', self::T1)['offer_matches'] as $m) {
            $out[$m['offer']->offer_id] = $m;
        }

        return $out;
    }

    private function import(array $records): CompanyOpportunity
    {
        app(MarketImporter::class)->import(self::T1, $records);

        return CompanyOpportunity::orderByDesc('id')->first();
    }

    // ── scoring ──────────────────────────────────────────────────────────────

    public function test_each_matching_need_code_adds_25_and_the_segment_bonus_is_added_once(): void
    {
        $this->offer('A-1', ['N01', 'N02'], ['GOV-STATE', 'GOV-STATE']);
        $this->offer('A-2', ['N01'], ['SME']);
        $this->offer('A-3', ['N09'], ['SME']);

        $m = $this->match($this->import([$this->rec()]));

        $this->assertSame(65, $m['A-1']['match_score'], '2 codes x 25 + 15 segment, once');
        $this->assertSame(['N01', 'N02'], $m['A-1']['matched_need_codes']);
        $this->assertSame(25, $m['A-2']['match_score'], 'one code, segment does not match');
        $this->assertArrayNotHasKey('A-3', $m, 'nothing in common: not a match');
    }

    public function test_the_score_is_capped_at_100(): void
    {
        $this->offer('A-1', ['N01', 'N02', 'N03', 'N04'], ['GOV-STATE']);

        $m = $this->match($this->import([$this->rec(['candidate_need_codes' => ['N01', 'N02', 'N03', 'N04']])]));

        $this->assertSame(100, $m['A-1']['match_score'], '4 x 25 + 15 = 115, capped');
    }

    public function test_a_record_without_codes_or_segment_scores_exactly_as_before(): void
    {
        $this->offer('A-1', ['N01'], ['GOV-STATE']);
        $r = $this->rec();
        unset($r['candidate_need_codes'], $r['buyer_segment']);

        $this->assertSame([], $this->match($this->import([$r])), 'no keyword hit and no codes: no match');
    }

    public function test_readiness_never_changes_the_score_only_the_deliverable_flag(): void
    {
        $this->offer('UNVERIFIED', ['N01', 'N02'], ['GOV-STATE'], false, 'In development');
        $this->offer('CONFIRMED', ['N01', 'N02'], ['GOV-STATE'], true, 'Live – with client');

        $m = $this->match($this->import([$this->rec()]));

        $this->assertSame($m['CONFIRMED']['match_score'], $m['UNVERIFIED']['match_score'], 'identical offers must score identically');
        $this->assertTrue($m['CONFIRMED']['is_deliverable']);
        $this->assertFalse($m['UNVERIFIED']['is_deliverable']);
        $this->assertSame('In development', $m['UNVERIFIED']['readiness_status']);
    }

    // ── stored snapshots ─────────────────────────────────────────────────────

    public function test_matches_are_stored_with_a_readiness_snapshot_and_reviews_survive_a_re_import(): void
    {
        $this->offer('UNVERIFIED', ['N01', 'N02'], ['GOV-STATE'], false, 'In development');
        $o = $this->import([$this->rec()]);

        $row = OpportunityMatch::sole();
        $this->assertSame([$o->id, 'UNVERIFIED', 'Matched'], [(int) $row->signal_id, $row->offer_id, $row->match_status]);
        $this->assertSame(65, (int) $row->match_score);
        $this->assertSame('In development', $row->readiness_at_match);
        $this->assertFalse($row->is_deliverable);
        $this->assertSame(['N01', 'N02'], $row->matched_need_codes);

        // A person reviews it, then the same signal advances and is re-imported.
        $row->update(['match_status' => 'Follow-up', 'review_notes' => 'Call them']);
        $this->import([$this->rec(['expires_at' => '2026-11-30'])]);

        $row = OpportunityMatch::sole();
        $this->assertSame(['Follow-up', 'Call them'], [$row->match_status, $row->review_notes], 'a human review is never overwritten');
    }

    public function test_weak_matches_below_the_store_threshold_are_not_stored(): void
    {
        $this->offer('WEAK', ['N09'], ['GOV-STATE']); // only the 15-point segment bonus
        $this->import([$this->rec()]);

        $this->assertSame(0, OpportunityMatch::count());
    }

    // ── feed section from evidence ───────────────────────────────────────────

    /** @dataProvider sections */
    #[\PHPUnit\Framework\Attributes\DataProvider('sections')]
    public function test_feed_section_comes_from_the_evidence(array $over, string $expected): void
    {
        $o = $this->import([$this->rec($over)]);

        $this->assertSame($expected, $o->feed_section);
    }

    public static function sections(): array
    {
        return [
            'confirmed + full document' => [['claim_level' => 'confirmed', 'fetch_level' => 'full_document'], 'immediate_action'],
            'confirmed + page text' => [['claim_level' => 'confirmed', 'fetch_level' => 'page_text'], 'immediate_action'],
            'confirmed but only a snippet (downgraded)' => [['claim_level' => 'confirmed', 'fetch_level' => 'search_snippet'], 'watchlist'],
            'confirmed but blocked (downgraded)' => [['claim_level' => 'confirmed', 'fetch_level' => 'blocked'], 'watchlist'],
            'inference' => [['claim_level' => 'inference', 'fetch_level' => 'full_document'], 'watchlist'],
            'hypothesis' => [['claim_level' => 'hypothesis', 'fetch_level' => 'full_document'], 'watchlist'],
            'confirmed, no expiry' => [['expires_at' => null], 'immediate_action'],
            'confirmed but already expired' => [['expires_at' => '2026-10-01'], 'watchlist'],
        ];
    }

    public function test_a_dedup_update_never_changes_review_status(): void
    {
        $o = $this->import([$this->rec()]);
        $o->update(['review_status' => 'Dismissed', 'pipeline_status' => 'LOST']);

        $this->import([$this->rec(['expires_at' => '2026-12-01'])]);

        $o->refresh();
        $this->assertSame(['Dismissed', 'LOST'], [$o->review_status, $o->pipeline_status]);
    }

    // ── readiness confirmation: admin only, audited, never automatic ────────

    public function test_an_administrator_confirms_readiness_and_it_is_audited(): void
    {
        $offer = $this->offer('A-1', ['N01']);
        $admin = $this->h(self::T1, 1);

        $this->postJson("/api/portfolio/offers/{$offer->id}/readiness", ['confirmed' => true, 'note' => 'Verified in production at client X'], $this->auth($admin))
            ->assertOk()->assertJsonPath('data.readiness_confirmed', true);

        $row = DB::table('g2g_product_offers')->where('id', $offer->id)->first();
        $this->assertSame([1, $admin['_uid']], [(int) $row->readiness_confirmed, (int) $row->readiness_confirmed_by]);
        $this->assertNotNull($row->readiness_confirmed_at);

        $log = DB::table('g2g_offer_readiness_log')->sole();
        $this->assertSame(['confirmed', $admin['_uid'], 'A-1', 'Verified in production at client X'], [$log->action, (int) $log->actor_id, $log->offer_id, $log->note]);

        $this->postJson("/api/portfolio/offers/{$offer->id}/readiness", ['confirmed' => false], $this->auth($admin))->assertOk();
        $row = DB::table('g2g_product_offers')->where('id', $offer->id)->first();
        $this->assertSame([0, null, null], [(int) $row->readiness_confirmed, $row->readiness_confirmed_by, $row->readiness_confirmed_at]);
        $this->assertSame(['unconfirmed', 'confirmed'], DB::table('g2g_offer_readiness_log')->orderByDesc('id')->pluck('action')->all());

        $this->getJson("/api/portfolio/offers/{$offer->id}/readiness-log", $this->auth($admin))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_administrators_can_confirm_not_hr_or_employees(): void
    {
        $offer = $this->offer('A-1', ['N01']);
        $body = ['confirmed' => true, 'note' => 'Verified in production'];

        $this->postJson("/api/portfolio/offers/{$offer->id}/readiness", $body, $this->auth($this->h(self::T1, 3)))->assertStatus(403);
        $this->postJson("/api/portfolio/offers/{$offer->id}/readiness", $body, $this->auth($this->h(self::T1, 2)))->assertStatus(403);
        $this->postJson("/api/portfolio/offers/{$offer->id}/readiness", $body)->assertStatus(401);

        $this->assertFalse((bool) DB::table('g2g_product_offers')->value('readiness_confirmed'));
        $this->assertSame(0, DB::table('g2g_offer_readiness_log')->count());
    }

    public function test_confirming_needs_a_note_and_cannot_cross_tenants(): void
    {
        $mine = $this->offer('A-1', ['N01']);
        $theirs = $this->offer('B-1', ['N01'], ['SME'], false, 'Built', self::T2);
        $shared = ProductOffer::create(['sub_institute_id' => null, 'offer_id' => 'S-1', 'name' => 'Shared', 'parent_product' => 'G2G', 'readiness_status' => 'Built', 'needs_solved' => [], 'primary_segments' => []]);
        $admin = $this->auth($this->h(self::T1, 1));

        $this->postJson("/api/portfolio/offers/{$mine->id}/readiness", ['confirmed' => true], $admin)->assertStatus(422);
        $this->postJson("/api/portfolio/offers/{$theirs->id}/readiness", ['confirmed' => true, 'note' => 'sneaky'], $admin)->assertStatus(404);
        $this->postJson("/api/portfolio/offers/{$shared->id}/readiness", ['confirmed' => true, 'note' => 'shared row'], $admin)->assertStatus(404);
    }

    public function test_no_other_path_can_confirm_readiness(): void
    {
        $admin = $this->auth($this->h(self::T1, 1));

        // Create: always starts unconfirmed, whatever is sent.
        $this->postJson('/api/portfolio/offers', [
            'offer_id' => 'N-1', 'name' => 'New', 'parent_product' => 'G2G', 'readiness_status' => 'Live – with client', 'readiness_confirmed' => true,
        ], $admin)->assertStatus(201);
        $this->assertFalse((bool) DB::table('g2g_product_offers')->where('offer_id', 'N-1')->value('readiness_confirmed'));

        // Update: an attempt to change the flag is refused rather than silently ignored.
        $id = DB::table('g2g_product_offers')->where('offer_id', 'N-1')->value('id');
        $this->putJson("/api/portfolio/offers/{$id}", ['readiness_confirmed' => true], $admin)->assertStatus(422);
        $this->assertFalse((bool) DB::table('g2g_product_offers')->where('id', $id)->value('readiness_confirmed'));

        // Update that leaves the flag alone still works.
        $this->putJson("/api/portfolio/offers/{$id}", ['name' => 'Renamed', 'readiness_confirmed' => false], $admin)->assertOk();
        $this->assertSame('Renamed', DB::table('g2g_product_offers')->where('id', $id)->value('name'));
    }

    public function test_a_spreadsheet_yes_never_confirms_an_offer(): void
    {
        // The workbook says "Yes" for a brand-new offer and for one that already exists.
        $this->offer('EXISTING', ['N01']);
        $rows = [
            ['offer_id' => 'X-1', 'name' => 'From sheet', 'parent_product' => 'G2G', 'readiness_status' => 'Live – with client', 'readiness_confirmed' => true],
            ['offer_id' => 'EXISTING', 'name' => 'Offer EXISTING', 'parent_product' => 'G2G', 'readiness_status' => 'Live – with client', 'readiness_confirmed' => true],
        ];
        $svc = new class($rows) extends \App\Domain\Portfolio\PortfolioImportService {
            public function __construct(private array $rows)
            {
            }

            protected function loadOffersData(?string $customPath = null): array
            {
                return $this->rows;
            }
        };

        $result = $svc->import(null, self::T1);

        $this->assertSame([1, 1], [$result['imported'], $result['updated']]);
        $this->assertSame([0, 0], DB::table('g2g_product_offers')->whereIn('offer_id', ['X-1', 'EXISTING'])->pluck('readiness_confirmed')->map(fn ($v) => (int) $v)->all());
    }
    // ── the feed API (B5) ────────────────────────────────────────────────────

    private function feed(array $query = [], int $tenant = self::T1): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/signals/opportunities?' . http_build_query($query), $this->auth($this->h($tenant, 1)));
    }

    /** @return list<string> titles in the order returned */
    private function titles(array $query = [], int $tenant = self::T1): array
    {
        return collect($this->feed($query, $tenant)->assertOk()->json('data'))->pluck('title')->all();
    }

    private function seedFeed(): void
    {
        $imp = fn (string $ref, array $over) => $this->import([$this->rec(['reference_no' => $ref, 'source_url' => "https://example.invalid/{$ref}", 'trigger_summary' => "T-{$ref}"] + $over)]);

        $imp('late', ['expires_at' => '2026-11-30']);
        $imp('soon', ['expires_at' => '2026-10-08']);
        $imp('none', ['expires_at' => null]);
        $imp('old', ['expires_at' => '2026-10-01']);                 // expired
        $imp('sample', ['is_sample' => true, 'expires_at' => '2026-10-10']);
        $imp('hyp', ['claim_level' => 'hypothesis', 'expires_at' => '2026-10-20', 'business_fit' => 'eb', 'candidate_need_codes' => ['N09'], 'is_government_track' => true]);
    }

    public function test_the_feed_hides_expired_and_sample_rows_by_default(): void
    {
        $this->seedFeed();

        $this->assertSame(['T-soon', 'T-hyp', 'T-late', 'T-none'], $this->titles(), 'soonest expiry first, rows with no expiry last');

        $summary = $this->feed()->json('summary');
        $this->assertSame([1, 1], [$summary['expired'], $summary['samples']], 'hidden rows are counted so the filters can say how many');
    }

    public function test_the_expired_filter_and_the_samples_toggle_reveal_them(): void
    {
        $this->seedFeed();

        $this->assertSame(['T-old'], $this->titles(['expired' => 'only']));
        $this->assertContains('T-old', $this->titles(['expired' => 'include']));
        $this->assertContains('T-sample', $this->titles(['include_samples' => 1]));
        $this->assertNotContains('T-old', $this->titles(['include_samples' => 1]), 'including samples does not include expired rows');
    }

    public function test_evidence_expiry_scores_and_offer_badges_are_exposed(): void
    {
        $this->offer('VERIFIED', ['N01', 'N02'], ['GOV-STATE'], true, 'Live – with client');
        $this->offer('UNVERIFIED', ['N01', 'N02'], ['GOV-STATE'], false, 'In development');
        $this->import([$this->rec(['scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 4, 'urgency' => 4, 'potential_value' => 4, 'evidence_quality' => 4]])]);

        $row = $this->feed()->assertOk()->json('data.0');

        $this->assertSame(['confirmed', 'full_document', 15, false, 'NEW'], [$row['claim_level'], $row['fetch_level'], $row['days_left'], $row['is_expired'], $row['pipeline_status']]);
        $this->assertSame(28, $row['scores']['total']);
        $this->assertSame(['N01', 'N02'], $row['candidate_need_codes']);
        $this->assertSame('government', $row['buyer_type']);

        $offers = collect($row['matched_offers'])->keyBy('offer_id');
        $this->assertTrue($offers['VERIFIED']['is_deliverable']);
        $this->assertFalse($offers['UNVERIFIED']['is_deliverable'], 'shown, clearly not yet verified');
        $this->assertSame('In development', $offers['UNVERIFIED']['readiness_at_match']);
        $this->assertSame($offers['VERIFIED']['match_score'], $offers['UNVERIFIED']['match_score'], 'readiness does not rank offers');
    }

    public function test_the_feed_filters_by_evidence_fit_need_code_and_government_track(): void
    {
        $this->seedFeed();

        $this->assertSame(['T-hyp'], $this->titles(['claim_level' => 'hypothesis']));
        $this->assertSame(['T-hyp'], $this->titles(['business_fit' => 'eb']));
        $this->assertSame(['T-hyp'], $this->titles(['need_code' => 'N09']));
        $this->assertSame(['T-hyp'], $this->titles(['government_track' => 1]));
        $this->assertCount(3, $this->titles(['government_track' => 0]) ?: [], 'the non-government rows');
        $this->feed(['need_code' => 'N99'])->assertStatus(422);
        $this->feed(['claim_level' => 'certain'])->assertStatus(422);
    }

    public function test_the_feed_is_tenant_scoped_and_still_lists_researched_rows(): void
    {
        $this->seedFeed();
        $company = \App\Domain\Signals\Opportunities\Company::create(['sub_institute_id' => self::T1, 'name' => 'Legacy Co', 'normalized_name' => 'legacy co']);
        DB::table('g2g_company_opportunities')->insert([
            'sub_institute_id' => self::T1, 'company_id' => $company->id, 'research_run_id' => 5, 'title' => 'Researched row', 'category' => 'expansion',
            'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a', 'priority' => 'High',
            'qualification' => 'Monitoring', 'confidence' => 'Low', 'sources' => '[]', 'fingerprint' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $titles = $this->titles();
        $this->assertSame(['T-soon', 'T-hyp', 'T-late', 'Researched row', 'T-none'], $titles, 'a researched row keeps working and sorts with the no-expiry rows by its own priority');
        $this->assertSame([], $this->titles([], self::T2), 'another organisation sees none of it');
    }

    public function test_a_review_click_changes_review_status_only(): void
    {
        $o = $this->import([$this->rec()]);
        $admin = $this->auth($this->h(self::T1, 1));

        $this->patchJson("/api/signals/opportunities/{$o->id}/review", [], $admin)->assertOk();

        $o->refresh();
        $this->assertSame(['Reviewed', 'NEW'], [$o->review_status, $o->pipeline_status]);
    }
}
