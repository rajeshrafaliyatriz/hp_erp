<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * agent:organisation-run (slice 7, thin): the same service as the Run agent button, from the command line, for development databases. The model is the
 * fake of the other agent tests, so nothing is bought and nothing is sent.
 */
class AgentOrganisationRunCommandTest extends TestCase
{
    use BuildsAgentWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    private function answer(array $org): array
    {
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];

        return ['brief' => 'Two hiring signals and no follow-up yet.', 'brief_evidence' => $signal, 'proposals' => [
            ['action' => 'add_note', 'text' => 'Hiring a principal for the new campus.', 'reason' => 'Signal', 'evidence' => $signal],
            ['action' => 'add_task', 'title' => 'Call the trustee', 'due_date' => now()->addDays(3)->toDateString(), 'reason' => 'Signal', 'evidence' => $signal],
        ]];
    }

    public function test_it_runs_the_agent_once_as_the_person_named_and_prints_the_brief_the_proposals_the_cost_and_the_trail(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        $this->model->respond = $this->answer($org);

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--user' => $user['id']])
            ->expectsOutputToContain("acting for user {$user['id']}")
            ->expectsOutputToContain('Run #1: COMPLETED')
            ->expectsOutputToContain('Model: deepseek / deepseek-flash')
            ->expectsOutputToContain('Tokens: in 1200, out 300 | Cost: INR 0.0691')
            ->expectsOutputToContain('Two hiring signals and no follow-up yet.')
            ->expectsOutputToContain('cites signal:' . $org['signals'][0])
            ->expectsOutputToContain('add_note | APPLIED by the agent, alone')
            ->expectsOutputToContain('Add a task: Call the trustee')
            ->expectsOutputToContain('read_context -> read')
            ->assertExitCode(0);

        $run = DB::table('g2g_agent_runs')->first();
        $this->assertSame(['cli', 'completed', $user['id']], [$run->trigger_source, $run->status, (int) $run->user_id]);
        $this->assertSame($user['id'], (int) DB::table('crm_organization_comments')->value('created_by'), 'the note is written as the person the agent acted for');
    }

    public function test_without_a_user_it_acts_for_the_identitys_owner_else_the_first_administrator(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $this->model->respond = $this->answer($org);
        $someone = $this->user([]);
        $admin = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T]);
        $adminUser = DB::table('tbluser')->insertGetId(['user_name' => 'boss', 'sub_institute_id' => self::T, 'user_profile_id' => $admin, 'status' => 1]);
        DB::table('tbluser')->insert(['user_name' => 'boss2', 'sub_institute_id' => self::T, 'user_profile_id' => $admin, 'status' => 1]);

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity']])->expectsOutputToContain("acting for user {$adminUser}")->assertExitCode(0);

        DB::table('g2g_org_accounts')->where('id', $org['identity'])->update(['owner_user_id' => $someone['id']]);
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity']])->expectsOutputToContain("acting for user {$someone['id']}")->assertExitCode(0);
    }

    public function test_it_refuses_the_live_connection_and_does_not_touch_anything(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--database' => 'live'])
            ->expectsOutputToContain('refused: this command is for development databases')
            ->assertExitCode(2);

        $this->assertSame([], $this->model->calls);
        $this->assertSame(0, DB::table('g2g_agent_runs')->count());
    }

    public function test_it_refuses_what_it_cannot_resolve_without_writing_or_calling_the_model(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $theirs = $this->makeOrg(self::T2, 'Their School', ['signals' => 1]);
        $user = $this->editor();

        $this->artisan('agent:organisation-run', ['--tenant' => self::T])->expectsOutputToContain('both required')->assertExitCode(2);
        $this->artisan('agent:organisation-run', ['--tenant' => 'x', '--org-account' => 1])->assertExitCode(2);
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $theirs['identity']])->expectsOutputToContain('has no Organisation 360 identity')->assertExitCode(1);
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => 999999])->assertExitCode(1);
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--user' => $this->user([], self::T2)['id']])
            ->expectsOutputToContain('is not an active user of organisation')->assertExitCode(1);
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--database' => 'nowhere'])->expectsOutputToContain("no database connection called 'nowhere'")->assertExitCode(2);
        // No --user, no owner and no administrator: it says so rather than guessing a person to act for.
        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity']])->expectsOutputToContain('no active administrator')->assertExitCode(1);

        $this->assertSame([], $this->model->calls);
        $this->assertSame(0, DB::table('g2g_agent_runs')->count());
        $this->assertNotNull($user);
    }

    public function test_an_unavailable_model_is_said_plainly_and_exits_with_an_error_and_nothing_was_called(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        $this->model->unavailable = 'No AI model is set up for your organisation, so the agent cannot run.';

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--user' => $user['id']])
            ->expectsOutputToContain('Run #1: UNAVAILABLE [model_unavailable]')
            ->expectsOutputToContain('No AI model is set up for your organisation')
            ->assertExitCode(1);

        $this->assertSame([], $this->model->calls);
    }

    public function test_a_refusal_before_the_run_is_reported_with_its_code(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => '*', 'level' => 'off']);

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--user' => $user['id']])
            ->expectsOutputToContain('Refused before a run was made [agent_off]')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('g2g_agent_runs')->count());
    }

    public function test_the_default_connection_is_put_back_after_the_run(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        $this->model->respond = ['brief' => '', 'proposals' => []];
        $before = DB::getDefaultConnection();

        $this->artisan('agent:organisation-run', ['--tenant' => self::T, '--org-account' => $org['identity'], '--user' => $user['id'], '--database' => $before])->assertExitCode(0);

        $this->assertSame($before, DB::getDefaultConnection());
    }
}
