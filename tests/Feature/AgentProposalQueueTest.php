<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\OrgAgentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The tenant-wide waiting queue (S7b): GET /api/agent/proposals. It lists what nobody has decided yet across the organisation's organisations; a decision
 * is the existing POST /proposals/{id}/accept or /dismiss (the one apply path), and these tests prove the queue neither adds a second path nor shows
 * another organisation's rows or names.
 */
class AgentProposalQueueTest extends TestCase
{
    use BuildsAgentWorld;

    private const P = '/api/agent';

    /** The keys of a proposal as the organisation panel shows it (AgentGatewayApiTest::PROPOSAL_KEYS) plus what the queue adds. */
    private const ROW_KEYS = ['id', 'run_id', 'action', 'status', 'summary', 'payload', 'reason', 'evidence', 'applied_via', 'applied_at', 'decided_by', 'decided_by_name', 'decided_at',
        'decision_note', 'result_ref', 'error', 'created_at', 'label', 'organisation', 'can'];

    /** @var array{identity: int, crm: ?int, company: ?int, gtm: ?int, signals: list<int>} */
    private array $green;

    /** @var array{identity: int, crm: ?int, company: ?int, gtm: ?int, signals: list<int>} */
    private array $hill;

    /** @var array{identity: int, crm: ?int, company: ?int, gtm: ?int, signals: list<int>} */
    private array $theirs;

    /** @var array{id: int, h: array<string, string>} */
    private array $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
        $this->editor = $this->editor();
        foreach ([self::T, self::T2] as $tenant) {
            foreach (['add_note', 'add_task'] as $action) {
                DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => $tenant, 'action_key' => $action, 'level' => 'approval']);
            }
        }
        $this->green = $this->makeOrg(self::T, 'Greenfield', ['signals' => 2]);
        $this->hill = $this->makeOrg(self::T, 'Hillside', ['signals' => 1]);
        $this->theirs = $this->makeOrg(self::T2, 'Their School', ['signals' => 1]);
    }

    /** A real run on an organisation: a note and a task, both left waiting (the level is `approval`). */
    private function runAgent(array $org, string $text, int $tenant = self::T, ?array $user = null): void
    {
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];
        $this->model->respond = ['brief' => 'b', 'brief_evidence' => $signal, 'proposals' => [
            ['action' => 'add_note', 'text' => "Note {$text}", 'reason' => "Because {$text}", 'evidence' => $signal],
            ['action' => 'add_task', 'title' => "Task {$text}", 'due_date' => now()->addDays(3)->toDateString(), 'reason' => 'r', 'evidence' => $signal],
        ]];
        app(OrgAgentService::class)->run($tenant, ($user ?? ($tenant === self::T ? $this->editor : $this->editor($tenant)))['id'], $org['identity']);
    }

    /** @return list<int> */
    private function pendingIds(int $tenant = self::T): array
    {
        return DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenant)->where('status', 'pending')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function queue(string $query = '', ?array $user = null): array
    {
        return $this->getJson(self::P . '/proposals' . $query, ($user ?? $this->editor)['h'])->assertOk()->json('data');
    }

    /** @return list<array<string, mixed>> every table the queue could change */
    private function everything(): array
    {
        $all = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['proposals' => $all('g2g_agent_proposals'), 'runs' => $all('g2g_agent_runs'), 'calls' => $all('g2g_agent_tool_calls'), 'events' => $all('g2g_event'), 'comments' => $all('crm_organization_comments'),
            'tasks' => $all('task_management_calendar_events'), 'autonomy' => $all('g2g_agent_autonomy')];
    }

    // ---- what it lists ----

    public function test_it_lists_this_organisations_waiting_proposals_newest_first_in_words_with_the_organisation_each_is_about(): void
    {
        $this->runAgent($this->green, 'one');
        $this->runAgent($this->hill, 'two');
        $this->runAgent($this->theirs, 'theirs', self::T2);

        $d = $this->queue();

        $this->assertSame(['rows', 'pagination'], array_keys($d));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 4, 'last_page' => 1], $d['pagination'], "the other organisation's two are not counted");
        $this->assertSame(array_reverse($this->pendingIds()), array_column($d['rows'], 'id'), 'newest first, and only this organisation\'s');
        $this->assertSame(['pending'], array_values(array_unique(array_column($d['rows'], 'status'))));
        $this->assertSame(['Hillside', 'Hillside', 'Greenfield', 'Greenfield'], array_column(array_column($d['rows'], 'organisation'), 'name'));
        $this->assertNotContains('Their School', array_column(array_column($d['rows'], 'organisation'), 'name'));
        $this->assertStringNotContainsString('Their School', json_encode($d));

        $task = $d['rows'][0];
        $this->assertSame('add_task', $task['action']);
        $this->assertSame('Add a follow-up task', $task['label']);
        $this->assertSame('Add a task: Task two (due ' . now()->addDays(3)->toDateString() . ')', $task['summary'], 'the plain-words line the presenter already builds');
        $note = $d['rows'][1];
        $this->assertSame(['Add a note', 'Add a note: Note two', 'Because two'], [$note['label'], $note['summary'], $note['reason']]);
        $this->assertSame(['text' => 'Note two'], $note['payload'], 'the whole text a person is asked to accept');
        $this->assertSame('signal', $note['evidence'][0]['type']);
        $this->assertSame('Signal 1 for Hillside', $note['evidence'][0]['label'], 'the evidence as the organisation panel shows it');
    }

    public function test_a_row_is_the_proposal_as_the_panel_shows_it_plus_the_label_the_organisation_and_what_the_caller_may_do(): void
    {
        $this->runAgent($this->green, 'one');

        $row = $this->queue()['rows'][1];

        $keys = array_keys($row);
        sort($keys);
        $expected = self::ROW_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertSame(['id' => $this->green['identity'], 'name' => 'Greenfield', 'href_hint' => "/module/crm/marketing/organizations/{$this->green['crm']}", 'removed' => false], $row['organisation']);
        $this->assertSame(['decide' => true], $row['can']);
        $panel = $this->getJson(self::P . "/organisations/{$this->green['identity']}", $this->editor['h'])->assertOk()->json('data.pending');
        $asPanel = collect($panel)->firstWhere('id', $row['id']);
        $this->assertSame($asPanel, array_diff_key($row, ['label' => 1, 'organisation' => 1, 'can' => 1]), 'the very same proposal object the panel gets');
    }

    public function test_the_status_filter_pages_and_the_validation(): void
    {
        $this->runAgent($this->green, 'one');
        $this->runAgent($this->hill, 'two');
        [$a, $b, $c, $d] = $this->pendingIds();
        $this->postJson(self::P . "/proposals/{$a}/accept", [], $this->editor['h'])->assertOk();
        $this->postJson(self::P . "/proposals/{$b}/dismiss", ['reason' => 'Not now'], $this->editor['h'])->assertOk();

        $this->assertSame([$d, $c], array_column($this->queue()['rows'], 'id'), 'the default is what is waiting');
        $this->assertSame([$d, $c], array_column($this->queue('?status=pending')['rows'], 'id'));
        $applied = $this->queue('?status=applied')['rows'];
        $this->assertSame([$a], array_column($applied, 'id'));
        $this->assertSame(['applied', 'user', 'Asha Rao'], [$applied[0]['status'], $applied[0]['applied_via'], $applied[0]['decided_by_name']]);
        $dismissed = $this->queue('?status=dismissed')['rows'];
        $this->assertSame([$b], array_column($dismissed, 'id'));
        $this->assertSame('Not now', $dismissed[0]['decision_note']);
        $this->assertSame([], $this->queue('?status=failed')['rows']);

        $page = $this->queue('?per_page=1&page=2');
        $this->assertSame([$c], array_column($page['rows'], 'id'));
        $this->assertSame(['page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $page['pagination']);
        $beyond = $this->queue('?per_page=1&page=9');
        $this->assertSame([], $beyond['rows']);
        $this->assertSame(['page' => 9, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $beyond['pagination']);
        $this->assertSame(50, $this->queue('?per_page=500')['pagination']['per_page'], 'capped at 50');
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1], $this->queue('?status=failed')['pagination']);

        foreach (['status=bogus', 'status=all', 'page=0', 'page=x', 'per_page=0', 'per_page=-3'] as $bad) {
            $this->getJson(self::P . "/proposals?{$bad}", $this->editor['h'])->assertStatus(422);
        }
    }

    public function test_a_queue_of_many_is_paged_in_order_and_the_other_organisations_rows_are_never_in_a_page_or_the_total(): void
    {
        $ids = [];
        foreach (range(1, 7) as $n) {
            $ids[] = (int) DB::table('g2g_agent_proposals')->insertGetId($this->row(self::T, $this->green['identity'], "Mine {$n}"));
        }
        foreach (range(1, 5) as $n) {
            DB::table('g2g_agent_proposals')->insert($this->row(self::T2, $this->theirs['identity'], "Theirs {$n}"));
        }
        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $d = $this->queue("?per_page=3&page={$page}");
            $this->assertSame(['page' => $page, 'per_page' => 3, 'total' => 7, 'last_page' => 3], $d['pagination']);
            $seen = array_merge($seen, array_column($d['rows'], 'id'));
        }

        $this->assertSame(array_reverse($ids), $seen, 'every one of mine once, newest first, none of theirs');
    }

    // ---- the removed organisation ----

    public function test_a_proposal_whose_organisation_was_removed_is_shown_as_such_and_not_dropped(): void
    {
        $this->runAgent($this->green, 'one');
        $this->runAgent($this->hill, 'two');
        $this->assertCount(4, $this->queue()['rows']);

        // 1. the identity was removed (soft-deleted, as a merge does): its name stays in the history, with no page to open
        DB::table('g2g_org_accounts')->where('id', $this->hill['identity'])->update(['deleted_at' => now()]);
        $rows = $this->queue()['rows'];
        $this->assertCount(4, $rows, 'not dropped');
        $hill = collect($rows)->filter(fn ($r) => $r['organisation']['id'] === $this->hill['identity'])->values();
        $this->assertCount(2, $hill);
        $this->assertSame(['id' => $this->hill['identity'], 'name' => 'Hillside', 'href_hint' => null, 'removed' => true], $hill[0]['organisation']);

        // 2. the identity row is gone altogether: still listed, with no name to show
        DB::table('g2g_org_accounts')->where('id', $this->hill['identity'])->delete();
        $gone = collect($this->queue()['rows'])->firstWhere('id', $hill[0]['id']);
        $this->assertSame(['id' => $this->hill['identity'], 'name' => null, 'href_hint' => null, 'removed' => true], $gone['organisation']);
        $this->assertTrue($gone['can']['decide'], 'it can still be dismissed');
        $this->postJson(self::P . "/proposals/{$gone['id']}/dismiss", [], $this->editor['h'])->assertOk()->assertJsonPath('data.outcome', 'dismissed');

        // 3. the CRM record went but the identity is alive: the organisation is not removed, it just has no page to link to
        DB::table('crm_organizations')->where('id', $this->green['crm'])->update(['deleted_at' => now()]);
        $green = collect($this->queue()['rows'])->filter(fn ($r) => $r['organisation']['id'] === $this->green['identity'])->first();
        $this->assertSame(['id' => $this->green['identity'], 'name' => 'Greenfield', 'href_hint' => null, 'removed' => false], $green['organisation']);
    }

    public function test_a_pointer_into_another_organisations_identity_shows_nothing_of_it(): void
    {
        $this->runAgent($this->green, 'one');
        DB::table('g2g_agent_proposals')->where('sub_institute_id', self::T)->update(['subject_id' => $this->theirs['identity']]);

        $d = $this->queue();

        $this->assertCount(2, $d['rows']);
        $gone = ['id' => $this->theirs['identity'], 'name' => null, 'href_hint' => null, 'removed' => true];
        $this->assertSame([$gone, $gone], array_column($d['rows'], 'organisation'), 'the identity is not this organisation\'s, so it is as good as removed');
        $this->assertStringNotContainsString('Their School', json_encode($d), "the other organisation's name, even through a stray pointer");
    }

    public function test_a_crm_pointer_into_another_organisations_record_gives_no_link(): void
    {
        $this->runAgent($this->green, 'one');
        DB::table('g2g_org_accounts')->where('id', $this->green['identity'])->update(['crm_organization_id' => $this->theirs['crm']]);

        $organisation = $this->queue()['rows'][0]['organisation'];

        $this->assertSame(['id' => $this->green['identity'], 'name' => 'Greenfield', 'href_hint' => null, 'removed' => false], $organisation, "the other organisation's CRM record is not a page this one may be sent to");
    }

    public function test_the_decided_by_name_is_never_a_person_of_another_organisation(): void
    {
        $this->runAgent($this->green, 'one');
        $stranger = $this->editor(self::T2, 'Mallory', 'Villain');
        [$id] = $this->pendingIds();
        DB::table('g2g_agent_proposals')->where('id', $id)->update(['status' => 'dismissed', 'decided_by' => $stranger['id'], 'decided_at' => now()]);

        $row = $this->queue('?status=dismissed')['rows'][0];

        $this->assertNull($row['decided_by_name']);
        $this->assertStringNotContainsString('Mallory', json_encode($row));
    }

    // ---- rights ----

    public function test_reading_needs_a_token_and_the_view_right_on_the_run_history_and_deciding_is_told_apart_from_reading(): void
    {
        $this->runAgent($this->green, 'one');
        $this->getJson(self::P . '/proposals')->assertStatus(401);

        $this->getJson(self::P . '/proposals', $this->user([])['h'])->assertStatus(403);
        $this->getJson(self::P . '/proposals', $this->user([self::ORG_LINK => ['view', 'edit']])['h'])->assertStatus(403);

        // history view only: may read the queue, may not decide: the buttons are not theirs
        $reader = $this->user([self::HISTORY_LINK => ['view']]);
        $this->assertSame([false, false], array_column(array_column($this->queue('', $reader)['rows'], 'can'), 'decide'));
        // organisation edit on top: may decide
        $both = $this->user([self::HISTORY_LINK => ['view'], self::ORG_LINK => ['view', 'edit']]);
        $this->assertSame([true, true], array_column(array_column($this->queue('', $both)['rows'], 'can'), 'decide'));
        // organisation view only (not edit) plus history: may not
        $viewOrgs = $this->user([self::HISTORY_LINK => ['view'], self::ORG_LINK => ['view']]);
        $this->assertSame([false, false], array_column(array_column($this->queue('', $viewOrgs)['rows'], 'can'), 'decide'));

        // and the reader cannot decide through the existing routes either
        [$id] = $this->pendingIds();
        $this->postJson(self::P . "/proposals/{$id}/accept", [], $reader['h'])->assertStatus(403);
        $this->postJson(self::P . "/proposals/{$id}/dismiss", [], $reader['h'])->assertStatus(403);
        $this->assertCount(2, $this->pendingIds());
    }

    // ---- deciding from the queue ----

    public function test_a_decision_from_the_queue_uses_the_existing_accept_and_dismiss_and_the_row_leaves_the_queue(): void
    {
        $this->runAgent($this->green, 'one');
        $note = collect($this->queue()['rows'])->firstWhere('action', 'add_note');
        $task = collect($this->queue()['rows'])->firstWhere('action', 'add_task');

        $this->postJson(self::P . "/proposals/{$note['id']}/accept", [], $this->editor['h'])->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->postJson(self::P . "/proposals/{$task['id']}/dismiss", ['reason' => 'Already called'], $this->editor['h'])->assertOk();

        $this->assertSame([], $this->queue()['rows']);
        $this->assertSame(0, $this->queue()['pagination']['total']);
        $this->assertSame('[Agent] Note one', DB::table('crm_organization_comments')->value('comment'), 'written by the same applier, as the person');
        $this->assertSame(1, DB::table('crm_organization_comments')->count());
        $this->assertSame(0, DB::table('task_management_calendar_events')->count(), 'a dismissed task wrote nothing');
    }

    public function test_two_clicks_on_the_same_row_write_once(): void
    {
        $this->runAgent($this->green, 'one');
        $note = collect($this->queue()['rows'])->firstWhere('action', 'add_note');

        $first = $this->postJson(self::P . "/proposals/{$note['id']}/accept", [], $this->editor['h'])->assertOk();
        $second = $this->postJson(self::P . "/proposals/{$note['id']}/accept", [], $this->editor['h'])->assertOk();

        $this->assertSame(['applied', 'already_applied'], [$first->json('data.outcome'), $second->json('data.outcome')]);
        $this->assertSame(1, DB::table('crm_organization_comments')->count());
    }

    public function test_the_queue_is_read_only_and_adds_no_second_way_to_decide(): void
    {
        $this->runAgent($this->green, 'one');
        $before = $this->everything();

        $this->queue();
        $this->queue('?status=applied&per_page=3');
        $this->queue('', $this->user([self::HISTORY_LINK => ['view']]));

        $this->assertSame($before, $this->everything());
        // the only routes that touch /proposals are this list and the two decisions; the list answers GET only
        $this->postJson(self::P . '/proposals', [], $this->editor['h'])->assertStatus(405);
        $this->putJson(self::P . '/proposals', [], $this->editor['h'])->assertStatus(405);
        $this->deleteJson(self::P . '/proposals', [], $this->editor['h'])->assertStatus(405);
    }

    public function test_the_presenter_builds_the_row_from_one_batch_of_lookups_not_one_query_per_row(): void
    {
        foreach (range(1, 12) as $n) {
            DB::table('g2g_agent_proposals')->insert($this->row(self::T, $this->green['identity'], "Batch {$n}"));
        }
        DB::connection()->enableQueryLog();

        $this->queue('?per_page=12');

        $queries = array_filter(array_column(DB::connection()->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'g2g_org_accounts') || str_contains($q, 'crm_organizations'));
        $this->assertLessThanOrEqual(2, count($queries), 'the organisations of the whole page, and their CRM records: one query each, not one per row');
    }

    /** @return array<string, mixed> a waiting proposal row, for tests that need many of them without a model call */
    private function row(int $tenant, int $subject, string $text): array
    {
        return [
            'sub_institute_id' => $tenant, 'run_id' => 1, 'subject_type' => 'org_account', 'subject_id' => $subject, 'action' => 'add_note', 'entity_type' => 'organization', 'entity_id' => 1,
            'payload' => json_encode(['text' => $text]), 'payload_hash' => sha1($text . $tenant), 'evidence' => '[]', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
