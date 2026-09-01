<?php

namespace Tests\Unit;

use App\Services\TaskManagement\WorkstreamHealth;
use PHPUnit\Framework\TestCase;

/**
 * The workstream health rules, asserted directly.
 *
 * These are judgements, not plumbing: "an empty workstream is not healthy" and
 * "an unread KPI is not a failing KPI" are product decisions, and the cheapest
 * place to be sure of them is here rather than through an HTTP call against
 * seeded rows.
 *
 * The two tests that matter most are the ones a three-state model would get
 * wrong — NOT STARTED must not read as ON TRACK, and UNMEASURED must not read as
 * AT RISK.
 */
class WorkstreamHealthTest extends TestCase
{
    private WorkstreamHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = new WorkstreamHealth();
    }

    /** The shape WorkstreamRollup hands over, with everything at zero. */
    private function counts(array $overrides = []): array
    {
        $base = [
            'deliverables' => ['total' => 0, 'done' => 0, 'in_flight' => 0, 'open' => 0, 'overdue' => 0],
            'kpis'         => ['total' => 0, 'met' => 0, 'on_track' => 0, 'at_risk' => 0, 'off_track' => 0, 'unmeasured' => 0],
            'risks'        => ['open' => 0, 'closed' => 0, 'regulated_open' => 0, 'severe_open' => 0, 'moderate_open' => 0],
            'tasks'        => ['total' => 0, 'completed' => 0, 'overdue' => 0, 'blocked' => 0],
            'milestones'   => ['total' => 0, 'completed' => 0, 'overdue' => 0],
        ];

        foreach ($overrides as $group => $values) {
            $base[$group] = array_merge($base[$group], $values);
        }

        return $base;
    }

    public function test_an_empty_workstream_is_not_started_rather_than_on_track(): void
    {
        $result = $this->health->evaluate($this->counts());

        // The whole point: nothing overdue is trivially true of nothing at all.
        $this->assertSame(WorkstreamHealth::NOT_STARTED, $result['state']);
        $this->assertStringContainsString('Nothing has been planned', $result['state_reason']);
    }

    public function test_progress_is_null_not_zero_when_there_are_no_deliverables(): void
    {
        $result = $this->health->evaluate($this->counts());

        $this->assertNull($result['progress'], 'No deliverables means unmeasurable, not 0% complete.');
    }

    public function test_a_planned_but_unread_workstream_is_unmeasured_not_at_risk(): void
    {
        // Exactly the state of the seeded workstreams: deliverables and KPIs
        // exist, none has been touched, nothing is overdue.
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 4, 'open' => 4],
            'kpis'         => ['total' => 3, 'unmeasured' => 3],
        ]));

        $this->assertSame(WorkstreamHealth::UNMEASURED, $result['state']);
        $this->assertStringContainsString('no reading yet', $result['state_reason']);
    }

    public function test_seeded_workstream_never_reports_on_track(): void
    {
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 4, 'open' => 4],
            'kpis'         => ['total' => 3, 'unmeasured' => 3],
        ]));

        $this->assertNotSame(
            WorkstreamHealth::ON_TRACK,
            $result['state'],
            'A workstream nobody has measured must never claim to be on track.'
        );
    }

    public function test_an_open_regulated_risk_is_off_track(): void
    {
        // A compliance exposure is a problem that exists now, not one that might
        // arrive — the only risk severity that counts as failure.
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 2, 'done' => 1, 'in_flight' => 1],
            'risks'        => ['open' => 1, 'regulated_open' => 1],
        ]));

        $this->assertSame(WorkstreamHealth::OFF_TRACK, $result['state']);
        $this->assertStringContainsString('regulated risk', $result['state_reason']);
    }

    public function test_a_high_open_risk_is_drift_not_failure(): void
    {
        /*
         * THE REGRESSION THIS EXISTS FOR. High open risks used to read as
         * OFF TRACK, which turned all four of the customer's workstreams red the
         * moment their own documented risks were recorded. A risk register is a
         * list of things that MIGHT go wrong; marking a team as failing for
         * having written them down punishes the practice the model asks for.
         */
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 4, 'done' => 1, 'in_flight' => 1],
            'risks'        => ['open' => 1, 'severe_open' => 1],
        ]));

        $this->assertSame(WorkstreamHealth::AT_RISK, $result['state']);
        $this->assertStringContainsString('high-severity risk', $result['state_reason']);
    }

    public function test_a_seeded_workstream_with_documented_risks_is_not_off_track(): void
    {
        // Exactly the live shape after seeding: deliverables defined but not
        // started, no KPI readings, and one documented High risk per workstream.
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 4, 'open' => 4],
            'risks'        => ['open' => 1, 'severe_open' => 1],
        ]));

        $this->assertNotSame(WorkstreamHealth::OFF_TRACK, $result['state']);
    }

    public function test_an_overdue_deliverable_is_off_track_and_the_reason_names_the_count(): void
    {
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 5, 'done' => 2, 'in_flight' => 1, 'open' => 3, 'overdue' => 2],
        ]));

        $this->assertSame(WorkstreamHealth::OFF_TRACK, $result['state']);
        $this->assertStringContainsString('2 deliverables are past the due date', $result['state_reason']);
    }

    public function test_a_moderate_risk_is_drift_not_failure(): void
    {
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 2, 'done' => 1, 'in_flight' => 1],
            'risks'        => ['open' => 1, 'moderate_open' => 1],
        ]));

        $this->assertSame(WorkstreamHealth::AT_RISK, $result['state']);
    }

    public function test_overdue_tasks_are_judged_as_a_share_not_a_count(): void
    {
        // 3 of 90 overdue is not a crisis; a fixed count threshold would say it is.
        $many = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 1, 'done' => 1],
            'tasks'        => ['total' => 90, 'completed' => 40, 'overdue' => 3],
        ]));
        $this->assertSame(WorkstreamHealth::ON_TRACK, $many['state']);

        // 3 of 4 is.
        $few = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 1, 'done' => 1],
            'tasks'        => ['total' => 4, 'completed' => 1, 'overdue' => 3],
        ]));
        $this->assertSame(WorkstreamHealth::AT_RISK, $few['state']);
    }

    public function test_failure_outranks_drift(): void
    {
        // Both conditions present; the verdict must be the worse one.
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 3, 'done' => 1, 'in_flight' => 1, 'overdue' => 1],
            'kpis'         => ['total' => 2, 'on_track' => 1, 'at_risk' => 1],
            'risks'        => ['open' => 1, 'moderate_open' => 1],
        ]));

        $this->assertSame(WorkstreamHealth::OFF_TRACK, $result['state']);
    }

    public function test_a_measured_healthy_workstream_is_on_track(): void
    {
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 4, 'done' => 3, 'in_flight' => 1, 'open' => 1],
            'kpis'         => ['total' => 2, 'met' => 1, 'on_track' => 1],
            'tasks'        => ['total' => 10, 'completed' => 8],
        ]));

        $this->assertSame(WorkstreamHealth::ON_TRACK, $result['state']);
        $this->assertSame(75.0, $result['progress']);
        $this->assertStringContainsString('3 of 4 deliverables complete', $result['state_reason']);
    }

    public function test_reason_reads_as_a_sentence_when_several_things_are_wrong(): void
    {
        $result = $this->health->evaluate($this->counts([
            'deliverables' => ['total' => 3, 'overdue' => 1, 'open' => 3],
            'kpis'         => ['total' => 2, 'off_track' => 2],
            'risks'        => ['open' => 1, 'regulated_open' => 1],
        ]));

        // Comma-separated with a trailing "and", never a list ending in a comma.
        $this->assertStringContainsString(' and ', $result['state_reason']);
        $this->assertStringEndsWith('.', $result['state_reason']);
    }
}
