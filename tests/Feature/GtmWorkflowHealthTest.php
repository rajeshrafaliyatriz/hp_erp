<?php

namespace Tests\Feature;

use App\Domain\Gtm\GtmState;
use App\Domain\Gtm\Workflows\WorkflowHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsWorkflowWorld;
use Tests\TestCase;

/**
 * GET /api/gtm/workflows/health: is the clock that drives automations running? Answered only from what the scheduler itself recorded (the run ledger g2g_platform_task_runs for
 * events:react and gtm:tick, and gtm_system_state.last_tick_at). No record, a missing record or a missing table is `unknown`, NEVER `running`.
 */
class GtmWorkflowHealthTest extends TestCase
{
    use BuildsWorkflowWorld;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowWorld();
        $this->now = Carbon::parse('2026-11-26 12:00:00');
    }

    private function ran(string $key, Carbon $at, ?string $status = 'ok'): void
    {
        DB::table('g2g_platform_task_runs')->insert(['task_key' => $key, 'started_at' => $at->format('Y-m-d H:i:s.v'), 'finished_at' => $at->format('Y-m-d H:i:s.v'), 'status' => $status]);
    }

    public function test_both_recorded_and_recent_is_running_with_a_plain_message(): void
    {
        $this->ran('events.react', $this->now->copy()->subMinutes(3));
        $this->ran('gtm.tick', $this->now->copy()->subMinutes(2));

        $h = WorkflowHealth::check($this->now);

        $this->assertSame('running', $h['state']);
        $this->assertSame(10, $h['expected_every_minutes']);
        $this->assertSame('Checked about every 10 minutes. Last check 3 minutes ago.', $h['message']);
        $this->assertSame(Carbon::parse('2026-11-26 11:57:00')->toIso8601String(), $h['last_events_at']);
        $this->assertSame(Carbon::parse('2026-11-26 11:58:00')->toIso8601String(), $h['last_tick_at']);
        $this->assertSame(['state', 'last_tick_at', 'last_events_at', 'expected_every_minutes', 'message'], array_keys($h));
    }

    public function test_the_command_name_form_of_the_ledger_key_counts_too_and_a_run_that_never_reported_did_start(): void
    {
        $this->ran('events:react', $this->now->copy()->subSeconds(20), null);
        $this->ran('gtm:tick', $this->now->copy()->subMinutes(1), 'failed');

        $h = WorkflowHealth::check($this->now);

        $this->assertSame('running', $h['state']);
        $this->assertSame('Checked about every 10 minutes. Last check less than a minute ago.', $h['message']);
    }

    public function test_the_tick_can_also_be_read_from_the_state_table(): void
    {
        $this->ran('events.react', $this->now->copy()->subMinutes(1));
        GtmState::set('last_tick_at', $this->now->copy()->subMinutes(4)->toDateTimeString());

        $this->assertSame('running', WorkflowHealth::check($this->now)['state']);
    }

    public function test_the_scheduler_that_stopped_is_stale_and_says_for_how_long(): void
    {
        $this->ran('events.react', $this->now->copy()->subDays(2)->subHour());
        $this->ran('gtm.tick', $this->now->copy()->subDays(2)->subHour());

        $h = WorkflowHealth::check($this->now);

        $this->assertSame('stale', $h['state']);
        $this->assertSame('The scheduler has not run for 2 days, so automations wait.', $h['message']);
    }

    public function test_late_means_three_runs_in_a_row_missed(): void
    {
        $this->ran('gtm.tick', $this->now->copy()->subMinutes(1));
        $this->ran('events.react', $this->now->copy()->subMinutes(29));
        $this->assertSame('running', WorkflowHealth::check($this->now)['state'], '29 minutes: the 10-minute check has missed two runs, not yet three');

        DB::table('g2g_platform_task_runs')->where('task_key', 'events.react')->delete();
        $this->ran('events.react', $this->now->copy()->subMinutes(31));
        $h = WorkflowHealth::check($this->now);
        $this->assertSame('stale', $h['state']);
        $this->assertSame('The scheduler has not run for 31 minutes, so automations wait.', $h['message']);
    }

    public function test_only_the_timed_pass_late_is_stale_too(): void
    {
        $this->ran('events.react', $this->now->copy()->subMinutes(2));
        $this->ran('gtm.tick', $this->now->copy()->subMinutes(40));

        $h = WorkflowHealth::check($this->now);

        $this->assertSame('stale', $h['state']);
        $this->assertSame('The scheduler\'s timed pass has not run for 40 minutes, so retries and timed checks wait.', $h['message']);
    }

    public function test_no_record_at_all_is_unknown_never_running(): void
    {
        $h = WorkflowHealth::check($this->now);

        $this->assertSame('unknown', $h['state']);
        $this->assertNull($h['last_tick_at']);
        $this->assertNull($h['last_events_at']);
        $this->assertStringStartsWith('Not known:', $h['message']);
    }

    public function test_a_skipped_run_is_not_a_run(): void
    {
        $this->ran('events.react', $this->now->copy()->subMinutes(1), 'skipped');
        $this->ran('gtm.tick', $this->now->copy()->subMinutes(1), 'skipped');

        $this->assertSame('unknown', WorkflowHealth::check($this->now)['state']);
    }

    public function test_one_missing_record_is_unknown_not_running(): void
    {
        $this->ran('gtm.tick', $this->now->copy()->subMinutes(1));
        $onlyTick = WorkflowHealth::check($this->now);
        $this->assertSame('unknown', $onlyTick['state']);
        $this->assertNotNull($onlyTick['last_tick_at']);
        $this->assertNull($onlyTick['last_events_at']);

        DB::table('g2g_platform_task_runs')->delete();
        $this->ran('events.react', $this->now->copy()->subMinutes(1));
        $onlyEvents = WorkflowHealth::check($this->now);
        $this->assertSame('unknown', $onlyEvents['state']);
        $this->assertStringContainsString('no record of the timed pass', $onlyEvents['message']);
    }

    public function test_a_missing_table_is_unknown_not_an_error(): void
    {
        Schema::drop('g2g_platform_task_runs');
        Schema::drop('gtm_system_state');

        $h = WorkflowHealth::check($this->now);

        $this->assertSame('unknown', $h['state']);
        $this->assertSame(['state', 'last_tick_at', 'last_events_at', 'expected_every_minutes', 'message'], array_keys($h));
    }

    public function test_the_route_answers_in_the_envelope_and_needs_the_view_right(): void
    {
        $admin = $this->person('administrator');
        $executive = $this->person('executive');
        $employee = $this->person('employee');
        $this->ran('events.react', now()->subMinutes(2));
        $this->ran('gtm.tick', now()->subMinutes(2));

        $r = $this->getJson('/api/gtm/workflows/health', $admin['h'])->assertOk()->assertJsonPath('status', 1)->assertJsonPath('data.state', 'running')->assertJsonPath('data.expected_every_minutes', 10);
        $this->assertSame('Checked about every 10 minutes. Last check 2 minutes ago.', $r->json('data.message'));
        $this->getJson('/api/gtm/workflows/health', $executive['h'])->assertOk();
        $this->getJson('/api/gtm/workflows/health', $employee['h'])->assertForbidden();
        $this->getJson('/api/gtm/workflows/health')->assertUnauthorized();
    }
}
