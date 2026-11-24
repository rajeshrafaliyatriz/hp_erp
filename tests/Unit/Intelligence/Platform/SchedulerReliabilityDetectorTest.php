<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\PlatformAccess;
use App\Domain\Intelligence\Detectors\Platform\SchedulerReliabilityDetector;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;

class SchedulerReliabilityDetectorTest extends PlatformTestCase
{
    private function detector(): SchedulerReliabilityDetector
    {
        return new SchedulerReliabilityDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1)
    {
        return $this->platformCaller('administrator', PlatformAccess::platformModuleLinks(), $tenant);
    }

    private function healthyRuns(int $n = 10): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addTaskRun('events:project', null, 'ok', '2026-11-21 08:' . sprintf('%02d', $i) . ':00');
        }
    }

    public function test_failing_stuck_and_never_closed_tasks_are_reported_without_reading_output(): void
    {
        $this->healthyRuns(8);
        $this->addTaskRun('leave:escalate', null, 'ok', '2026-11-20 08:00:00');
        $this->addTaskRun('leave:escalate', null, 'failed', '2026-11-21 08:30:00');                 // latest run failed
        for ($i = 0; $i < 5; $i++) {
            $this->addTaskRun('readiness:recompute', null, 'skipped', '2026-11-21 07:0' . $i . ':00');   // skipped every time
        }
        $this->addTaskRun('certifications:scan-expiry', null, 'open', '2026-11-21 01:00:00');       // opened, never closed
        $this->addTaskRun('other:task', 92, 'failed', '2026-11-21 08:45:00');                       // another tenant's run

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('high', $insight->severity);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(1, $ev['Tasks whose latest run failed']['value']);
        $this->assertSame(1, $ev['Tasks skipped 5+ times and never completed']['value']);
        $this->assertSame(1, $ev['Runs started but never closed']['value']);
        $this->assertArrayHasKey('Failing task: leave:escalate', $ev);
        $this->assertArrayHasKey('Stuck task: readiness:recompute', $ev);
        $this->assertArrayNotHasKey('Failing task: other:task', $ev, 'another tenant\'s run is never read');
        $this->assertSame('/platform-services/scheduler', $insight->deepLink);
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $this->jsonOf($result), 'run output and host are never read');
        $this->assertFalse($this->hasEntityEvidence($insight), 'installation-wide runs have no tenant to reference');
    }

    public function test_the_output_and_host_columns_are_never_selected(): void
    {
        $this->healthyRuns();
        $this->addTaskRun('a:b', null, 'failed', '2026-11-21 08:30:00');

        $sql = implode("\n", $this->sqlOf(fn () => $this->detector()->detect($this->admin(), $this->clock())));

        $this->assertStringNotContainsString('output_head', $sql);
        $this->assertStringNotContainsString('"host"', $sql);
        $this->assertStringNotContainsString('select *', strtolower($sql));
    }

    public function test_a_silent_scheduler_is_reported_even_when_the_window_is_thin(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->addTaskRun('events:project', null, 'ok', '2026-11-20 0' . $i . ':00:00');   // latest start is more than 12 hours ago
        }

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $this->assertSame('high', $result->insights[0]->severity);
        $this->assertStringContainsString('No scheduled task has started', $result->insights[0]->title);
    }

    public function test_no_run_history_at_all_is_insufficient_not_a_verdict(): void
    {
        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(0, $result->coverage->observed);
    }

    public function test_too_few_recent_runs_is_insufficient(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->addTaskRun('events:project', null, 'ok', '2026-11-21 08:0' . $i . ':00');
        }

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $this->detector()->detect($this->admin(), $this->clock())->state);
    }

    public function test_healthy_ledger_is_ok_and_empty(): void
    {
        $this->healthyRuns();

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
        $this->assertSame(10, $result->coverage->observed);
    }

    public function test_other_tenants_runs_are_never_counted_but_installation_wide_runs_are(): void
    {
        $this->healthyRuns();
        for ($i = 0; $i < 30; $i++) {
            $this->addTaskRun('x:y', 92, 'failed', '2026-11-21 08:3' . ($i % 10) . ':00');
        }

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertSame([], $result->insights);
        $this->assertSame(10, $result->coverage->observed, 'only the installation-wide runs, none of tenant 92\'s');

        $own = $this->addTaskRun('own:task', 1, 'failed', '2026-11-21 08:50:00');
        $this->assertGreaterThan(0, $own);
        $this->assertCount(1, $this->detector()->detect($this->admin(1), $this->clock())->insights, 'this tenant\'s own failed run counts');
    }

    public function test_tasks_switched_off_for_this_organisation_only(): void
    {
        $this->healthyRuns();
        $this->db()->table('g2g_platform_scheduled_tasks')->insert([['sub_institute_id' => 1, 'task_key' => 'leave.escalate', 'disabled' => 1],
            ['sub_institute_id' => 1, 'task_key' => 'on.task', 'disabled' => 0], ['sub_institute_id' => 92, 'task_key' => 'other.tenant', 'disabled' => 1]]);

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertCount(1, $result->insights);
        $this->assertSame('info', $result->insights[0]->severity);
        $ev = $this->evidenceByLabel($result->insights[0]);
        $this->assertSame(1, $ev['Tasks switched off for this organisation']['value']);
        $this->assertArrayHasKey('Switched off: leave.escalate', $ev);
        $this->assertArrayNotHasKey('Switched off: other.tenant', $ev);
    }

    public function test_a_missing_ledger_is_data_unavailable_and_leaks_no_sql(): void
    {
        Schema::drop('g2g_platform_task_runs');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $result->state);
        $this->assertNull($result->coverage);
        $this->assertNull($result->summary()['insight_count']);
        $this->assertStringNotContainsString('g2g_platform', $this->jsonOf($result));
    }

    public function test_a_missing_overrides_table_only_skips_that_metric(): void
    {
        Schema::drop('g2g_platform_scheduled_tasks');
        $this->healthyRuns();

        $this->assertSame(Taxonomy::STATE_OK, $this->detector()->detect($this->admin(), $this->clock())->state);
    }

    public function test_authorization_needs_a_platform_services_module_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/platform-services/event-bus', '/ai/usage-cost'])));
        $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', ['/platform-services/workflow?module=crm'])));
        $this->assertSame(Taxonomy::DOMAIN_PLATFORM, $this->detector()->domain());
    }
}
