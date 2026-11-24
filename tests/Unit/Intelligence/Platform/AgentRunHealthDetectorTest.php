<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\AgentRunHealthDetector;
use App\Domain\Intelligence\Detectors\Platform\PlatformAccess;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;

class AgentRunHealthDetectorTest extends PlatformTestCase
{
    private function detector(): AgentRunHealthDetector
    {
        return new AgentRunHealthDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1, string $role = 'administrator')
    {
        return $this->platformCaller($role, ['/module/agentic-ai/run-log'], $tenant);
    }

    private function runs(int $tenant, int $agent, string $status, int $n, string $at = '2026-11-15 08:00:00', ?float $cost = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addAgentRun($tenant, $agent, $status, $at, $cost);
        }
    }

    /** @return array<string, \App\Domain\Intelligence\Insight> */
    private function bySubject(\App\Domain\Intelligence\DetectorResult $r): array
    {
        $out = [];
        foreach ($r->insights as $i) {
            $out[explode(':', $i->key)[1]] = $i;
        }

        return $out;
    }

    public function test_failed_run_share_names_the_agents_and_ignores_cancelled_and_other_tenants(): void
    {
        $a = $this->addAgent(1);
        $b = $this->addAgent(1);
        $this->runs(1, $a, 'success', 15);
        $this->runs(1, $a, 'error', 4);
        $this->runs(1, $b, 'error', 1);
        $this->runs(1, $a, 'cancelled', 30);          // neither a failure nor a success
        $this->runs(92, $a, 'error', 50);            // another tenant's runs
        $this->addAgentRun(1, $a, 'error', '2026-11-15 08:00:00', null, ['deleted_at' => '2026-11-16 00:00:00']);   // deleted

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $by = $this->bySubject($result);
        $this->assertArrayHasKey('failed_share', $by);
        $this->assertSame('attention', $by['failed_share']->severity, '5 of 20 = 25%');
        $ev = $this->evidenceByLabel($by['failed_share']);
        $this->assertSame(20, $ev['Finished agent runs']['value']);
        $this->assertSame(5, $ev['Failed runs']['value']);
        $this->assertSame(25.0, (float) $ev['Share of finished runs that failed']['value']);
        $ids = array_column(array_filter($by['failed_share']->toArray()['evidence'], fn ($e) => $e['kind'] === 'entity'), 'entity_id');
        $this->assertSame([$a, $b], $ids, 'the agent with the most failures first');
        $this->assertSame('/module/agentic-ai/run-log', $by['failed_share']->deepLink);
        $this->assertSame(20, $result->coverage->observed);
    }

    public function test_a_failure_share_at_least_double_the_threshold_is_high(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 6);
        $this->runs(1, $a, 'error', 6);

        $this->assertSame('high', $this->bySubject($this->detector()->detect($this->admin(), $this->clock()))['failed_share']->severity);
    }

    public function test_a_low_failure_share_raises_nothing(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 19);
        $this->runs(1, $a, 'error', 1);

        $this->assertSame([], $this->detector()->detect($this->admin(), $this->clock())->insights);
    }

    public function test_stalled_runs_need_no_volume(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'pending', 1, '2026-11-19 08:00:00');    // stalled: more than 24 hours
        $this->runs(1, $a, 'running', 1, '2026-11-21 08:00:00');    // one hour old: normal
        $this->runs(1, $a, 'running', 1, '2026-11-20 08:00:00');    // 25 hours: stalled
        $this->runs(92, $a, 'running', 9, '2026-11-01 08:00:00');   // another tenant

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $by = $this->bySubject($result);
        $this->assertSame(['stalled'], array_keys($by));
        $this->assertSame(2, $this->evidenceByLabel($by['stalled'])['Runs pending or running after 24 hours']['value']);
    }

    public function test_idle_deployed_agents_exclude_drafts_deleted_and_shared_catalogue_agents(): void
    {
        $busy = $this->addAgent(1);
        $this->runs(1, $busy, 'success', 2);
        $idle1 = $this->addAgent(1);
        $idle2 = $this->addAgent(1);
        $idle3 = $this->addAgent(1);
        $this->addAgent(1, 'draft');
        $this->addAgent(1, 'paused');
        $this->addAgent(1, 'deployed', '2026-11-01 00:00:00');   // deleted
        $this->addAgent(null);                                    // the shared catalogue: not this organisation's agent
        $this->addAgent(92);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $by = $this->bySubject($result);
        $this->assertSame(['idle'], array_keys($by));
        $this->assertSame('info', $by['idle']->severity);
        $ev = $this->evidenceByLabel($by['idle']);
        $this->assertSame(4, $ev['Deployed agents']['value']);
        $this->assertSame(3, $ev['Deployed agents with no run in the window']['value']);
        $ids = array_column(array_filter($by['idle']->toArray()['evidence'], fn ($e) => $e['kind'] === 'entity'), 'entity_id');
        $this->assertSame([$idle1, $idle2, $idle3], $ids);
    }

    public function test_idle_is_not_assessed_below_the_deployed_agent_minimum(): void
    {
        $this->addAgent(1);
        $this->addAgent(1);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
    }

    public function test_a_rise_in_cost_per_run_is_reported_as_recorded_without_a_currency(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 5, '2026-11-01 08:00:00', 1.0);   // first half of the 30 days (before 2026-11-06)
        $this->runs(1, $a, 'success', 5, '2026-11-15 08:00:00', 2.0);   // second half
        $this->runs(1, $a, 'success', 4, '2026-11-15 08:00:00', null);   // not priced: never zero

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $by = $this->bySubject($result);
        $this->assertSame(['cost_rise'], array_keys($by));
        $ev = $this->evidenceByLabel($by['cost_rise']);
        $this->assertSame(1.0, (float) $ev['Average cost per run, earlier half (as recorded, no currency stored)']['value']);
        $this->assertSame(2.0, (float) $ev['Average cost per run, later half (as recorded, no currency stored)']['value']);
        $this->assertArrayNotHasKey('unit', $ev['Average cost per run, earlier half (as recorded, no currency stored)'], 'no currency is ever attached');
        $this->assertStringContainsString('100%', $by['cost_rise']->title);
    }

    public function test_a_stable_cost_raises_nothing(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 6, '2026-11-01 08:00:00', 1.0);
        $this->runs(1, $a, 'success', 6, '2026-11-15 08:00:00', 1.1);

        $this->assertSame([], $this->detector()->detect($this->admin(), $this->clock())->insights);
    }

    public function test_too_few_finished_runs_is_insufficient(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 3);
        $this->runs(1, $a, 'error', 3);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(6, $result->coverage->observed);
    }

    public function test_prompts_inputs_outputs_errors_and_names_are_never_selected(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 10);
        $this->runs(1, $a, 'error', 10);
        $this->addAgent(1);
        $this->addAgent(1);

        $result = null;
        $sql = strtolower(implode("\n", $this->sqlOf(function () use (&$result) {
            $result = $this->detector()->detect($this->admin(), $this->clock());
        })));

        foreach (['system_prompt', '"input"', '"output"', 'error_message', '"name"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql, $forbidden);
        }
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $this->jsonOf($result));
    }

    public function test_executive_and_auditor_get_no_record_references(): void
    {
        $a = $this->addAgent(1);
        $this->runs(1, $a, 'success', 6);
        $this->runs(1, $a, 'error', 6);

        foreach (['executive', 'auditor'] as $role) {
            $result = $this->detector()->detect($this->admin(1, $role), $this->clock());
            $this->assertNotEmpty($result->insights, $role);
            foreach ($result->insights as $insight) {
                $this->assertFalse($this->hasEntityEvidence($insight), $role);
            }
        }
    }

    public function test_a_missing_runs_table_is_data_unavailable_and_a_missing_agents_table_only_skips_idle(): void
    {
        Schema::drop('agentic_agents');
        $a = 5;
        $this->runs(1, $a, 'success', 15);
        $this->assertSame(Taxonomy::STATE_OK, $this->detector()->detect($this->admin(), $this->clock())->state);

        Schema::drop('agentic_agent_runs');
        $result = $this->detector()->detect($this->admin(), $this->clock());
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $result->state);
        $this->assertNull($result->summary()['insight_count']);
    }

    public function test_authorization_needs_one_of_the_agentic_screens(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/ai/usage-cost', '/platform-services/event-bus'])));
        foreach (PlatformAccess::agenticLinks() as $link) {
            $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', [$link])), $link);
        }
    }
}
