<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Review\SignalAudit;
use App\Models\auth\tbluserModel;
use App\Services\Events\AuditLogProjector;
use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The decision side of the Signals API (slice 2, task 5): the strict POST /opportunities/{id}/decisions, the lenient PATCH /opportunities/{id}/{action}
 * the original screen still uses, GET /opportunities/{id}/reviews, and the history row + event that promote-to-crm now leaves.
 *
 * The rule under test above all: a decision is ONE transaction (history row, the signal's own columns, the event). A refusal and a failure part-way
 * must leave nothing behind. Runs on the in-memory sqlite database; nothing is sent anywhere.
 */
class SignalReviewApiTest extends TestCase
{
    private const T1 = 1;

    private const T2 = 2;

    private const PREFIX = '/api/signals/opportunities/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        foreach (['SIGNALS_USE_MENU_RIGHTS'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        parent::tearDown();
    }

    private function buildSchema(): void
    {
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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        // The lead the promote tests create: only the columns LeadFactory writes and the feed reads.
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('lead_no', 30)->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('designation')->nullable();
            $t->string('website')->nullable();
            $t->string('industry')->nullable();
            $t->string('lead_source')->nullable();
            $t->string('lead_status')->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('source_company_opportunity_id')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->integer('lead_score')->nullable();
            $t->text('lead_score_basis')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();   // the note writer (slice 6) only writes on a lead that is not removed
        });
        // The one note promoting a signal writes on the lead (slice 6): where the signal says it came from.
        Schema::create('crm_lead_comments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->text('comment');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_lead_scoring_rules', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('field_key');
            $t->string('operator');
            $t->string('value')->nullable();
            $t->integer('points')->default(0);
        });
        // LeadFactory numbers a lead with MariaDB's SUBSTRING_INDEX; sqlite has no such function, so the test connection is given one.
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($text, $delimiter, $count) {
            $parts = explode((string) $delimiter, (string) $text);

            return implode((string) $delimiter, (int) $count < 0 ? array_slice($parts, (int) $count) : array_slice($parts, 0, (int) $count));
        }, 3);

        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_08_090000_create_g2g_signal_reviews_table', '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
            '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs',
            '2026_08_10_140000_create_g2g_event_store', '2026_08_10_150000_create_g2g_audit_log',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 3, 'name' => 'Reporting Manager', 'role_key' => 'reporting_manager'],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{id: int, headers: array<string, string>} */
    private function actor(int $tenant = self::T1, int $profile = 1, string $first = 'Asha', string $last = 'Rao'): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => $first, 'last_name' => $last, 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['id' => $id, 'headers' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    private function signal(int $tenant = self::T1, array $over = []): CompanyOpportunity
    {
        $run = ResearchRun::firstOrCreate(['sub_institute_id' => $tenant, 'report_date' => '2026-10-05', 'trigger' => 'scheduled', 'status' => 'success']);
        $company = Company::create(['sub_institute_id' => $tenant, 'name' => 'Co ' . uniqid(), 'normalized_name' => uniqid()]);

        return CompanyOpportunity::create($over + [
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'research_run_id' => $run->id, 'title' => 'T ' . uniqid(),
            'category' => 'hiring', 'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a',
            'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium', 'review_status' => 'New',
            'sources' => [['url' => 'https://x.example.com', 'title' => 'x']], 'fingerprint' => uniqid(), 'first_discovered_at' => now(), 'last_verified_at' => now(),
        ]);
    }

    private function decide(int $id, array $body, array $actor)
    {
        return $this->postJson(self::PREFIX . "{$id}/decisions", $body, $actor['headers']);
    }

    private function legacy(int $id, string $action, array $body, array $actor)
    {
        return $this->patchJson(self::PREFIX . "{$id}/{$action}", $body, $actor['headers']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function history(?int $opportunityId = null)
    {
        return DB::table('g2g_signal_reviews')->when($opportunityId, fn ($q) => $q->where('opportunity_id', $opportunityId))->orderBy('id')->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function events(string $like = 'signals.%')
    {
        return DB::table('g2g_event')->where('type', 'like', $like)->orderBy('id')->get();
    }

    /** @return array<string, mixed> everything the decision is supposed to touch, for "nothing was written" checks */
    private function footprint(CompanyOpportunity $signal): array
    {
        $row = (array) DB::table('g2g_company_opportunities')->where('id', $signal->id)->first();

        return [
            'history' => $this->history()->count(), 'events' => DB::table('g2g_event')->count(),
            'signal' => array_intersect_key($row, array_flip(['review_status', 'review_notes', 'reviewed_by', 'reviewed_at', 'updated_at'])),
        ];
    }

    // ── one decision = one history row + the signal's columns + one event ─────

    public function test_a_dismissal_is_one_history_row_the_signal_columns_and_one_event_together(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $res = $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'stale', 'note' => '  Seen in 2023.  ', 'expected_status' => 'New'], $actor)->assertOk();

        $res->assertJsonPath('status', 1)->assertJsonPath('data.id', $signal->id)->assertJsonPath('data.review_status', 'Dismissed')
            ->assertJsonPath('data.review_notes', 'Seen in 2023.')
            ->assertJsonPath('review.decision', 'dismissed')->assertJsonPath('review.reason_code', 'stale')->assertJsonPath('review.reason_label', 'Too old to act on')
            ->assertJsonPath('review.note', 'Seen in 2023.')->assertJsonPath('review.from_status', 'New')->assertJsonPath('review.to_status', 'Dismissed')
            ->assertJsonPath('review.via', 'signal_review')->assertJsonPath('review.decided_by', $actor['id'])->assertJsonPath('review.decided_by_name', 'Asha Rao');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $res->json('review.decided_at'));

        $rows = $this->history();
        $this->assertCount(1, $rows);
        $this->assertSame([self::T1, $signal->id, 'dismissed', 'stale', 'Seen in 2023.', 'New', 'Dismissed', 'signal_review', $actor['id']], [
            (int) $rows[0]->sub_institute_id, (int) $rows[0]->opportunity_id, $rows[0]->decision, $rows[0]->reason_code, $rows[0]->note,
            $rows[0]->from_status, $rows[0]->to_status, $rows[0]->via, (int) $rows[0]->decided_by,
        ]);
        $this->assertSame($rows[0]->id, $res->json('review.id'));

        $fresh = $signal->fresh();
        $this->assertSame('Dismissed', $fresh->review_status);
        $this->assertSame($actor['id'], (int) $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame('Seen in 2023.', $fresh->review_notes);

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('signals.opportunity.dismissed', $events[0]->type);
        $this->assertSame([self::T1, 'g2g_company_opportunities', $signal->id, $actor['id']], [(int) $events[0]->sub_institute_id, $events[0]->entity_type, (int) $events[0]->entity_id, (int) $events[0]->actor_id]);
        $this->assertSame("signals.opportunity.dismissed:" . self::T1 . ":{$signal->id}:{$rows[0]->id}", $events[0]->idempotency_key);
        $this->assertEquals([
            'opportunity_id' => $signal->id, 'review_id' => $rows[0]->id, 'decision' => 'dismissed', 'reason_code' => 'stale', 'from_status' => 'New', 'to_status' => 'Dismissed',
        ], json_decode($events[0]->payload, true));
    }

    #[DataProvider('decisions')]
    public function test_each_decision_stores_its_status_and_records_its_own_event_type(string $decision, string $startsAs, string $status, string $eventType, array $extra): void
    {
        $actor = $this->actor();
        $signal = $this->signal(self::T1, ['review_status' => $startsAs]);

        $res = $this->decide($signal->id, ['decision' => $decision] + $extra, $actor)->assertOk();

        $res->assertJsonPath('data.review_status', $status)->assertJsonPath('review.decision', $decision)->assertJsonPath('review.from_status', $startsAs)->assertJsonPath('review.to_status', $status);
        $this->assertSame($status, $signal->fresh()->review_status);
        $this->assertSame([$eventType], $this->events()->pluck('type')->all());
        $this->assertSame($eventType, SignalAudit::TYPES[$decision]);
        // The reason belongs to a dismissal; sent with anything else it is ignored, not kept.
        $this->assertSame($decision === 'dismissed' ? 'stale' : null, $this->history()[0]->reason_code);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>}> */
    public static function decisions(): array
    {
        return [
            'reviewed' => ['reviewed', 'New', 'Reviewed', 'signals.opportunity.reviewed', ['reason_code' => 'stale']],
            'follow_up is recorded as contacted' => ['follow_up', 'New', 'Follow-up', 'signals.opportunity.contacted', ['reason_code' => 'stale']],
            'dismissed' => ['dismissed', 'New', 'Dismissed', 'signals.opportunity.dismissed', ['reason_code' => 'stale']],
            'reopened' => ['reopened', 'Dismissed', 'New', 'signals.opportunity.reopened', ['reason_code' => 'stale']],
        ];
    }

    public function test_the_decision_response_is_the_same_signal_the_detail_endpoint_shows(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $decided = $this->decide($signal->id, ['decision' => 'follow_up', 'note' => 'Called the principal.'], $actor)->assertOk()->json('data');
        $shown = $this->getJson(self::PREFIX . $signal->id, $actor['headers'])->assertOk()->json('data');

        $this->assertEquals($shown, $decided);
        $this->assertSame('Follow-up', $decided['review_status']);
        $this->assertSame('Called the principal.', $decided['review_notes']);
        $this->assertNotNull($decided['reviewed_at']);
    }

    // ── a failure part-way saves nothing ──────────────────────────────────────

    public function test_when_the_event_cannot_be_written_nothing_is_saved_on_either_screen(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $before = $this->footprint($signal);
        Schema::drop('g2g_event');

        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'stale'], $actor)->assertStatus(500);
        $this->legacy($signal->id, 'review', [], $actor)->assertStatus(500);
        $this->legacy($signal->id, 'dismiss', ['notes' => 'x'], $actor)->assertStatus(500);

        $this->assertSame(0, $this->history()->count(), 'the history row went with the event');
        $signal = $signal->fresh();
        $this->assertSame(['New', null, null], [$signal->review_status, $signal->reviewed_by, $signal->review_notes], 'the signal is exactly as it was');
        $this->assertNull($signal->reviewed_at);
        $this->assertSame($before['signal']['updated_at'], DB::table('g2g_company_opportunities')->where('id', $signal->id)->value('updated_at'));
    }

    public function test_the_signal_update_and_the_history_row_stand_or_fall_together(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        // A trigger that refuses the signal's update makes the write fail AFTER the history row was inserted.
        DB::unprepared("CREATE TRIGGER refuse_review BEFORE UPDATE OF review_status ON g2g_company_opportunities BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $this->decide($signal->id, ['decision' => 'reviewed'], $actor)->assertStatus(500);

        $this->assertSame(0, $this->history()->count());
        $this->assertSame(0, DB::table('g2g_event')->count());
        $this->assertSame('New', $signal->fresh()->review_status);
    }

    public function test_a_repeated_idempotency_key_is_not_a_failure_but_any_other_event_error_is(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        // The event for the first history row of this signal is already in the store (a retry after a crash, say).
        $existing = app(EventRecorder::class)->record('signals.opportunity.reviewed', self::T1, 'g2g_company_opportunities', $signal->id, $actor['id'], ['seen' => true], null, 'signals.opportunity.reviewed:' . self::T1 . ":{$signal->id}:1");

        $this->decide($signal->id, ['decision' => 'reviewed'], $actor)->assertOk();

        $this->assertSame(1, $this->history()->count());
        $this->assertSame([$existing], $this->events()->pluck('id')->map(fn ($id) => (int) $id)->all(), 'the existing event stands; no second one');
        $this->assertSame($existing, SignalAudit::record('signals.opportunity.reviewed', self::T1, $signal->id, $actor['id'], ['seen' => true], 1));
    }

    // ── the strict rules ──────────────────────────────────────────────────────

    #[DataProvider('badBodies')]
    public function test_a_body_that_breaks_a_rule_is_a_field_keyed_422_and_writes_nothing(array $body, array $fields): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $before = $this->footprint($signal);

        $res = $this->decide($signal->id, $body, $actor)->assertStatus(422)->assertJsonPath('status', 0)->assertJsonPath('message', 'Validation failed');

        $this->assertSame($fields, array_keys($res->json('errors')));
        foreach ($fields as $field) {
            $this->assertNotEmpty($res->json("errors.{$field}.0"));
        }
        $this->assertSame($before, $this->footprint($signal), 'a refusal changes nothing');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function badBodies(): array
    {
        return [
            'no decision' => [[], ['decision']],
            'unknown decision' => [['decision' => 'explode'], ['decision']],
            'a decision that is not text' => [['decision' => ['reviewed']], ['decision']],
            'promoted is not a decision a person makes' => [['decision' => 'promoted'], ['decision']],
            'dismiss without a reason' => [['decision' => 'dismissed'], ['reason_code']],
            'dismiss with a blank reason' => [['decision' => 'dismissed', 'reason_code' => '   '], ['reason_code']],
            'dismiss with a reason that is not on the list' => [['decision' => 'dismissed', 'reason_code' => 'no_budget'], ['reason_code']],
            'dismiss with a reason that is not text' => [['decision' => 'dismissed', 'reason_code' => ['stale']], ['reason_code']],
            'other without a note' => [['decision' => 'dismissed', 'reason_code' => 'other'], ['note']],
            'other with a blank note' => [['decision' => 'dismissed', 'reason_code' => 'other', 'note' => "  \n "], ['note']],
            'a note over the limit' => [['decision' => 'reviewed', 'note' => str_repeat('x', 1001)], ['note']],
            'a note that is not text' => [['decision' => 'reviewed', 'note' => ['a']], ['note']],
            'an expected status that is not text' => [['decision' => 'reviewed', 'expected_status' => ['New']], ['expected_status']],
            'several rules at once are all reported' => [['decision' => 'explode', 'note' => str_repeat('x', 1001)], ['decision', 'note']],
        ];
    }

    public function test_the_note_limit_comes_from_config_and_counts_characters_after_trimming(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $this->decide($signal->id, ['decision' => 'reviewed', 'note' => str_repeat('é', 1000)], $actor)->assertOk();   // the default limit is 1000 characters, not bytes

        config(['signals.review.note_max' => 10]);
        $other = $this->signal();
        $this->decide($other->id, ['decision' => 'reviewed', 'note' => str_repeat('é', 11)], $actor)->assertStatus(422)->assertJsonPath('errors.note.0', 'The note can be at most 10 characters.');
        $this->decide($other->id, ['decision' => 'reviewed', 'note' => '  ' . str_repeat('é', 10) . '  '], $actor)->assertOk();
    }

    public function test_every_reason_on_the_list_is_accepted_and_other_with_a_note_is_too(): void
    {
        $actor = $this->actor();

        foreach (array_keys(config('signals.review.reasons')) as $code) {
            $signal = $this->signal();
            $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => $code, 'note' => 'Because.'], $actor)->assertOk()->assertJsonPath('review.reason_code', $code);
        }
    }

    public function test_deciding_twice_the_same_way_is_a_409_unchanged_that_shows_the_latest_decision(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $first = $this->decide($signal->id, ['decision' => 'reviewed'], $actor)->assertOk()->json('review');
        $before = $this->footprint($signal);

        $res = $this->decide($signal->id, ['decision' => 'reviewed'], $actor)->assertStatus(409);

        $res->assertJsonPath('status', 0)->assertJsonPath('code', 'unchanged')->assertJsonPath('current.id', $first['id'])->assertJsonPath('current.decision', 'reviewed');
        $this->assertStringContainsString('already Reviewed', $res->json('message'));
        $this->assertSame($before, $this->footprint($signal));

        // A different reason for the same dismissal, with no note, is still nothing new.
        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'stale'], $actor)->assertOk();
        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'duplicate'], $actor)->assertStatus(409)->assertJsonPath('code', 'unchanged');
        $this->assertSame(2, $this->history()->count());
    }

    public function test_reopening_a_signal_that_is_already_new_is_refused_even_with_a_note(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $this->decide($signal->id, ['decision' => 'reopened', 'note' => 'Please look again.'], $actor)->assertStatus(409)->assertJsonPath('code', 'unchanged')->assertJsonPath('current', null);

        $this->assertSame(0, $this->history()->count());
        $this->assertSame(0, DB::table('g2g_event')->count());
        $this->assertNull($signal->fresh()->review_notes);
    }

    public function test_the_same_status_with_a_note_records_the_note_as_one_more_history_row(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $this->decide($signal->id, ['decision' => 'follow_up'], $actor)->assertOk();

        $this->decide($signal->id, ['decision' => 'follow_up', 'note' => 'Left a voicemail.'], $actor)->assertOk()
            ->assertJsonPath('review.from_status', 'Follow-up')->assertJsonPath('review.to_status', 'Follow-up')->assertJsonPath('data.review_notes', 'Left a voicemail.');

        $this->assertSame(['Follow-up', 'Follow-up'], $this->history()->pluck('to_status')->all());
        $this->assertCount(2, $this->events('signals.opportunity.contacted'));
    }

    public function test_an_expected_status_that_is_no_longer_true_is_a_409_conflict_that_shows_who_decided_first(): void
    {
        $first = $this->actor(self::T1, 1, 'Meera', 'Shah');
        $second = $this->actor(self::T1, 1, 'Asha', 'Rao');
        $signal = $this->signal();
        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'duplicate'], $first)->assertOk();
        $before = $this->footprint($signal);

        $res = $this->decide($signal->id, ['decision' => 'reviewed', 'expected_status' => 'New'], $second)->assertStatus(409);

        $res->assertJsonPath('code', 'conflict')->assertJsonPath('current.decision', 'dismissed')->assertJsonPath('current.decided_by_name', 'Meera Shah')->assertJsonPath('current.reason_code', 'duplicate');
        $this->assertStringContainsString('now Dismissed', $res->json('message'));
        $this->assertSame($before, $this->footprint($signal));

        // The status the caller saw is still true: it goes through.
        $this->decide($signal->id, ['decision' => 'reopened', 'expected_status' => 'Dismissed'], $second)->assertOk();
    }

    public function test_a_conflict_is_reported_before_an_unchanged_decision_and_current_is_null_without_history(): void
    {
        $actor = $this->actor();
        $signal = $this->signal(self::T1, ['review_status' => 'Reviewed']);   // decided before the history table existed

        $this->decide($signal->id, ['decision' => 'reviewed', 'expected_status' => 'New'], $actor)->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('current', null);
        $this->decide($signal->id, ['decision' => 'reviewed', 'expected_status' => 'Reviewed'], $actor)->assertStatus(409)->assertJsonPath('code', 'unchanged');
    }

    public function test_a_signal_can_be_reopened_after_a_dismissal_and_the_earlier_rows_are_never_edited(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'stale'], $actor)->assertOk();
        $afterFirst = $this->history()->all();
        $this->decide($signal->id, ['decision' => 'reopened', 'note' => 'New tender published.'], $actor)->assertOk()
            ->assertJsonPath('data.review_status', 'New')->assertJsonPath('review.decision', 'reopened')->assertJsonPath('review.reason_code', null);
        $afterSecond = $this->history()->all();
        $this->decide($signal->id, ['decision' => 'follow_up'], $actor)->assertOk();

        $rows = $this->history()->all();
        $this->assertCount(3, $rows);
        $this->assertEquals($afterFirst[0], $rows[0], 'a later decision never edits an earlier row');
        $this->assertEquals($afterSecond[1], $rows[1]);
        $this->assertSame(['Dismissed', 'New', 'Follow-up'], array_map(fn ($r) => $r->to_status, $rows));
        $this->assertSame(['signals.opportunity.dismissed', 'signals.opportunity.reopened', 'signals.opportunity.contacted'], $this->events()->pluck('type')->all());
    }

    // ── the history endpoint ──────────────────────────────────────────────────

    public function test_the_history_lists_every_decision_newest_first_with_who_and_why(): void
    {
        $meera = $this->actor(self::T1, 1, 'Meera', 'Shah');
        $asha = $this->actor(self::T1, 3, 'Asha', 'Rao');
        $signal = $this->signal();
        $this->decide($signal->id, ['decision' => 'dismissed', 'reason_code' => 'stale', 'note' => 'Old news.'], $meera)->assertOk();
        $this->decide($signal->id, ['decision' => 'reopened'], $asha)->assertOk();
        $this->decide($signal->id, ['decision' => 'reviewed'], $asha)->assertOk();

        $res = $this->getJson(self::PREFIX . "{$signal->id}/reviews", $asha['headers'])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(['reviewed', 'reopened', 'dismissed'], array_column($res->json('data'), 'decision'));
        $this->assertSame(['id', 'decision', 'reason_code', 'reason_label', 'note', 'from_status', 'to_status', 'via', 'decided_by', 'decided_by_name', 'decided_at'], array_keys($res->json('data.2')));
        $res->assertJsonPath('data.2.reason_label', 'Too old to act on')->assertJsonPath('data.2.note', 'Old news.')->assertJsonPath('data.2.decided_by_name', 'Meera Shah')
            ->assertJsonPath('data.0.decided_by_name', 'Asha Rao')->assertJsonPath('data.0.reason_code', null)->assertJsonPath('data.0.reason_label', null);
    }

    public function test_a_signal_nobody_decided_on_has_an_empty_history(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $actor['headers'])->assertOk()->assertExactJson(['status' => 1, 'data' => []]);
    }

    public function test_a_reason_that_left_the_list_keeps_its_code_and_loses_only_its_label(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        DB::table('g2g_signal_reviews')->insert([
            'sub_institute_id' => self::T1, 'opportunity_id' => $signal->id, 'decision' => 'dismissed', 'reason_code' => 'retired_reason', 'note' => null,
            'from_status' => 'New', 'to_status' => 'Dismissed', 'via' => 'backfill', 'decided_by' => null, 'decided_at' => '2026-11-03 10:00:00', 'created_at' => '2026-11-03 10:00:00',
        ]);

        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $actor['headers'])->assertOk()
            ->assertJsonPath('data.0.reason_code', 'retired_reason')->assertJsonPath('data.0.reason_label', null)->assertJsonPath('data.0.decided_by', null)->assertJsonPath('data.0.decided_by_name', null)
            ->assertJsonPath('data.0.via', 'backfill');
    }

    public function test_the_history_never_shows_another_organisations_rows_or_user_names(): void
    {
        $mine = $this->actor(self::T1, 1, 'Asha', 'Rao');
        $theirs = $this->actor(self::T2, 1, 'Zed', 'Outsider');
        $signal = $this->signal();
        $row = fn (int $tenant, ?int $by, string $decision) => [
            'sub_institute_id' => $tenant, 'opportunity_id' => $signal->id, 'decision' => $decision, 'reason_code' => null, 'note' => null,
            'from_status' => 'New', 'to_status' => 'Reviewed', 'via' => 'signal_review', 'decided_by' => $by, 'decided_at' => '2026-11-03 10:00:00', 'created_at' => '2026-11-03 10:00:00',
        ];
        DB::table('g2g_signal_reviews')->insert([
            $row(self::T1, $theirs['id'], 'reviewed'),     // my organisation's row, written by a user id that is another organisation's
            $row(self::T2, $theirs['id'], 'follow_up'),    // another organisation's row that happens to carry this signal's id
        ]);

        $res = $this->getJson(self::PREFIX . "{$signal->id}/reviews", $mine['headers'])->assertOk();

        $this->assertCount(1, $res->json('data'), 'the other organisation\'s row is not mine');
        $this->assertSame('reviewed', $res->json('data.0.decision'));
        $this->assertSame($theirs['id'], $res->json('data.0.decided_by'), 'the id is mine to see');
        $this->assertNull($res->json('data.0.decided_by_name'), 'their name is not');
    }

    // ── another organisation's signal is a 404 on every path ──────────────────

    public function test_another_organisations_signal_is_a_404_exactly_like_a_missing_one_on_every_path(): void
    {
        $mine = $this->actor(self::T1);
        $foreign = $this->signal(self::T2, ['review_status' => 'Reviewed']);
        $before = $this->footprint($foreign);
        $missing = 987654;

        $calls = [
            fn (int $id) => $this->decide($id, ['decision' => 'dismissed', 'reason_code' => 'stale'], $mine),
            fn (int $id) => $this->decide($id, ['decision' => 'explode'], $mine),
            fn (int $id) => $this->legacy($id, 'review', [], $mine),
            fn (int $id) => $this->legacy($id, 'dismiss', [], $mine),
            fn (int $id) => $this->legacy($id, 'follow-up', ['notes' => 'x'], $mine),
            fn (int $id) => $this->getJson(self::PREFIX . "{$id}/reviews", $mine['headers']),
            fn (int $id) => $this->postJson(self::PREFIX . "{$id}/promote-to-crm", [], $mine['headers']),
        ];
        foreach ($calls as $n => $call) {
            $res = $call($foreign->id)->assertStatus(404);
            $this->assertSame($call($missing)->json(), $res->json(), "call {$n} must not hint that the signal exists");
        }

        $this->assertSame($before, $this->footprint($foreign));
        $this->assertSame(0, DB::table('crm_leads')->count());
        // The owner of that signal can of course decide on it.
        $this->decide($foreign->id, ['decision' => 'follow_up'], $this->actor(self::T2))->assertOk();
    }

    // ── rights ────────────────────────────────────────────────────────────────

    public function test_someone_without_the_signals_right_is_refused_and_nothing_is_written(): void
    {
        $employee = $this->actor(self::T1, 2);
        $signal = $this->signal();
        $before = $this->footprint($signal);

        $this->decide($signal->id, ['decision' => 'reviewed'], $employee)->assertStatus(403);
        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $employee['headers'])->assertStatus(403);
        $this->postJson(self::PREFIX . "{$signal->id}/decisions", ['decision' => 'reviewed'])->assertStatus(401);

        $this->assertSame($before, $this->footprint($signal));
        // The tier that could review before can still decide.
        $this->decide($signal->id, ['decision' => 'reviewed'], $this->actor(self::T1, 3))->assertOk();
    }

    public function test_the_new_routes_sit_behind_the_same_gates_as_the_original_buttons_and_the_feed(): void
    {
        $find = fn (string $method, string $uri) => collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === $uri);

        $decisions = $find('POST', 'api/signals/opportunities/{id}/decisions');
        $buttons = $find('PATCH', 'api/signals/opportunities/{id}/{action}');
        $reviews = $find('GET', 'api/signals/opportunities/{id}/reviews');
        $detail = $find('GET', 'api/signals/opportunities/{id}');

        $this->assertNotNull($decisions);
        $this->assertNotNull($reviews);
        $this->assertSame($buttons->gatherMiddleware(), $decisions->gatherMiddleware(), 'deciding needs the same right as the original buttons');
        $this->assertSame($detail->gatherMiddleware(), $reviews->gatherMiddleware(), 'the history needs the same right as looking at the signal');
        $this->assertContains('api.token', $decisions->gatherMiddleware());
        $this->assertSame(['id' => '[0-9]+'], $decisions->wheres);
        $this->assertSame(['id' => '[0-9]+'], $reviews->wheres);
    }

    public function test_with_menu_rights_on_deciding_needs_the_edit_right_and_the_history_the_view_right(): void
    {
        // The gate is chosen when the routes are registered, so the switch is set before the application is built again.
        $_SERVER['SIGNALS_USE_MENU_RIGHTS'] = $_ENV['SIGNALS_USE_MENU_RIGHTS'] = 'true';
        putenv('SIGNALS_USE_MENU_RIGHTS=true');
        $this->refreshApplication();
        $this->buildSchema();
        $this->assertTrue((bool) config('signals.permissions.use_menu_rights'));

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->string('access_link')->nullable();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_edit')->nullable();
        });
        DB::table('tblmenumaster_g2g')->insert(['id' => 500, 'menu_name' => 'Company Opportunities', 'access_link' => '/signals']);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['sub_institute_id' => self::T1, 'menu_id' => 500, 'profile_id' => 1, 'can_view' => 1, 'can_edit' => 1],   // may look and decide
            ['sub_institute_id' => self::T1, 'menu_id' => 500, 'profile_id' => 3, 'can_view' => 1, 'can_edit' => 0],   // may only look
        ]);                                                                                                            // the employee has no row at all
        $signal = $this->signal();
        $editor = $this->actor(self::T1, 1);
        $viewer = $this->actor(self::T1, 3);
        $nobody = $this->actor(self::T1, 2);
        $before = $this->footprint($signal);

        $this->decide($signal->id, ['decision' => 'reviewed'], $viewer)->assertStatus(403);
        $this->decide($signal->id, ['decision' => 'reviewed'], $nobody)->assertStatus(403);
        $this->legacy($signal->id, 'review', [], $viewer)->assertStatus(403);
        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $nobody['headers'])->assertStatus(403);
        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $viewer['headers'])->assertOk();
        $this->assertSame($before, $this->footprint($signal), 'a refusal writes nothing');

        $this->decide($signal->id, ['decision' => 'reviewed'], $editor)->assertOk();
    }

    // ── the original screen's buttons ─────────────────────────────────────────

    public function test_the_original_buttons_keep_their_response_and_now_leave_history_and_an_event(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $res = $this->legacy($signal->id, 'review', [], $actor)->assertOk();

        $this->assertSame(['status', 'data'], array_keys($res->json()), 'the response has the shape it always had');
        $res->assertJsonPath('status', 1)->assertJsonPath('data.id', $signal->id)->assertJsonPath('data.review_status', 'Reviewed')->assertJsonPath('data.sources.0.url', 'https://x.example.com');
        $this->assertNotNull($res->json('data.reviewed_at'));

        $row = $this->history()->sole();
        $this->assertSame(['reviewed', 'legacy_screen', null, null, 'New', 'Reviewed', $actor['id']], [$row->decision, $row->via, $row->reason_code, $row->note, $row->from_status, $row->to_status, (int) $row->decided_by]);
        $this->assertSame(['signals.opportunity.reviewed'], $this->events()->pluck('type')->all());

        $this->legacy($signal->id, 'follow-up', [], $actor)->assertOk()->assertJsonPath('data.review_status', 'Follow-up');
        $this->assertSame('signals.opportunity.contacted', $this->events()->last()->type);
        $this->legacy($signal->id, 'explode', [], $actor)->assertStatus(404);   // the route only takes the three actions
    }

    public function test_a_dismissal_with_no_reason_is_kept_with_the_fallback_reason_and_note_but_review_notes_stay_a_persons_text(): void
    {
        $actor = $this->actor();
        $signal = $this->signal(self::T1, ['review_notes' => 'Written by hand earlier.']);

        $this->legacy($signal->id, 'dismiss', [], $actor)->assertOk()->assertJsonPath('data.review_status', 'Dismissed')->assertJsonPath('data.review_notes', 'Written by hand earlier.');

        $row = $this->history()->sole();
        $this->assertSame([config('signals.review.legacy_dismiss_reason'), config('signals.review.legacy_note'), 'legacy_screen'], [$row->reason_code, $row->note, $row->via]);
        $this->assertSame('signals.opportunity.dismissed', $this->events()->sole()->type);
        $this->assertSame(config('signals.review.legacy_dismiss_reason'), json_decode($this->events()->sole()->payload, true)['reason_code']);
    }

    public function test_notes_and_a_reason_sent_by_the_original_screen_are_kept_and_held_to_the_same_rules(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();

        $this->legacy($signal->id, 'dismiss', ['reason_code' => 'duplicate', 'notes' => 'Same as signal 4.'], $actor)->assertOk()->assertJsonPath('data.review_notes', 'Same as signal 4.');
        $row = $this->history()->sole();
        $this->assertSame(['duplicate', 'Same as signal 4.'], [$row->reason_code, $row->note]);

        $other = $this->signal();
        $before = $this->footprint($other);
        $this->legacy($other->id, 'dismiss', ['reason_code' => 'no_budget'], $actor)->assertStatus(422)->assertJsonPath('errors.reason_code.0', 'That reason is not on the list.');
        $this->legacy($other->id, 'review', ['notes' => str_repeat('x', 1001)], $actor)->assertStatus(422)->assertJsonPath('status', 0);
        $this->assertSame(['notes'], array_keys($this->legacy($other->id, 'review', ['notes' => str_repeat('x', 1001)], $actor)->json('errors')), 'keyed by the field that was sent');
        $this->legacy($other->id, 'dismiss', ['reason_code' => 'other'], $actor)->assertStatus(422)->assertJsonPath('errors.notes.0', 'Add a note: it is required when the reason is Other.');
        $this->assertSame($before, $this->footprint($other));

        // A reason sent with a button that does not take one is ignored, as on the new endpoint.
        $this->legacy($other->id, 'review', ['reason_code' => 'no_budget'], $actor)->assertOk();
        $this->assertNull($this->history($other->id)->sole()->reason_code);
    }

    public function test_pressing_the_button_the_signal_is_already_in_is_a_success_that_writes_nothing(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $this->legacy($signal->id, 'dismiss', [], $actor)->assertOk();
        $before = $this->footprint($signal);

        $this->legacy($signal->id, 'dismiss', [], $actor)->assertOk()->assertJsonPath('data.review_status', 'Dismissed');
        $this->legacy($signal->id, 'dismiss', ['notes' => ''], $actor)->assertOk();

        $this->assertSame($before, $this->footprint($signal), 'no history row, no event, no column touched');

        // With a note there is something to record.
        $this->legacy($signal->id, 'dismiss', ['notes' => 'Checked again.'], $actor)->assertOk()->assertJsonPath('data.review_notes', 'Checked again.');
        $this->assertSame(2, $this->history()->count());
        $this->assertSame('Checked again.', $this->history()->last()->note);
    }

    // ── promote to CRM ────────────────────────────────────────────────────────

    public function test_promoting_leaves_a_history_row_and_an_event_with_the_lead_and_changes_no_status(): void
    {
        $actor = $this->actor();
        $signal = $this->signal(self::T1, ['review_status' => 'Follow-up']);

        $res = $this->postJson(self::PREFIX . "{$signal->id}/promote-to-crm", [], $actor['headers'])->assertStatus(201);

        $leadId = DB::table('crm_leads')->where('source_company_opportunity_id', $signal->id)->value('id');
        $this->assertSame(['status' => 1, 'data' => ['lead_id' => (string) $leadId, 'created' => true]], $res->json(), 'the response is what it always was');

        $row = $this->history()->sole();
        $this->assertSame(['promoted', 'promote', null, "Lead #{$leadId} created", 'Follow-up', 'Follow-up', $actor['id']], [
            $row->decision, $row->via, $row->reason_code, $row->note, $row->from_status, $row->to_status, (int) $row->decided_by,
        ]);
        $event = $this->events()->sole();
        $this->assertSame('signals.opportunity.promoted', $event->type);
        $this->assertSame("signals.opportunity.promoted:" . self::T1 . ":{$signal->id}:{$row->id}", $event->idempotency_key);
        $this->assertEquals([
            'opportunity_id' => $signal->id, 'review_id' => $row->id, 'decision' => 'promoted', 'reason_code' => null, 'from_status' => 'Follow-up', 'to_status' => 'Follow-up',
        ], json_decode($event->payload, true));
        $this->assertSame('Follow-up', $signal->fresh()->review_status);
        $this->assertNull($signal->fresh()->reviewed_by, 'promoting is not a review of the signal');
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.lead.created')->count(), 'the lead\'s own event is untouched');
    }

    public function test_pressing_promote_again_writes_nothing(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $this->postJson(self::PREFIX . "{$signal->id}/promote-to-crm", [], $actor['headers'])->assertStatus(201);
        $before = $this->footprint($signal) + ['leads' => DB::table('crm_leads')->count()];

        $this->postJson(self::PREFIX . "{$signal->id}/promote-to-crm", [], $actor['headers'])->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame($before, $this->footprint($signal) + ['leads' => DB::table('crm_leads')->count()]);
        $this->assertCount(1, $this->events('signals.opportunity.promoted'));
    }

    public function test_if_the_promotion_cannot_be_kept_the_lead_is_not_made(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        Schema::drop('g2g_event');

        $this->postJson(self::PREFIX . "{$signal->id}/promote-to-crm", [], $actor['headers'])->assertStatus(500);

        $this->assertSame(0, DB::table('crm_leads')->count(), 'no lead without its line in the history');
        $this->assertSame(0, $this->history()->count());
    }

    // ── the history table is not on this server yet ───────────────────────────

    public function test_before_the_history_table_exists_the_original_buttons_and_promote_work_as_they_always_did(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        Schema::drop('g2g_signal_reviews');

        $this->legacy($signal->id, 'dismiss', ['notes' => 'No longer needed.'], $actor)->assertOk()->assertJsonPath('data.review_status', 'Dismissed')->assertJsonPath('data.review_notes', 'No longer needed.');
        $at = DB::table('g2g_company_opportunities')->where('id', $signal->id)->value('reviewed_at');
        $this->legacy($signal->id, 'dismiss', [], $actor)->assertOk();
        $this->assertSame($at, DB::table('g2g_company_opportunities')->where('id', $signal->id)->value('reviewed_at'), 'pressing it again changes nothing');
        $this->legacy($signal->id, 'dismiss', ['reason_code' => 'no_budget'], $actor)->assertStatus(422);
        $this->legacy(987654, 'review', [], $actor)->assertStatus(404);
        $this->postJson(self::PREFIX . "{$signal->id}/promote-to-crm", [], $actor['headers'])->assertStatus(201);
        $this->getJson(self::PREFIX . "{$signal->id}/reviews", $actor['headers'])->assertOk()->assertExactJson(['status' => 1, 'data' => []]);

        $this->assertSame(0, DB::table('g2g_event')->where('type', 'like', 'signals.%')->count());
    }

    public function test_before_the_history_table_exists_a_strict_decision_is_refused_not_forgotten(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        Schema::drop('g2g_signal_reviews');

        $this->decide($signal->id, ['decision' => 'reviewed'], $actor)->assertStatus(503)->assertJsonPath('status', 0)->assertJsonPath('code', 'unavailable');

        $this->assertSame('New', $signal->fresh()->review_status);
        $this->assertSame(0, DB::table('g2g_event')->count());
    }

    // ── the event catalogue ───────────────────────────────────────────────────

    public function test_the_five_events_are_registered_with_the_audit_projector_and_the_catalogue_stays_coherent(): void
    {
        $this->assertSame([], EventCatalogue::assertInvariants());

        $types = ['signals.opportunity.reviewed', 'signals.opportunity.dismissed', 'signals.opportunity.contacted', 'signals.opportunity.reopened', 'signals.opportunity.promoted'];
        foreach ($types as $type) {
            $this->assertSame(['AuditLogProjector' => EventCatalogue::PROJECTOR], EventCatalogue::SHIPPED[$type] ?? null, $type);
        }
        $this->assertEqualsCanonicalizing($types, array_values(SignalAudit::TYPES));
        $this->assertSame($types, array_values(array_filter(array_keys(EventCatalogue::SHIPPED), fn ($t) => str_starts_with($t, 'signals.'))));
    }

    public function test_a_decision_can_be_projected_into_the_audit_log(): void
    {
        $actor = $this->actor();
        $signal = $this->signal();
        $this->decide($signal->id, ['decision' => 'follow_up', 'note' => 'Called.'], $actor)->assertOk();

        // Into a shadow table: the projector's delivery ledger uses a MariaDB-only `attempts + 1` insert that sqlite cannot run, and the ledger is not
        // what is under test. What is: the stored event has what the projector needs.
        DB::statement('CREATE TABLE audit_shadow AS SELECT * FROM g2g_audit_log WHERE 0');
        app(AuditLogProjector::class)->project($this->events()->sole(), 'audit_shadow');

        $audit = DB::table('audit_shadow')->sole();
        $this->assertSame(['signals.opportunity.contacted', self::T1, 'g2g_company_opportunities', $signal->id, $actor['id']], [
            $audit->type, (int) $audit->sub_institute_id, $audit->entity_type, (int) $audit->entity_id, (int) $audit->actor_id,
        ]);
    }
}
