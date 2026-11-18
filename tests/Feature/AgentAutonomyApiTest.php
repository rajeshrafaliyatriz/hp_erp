<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\AgentRefused;
use App\Domain\AgentGateway\AutonomyPolicy;
use App\Domain\AgentGateway\AutonomySettings;
use App\Services\Events\EventCatalogue;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The autonomy settings API (S7b): GET /api/agent/autonomy, PUT /api/agent/autonomy/{actionKey} and PUT /api/agent/autonomy-kill-switch, with real tokens,
 * real rights rows and the real migrations on in-memory sqlite. The model is the fake of the other agent tests, so a level changed here is proved by what
 * the next RUN does with it, not by what a row says.
 *
 * The cross-tenant sweep of these routes is in AgentGatewayTenantSweepTest; the waiting queue is AgentProposalQueueTest.
 */
class AgentAutonomyApiTest extends TestCase
{
    use BuildsAgentWorld;

    private const P = '/api/agent';

    private const ACTION_KEYS = ['key', 'label', 'description', 'default', 'level', 'overridden', 'levels', 'counts', 'last_changed'];

    private const COUNT_KEYS = ['proposed', 'applied', 'accepted', 'dismissed', 'failed'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    // ---- helpers ----

    /** An HR manager who holds EDIT on Agent Run History (as profile 18 does on tenant 6): the edit right alone must not be enough to change a level. */
    private function hr(int $tenant = self::T): array
    {
        return $this->userWithRole('hr_manager', [self::HISTORY_LINK => ['view', 'edit'], self::ORG_LINK => ['view', 'edit']], $tenant, 'Hema', 'Rao');
    }

    /** A profile with NO role_key whose name is exactly "Admin": the legacy shape on most of the app host's profiles. */
    private function legacyAdmin(string $profileName = 'Admin'): array
    {
        $user = $this->user([self::HISTORY_LINK => ['view', 'edit']], self::T, 'Legacy', 'Admin');
        $profile = (int) DB::table('tbluser')->where('id', $user['id'])->value('user_profile_id');
        DB::table('tbluserprofilemaster')->where('id', $profile)->update(['role_key' => null, 'name' => $profileName]);
        RoleKey::flushCache();

        return $user;
    }

    /** @return array<string, string> link => level of the organisation's own rows, in key order */
    private function ownRows(int $tenant = self::T): array
    {
        return DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenant)->orderBy('action_key')->pluck('level', 'action_key')->all();
    }

    /** @return list<array<string, mixed>> */
    private function everything(): array
    {
        return [
            'rows' => DB::table('g2g_agent_autonomy')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'calls' => DB::table('g2g_agent_tool_calls')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'events' => DB::table('g2g_event')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @return list<object> the audit rows of settings changes (they belong to no run: run 0) */
    private function audits(int $tenant = self::T): array
    {
        return DB::table('g2g_agent_tool_calls')->where('sub_institute_id', $tenant)->where('run_id', 0)->orderBy('id')->get()->all();
    }

    /** @return list<array{type: string, actor: ?int, key: ?string, entity: ?int, payload: array<string, mixed>}> */
    private function events(int $tenant = self::T): array
    {
        return DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', 'agent.autonomy.changed')->orderBy('id')->get()
            ->map(fn ($e) => ['type' => $e->type, 'actor' => $e->actor_id !== null ? (int) $e->actor_id : null, 'key' => $e->idempotency_key, 'entity' => $e->entity_id !== null ? (int) $e->entity_id : null,
                'payload' => json_decode((string) $e->payload, true)])->all();
    }

    private function proposal(int $tenant, string $action, string $status, ?string $via = null, int $daysAgo = 1): int
    {
        return (int) DB::table('g2g_agent_proposals')->insertGetId([
            'sub_institute_id' => $tenant, 'run_id' => 1, 'subject_type' => 'org_account', 'subject_id' => 1, 'action' => $action, 'entity_type' => 'organization', 'entity_id' => 1,
            'payload' => json_encode(['text' => 'n' . uniqid()]), 'payload_hash' => sha1(uniqid('', true)), 'evidence' => '[]', 'status' => $status, 'applied_via' => $via,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now(),
        ]);
    }

    private function state(array $user): array
    {
        return $this->getJson(self::P . '/autonomy', $user['h'])->assertOk()->json('data');
    }

    /** @param array<string, mixed> $state */
    private function action(array $state, string $key): array
    {
        return collect($state['actions'])->firstWhere('key', $key);
    }

    // ---- reading ----

    public function test_the_state_lists_every_action_in_words_with_its_default_its_counters_and_whether_the_caller_may_change_it(): void
    {
        $admin = $this->administrator();

        $res = $this->getJson(self::P . '/autonomy', $admin['h'])->assertOk();
        $d = $res->json('data');

        $this->assertSame(['kill_switch', 'actions', 'can'], array_keys($d));
        $this->assertSame(['on' => false, 'scope' => null], $d['kill_switch']);
        $this->assertSame(['change' => true], $d['can']);
        $this->assertSame(['add_note', 'add_task', 'confirm_link'], array_column($d['actions'], 'key'), 'the config\'s order, and only the real tools');
        foreach ($d['actions'] as $a) {
            $this->assertSame(self::ACTION_KEYS, array_keys($a), $a['key']);
            $this->assertSame(['off', 'suggest', 'draft', 'approval', 'alone'], $a['levels']);
            $this->assertSame(self::COUNT_KEYS, array_keys($a['counts']));
            $this->assertSame(array_fill(0, 5, 0), array_values($a['counts']), 'no run yet: every counter is 0, never null');
            $this->assertNull($a['last_changed']);
            $this->assertFalse($a['overridden']);
            $this->assertSame($a['default'], $a['level']);
            $this->assertNotSame($a['key'], $a['label'], 'plain words, not the key');
            $this->assertNotSame('', $a['description']);
        }
        $this->assertSame(['alone', 'alone', 'approval'], array_column($d['actions'], 'level'), "the owner's standing default");
        $this->assertSame('Add a note', $this->action($d, 'add_note')['label']);
    }

    public function test_every_action_in_the_config_has_its_words(): void
    {
        foreach (array_keys(config('agent_actions.actions')) as $key) {
            $this->assertNotSame('', (string) config("agent_actions.labels.{$key}.label"), "{$key} needs a label");
            $this->assertNotSame('', (string) config("agent_actions.labels.{$key}.description"), "{$key} needs a description");
        }
        $this->assertSame([], array_diff(array_keys(config('agent_actions.labels')), array_keys(config('agent_actions.actions'))), 'wording for an action that does not exist');
    }

    public function test_the_counters_are_the_last_30_days_of_this_organisations_proposals_and_nobody_elses(): void
    {
        $admin = $this->administrator();
        // add_note, this organisation, inside the window: 3 applied alone, 2 accepted by a person, 1 applied with no recorded way, 2 dismissed, 1 failed, 1 waiting
        foreach (range(1, 3) as $_) { $this->proposal(self::T, 'add_note', 'applied', 'policy'); }
        foreach (range(1, 2) as $_) { $this->proposal(self::T, 'add_note', 'applied', 'user'); }
        $this->proposal(self::T, 'add_note', 'applied', null);
        foreach (range(1, 2) as $_) { $this->proposal(self::T, 'add_note', 'dismissed'); }
        $this->proposal(self::T, 'add_note', 'failed');
        $this->proposal(self::T, 'add_note', 'pending');
        $this->proposal(self::T, 'add_note', 'pending', null, 29);          // still inside the window
        // outside the window: not counted
        foreach (range(1, 4) as $_) { $this->proposal(self::T, 'add_note', 'applied', 'policy', 31); }
        $this->proposal(self::T, 'add_note', 'dismissed', null, 90);
        // another organisation's rows, the same action: never counted here
        foreach (range(1, 6) as $_) { $this->proposal(self::T2, 'add_note', 'applied', 'user'); }
        $this->proposal(self::T2, 'add_note', 'failed');
        // another action of this organisation, and a row for something that is not an action
        $this->proposal(self::T, 'add_task', 'dismissed');
        $this->proposal(self::T, 'send_email', 'applied', 'policy');

        $d = $this->state($admin);

        $this->assertSame(['proposed' => 11, 'applied' => 6, 'accepted' => 2, 'dismissed' => 2, 'failed' => 1], $this->action($d, 'add_note')['counts']);
        $this->assertSame(['proposed' => 1, 'applied' => 0, 'accepted' => 0, 'dismissed' => 1, 'failed' => 0], $this->action($d, 'add_task')['counts']);
        $this->assertSame(array_fill_keys(self::COUNT_KEYS, 0), $this->action($d, 'confirm_link')['counts']);
        $this->assertNotContains('send_email', array_column($d['actions'], 'key'));

        $theirs = $this->state($this->administrator(self::T2));
        $this->assertSame(['proposed' => 7, 'applied' => 6, 'accepted' => 6, 'dismissed' => 0, 'failed' => 1], $this->action($theirs, 'add_note')['counts'], 'and theirs are theirs');
    }

    public function test_the_kill_switch_is_reported_with_its_scope_and_the_shared_one_wins_when_both_are_set(): void
    {
        $admin = $this->administrator();
        $set = fn (int $tenant) => DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => $tenant, 'action_key' => '*', 'level' => 'off']);

        $this->assertSame(['on' => false, 'scope' => null], $this->state($admin)['kill_switch']);

        $set(self::T2);
        $this->assertSame(['on' => false, 'scope' => null], $this->state($admin)['kill_switch'], "another organisation's switch is not this one's");

        $set(self::T);
        $this->assertSame(['on' => true, 'scope' => 'organisation'], $this->state($admin)['kill_switch']);

        $set(0);
        $this->assertSame(['on' => true, 'scope' => 'platform'], $this->state($admin)['kill_switch'], 'the shared switch cannot be changed from a screen, and it wins');

        DB::table('g2g_agent_autonomy')->where('sub_institute_id', self::T)->delete();
        $this->assertSame(['on' => true, 'scope' => 'platform'], $this->state($admin)['kill_switch']);
        DB::table('g2g_agent_autonomy')->where('sub_institute_id', 0)->update(['level' => 'alone']);
        $this->assertSame(['on' => false, 'scope' => null], $this->state($admin)['kill_switch'], "a '*' row that is not 'off' is not a switch");
    }

    public function test_the_screen_shows_the_setting_even_while_the_agent_is_switched_off(): void
    {
        $admin = $this->administrator();
        DB::table('g2g_agent_autonomy')->insert([
            ['sub_institute_id' => self::T, 'action_key' => 'add_note', 'level' => 'approval'],
            ['sub_institute_id' => self::T, 'action_key' => '*', 'level' => 'off'],
        ]);

        $note = $this->action($this->state($admin), 'add_note');

        $this->assertSame(['approval', true], [$note['level'], $note['overridden']], 'the configured level, not the "off" the kill switch imposes');
        $this->assertSame('off', (new AutonomyPolicy())->level(self::T, 'add_note'), 'while the agent itself is stopped');
    }

    public function test_a_level_set_for_everybody_is_the_default_of_an_organisation_with_none_of_its_own(): void
    {
        $admin = $this->administrator();
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => 0, 'action_key' => 'add_task', 'level' => 'approval']);

        $task = $this->action($this->state($admin), 'add_task');
        $this->assertSame(['approval', 'approval', false], [$task['default'], $task['level'], $task['overridden']]);

        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'alone'], $admin['h'])->assertOk();
        $task = $this->action($this->state($admin), 'add_task');
        $this->assertSame(['approval', 'alone', true], [$task['default'], $task['level'], $task['overridden']], "config says alone, but the default HERE is the shared row's: 'alone' is a choice");
        $this->assertSame(['add_task' => 'alone'], $this->ownRows());

        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'approval'], $admin['h'])->assertOk()->assertJsonPath('data.overridden', false);
        $this->assertSame([], $this->ownRows(), "equal to the default of THIS organisation (the shared row's) removes the row");
        $this->assertSame('approval', (new AutonomyPolicy())->level(self::T, 'add_task'));
    }

    public function test_who_last_changed_a_level_and_when_comes_from_the_trail_the_row_or_whichever_is_newer(): void
    {
        $admin = $this->administrator();
        $other = $this->editor(self::T, 'Olive', 'Other');
        $stranger = $this->editor(self::T2, 'Mallory', 'Villain');

        // a change through the API: by the administrator, and still shown after the level went back to its default (no row is left then)
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk();
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'alone'], $admin['h'])->assertOk();
        $this->assertSame([], $this->ownRows());
        $note = $this->action($this->state($admin), 'add_note');
        $this->assertSame('Ada Admin', $note['last_changed']['by_name']);
        $this->assertEqualsWithDelta(now()->timestamp, Carbon::parse($note['last_changed']['at'])->timestamp, 5);
        $this->assertNull($this->action($this->state($admin), 'add_task')['last_changed'], 'an action nobody changed');

        // a setting written by hand has no event: its own row says who and when
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => 'add_task', 'level' => 'approval', 'updated_by' => $other['id'], 'updated_at' => now()]);
        $this->assertSame('Olive Other', $this->action($this->state($admin), 'add_task')['last_changed']['by_name']);

        // the newer of the two wins: a hand edit AFTER the event, and the event after an older hand edit
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => 'add_note', 'level' => 'off', 'updated_by' => $other['id'], 'updated_at' => now()->addHour()]);
        $this->assertSame('Olive Other', $this->action($this->state($admin), 'add_note')['last_changed']['by_name'], 'a later hand edit is the last change');
        DB::table('g2g_agent_autonomy')->where('action_key', 'add_note')->update(['updated_at' => now()->subYear()]);
        $this->assertSame('Ada Admin', $this->action($this->state($admin), 'add_note')['last_changed']['by_name'], 'an older hand edit is not');

        // a person of ANOTHER organisation is never named, and another organisation's changes are not this one's
        $this->putJson(self::P . '/autonomy/confirm_link', ['level' => 'off'], $this->administrator(self::T2)['h'])->assertOk();
        $this->assertNull($this->action($this->state($admin), 'confirm_link')['last_changed'], "another organisation's change");
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => 'confirm_link', 'level' => 'off', 'updated_by' => $stranger['id'], 'updated_at' => now()]);
        $change = $this->action($this->state($admin), 'confirm_link')['last_changed'];
        $this->assertNotNull($change['at']);
        $this->assertNull($change['by_name'], 'no name for somebody of another organisation');
    }

    public function test_the_newest_change_is_the_one_that_names_who_changed_a_level_last(): void
    {
        $ada = $this->administrator(self::T, 'Ada', 'Admin');
        $bo = $this->administrator(self::T, 'Bo', 'Boss');

        // both changes end at the default, so no row is left: only the trail can answer, and it must answer with the newest event
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $ada['h'])->assertOk();
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'alone'], $bo['h'])->assertOk();

        $this->assertSame([], $this->ownRows());
        $this->assertSame('Bo Boss', $this->action($this->state($ada), 'add_note')['last_changed']['by_name']);
    }

    public function test_a_config_entry_that_is_not_a_tool_is_not_an_action(): void
    {
        $admin = $this->administrator();
        config(['agent_actions.actions.send_email' => 'alone', 'agent_actions.labels.send_email' => ['label' => 'Send an email', 'description' => 'Sends it.']]);
        $before = $this->everything();

        $this->assertSame(['add_note', 'add_task', 'confirm_link'], array_column($this->state($admin)['actions'], 'key'), 'only what the code can do is listed');
        $this->putJson(self::P . '/autonomy/send_email', ['level' => 'alone'], $admin['h'])->assertStatus(404)->assertJson(['code' => 'unknown_action']);
        $this->assertSame($before, $this->everything());
    }

    public function test_reading_needs_a_token_and_the_view_right_on_the_run_history_and_the_edit_buttons_follow_the_administrator_role(): void
    {
        $this->getJson(self::P . '/autonomy')->assertStatus(401);

        $nobody = $this->user([]);
        $orgOnly = $this->user([self::ORG_LINK => ['view', 'edit']]);
        $this->getJson(self::P . '/autonomy', $nobody['h'])->assertStatus(403);
        $this->getJson(self::P . '/autonomy', $orgOnly['h'])->assertStatus(403);

        $viewer = $this->user([self::HISTORY_LINK => ['view']]);
        $this->assertSame(['change' => false], $this->getJson(self::P . '/autonomy', $viewer['h'])->assertOk()->json('data.can'), 'view only');
        $this->assertSame(['change' => false], $this->getJson(self::P . '/autonomy', $this->hr()['h'])->assertOk()->json('data.can'), 'edit on the history row is not enough: HR');
        $adminWithoutEdit = $this->userWithRole('administrator', [self::HISTORY_LINK => ['view']]);
        $this->assertSame(['change' => false], $this->getJson(self::P . '/autonomy', $adminWithoutEdit['h'])->assertOk()->json('data.can'), 'an administrator whose row lacks edit');
        $this->assertSame(['change' => true], $this->getJson(self::P . '/autonomy', $this->administrator()['h'])->assertOk()->json('data.can'));
    }

    public function test_reading_changes_nothing(): void
    {
        $this->proposal(self::T, 'add_note', 'pending');
        $before = $this->everything();

        $this->state($this->administrator());
        $this->state($this->hr());

        $this->assertSame($before, $this->everything());
    }

    // ---- changing a level ----

    public function test_an_administrator_sets_a_level_and_gets_the_action_back_with_the_change_audited_and_recorded(): void
    {
        $admin = $this->administrator();

        $res = $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval', 'reason' => 'We want to read them first.'], $admin['h'])->assertOk();

        $a = $res->json('data');
        $this->assertSame(self::ACTION_KEYS, array_keys($a), 'the same action object the state lists');
        $this->assertSame(['add_note', 'alone', 'approval', true], [$a['key'], $a['default'], $a['level'], $a['overridden']]);
        $this->assertSame('Ada Admin', $a['last_changed']['by_name']);
        $this->assertSame($a, $this->action($this->state($admin), 'add_note'), 'the answer IS the action as the next GET lists it');

        $row = DB::table('g2g_agent_autonomy')->first();
        $this->assertSame([self::T, 'add_note', 'approval', $admin['id']], [(int) $row->sub_institute_id, $row->action_key, $row->level, (int) $row->updated_by]);
        $this->assertNotNull($row->updated_at);

        // the audit: one row, belonging to no run, saying who changed what from what to what and why
        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame(['set_autonomy', 'applied', 0, 'g2g_agent_autonomy:' . $row->id], [$audits[0]->tool, $audits[0]->outcome, (int) $audits[0]->run_id, $audits[0]->result_ref]);
        $this->assertSame(['action' => 'add_note', 'from' => 'alone', 'to' => 'approval', 'reason' => 'We want to read them first.', 'by' => $admin['id']], json_decode($audits[0]->args, true));

        // the event: in the platform's store, by the administrator, with its own key
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['agent.autonomy.changed', $admin['id'], (int) $row->id], [$events[0]['type'], $events[0]['actor'], $events[0]['entity']]);
        $this->assertSame(['action' => 'add_note', 'from' => 'alone', 'to' => 'approval', 'reason' => 'We want to read them first.'], $events[0]['payload']);
        $this->assertSame('agent.autonomy.changed:' . self::T . ':' . $audits[0]->id, $events[0]['key']);
    }

    public function test_every_change_is_its_own_event_even_for_the_same_setting_changed_back_and_forth(): void
    {
        $admin = $this->administrator();

        foreach (['approval', 'alone', 'off', 'alone', 'approval'] as $level) {
            $this->putJson(self::P . '/autonomy/add_note', ['level' => $level], $admin['h'])->assertOk();
        }

        $events = $this->events();
        $this->assertCount(5, $events, 'a key naming the setting would have kept only the first');
        $this->assertSame(['alone>approval', 'approval>alone', 'alone>off', 'off>alone', 'alone>approval'], array_map(fn ($e) => $e['payload']['from'] . '>' . $e['payload']['to'], $events));
        $this->assertCount(5, array_unique(array_column($events, 'key')));
        $this->assertCount(5, $this->audits());
        $this->assertSame(['add_note' => 'approval'], $this->ownRows());
    }

    public function test_a_level_equal_to_the_default_removes_the_organisations_own_setting_and_nothing_else_does(): void
    {
        $admin = $this->administrator();
        $this->putJson(self::P . '/autonomy/confirm_link', ['level' => 'draft'], $admin['h'])->assertOk();
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $admin['h'])->assertOk();
        $this->assertSame(['add_note' => 'off', 'confirm_link' => 'draft'], $this->ownRows());

        $res = $this->putJson(self::P . '/autonomy/add_note', ['level' => 'alone'], $admin['h'])->assertOk();

        $this->assertSame(['alone', 'alone', false], [$res->json('data.default'), $res->json('data.level'), $res->json('data.overridden')]);
        $this->assertSame(['confirm_link' => 'draft'], $this->ownRows(), 'only that row went');
        $last = array_slice($this->events(), -1)[0];
        $this->assertSame(['off', 'alone'], [$last['payload']['from'], $last['payload']['to']], 'and the removal is a change like any other');
        $this->assertNotNull($last['entity'], 'pointing at the row that was removed');
    }

    public function test_a_request_that_changes_nothing_writes_nothing(): void
    {
        $admin = $this->administrator();
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk();
        $before = $this->everything();

        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk()->assertJsonPath('data.level', 'approval');
        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'alone'], $admin['h'])->assertOk()->assertJsonPath('data.overridden', false);

        $this->assertSame($before, $this->everything(), 'the same level again, and the default where there is no setting: no row, no audit, no event');
    }

    public function test_the_refusals_each_have_their_status_and_write_nothing(): void
    {
        $admin = $this->administrator();
        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'approval'], $admin['h'])->assertOk();
        $before = $this->everything();
        $put = fn (string $key, array $body) => $this->putJson(self::P . "/autonomy/{$key}", $body, $admin['h']);

        // a key that is not an action: there is no way to create one, and the kill switch's own key is not an action either
        foreach (['send_email', 'delete_organization', 'ADD_NOTE', 'add_note%20', 'add-note', '%2A', '*', '0', str_repeat('a', 300)] as $key) {
            $put($key, ['level' => 'alone'])->assertStatus(404)->assertJson(['status' => 0, 'code' => 'unknown_action']);
        }
        // a level that is not a level
        foreach (['turbo', 'ALONE', 'Approval', '', '*', 'on', 'alone;'] as $level) {
            $res = $put('add_note', ['level' => $level]);
            $this->assertSame(422, $res->getStatusCode(), "level '{$level}'");
        }
        $put('add_note', [])->assertStatus(422);
        $put('add_note', ['level' => ['alone']])->assertStatus(422);
        $put('add_note', ['level' => 'alone', 'reason' => str_repeat('r', 301)])->assertStatus(422)->assertJsonPath('message', 'A reason can have at most 300 characters.');
        $put('add_note', ['level' => 'alone', 'reason' => ['x']])->assertStatus(422);
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'turbo'], $admin['h'])->assertJson(['status' => 0, 'code' => 'unknown_level']);

        $this->assertSame($before, $this->everything(), 'not a row, an audit or an event');
        $this->assertSame(['add_task' => 'approval'], $this->ownRows());
    }

    public function test_a_reason_is_kept_as_plain_text_and_is_optional(): void
    {
        $admin = $this->administrator();

        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off', 'reason' => "  <b>Too</b>   chatty <script>alert(1)</script>\n"], $admin['h'])->assertOk();
        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'off'], $admin['h'])->assertOk();

        $events = $this->events();
        $this->assertSame('Too chatty alert(1)', $events[0]['payload']['reason']);
        $this->assertArrayNotHasKey('reason', $events[1]['payload']);
        $this->assertArrayNotHasKey('reason', json_decode($this->audits()[1]->args, true));
    }

    // ---- who may change ----

    public function test_only_an_administrator_changes_a_level_not_a_role_that_merely_holds_the_edit_right(): void
    {
        $this->administrator();
        $before = $this->everything();

        // HR holds EDIT on the history row (the middleware lets the request in); the controller turns it away
        $res = $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $this->hr()['h'])->assertStatus(403);
        $res->assertJson(['status' => 0, 'code' => 'administrators_only']);
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $this->hr()['h'])->assertStatus(403)->assertJson(['code' => 'administrators_only']);

        // without the edit right the middleware stops it, administrator or not
        $viewOnly = $this->userWithRole('administrator', [self::HISTORY_LINK => ['view']]);
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $viewOnly['h'])->assertStatus(403)->assertJsonMissing(['code' => 'administrators_only']);
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $this->user([])['h'])->assertStatus(403);
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $this->editor()['h'])->assertStatus(403);
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'])->assertStatus(401);

        // nothing was written for any of them, and a refused request is refused BEFORE its key and level are even looked at
        $this->assertSame($before, $this->everything());
        $this->putJson(self::P . '/autonomy/no_such_action', ['level' => 'turbo'], $this->hr()['h'])->assertStatus(403);
    }

    public function test_the_administrator_is_found_through_the_role_resolution_not_a_raw_role_key(): void
    {
        $legacy = $this->legacyAdmin();
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $legacy['h'])->assertOk();
        $this->assertSame(['add_note' => 'off'], $this->ownRows(), "role_key is NULL on the profile; its exact legacy name 'Admin' resolves to administrator");

        $assistant = $this->legacyAdmin('Admin Assistant');
        $this->putJson(self::P . '/autonomy/add_task', ['level' => 'off'], $assistant['h'])->assertStatus(403);
        $this->assertSame(['add_note' => 'off'], $this->ownRows(), 'a profile with "admin" inside its name is not an administrator');
        $this->assertSame(['change' => true], $this->getJson(self::P . '/autonomy', $legacy['h'])->json('data.can'));
        $this->assertSame(['change' => false], $this->getJson(self::P . '/autonomy', $assistant['h'])->json('data.can'));
    }

    public function test_an_administrator_of_another_organisation_changes_that_organisations_settings_and_not_this_ones(): void
    {
        $theirs = $this->administrator(self::T2, 'Tara', 'Other');

        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $theirs['h'])->assertOk();

        $this->assertSame([], $this->ownRows(self::T));
        $this->assertSame(['add_note' => 'off'], $this->ownRows(self::T2));
        $this->assertSame([], $this->events(self::T));
        $this->assertCount(1, $this->events(self::T2));
        $this->assertSame('alone', (new AutonomyPolicy())->level(self::T, 'add_note'));
    }

    // ---- the kill switch ----

    public function test_the_kill_switch_stops_a_run_and_a_decision_and_switching_it_back_on_restores_them(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $admin = $this->administrator();
        $editor = $this->editor();
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];
        $answer = fn (string $text) => ['brief' => 'b', 'brief_evidence' => $signal, 'proposals' => [['action' => 'add_note', 'text' => $text, 'reason' => 'r', 'evidence' => $signal]]];
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk();
        $this->model->respond = $answer('Waiting note.');
        $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $editor['h'])->assertStatus(201);
        $waiting = (int) DB::table('g2g_agent_proposals')->where('status', 'pending')->value('id');
        $this->assertCount(1, $this->model->calls);

        $res = $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true, 'reason' => 'Pausing while we review.'], $admin['h'])->assertOk();

        $this->assertSame(['kill_switch' => ['on' => true, 'scope' => 'organisation']], $res->json('data'));
        $this->assertSame(['*' => 'off', 'add_note' => 'approval'], $this->ownRows());
        $this->assertSame(['on' => true, 'scope' => 'organisation'], $this->state($admin)['kill_switch']);
        $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $editor['h'])->assertStatus(403)->assertJson(['code' => 'agent_off']);
        $this->postJson(self::P . "/proposals/{$waiting}/accept", [], $editor['h'])->assertStatus(409)->assertJson(['code' => 'agent_off']);
        $this->assertCount(1, $this->model->calls, 'the model was not asked again');
        $this->assertSame(0, DB::table('crm_organization_comments')->count(), 'and nothing was written');
        $this->assertSame('approval', $this->action($this->state($admin), 'add_note')['level'], 'the level is still what it was: only the agent is stopped');

        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => false], $admin['h'])->assertOk()->assertJsonPath('data.kill_switch', ['on' => false, 'scope' => null]);

        $this->assertSame(['add_note' => 'approval'], $this->ownRows());
        $this->postJson(self::P . "/proposals/{$waiting}/accept", [], $editor['h'])->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->model->respond = $answer('Another note.');
        $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $editor['h'])->assertStatus(201);
        $this->assertCount(2, $this->model->calls);
    }

    public function test_the_kill_switch_is_audited_and_recorded_like_a_level_with_a_key_of_its_own_each_time(): void
    {
        $admin = $this->administrator();

        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true, 'reason' => 'Review'], $admin['h'])->assertOk();
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => false], $admin['h'])->assertOk();
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $admin['h'])->assertOk();

        $events = $this->events();
        $this->assertSame(['running>stopped', 'stopped>running', 'running>stopped'], array_map(fn ($e) => $e['payload']['from'] . '>' . $e['payload']['to'], $events));
        $this->assertSame(['*', '*', '*'], array_column(array_column($events, 'payload'), 'action'));
        $this->assertSame('Review', $events[0]['payload']['reason']);
        $this->assertCount(3, array_unique(array_column($events, 'key')));
        $audits = $this->audits();
        $this->assertCount(3, $audits);
        $this->assertSame(['set_kill_switch', 'set_kill_switch', 'set_kill_switch'], array_column($audits, 'tool'));
        $this->assertSame([$admin['id']], array_values(array_unique(array_column($events, 'actor'))));
        $this->assertNotContains('*', array_column($this->state($admin)['actions'], 'key'), 'the switch is not one of the actions');
    }

    public function test_the_kill_switch_only_ever_touches_this_organisations_own_row_never_the_shared_one(): void
    {
        $admin = $this->administrator();
        DB::table('g2g_agent_autonomy')->insert([
            ['sub_institute_id' => 0, 'action_key' => '*', 'level' => 'off', 'updated_by' => 77, 'updated_at' => '2026-01-01 00:00:00'],
            ['sub_institute_id' => self::T2, 'action_key' => '*', 'level' => 'off', 'updated_by' => 78, 'updated_at' => '2026-01-02 00:00:00'],
        ]);
        $shared = fn () => [DB::table('g2g_agent_autonomy')->where('sub_institute_id', 0)->get()->map(fn ($r) => (array) $r)->all(), DB::table('g2g_agent_autonomy')->where('sub_institute_id', self::T2)->get()->map(fn ($r) => (array) $r)->all()];
        $before = $shared();

        $on = $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $admin['h'])->assertOk()->json('data.kill_switch');
        $this->assertSame(['on' => true, 'scope' => 'platform'], $on, 'the shared switch is on: that is what the screen has to say');
        $this->assertSame(['*' => 'off'], $this->ownRows(), "the organisation's own row was written");
        $this->assertSame($before, $shared());

        $off = $this->putJson(self::P . '/autonomy-kill-switch', ['on' => false], $admin['h'])->assertOk()->json('data.kill_switch');
        $this->assertSame(['on' => true, 'scope' => 'platform'], $off, "switching its own off cannot bring back an agent the platform stopped");
        $this->assertSame([], $this->ownRows());
        $this->assertSame($before, $shared(), "row 0 and the other organisation's row are exactly as they were");

        // and with no organisation at all, nothing can be changed: the call that would have reached row 0 is refused
        $settings = app(AutonomySettings::class);
        foreach ([fn () => $settings->setKillSwitch(0, $admin['id'], false), fn () => $settings->setKillSwitch(0, $admin['id'], true), fn () => $settings->setLevel(0, $admin['id'], 'add_note', 'off')] as $call) {
            try {
                $call();
                $this->fail('an organisation of 0 is the shared row: it must be refused');
            } catch (AgentRefused $e) {
                $this->assertSame(['no_organisation', 403], [$e->reason, $e->status]);
            }
        }
        $this->assertSame($before, $shared());
        $this->assertSame([], $this->events(0));
    }

    public function test_a_kill_switch_request_that_changes_nothing_writes_nothing_and_a_row_that_is_not_a_switch_is_left_alone(): void
    {
        $admin = $this->administrator();
        $before = $this->everything();

        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => false], $admin['h'])->assertOk()->assertJsonPath('data.kill_switch.on', false);
        $this->assertSame($before, $this->everything(), 'it was not on');

        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => '*', 'level' => 'alone']);
        $stray = $this->everything();
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => false], $admin['h'])->assertOk();
        $this->assertSame($stray, $this->everything(), "a '*' row that is not 'off' is no switch: switching off is nothing to do");

        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $admin['h'])->assertOk()->assertJsonPath('data.kill_switch.on', true);
        $this->assertSame(['*' => 'off'], $this->ownRows(), 'switching on turns that row into the switch (the unique key allows one row)');
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $admin['h'])->assertOk();
        $this->assertCount(1, $this->events(), 'once');
    }

    public function test_the_kill_switch_body_must_say_on_or_off(): void
    {
        $admin = $this->administrator();
        $before = $this->everything();

        $this->putJson(self::P . '/autonomy-kill-switch', [], $admin['h'])->assertStatus(422);
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => 'yes'], $admin['h'])->assertStatus(422);
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => ['true']], $admin['h'])->assertStatus(422);
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true, 'reason' => str_repeat('r', 301)], $admin['h'])->assertStatus(422);
        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], [])->assertStatus(401);

        $this->assertSame($before, $this->everything());
    }

    // ---- the whole point: what a run does ----

    public function test_a_level_changed_through_the_api_is_what_the_agent_then_applies(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 2]);
        $admin = $this->administrator();
        $editor = $this->editor();
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];
        $run = function (string $text) use ($org, $editor, $signal) {
            $this->model->respond = ['brief' => 'b', 'brief_evidence' => $signal, 'proposals' => [['action' => 'add_note', 'text' => $text, 'reason' => 'r', 'evidence' => $signal]]];

            return $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $editor['h'])->assertStatus(201)->json('data');
        };
        $proposalStatuses = fn () => DB::table('g2g_agent_proposals')->orderBy('id')->pluck('status')->all();

        // the default (alone): the note is written at once
        $run('First note.');
        $this->assertSame(['applied'], $proposalStatuses());
        $this->assertSame(1, DB::table('crm_organization_comments')->count());

        // an administrator asks for approval: the next run leaves a proposal waiting and writes no note
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk();
        $this->assertSame('approval', $this->getJson(self::P . "/organisations/{$org['identity']}", $editor['h'])->json('data.agent.autonomy.add_note'), 'the panel reads the same setting');
        $d = $run('Second note.');
        $this->assertSame(['applied', 'pending'], $proposalStatuses());
        $this->assertSame(['pending' => 1, 'applied' => 0, 'dismissed' => 0, 'failed' => 0], $d['proposal_counts']);
        $this->assertSame(1, DB::table('crm_organization_comments')->count(), 'no second note');

        // off: the agent may not even propose it
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'off'], $admin['h'])->assertOk();
        $d = $run('Third note.');
        $this->assertSame(['applied', 'pending'], $proposalStatuses(), 'nothing new was stored');
        $this->assertSame('policy_off', DB::table('g2g_agent_tool_calls')->where('run_id', $d['id'])->where('outcome', 'denied')->value('deny_reason'));

        // and what the Autonomy tab counts is what happened
        $note = $this->action($this->state($admin), 'add_note');
        $this->assertSame(['proposed' => 2, 'applied' => 1, 'accepted' => 0, 'dismissed' => 0, 'failed' => 0], $note['counts']);

        // back to the default: the setting disappears and the next run writes the note again
        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'alone'], $admin['h'])->assertOk()->assertJsonPath('data.overridden', false);
        $this->assertSame([], $this->ownRows());
        $run('Fourth note.');
        $this->assertSame(['applied', 'pending', 'applied'], $proposalStatuses());
        $this->assertSame(2, DB::table('crm_organization_comments')->count());
    }

    public function test_the_autonomy_event_is_in_the_catalogue_and_the_catalogue_is_still_coherent(): void
    {
        $this->assertSame(['AuditLogProjector' => EventCatalogue::PROJECTOR], EventCatalogue::SHIPPED['agent.autonomy.changed'] ?? null);
        $this->assertSame([], EventCatalogue::assertInvariants());
    }

    public function test_a_second_administrator_saving_the_same_first_setting_at_the_same_instant_is_retried_not_an_error(): void
    {
        $admin = $this->administrator();
        $raced = 0;
        // The first INSERT into the settings table is preceded by a competing INSERT of the same (organisation, action): the unique key refuses the real one.
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if ($raced === 0 && str_starts_with(strtolower($query), 'insert into "g2g_agent_autonomy"')) {
                $raced++;
                DB::connection()->getPdo()->exec("insert into g2g_agent_autonomy (sub_institute_id, action_key, level) values (" . self::T . ", 'add_note', 'draft')");
            }
        });

        $this->putJson(self::P . '/autonomy/add_note', ['level' => 'approval'], $admin['h'])->assertOk()->assertJsonPath('data.level', 'approval');

        $this->assertSame(1, $raced, 'the competing write happened once');
        $this->assertSame(['add_note' => 'approval'], $this->ownRows());
        $this->assertCount(1, DB::table('g2g_agent_autonomy')->get(), 'one row for the one setting');
    }

    public function test_the_same_race_on_the_kill_switch_is_retried_too(): void
    {
        $admin = $this->administrator();
        $raced = 0;
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if ($raced === 0 && str_starts_with(strtolower($query), 'insert into "g2g_agent_autonomy"')) {
                $raced++;
                DB::connection()->getPdo()->exec("insert into g2g_agent_autonomy (sub_institute_id, action_key, level) values (" . self::T . ", '*', 'off')");
            }
        });

        $this->putJson(self::P . '/autonomy-kill-switch', ['on' => true], $admin['h'])->assertOk()->assertJsonPath('data.kill_switch.on', true);

        $this->assertSame(1, $raced);
        $this->assertSame(['*' => 'off'], $this->ownRows());
        $this->assertCount(1, $this->events());
    }
}
