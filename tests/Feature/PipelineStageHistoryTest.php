<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Sales\OpportunityGtmSyncBridge;
use App\Domain\Crm\Sales\OpportunityStageHistory;
use App\Domain\Gtm\Workflows\WorkflowActions;
use App\Http\Controllers\Api\Gtm\DealController;
use App\Models\auth\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * Slice 11, acceptance 2: EVERY path that changes an opportunity's stage leaves a history row with from, to, amount, probability and closing date, and a path
 * that changes nothing leaves none. One test per path, found by reading every writer of crm_opportunities (see the slice file's work log):
 *
 *   create, edit, kanban move (the opportunity controller) · CSV import · a signal promoted from the Signal Inbox · the GTM deal sync, both branches (a deal
 *   makes an opportunity; a deal edit changes one) · a GTM deal converted to CRM · the workflow engine's "set stage" · a converted lead (makes an opportunity with
 *   NO stage, so no row is owed; its first move writes one from NULL).
 *
 * The row and the change stand together: a history row that cannot be written takes the change with it, never the other way round.
 */
class PipelineStageHistoryTest extends TestCase
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
        $this->caller = $this->opportunitiesUser();
    }

    /** @return list<array<string, mixed>> the history of one opportunity, oldest first */
    private function history(int $opportunityId): array
    {
        return DB::table('crm_opportunity_stage_history')->where('opportunity_id', $opportunityId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function dealRequest(string $method, string $uri, array $payload = []): Request
    {
        $user = DB::table('tbluser')->insertGetId(['user_name' => 'd' . uniqid(), 'sub_institute_id' => self::T, 'user_profile_id' => 1, 'status' => 1]);
        $request = Request::create($uri, $method, $payload);
        $request->headers->set('Authorization', 'Bearer ' . tbluserModel::find($user)->createToken('t')->plainTextToken);

        return $request;
    }

    // ---- the opportunity controller ----

    public function test_create_with_a_stage_writes_the_first_row_and_without_one_writes_none(): void
    {
        $with = $this->postJson('/api/crm/opportunities', ['name' => 'A', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Qualification', 'amount' => 1200.5, 'probability' => 30, 'closingDate' => '2026-12-31'], $this->caller['h'])->assertStatus(201);
        $without = $this->postJson('/api/crm/opportunities', ['name' => 'B', 'assignedTo' => $this->caller['id']], $this->caller['h'])->assertStatus(201);

        $rows = $this->history((int) $with->json('data.id'));
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Qualification', '2026-12-31', $this->caller['id']], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['closing_date'], $rows[0]['changed_by']]);
        $this->assertEquals([1200.5, 30], [$rows[0]['amount'], $rows[0]['probability']]);
        $this->assertSame([], $this->history((int) $without->json('data.id')), 'no stage, no stage change, no row');
    }

    public function test_an_edit_that_moves_the_stage_writes_a_row_with_the_values_after_the_move(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'amount' => '1000.00', 'probability' => '10.00', 'closing_date' => '2026-12-01', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}", ['salesStage' => 'Proposal/Quotation', 'amount' => 1500, 'probability' => 60, 'closingDate' => '2026-12-20'], $this->caller['h'])->assertOk();

        $rows = $this->history($id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Prospecting', 'Proposal/Quotation', '2026-12-20'], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['closing_date']]);
        $this->assertEquals([1500, 60], [$rows[0]['amount'], $rows[0]['probability']]);
    }

    public function test_an_edit_that_only_pushes_the_closing_date_still_writes_a_row_and_one_that_changes_nothing_writes_none(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'amount' => '1000.00', 'probability' => '10.00', 'closing_date' => '2026-12-01', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}", ['closingDate' => '2027-01-15'], $this->caller['h'])->assertOk();
        $rows = $this->history($id);
        $this->assertCount(1, $rows, 'a deal pushed to next quarter is worth a row');
        $this->assertSame(['Prospecting', 'Prospecting', '2027-01-15'], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['closing_date']]);

        // The form sends the same values back in another format ("1000" for "1000.00", a date with a time): nothing changed, nothing is written.
        $this->putJson("/api/crm/opportunities/{$id}", ['name' => 'Renamed only', 'amount' => 1000, 'probability' => 10, 'closingDate' => '2027-01-15 00:00:00', 'salesStage' => 'Prospecting'], $this->caller['h'])->assertOk();
        $this->assertCount(1, $this->history($id), 'the same values in another format are not a change');
    }

    public function test_a_kanban_move_writes_a_row_and_a_move_to_the_same_stage_writes_none(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'amount' => '800.00', 'assigned_to' => $this->caller['id']]);

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Qualification'], $this->caller['h'])->assertOk();
        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Qualification'], $this->caller['h'])->assertOk();

        $rows = $this->history($id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Prospecting', 'Qualification'], [$rows[0]['from_stage'], $rows[0]['to_stage']]);
        $this->assertEquals(800, $rows[0]['amount'], 'the amount the deal had at the move');
    }

    public function test_a_row_that_cannot_be_written_takes_the_new_opportunity_with_it(): void
    {
        Schema::drop('crm_opportunity_stage_history');

        $this->postJson('/api/crm/opportunities', ['name' => 'Never saved', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Prospecting'], $this->caller['h'])->assertStatus(500);

        $this->assertSame(0, DB::table('crm_opportunities')->where('name', 'Never saved')->count(), 'no opportunity without its first row');
    }

    public function test_an_update_that_fails_takes_its_history_row_with_it(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
        DB::unprepared("CREATE TRIGGER no_close BEFORE UPDATE ON crm_opportunities WHEN NEW.sales_stage = 'Value Proposition' BEGIN SELECT RAISE(ABORT, 'refused'); END;");

        $this->putJson("/api/crm/opportunities/{$id}/stage", ['salesStage' => 'Value Proposition'], $this->caller['h'])->assertStatus(500);

        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($id)->sales_stage);
        $this->assertSame([], $this->history($id), 'no row for a move that did not happen');
    }

    // ---- the paths that do not go through the controller's forms ----

    public function test_a_csv_import_row_with_a_stage_writes_its_first_row(): void
    {
        $res = $this->postJson('/api/crm/opportunities/import', ['rows' => [
            ['name' => 'Imported A', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Needs Analysis', 'amount' => 500, 'closingDate' => '2026-12-10'],
            ['name' => 'Imported B', 'assignedTo' => $this->caller['id']],
        ]], $this->caller['h'])->assertOk();

        $this->assertSame(2, $res->json('data.created'));
        $a = (int) DB::table('crm_opportunities')->where('name', 'Imported A')->value('id');
        $b = (int) DB::table('crm_opportunities')->where('name', 'Imported B')->value('id');
        $rows = $this->history($a);
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Needs Analysis', '2026-12-10'], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['closing_date']]);
        $this->assertEquals(500, $rows[0]['amount']);
        $this->assertSame([], $this->history($b), 'a row without a stage has no stage change');
    }

    public function test_a_signal_promoted_to_an_opportunity_writes_its_first_row(): void
    {
        $user = $this->leadsUser(self::T);
        $signal = (int) DB::table('crm_signal_inbox')->insertGetId(['signal_key' => 'k' . uniqid(), 'title' => 'A school is hiring', 'status' => 'pending', 'classification' => 'unclassified',
            'ingested_at' => now(), 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'opportunity'], $user['h'])->assertOk();

        $opportunity = (int) $res->json('data.promoted_id');
        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($opportunity)->sales_stage);
        $rows = $this->history($opportunity);
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Prospecting', $user['id']], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['changed_by']]);
    }

    public function test_a_signal_promoted_to_a_lead_makes_no_opportunity_and_no_row(): void
    {
        $user = $this->leadsUser(self::T);
        $signal = (int) DB::table('crm_signal_inbox')->insertGetId(['signal_key' => 'k' . uniqid(), 'title' => 'Another', 'status' => 'pending', 'classification' => 'unclassified',
            'ingested_at' => now(), 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson("/api/crm/signal-inbox/{$signal}/promote", ['promoted_to' => 'lead'], $user['h'])->assertOk();

        $this->assertSame(0, DB::table('crm_opportunity_stage_history')->count());
    }

    public function test_a_gtm_deal_that_makes_an_opportunity_writes_its_first_row(): void
    {
        $account = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);

        $res = app(DealController::class)->store($this->dealRequest('POST', '/api/gtm/deals', ['account_id' => $account, 'name' => 'GTM first', 'amount' => 900, 'currency' => 'INR', 'expected_close_date' => '2026-12-31']));

        $dealId = (int) $res->getData(true)['data']['deal']['id'];
        $opp = DB::table('crm_opportunities')->where('converted_from_gtm_deal_id', $dealId)->first();
        $this->assertNotNull($opp, 'the deal made its opportunity');
        $rows = $this->history((int) $opp->id);
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Needs Analysis', '2026-12-31'], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['closing_date']]);
        $this->assertEquals([900, 20], [$rows[0]['amount'], $rows[0]['probability']]);
    }

    public function test_a_gtm_deal_move_that_changes_an_opportunitys_stage_writes_a_row(): void
    {
        $account = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
        $res = app(DealController::class)->store($this->dealRequest('POST', '/api/gtm/deals', ['account_id' => $account, 'name' => 'Moves', 'amount' => 900, 'currency' => 'INR', 'expected_close_date' => '2026-12-31']));
        $dealId = (int) $res->getData(true)['data']['deal']['id'];
        $oppId = (int) DB::table('crm_opportunities')->where('converted_from_gtm_deal_id', $dealId)->value('id');

        app(DealController::class)->stage($this->dealRequest('POST', "/api/gtm/deals/{$dealId}/stage", ['stage' => 'negotiation']), $dealId);

        $this->assertSame('Negotiation/Review', DB::table('crm_opportunities')->find($oppId)->sales_stage);
        $rows = $this->history($oppId);
        $this->assertCount(2, $rows, 'the first row, then the move');
        $this->assertSame(['Needs Analysis', 'Negotiation/Review'], [$rows[1]['from_stage'], $rows[1]['to_stage']]);
    }

    public function test_a_gtm_deal_edit_that_changes_nothing_of_the_opportunity_writes_no_row(): void
    {
        $account = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Acme', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
        $res = app(DealController::class)->store($this->dealRequest('POST', '/api/gtm/deals', ['account_id' => $account, 'name' => 'Quiet', 'amount' => 900, 'currency' => 'INR', 'expected_close_date' => '2026-12-31']));
        $dealId = (int) $res->getData(true)['data']['deal']['id'];
        $oppId = (int) DB::table('crm_opportunities')->where('converted_from_gtm_deal_id', $dealId)->value('id');

        app(DealController::class)->update($this->dealRequest('PUT', "/api/gtm/deals/{$dealId}", ['next_step' => 'Call on Monday']), $dealId);

        $this->assertCount(1, $this->history($oppId), 'a next step is not a stage, amount, probability or date change');
    }

    public function test_the_deal_sync_no_longer_invents_a_closing_date(): void
    {
        // A deal without an expected close date makes an opportunity without one (it used to say TODAY) ...
        $deal = $this->makeDeal(self::T, ['name' => 'Undated', 'expected_close_date' => null]);
        $oppId = (int) OpportunityGtmSyncBridge::syncGtmDealToOpportunity(self::T, $deal);
        $this->assertNull(DB::table('crm_opportunities')->find($oppId)->closing_date);
        $this->assertNull($this->history($oppId)[0]['closing_date']);

        // ... and a later edit of the deal does not overwrite an opportunity's real date with one.
        DB::table('crm_opportunities')->where('id', $oppId)->update(['closing_date' => '2026-12-24']);
        DB::table('gtm_deals')->where('id', $deal)->update(['name' => 'Undated, renamed']);
        OpportunityGtmSyncBridge::syncGtmDealToOpportunity(self::T, $deal);
        $this->assertSame('2026-12-24', substr((string) DB::table('crm_opportunities')->find($oppId)->closing_date, 0, 10));
        $this->assertSame('Undated, renamed', DB::table('crm_opportunities')->find($oppId)->name);

        // A deal that has a date still passes it on, and a closed one falls back to its closing day.
        $dated = $this->makeDeal(self::T, ['name' => 'Dated', 'expected_close_date' => '2027-02-02']);
        $this->assertSame('2027-02-02', substr((string) DB::table('crm_opportunities')->find(OpportunityGtmSyncBridge::syncGtmDealToOpportunity(self::T, $dated))->closing_date, 0, 10));
        $won = $this->makeDeal(self::T, ['name' => 'Won', 'stage' => 'won', 'expected_close_date' => null, 'closed_at' => '2026-11-02 09:30:00']);
        $this->assertSame('2026-11-02', substr((string) DB::table('crm_opportunities')->find(OpportunityGtmSyncBridge::syncGtmDealToOpportunity(self::T, $won))->closing_date, 0, 10));
    }

    public function test_a_gtm_deal_converted_to_crm_writes_its_first_row(): void
    {
        $deal = $this->makeDeal(self::T, ['stage' => 'proposal', 'amount' => '5000.00']);

        $res = app(DealController::class)->convertToCrm($this->dealRequest('POST', "/api/gtm/deals/{$deal}/convert-to-crm"), $deal);

        $oppId = (int) $res->getData(true)['data']['crm_opportunity_id'];
        $rows = $this->history($oppId);
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Proposal/Price Quote'], [$rows[0]['from_stage'], $rows[0]['to_stage']]);
    }

    public function test_the_workflow_engines_set_stage_writes_a_row(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'amount' => '700.00', 'probability' => '10.00', 'closing_date' => '2026-12-05']);

        $result = (new WorkflowActions)->run('set_opportunity_stage', ['stage' => 'Qualification'], ['values' => [], 'ids' => ['opportunity' => $id]], (object) ['id' => 1, 'sub_institute_id' => self::T], (object) ['id' => 1, 'created_by' => 5]);

        $this->assertSame(['from' => 'Prospecting', 'to' => 'Qualification'], ['from' => $result['from'], 'to' => $result['to']]);
        $rows = $this->history($id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Prospecting', 'Qualification', 5], [$rows[0]['from_stage'], $rows[0]['to_stage'], $rows[0]['changed_by']]);
        $this->assertEquals(700, $rows[0]['amount']);
    }

    public function test_a_converted_lead_makes_an_opportunity_with_no_stage_and_its_first_move_writes_a_row_from_nothing(): void
    {
        $lead = $this->makeLead(self::T, ['company' => 'Greenfield School', 'first_name' => 'Rahul', 'last_name' => 'Verma']);
        $out = LeadConverter::convert(DB::table('crm_leads')->find($lead), self::T, $this->caller['id'], $this->caller['id'], true, true, null, true);

        $oppId = $out['opportunity_id'];
        $this->assertNotNull($oppId);
        $this->assertNull(DB::table('crm_opportunities')->find($oppId)->sales_stage, 'a converted lead starts with no stage (by design: "for the owner to set")');
        $this->assertSame([], $this->history($oppId), 'nothing changed stage, so no row is owed');

        $this->putJson("/api/crm/opportunities/{$oppId}/stage", ['salesStage' => 'Prospecting'], $this->caller['h'])->assertOk();

        $rows = $this->history($oppId);
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'Prospecting'], [$rows[0]['from_stage'], $rows[0]['to_stage']]);
    }

    public function test_bulk_assign_and_bulk_delete_change_no_stage_and_write_no_row(): void
    {
        $id = $this->makeOpportunity(self::T, ['sales_stage' => 'Prospecting', 'assigned_to' => $this->caller['id']]);
        $other = $this->user([], self::T);

        $this->postJson('/api/crm/opportunities/bulk/assign', ['ids' => [$id], 'assignedTo' => $other['id']], $this->caller['h']);
        $this->postJson('/api/crm/opportunities/bulk/delete', ['ids' => [$id]], $this->caller['h']);

        $this->assertSame('Prospecting', DB::table('crm_opportunities')->find($id)->sales_stage);
        $this->assertSame(0, DB::table('crm_opportunity_stage_history')->count());
    }

    // ---- the writer itself ----

    public function test_the_writer_compares_by_value(): void
    {
        $existing = (object) ['id' => 1, 'sales_stage' => 'Prospecting', 'amount' => '1000.00', 'probability' => '10.00', 'closing_date' => '2026-12-01'];

        $this->assertFalse(OpportunityStageHistory::changed($existing, ['amount' => 1000, 'probability' => '10', 'closing_date' => '2026-12-01 00:00:00', 'sales_stage' => ' Prospecting ']));
        $this->assertFalse(OpportunityStageHistory::changed($existing, ['name' => 'x']), 'a column that is not tracked');
        $this->assertTrue(OpportunityStageHistory::changed($existing, ['amount' => 1000.01]));
        $this->assertTrue(OpportunityStageHistory::changed($existing, ['closing_date' => null]));
        $this->assertTrue(OpportunityStageHistory::changed((object) ['id' => 1, 'sales_stage' => null, 'amount' => null, 'probability' => null, 'closing_date' => null], ['sales_stage' => 'Prospecting']));
        $this->assertFalse(OpportunityStageHistory::changed((object) ['id' => 1, 'sales_stage' => null, 'amount' => null, 'probability' => null, 'closing_date' => null], ['sales_stage' => '', 'amount' => '']), 'empty is empty');
    }
}
