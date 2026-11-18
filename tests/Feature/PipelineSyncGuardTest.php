<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\GtmSyncGuard;
use App\Http\Controllers\Api\Gtm\DealController;
use App\Models\auth\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\Support\ThrowingGtmBridge;
use Tests\TestCase;

/**
 * Defect D17 (slice 11): saving an opportunity or a GTM deal can never fail because the OTHER system's sync failed. The sync is forced to fail in two ways:
 * a bridge bound in the container that throws from both directions (the call is proved to have happened), and the real failure of a host that is behind
 * (the GTM deals table is gone). Each of the six call sites (three in the opportunity controller, three in the deal controller) is exercised on its own, and
 * every time the answer is the one a healthy sync gives, the row and its history are written, and the failure is logged once.
 */
class PipelineSyncGuardTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    /** @var array{id: int, h: array<string, string>} */
    private array $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        ThrowingGtmBridge::$leaveTransactionOpen = false;
        $this->caller = $this->opportunitiesUser();
    }

    private function assertLoggedOnce(string $direction, string $idKey, int $id): void
    {
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($direction, $idKey, $id) {
            return str_contains((string) $message, 'CRM/GTM sync failed') && ($context['direction'] ?? null) === $direction && ($context[$idKey] ?? null) === $id
                && ($context['sub_institute_id'] ?? null) === self::T && str_contains((string) ($context['error'] ?? ''), 'GTM sync is down');
        })->once();
    }

    private function dealRequest(string $method, string $uri, array $payload = []): Request
    {
        $user = DB::table('tbluser')->insertGetId(['user_name' => 'd' . uniqid(), 'sub_institute_id' => self::T, 'user_profile_id' => 1, 'status' => 1]);
        $request = Request::create($uri, $method, $payload);
        $request->headers->set('Authorization', 'Bearer ' . tbluserModel::find($user)->createToken('t')->plainTextToken);

        return $request;
    }

    // ---- the opportunity controller: three call sites, a bridge that throws ----

    public function test_create_answers_as_before_when_the_sync_throws(): void
    {
        Log::spy();
        $this->bindThrowingBridge();

        $response = $this->postJson('/api/crm/opportunities', ['name' => 'Big deal', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Prospecting', 'amount' => 1000, 'currency' => 'INR'], $this->caller['h']);

        $response->assertStatus(201)->assertJsonPath('status', 1)->assertJsonPath('message', 'Opportunity created.')->assertJsonPath('data.name', 'Big deal')->assertJsonPath('data.salesStage', 'Prospecting');
        $id = (int) $response->json('data.id');
        $this->assertSame(['forward:' . self::T . ':' . $id], ThrowingGtmBridge::$calls, 'the sync WAS attempted, and it failed');
        $this->assertSame('Big deal', DB::table('crm_opportunities')->find($id)->name, 'the row is saved');
        $history = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $id)->get();
        $this->assertCount(1, $history, 'and so is its first history row');
        $this->assertSame([null, 'Prospecting'], [$history[0]->from_stage, $history[0]->to_stage]);
        $this->assertLoggedOnce(GtmSyncGuard::FORWARD, 'opportunity_id', $id);
    }

    public function test_update_answers_as_before_when_the_sync_throws(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'amount' => '1000.00', 'currency' => 'INR', 'assigned_to' => $this->caller['id']]);
        Log::spy();
        $this->bindThrowingBridge();

        $response = $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Qualification', 'amount' => 2500], $this->caller['h']);

        $response->assertOk()->assertJsonPath('message', 'Opportunity updated.')->assertJsonPath('data.salesStage', 'Qualification')->assertJsonPath('data.amount', 2500);
        $this->assertSame(['forward:' . self::T . ':' . $id], ThrowingGtmBridge::$calls);
        $this->assertSame('Qualification', DB::table('crm_opportunities')->find($id)->sales_stage);
        $history = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(['Prospecting', 'Qualification'], [$history[0]->from_stage, $history[0]->to_stage]);
        $this->assertLoggedOnce(GtmSyncGuard::FORWARD, 'opportunity_id', $id);
    }

    public function test_a_stage_move_answers_as_before_when_the_sync_throws(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
        Log::spy();
        $this->bindThrowingBridge();

        $response = $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Needs Analysis'], $this->caller['h']);

        $response->assertOk()->assertJsonPath('message', 'Stage updated.')->assertJsonPath('data.salesStage', 'Needs Analysis');
        $this->assertSame(['forward:' . self::T . ':' . $id], ThrowingGtmBridge::$calls);
        $this->assertSame('Needs Analysis', DB::table('crm_opportunities')->find($id)->sales_stage);
        $this->assertSame(1, DB::table('crm_opportunity_stage_history')->where('opportunity_id', $id)->where('to_stage', 'Needs Analysis')->count());
        $this->assertLoggedOnce(GtmSyncGuard::FORWARD, 'opportunity_id', $id);
    }

    // ---- the GTM deal controller: three call sites ----

    public function test_a_deal_created_in_gtm_answers_as_before_when_the_reverse_sync_throws(): void
    {
        $account = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
        Log::spy();
        $this->bindThrowingBridge();

        $response = app(DealController::class)->store($this->dealRequest('POST', '/api/gtm/deals', ['account_id' => $account, 'name' => 'GTM first', 'amount' => 900, 'currency' => 'INR']));

        $this->assertSame(201, $response->getStatusCode());
        $dealId = (int) $response->getData(true)['data']['deal']['id'];
        $this->assertSame(['reverse:' . self::T . ':' . $dealId], ThrowingGtmBridge::$calls);
        $this->assertSame('GTM first', DB::table('gtm_deals')->find($dealId)->name, 'the deal is saved');
        $this->assertSame(1, DB::table('gtm_deal_stage_history')->where('deal_id', $dealId)->count(), 'with its own history');
        $this->assertLoggedOnce(GtmSyncGuard::REVERSE, 'deal_id', $dealId);
    }

    public function test_a_deal_edited_in_gtm_answers_as_before_when_the_reverse_sync_throws(): void
    {
        $deal = $this->makeDeal(self::T);
        Log::spy();
        $this->bindThrowingBridge();

        $response = app(DealController::class)->update($this->dealRequest('PUT', "/api/gtm/deals/{$deal}", ['name' => 'Renamed']), $deal);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['reverse:' . self::T . ':' . $deal], ThrowingGtmBridge::$calls);
        $this->assertSame('Renamed', DB::table('gtm_deals')->find($deal)->name);
        $this->assertLoggedOnce(GtmSyncGuard::REVERSE, 'deal_id', $deal);
    }

    public function test_a_deal_moved_in_gtm_answers_as_before_when_the_reverse_sync_throws(): void
    {
        $deal = $this->makeDeal(self::T);
        Log::spy();
        $this->bindThrowingBridge();

        $response = app(DealController::class)->stage($this->dealRequest('POST', "/api/gtm/deals/{$deal}/stage", ['stage' => 'qualification']), $deal);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['reverse:' . self::T . ':' . $deal], ThrowingGtmBridge::$calls);
        $this->assertSame('qualification', DB::table('gtm_deals')->find($deal)->stage);
        $this->assertSame(1, DB::table('gtm_deal_stage_history')->where('deal_id', $deal)->where('to_stage', 'qualification')->count());
        $this->assertLoggedOnce(GtmSyncGuard::REVERSE, 'deal_id', $deal);
    }

    // ---- the real failure of a host that is behind ----

    public function test_a_real_database_failure_inside_the_sync_does_not_reach_the_person(): void
    {
        // No bridge is replaced: the real sync runs and fails on its own, because the GTM deals table is not there.
        $this->breakGtmDeals();
        Log::spy();

        $response = $this->postJson('/api/crm/opportunities', ['name' => 'Saved anyway', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Prospecting'], $this->caller['h']);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Saved anyway');
        $id = (int) $response->json('data.id');
        $this->assertSame(1, DB::table('crm_opportunities')->where('id', $id)->count());
        $this->assertSame(1, DB::table('crm_opportunity_stage_history')->where('opportunity_id', $id)->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'CRM/GTM sync failed') && ($context['opportunity_id'] ?? null) === $id)->once();
    }

    public function test_a_failed_link_back_leaves_no_orphan_deal(): void
    {
        // The deal is made and then the link back to the opportunity fails. Made together or not at all: no deal may be left without its opportunity,
        // or a repair run would copy it into a second opportunity.
        DB::unprepared("CREATE TRIGGER no_link BEFORE UPDATE ON crm_opportunities WHEN NEW.converted_from_gtm_deal_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'link failed'); END;");
        Log::spy();

        $response = $this->postJson('/api/crm/opportunities', ['name' => 'Half synced', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Prospecting'], $this->caller['h']);

        $response->assertStatus(201);
        $id = (int) $response->json('data.id');
        $this->assertSame(1, DB::table('crm_opportunities')->where('id', $id)->count(), 'the opportunity is saved');
        $this->assertSame(0, DB::table('gtm_deals')->count(), 'and the half-made deal was rolled back with the failed link');
        $this->assertNull(DB::table('crm_opportunities')->find($id)->converted_from_gtm_deal_id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'CRM/GTM sync failed') && str_contains((string) ($context['error'] ?? ''), 'link failed'))->once();
    }

    public function test_a_healthy_sync_still_links_the_deal_and_logs_nothing(): void
    {
        Log::spy();

        $response = $this->postJson('/api/crm/opportunities', ['name' => 'Healthy', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Prospecting', 'amount' => 700, 'currency' => 'INR'], $this->caller['h']);

        $response->assertStatus(201);
        $id = (int) $response->json('data.id');
        $dealId = DB::table('crm_opportunities')->find($id)->converted_from_gtm_deal_id;
        $this->assertNotNull($dealId, 'the deal was made and linked');
        $this->assertSame('Healthy', DB::table('gtm_deals')->find($dealId)->name);
        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, GtmSyncGuard::failuresToday(self::T));
    }

    // ---- the guard itself ----

    public function test_it_never_rethrows_and_returns_null(): void
    {
        $this->bindThrowingBridge();

        $this->assertNull(GtmSyncGuard::opportunityToGtm(self::T, 55));
        $this->assertNull(GtmSyncGuard::dealToOpportunity(self::T, 56));
        $this->assertSame(['forward:' . self::T . ':55', 'reverse:' . self::T . ':56'], ThrowingGtmBridge::$calls);
    }

    public function test_it_catches_an_error_that_is_not_an_exception(): void
    {
        // A TypeError (an Error, not an Exception) is what an old host's missing class or a changed signature produces.
        $this->app->bind(\App\Domain\Crm\Sales\OpportunityGtmSyncBridge::class, fn () => new class extends \App\Domain\Crm\Sales\OpportunityGtmSyncBridge {
            public static function syncOpportunityToGtm(int $tenantId, int $opportunityId, ?int $actorId = null): ?int
            {
                throw new \TypeError('the old host');
            }
        });

        $this->assertNull(GtmSyncGuard::opportunityToGtm(self::T, 7));
    }

    public function test_it_rolls_back_a_transaction_the_failed_sync_left_open_and_says_so(): void
    {
        $this->bindThrowingBridge();
        ThrowingGtmBridge::$leaveTransactionOpen = true;
        Log::spy();
        $level = DB::transactionLevel();

        $this->assertNull(GtmSyncGuard::opportunityToGtm(self::T, 9));

        $this->assertSame($level, DB::transactionLevel(), 'the connection is back where the caller left it');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['transactions_rolled_back'] ?? null) === 1)->once();
    }

    public function test_it_leaves_an_enclosing_transaction_of_the_caller_alone(): void
    {
        $this->bindThrowingBridge();
        DB::beginTransaction();
        $level = DB::transactionLevel();

        GtmSyncGuard::opportunityToGtm(self::T, 10);

        $this->assertSame($level, DB::transactionLevel(), "the caller's own transaction is still open");
        DB::rollBack();
    }

    public function test_the_failures_are_counted_per_organisation_and_day(): void
    {
        $this->bindThrowingBridge();
        Cache::flush();

        GtmSyncGuard::opportunityToGtm(self::T, 1);
        GtmSyncGuard::dealToOpportunity(self::T, 2);
        GtmSyncGuard::opportunityToGtm(self::T2, 3);

        $this->assertSame(2, GtmSyncGuard::failuresToday(self::T));
        $this->assertSame(1, GtmSyncGuard::failuresToday(self::T2));
    }

    public function test_no_call_site_reaches_the_bridge_directly_any_more(): void
    {
        // The six call sites go through the guard. A new direct call to a sync method in a controller would put D17 back, so it fails here.
        foreach (['app/Http/Controllers/Api/Crm/CrmOpportunityController.php', 'app/Http/Controllers/Api/Gtm/DealController.php'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/OpportunityGtmSyncBridge::sync(OpportunityToGtm|GtmDealToOpportunity)\(/', $source, "{$file} calls the bridge's sync directly");
        }
        $this->assertSame(3, substr_count(file_get_contents(base_path('app/Http/Controllers/Api/Crm/CrmOpportunityController.php')), 'GtmSyncGuard::opportunityToGtm('));
        $this->assertSame(3, substr_count(file_get_contents(base_path('app/Http/Controllers/Api/Gtm/DealController.php')), 'GtmSyncGuard::dealToOpportunity('));
    }
}
