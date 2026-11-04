<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\OrgAgentService;
use App\Domain\AgentGateway\ProposalApplier;
use App\Domain\AgentGateway\ProposalDecisions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * "Accept applies once" under a RACE (slice 7, thin). Two clicks, or a click and a retry, can both read a proposal as pending; only the one whose conditional
 * UPDATE (status pending -> applied) changes a row may write. The sequential tests cannot reach that branch (the second request would read `applied` and stop
 * early), so this test makes the other request win between this request's read and its claim, by running its UPDATE from inside a query hook.
 */
class AgentApplierClaimTest extends TestCase
{
    use BuildsAgentWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    /** @return array{proposal: int, user: array{id: int, h: array<string, string>}} a note waiting for a person */
    private function waitingNote(): array
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => 'add_note', 'level' => 'approval']);
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];
        $this->model->respond = ['brief' => 'b', 'brief_evidence' => $signal, 'proposals' => [['action' => 'add_note', 'text' => 'A note.', 'reason' => 'r', 'evidence' => $signal]]];
        app(OrgAgentService::class)->run(self::T, $user['id'], $org['identity']);

        return ['proposal' => (int) DB::table('g2g_agent_proposals')->value('id'), 'user' => $user];
    }

    /**
     * Another request applies this proposal after this one has READ it as pending and before this one opens its transaction: the hook fires on the policy
     * read that sits exactly between the two (ProposalApplier checks the kill switch and the action's level after reading the row and before the claim), when
     * no transaction is open, so the other request's write is committed on its own, as it would be from another connection.
     */
    private function otherRequestWinsTheClaim(int $proposalId): void
    {
        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired, $proposalId) {
            if ($fired || ! str_contains($query, 'from "g2g_agent_autonomy"')) {
                return;
            }
            $fired = true;
            DB::table('g2g_agent_proposals')->where('id', $proposalId)->update(['status' => 'applied', 'applied_via' => 'user', 'decided_by' => 99, 'result_ref' => 'crm_organization_comments:77']);
        });
    }

    public function test_the_request_that_loses_the_claim_writes_nothing_and_reports_what_the_winner_did(): void
    {
        $w = $this->waitingNote();
        $this->otherRequestWinsTheClaim($w['proposal']);

        $result = app(ProposalDecisions::class)->accept(self::T, $w['user']['id'], $w['proposal']);

        $this->assertSame(ProposalApplier::ALREADY_APPLIED, $result['outcome']);
        $this->assertSame(0, DB::table('crm_organization_comments')->count(), 'the loser did not write the note a second time');
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'agent.action.applied')->count(), 'nor report an application it did not make');
        $this->assertSame(99, (int) DB::table('g2g_agent_proposals')->find($w['proposal'])->decided_by, "the winner's decision stands");
    }

    public function test_a_proposal_nobody_else_touched_is_applied_by_the_same_path(): void
    {
        $w = $this->waitingNote();

        $result = app(ProposalDecisions::class)->accept(self::T, $w['user']['id'], $w['proposal']);

        $this->assertSame(ProposalApplier::APPLIED, $result['outcome']);
        $this->assertSame(1, DB::table('crm_organization_comments')->count());
    }
}
