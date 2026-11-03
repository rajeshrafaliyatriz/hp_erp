<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\AgentSubjects;
use App\Domain\AgentGateway\OrgAgentService;
use App\Domain\AgentGateway\ProposalApplier;
use App\Domain\AgentGateway\ProposalDecisions;
use App\Domain\OrgAccounts\OrganisationMerger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * When two Organisation 360 identities become one, the runs and proposals of the one that is removed follow it (slice 7, thin; AgentSubjects).
 * Three doors lead to the same code: the class itself, the confirm_link tool (the agent's own way of merging), and OrganisationMerger (a merge of two CRM
 * organisations that removes an identity). Decision recorded in docs/agentic-crm/slices/S07-agent-gateway-thin.md.
 */
class AgentSubjectsTest extends TestCase
{
    use BuildsAgentWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    private function run_(int $tenant, int $subject, string $status = 'completed'): int
    {
        return (int) DB::table('g2g_agent_runs')->insertGetId([
            'sub_institute_id' => $tenant, 'agent_key' => 'organisation', 'subject_type' => 'org_account', 'subject_id' => $subject, 'trigger_source' => 'manual',
            'status' => $status, 'started_at' => now(), 'tool_calls' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function proposal(int $tenant, int $run, int $subject, string $action, string $key, string $status = 'pending'): int
    {
        return (int) DB::table('g2g_agent_proposals')->insertGetId([
            'sub_institute_id' => $tenant, 'run_id' => $run, 'subject_type' => 'org_account', 'subject_id' => $subject, 'action' => $action,
            'payload' => json_encode(['k' => $key]), 'payload_hash' => sha1($action . '|' . $key), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{keeper: int, removed: int, rows: array<string, int>} two identities, the older one the keeper, with the same story on both */
    private function twoIdentitiesWithHistory(): array
    {
        $keeper = $this->makeOrg(self::T, 'Keeper School')['identity'];
        $removed = $this->makeOrg(self::T, 'Removed School')['identity'];
        $rows = [];
        $rows['keeperRun'] = $this->run_(self::T, $keeper);
        $rows['removedRun1'] = $this->run_(self::T, $removed);
        $rows['removedRun2'] = $this->run_(self::T, $removed, 'unavailable');
        // On the keeper: the same note as the removed one wants (applied), and a task the person dismissed.
        $rows['keeperNote'] = $this->proposal(self::T, $rows['keeperRun'], $keeper, 'add_note', 'same note', 'applied');
        $rows['keeperTask'] = $this->proposal(self::T, $rows['keeperRun'], $keeper, 'add_task', 'same task', 'dismissed');
        // On the removed identity: a waiting note that the keeper already has (collides), a dismissed task the keeper also has (collides, not waiting),
        // a waiting task and an applied note that are its own (move).
        $rows['dupWaiting'] = $this->proposal(self::T, $rows['removedRun1'], $removed, 'add_note', 'same note', 'pending');
        $rows['dupDismissed'] = $this->proposal(self::T, $rows['removedRun1'], $removed, 'add_task', 'same task', 'dismissed');
        $rows['ownWaiting'] = $this->proposal(self::T, $rows['removedRun2'], $removed, 'add_task', 'only on the removed one', 'pending');
        $rows['ownApplied'] = $this->proposal(self::T, $rows['removedRun2'], $removed, 'add_note', 'also only on the removed one', 'applied');

        return ['keeper' => $keeper, 'removed' => $removed, 'rows' => $rows];
    }

    private function subjectOf(string $table, int $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('subject_id');
    }

    /** What every row of the two tables looks like, to prove that a call changed nothing (or only what it should). */
    private function snapshot(): array
    {
        return [
            'runs' => DB::table('g2g_agent_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'proposals' => DB::table('g2g_agent_proposals')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function assertFollowed(array $w): void
    {
        $r = $w['rows'];
        foreach (['removedRun1', 'removedRun2'] as $run) {
            $this->assertSame($w['keeper'], $this->subjectOf('g2g_agent_runs', $r[$run]), "{$run} follows the identity that survives");
        }
        foreach (['ownWaiting', 'ownApplied'] as $own) {
            $this->assertSame($w['keeper'], $this->subjectOf('g2g_agent_proposals', $r[$own]), "{$own} follows too");
        }
        $this->assertSame('pending', DB::table('g2g_agent_proposals')->find($r['ownWaiting'])->status, 'a waiting proposal that moved is still waiting for a person');
        $this->assertSame('applied', DB::table('g2g_agent_proposals')->find($r['ownApplied'])->status);

        // The two that the keeper already holds cannot move (the unique key) and stay where they were.
        $this->assertSame($w['removed'], $this->subjectOf('g2g_agent_proposals', $r['dupWaiting']));
        $this->assertSame($w['removed'], $this->subjectOf('g2g_agent_proposals', $r['dupDismissed']));
        $waiting = DB::table('g2g_agent_proposals')->find($r['dupWaiting']);
        $this->assertSame(['dismissed', AgentSubjects::MERGED_NOTE], [$waiting->status, $waiting->decision_note], 'a waiting duplicate is closed, with the reason, not left as work for a person on a removed organisation');
        $this->assertNull($waiting->decided_by);
        $this->assertSame('dismissed', DB::table('g2g_agent_proposals')->find($r['dupDismissed'])->status);
        $this->assertNull(DB::table('g2g_agent_proposals')->find($r['dupDismissed'])->decision_note, 'a duplicate that was already decided is left exactly as it was');

        // The keeper's own rows did not move.
        $this->assertSame($w['keeper'], $this->subjectOf('g2g_agent_runs', $r['keeperRun']));
        $this->assertSame([$w['keeper'], $w['keeper']], [$this->subjectOf('g2g_agent_proposals', $r['keeperNote']), $this->subjectOf('g2g_agent_proposals', $r['keeperTask'])]);
    }

    // ---- the class ----

    public function test_runs_and_proposals_follow_the_survivor_and_a_duplicate_the_survivor_already_holds_stays_behind(): void
    {
        $w = $this->twoIdentitiesWithHistory();

        $moved = AgentSubjects::repoint(self::T, $w['removed'], $w['keeper']);

        $this->assertSame(['runs' => 2, 'proposals' => 2, 'superseded' => 1], $moved);
        $this->assertFollowed($w);
    }

    public function test_doing_it_twice_changes_nothing_and_merging_an_identity_into_itself_does_nothing(): void
    {
        $w = $this->twoIdentitiesWithHistory();
        AgentSubjects::repoint(self::T, $w['removed'], $w['keeper']);
        $after = $this->snapshot();

        $this->assertSame(['runs' => 0, 'proposals' => 0, 'superseded' => 0], AgentSubjects::repoint(self::T, $w['removed'], $w['keeper']));
        $this->assertSame(['runs' => 0, 'proposals' => 0, 'superseded' => 0], AgentSubjects::repoint(self::T, $w['keeper'], $w['keeper']));
        $this->assertSame($after, $this->snapshot());
    }

    public function test_another_organisations_rows_with_the_same_ids_do_not_move(): void
    {
        $w = $this->twoIdentitiesWithHistory();
        $theirRun = $this->run_(self::T2, $w['removed']);
        $theirProposal = $this->proposal(self::T2, $theirRun, $w['removed'], 'add_note', 'theirs');

        AgentSubjects::repoint(self::T, $w['removed'], $w['keeper']);

        $this->assertSame($w['removed'], $this->subjectOf('g2g_agent_runs', $theirRun), "another organisation's run is not moved");
        $this->assertSame($w['removed'], $this->subjectOf('g2g_agent_proposals', $theirProposal));
        $this->assertSame('pending', DB::table('g2g_agent_proposals')->find($theirProposal)->status);
    }

    public function test_a_host_without_the_agent_tables_is_a_no_op_and_a_failure_never_breaks_the_merge_that_called_it(): void
    {
        Schema::drop('g2g_agent_proposals');

        $this->assertSame(['runs' => 0, 'proposals' => 0, 'superseded' => 0], AgentSubjects::repoint(self::T, 1, 2), 'a host that has not run the migrations');

        // Present but unusable (a column the code writes is gone): logged, answered with zeros, and no exception reaches the merge.
        Schema::create('g2g_agent_proposals', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
        });
        $this->assertSame(['runs' => 0, 'proposals' => 0, 'superseded' => 0], AgentSubjects::repoint(self::T, 1, 2));
    }

    // ---- the agent's own way of merging: confirm_link ----

    public function test_accepting_a_link_that_removes_this_identity_takes_its_history_to_the_survivor(): void
    {
        $subject = $this->makeOrg(self::T, 'Greenfield Academy', ['signals' => 1]);
        $older = $this->makeOrg(self::T, 'Greenfield Academy Pvt')['identity'];
        $key = "crm_organization:{$subject['crm']}|g2g_company:77";
        $this->resolver->suggestions = [[
            'key' => $key, 'basis' => 'name_similarity', 'score' => 0.92, 'place_conflict' => false, 'confirmable' => true,
            'left' => ['type' => 'crm_organization', 'id' => $subject['crm'], 'name' => 'Greenfield Academy', 'place' => null],
            'right' => ['type' => 'g2g_company', 'id' => 77, 'name' => 'Greenfield Academy Pvt', 'place' => null],
        ]];
        $this->model->respond = ['brief' => 'Same school.', 'brief_evidence' => [['type' => 'org_account', 'id' => (string) $subject['identity']]], 'proposals' => [
            ['action' => 'confirm_link', 'pair_key' => $key, 'reason' => 'Same name', 'evidence' => [['type' => 'link_suggestion', 'id' => $key]]],
        ]];
        $user = $this->editor();
        $run = app(OrgAgentService::class)->run(self::T, $user['id'], $subject['identity']);
        $proposal = DB::table('g2g_agent_proposals')->first();
        $this->assertSame($subject['identity'], (int) $proposal->subject_id);
        // The linker keeps the OLDER identity and removes this one.
        $this->linker->returns = $older;

        $result = app(ProposalDecisions::class)->accept(self::T, $user['id'], $proposal->id);

        $this->assertSame(ProposalApplier::APPLIED, $result['outcome']);
        $this->assertSame("g2g_org_accounts:{$older}", $result['proposal']->result_ref, 'the result points at the identity that now holds the organisation');
        $this->assertSame($older, $this->subjectOf('g2g_agent_runs', $run->id), 'the run is now the survivor\'s');
        $this->assertSame($older, $this->subjectOf('g2g_agent_proposals', $proposal->id), 'and so is the proposal that did the merge');
        $this->assertSame('applied', DB::table('g2g_agent_proposals')->find($proposal->id)->status);
    }

    public function test_accepting_a_link_where_this_identity_survives_moves_nothing(): void
    {
        $subject = $this->makeOrg(self::T, 'Greenfield Academy');
        $key = "crm_organization:{$subject['crm']}|g2g_company:77";
        $this->resolver->suggestions = [[
            'key' => $key, 'basis' => 'name_similarity', 'score' => 0.92, 'place_conflict' => false, 'confirmable' => true,
            'left' => ['type' => 'crm_organization', 'id' => $subject['crm'], 'name' => 'Greenfield Academy', 'place' => null],
            'right' => ['type' => 'g2g_company', 'id' => 77, 'name' => 'Greenfield Academy Pvt', 'place' => null],
        ]];
        $this->model->respond = ['brief' => 'Same school.', 'brief_evidence' => [['type' => 'org_account', 'id' => (string) $subject['identity']]], 'proposals' => [
            ['action' => 'confirm_link', 'pair_key' => $key, 'reason' => 'Same name', 'evidence' => [['type' => 'link_suggestion', 'id' => $key]]],
        ]];
        $user = $this->editor();
        $run = app(OrgAgentService::class)->run(self::T, $user['id'], $subject['identity']);
        $proposal = DB::table('g2g_agent_proposals')->first();
        $this->linker->returns = $subject['identity'];

        app(ProposalDecisions::class)->accept(self::T, $user['id'], $proposal->id);

        $this->assertSame($subject['identity'], $this->subjectOf('g2g_agent_runs', $run->id));
        $this->assertSame($subject['identity'], $this->subjectOf('g2g_agent_proposals', $proposal->id));
    }

    // ---- a merge of two CRM organisations that removes an identity ----

    public function test_a_crm_organisation_merge_that_removes_an_identity_takes_that_identitys_agent_history_to_the_one_it_keeps(): void
    {
        // The older identity points at the organisation that is being merged away; the newer one at the survivor. The merger keeps the older identity
        // and soft-deletes the newer (OrganisationMergeTest::test_the_survivors_identity_is_kept_and_the_duplicates_identity_is_absorbed).
        $dupe = $this->makeOrg(self::T, 'Dupe School');
        $keep = $this->makeOrg(self::T, 'Keep School');
        $this->assertLessThan($keep['identity'], $dupe['identity']);
        $run = $this->run_(self::T, $keep['identity']);
        $waiting = $this->proposal(self::T, $run, $keep['identity'], 'add_note', 'a note nobody has decided');

        OrganisationMerger::repoint(self::T, $dupe['crm'], $keep['crm']);

        $this->assertNotNull(DB::table('g2g_org_accounts')->find($keep['identity'])->deleted_at, 'the newer identity was removed by the merge');
        $this->assertNull(DB::table('g2g_org_accounts')->find($dupe['identity'])->deleted_at);
        $this->assertSame($dupe['identity'], $this->subjectOf('g2g_agent_runs', $run), 'its run now belongs to the identity that is kept');
        $this->assertSame($dupe['identity'], $this->subjectOf('g2g_agent_proposals', $waiting));
        $this->assertSame('pending', DB::table('g2g_agent_proposals')->find($waiting)->status, 'and the waiting proposal can still be decided there');
    }

    public function test_a_crm_organisation_merge_that_removes_no_identity_leaves_the_agent_history_where_it_is(): void
    {
        $dupe = $this->makeOrg(self::T, 'Dupe School');
        $keepCrm = (int) DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-' . strtoupper(substr(uniqid(), -8)), 'name' => 'Keep School', 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $run = $this->run_(self::T, $dupe['identity']);
        $before = $this->snapshot();

        // The surviving CRM organisation has no identity: the duplicate's identity is only re-pointed at it, never removed.
        OrganisationMerger::repoint(self::T, $dupe['crm'], $keepCrm);

        $identity = DB::table('g2g_org_accounts')->find($dupe['identity']);
        $this->assertNull($identity->deleted_at);
        $this->assertSame($keepCrm, (int) $identity->crm_organization_id);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($dupe['identity'], $this->subjectOf('g2g_agent_runs', $run));
    }
}
