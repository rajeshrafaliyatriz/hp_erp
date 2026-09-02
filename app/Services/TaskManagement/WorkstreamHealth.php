<?php

namespace App\Services\TaskManagement;

/**
 * Is this workstream in trouble, and why?
 *
 * ── PURE ON PURPOSE ─────────────────────────────────────────────────────────
 *
 * No database, no request, no clock beyond what is handed in. Every rule here is
 * a decision about what counts as trouble, and those decisions are worth
 * asserting directly in a unit test rather than through an HTTP round trip
 * against seeded rows. WorkstreamRollup does the counting; this does the judging.
 *
 * ── HEALTH IS COMPUTED, NEVER STORED ────────────────────────────────────────
 *
 * There is no `health` column and there must not be one. A stored verdict goes
 * stale the moment a due date passes with nobody touching the row, and a stale
 * verdict is worse than none because it looks current. This module already
 * carries the same lesson in three places — certification status that nothing
 * recomputed, readiness gates that answer with whenever they were last run, and
 * a task audit trail frozen because nothing drained it.
 *
 * ── THE FIVE STATES, AND WHY THERE ARE FIVE ─────────────────────────────────
 *
 *   NOT STARTED  nothing has been planned at all
 *   UNMEASURED   a plan exists, but nothing in it has been measured yet
 *   ON TRACK     measured, and nothing is failing
 *   AT RISK      something is drifting
 *   OFF TRACK    something has already failed
 *
 * The two that a simpler model would collapse are the important ones.
 *
 * NOT STARTED IS NOT "ON TRACK". An empty workstream satisfies every "nothing is
 * overdue" test trivially, so a three-state model reports a workstream nobody has
 * planned as healthy. That is the most misleading answer available.
 *
 * UNMEASURED IS NOT "AT RISK" EITHER. A KPI with no reading is not failing; it is
 * unread. The competency gap engine draws exactly this line — an unmeasured item
 * is not counted as a shortfall, "that would assert a shortfall nobody measured" —
 * and the readiness gates render a null value as "not yet computed" rather than 0.
 * Immediately after seeding, every workstream here is legitimately UNMEASURED.
 *
 * ── EVERY VERDICT CARRIES ITS REASON ────────────────────────────────────────
 *
 * `state_reason` is a sentence naming what actually triggered it — "2 deliverables
 * are past their due date" — not a restatement of the label. A colour with no
 * explanation gives somebody a feeling instead of a next action. This follows
 * tenant_readiness_gate.remedy, which interpolates the real numbers for the same
 * reason.
 */
class WorkstreamHealth
{
    public const NOT_STARTED = 'NOT STARTED';
    public const UNMEASURED  = 'UNMEASURED';
    public const ON_TRACK    = 'ON TRACK';
    public const AT_RISK     = 'AT RISK';
    public const OFF_TRACK   = 'OFF TRACK';

    /**
     * Share of linked tasks overdue before the workstream is called AT RISK.
     *
     * A quarter, not a fixed count: three overdue tasks out of four is a
     * different fact from three out of ninety, and a threshold expressed as a
     * count would call the second one a crisis.
     */
    private const OVERDUE_TASK_SHARE = 0.25;

    /**
     * @param  array $counts  as assembled by WorkstreamRollup::forWorkstream()
     * @return array{state:string, state_reason:string, progress:float|null}
     */
    public function evaluate(array $counts): array
    {
        $deliverables = $counts['deliverables'];
        $kpis         = $counts['kpis'];
        $risks        = $counts['risks'];
        $tasks        = $counts['tasks'];
        $milestones   = $counts['milestones'];

        $progress = $this->progress($deliverables, $tasks);

        // ── NOTHING PLANNED ────────────────────────────────────────────────
        $planned = $deliverables['total'] + $kpis['total'] + $risks['open'] + $risks['closed']
            + $tasks['total'] + $milestones['total'];

        if ($planned === 0) {
            return [
                'state'        => self::NOT_STARTED,
                'state_reason' => 'Nothing has been planned for this workstream yet — no deliverables, KPIs, risks, tasks or checkpoints.',
                'progress'     => $progress,
            ];
        }

        // ── ALREADY FAILING ────────────────────────────────────────────────
        $failures = [];

        /*
         * ONLY A REGULATED EXPOSURE COUNTS AS FAILURE HERE.
         *
         * This originally read `severe_open` — High AND Regulated — and testing
         * it against real seeded data showed why that is wrong: all four of the
         * customer's workstreams turned red the moment their own documented
         * risks were recorded, because a risk register is a list of things that
         * MIGHT go wrong. Marking a team as failing for having written its risks
         * down punishes exactly the practice the model is asking for.
         *
         * A High open risk is drift and appears under AT RISK below. A Regulated
         * one is different in kind: a compliance exposure is a problem that
         * exists now, not one that might arrive, which is why
         * TaskExecutionClassifier keeps `Regulated` outside its ordinary scale
         * too.
         */
        if ($risks['regulated_open'] > 0) {
            $failures[] = $this->plural($risks['regulated_open'], 'open regulated risk', 'open regulated risks');
        }
        if ($deliverables['overdue'] > 0) {
            $failures[] = $this->plural($deliverables['overdue'], 'deliverable is', 'deliverables are') . ' past the due date';
        }
        if ($kpis['off_track'] > 0) {
            $failures[] = $this->plural($kpis['off_track'], 'KPI is', 'KPIs are') . ' off track';
        }
        if ($milestones['overdue'] > 0) {
            $failures[] = $this->plural($milestones['overdue'], 'checkpoint is', 'checkpoints are') . ' past its target date';
        }

        if ($failures !== []) {
            return [
                'state'        => self::OFF_TRACK,
                'state_reason' => ucfirst($this->sentence($failures)) . '.',
                'progress'     => $progress,
            ];
        }

        // ── DRIFTING ───────────────────────────────────────────────────────
        $warnings = [];

        if ($risks['severe_open'] > 0) {
            $warnings[] = $this->plural($risks['severe_open'], 'open high-severity risk', 'open high-severity risks');
        }
        if ($kpis['at_risk'] > 0) {
            $warnings[] = $this->plural($kpis['at_risk'], 'KPI is', 'KPIs are') . ' at risk';
        }
        if ($risks['moderate_open'] > 0) {
            $warnings[] = $this->plural($risks['moderate_open'], 'open medium-severity risk', 'open medium-severity risks');
        }
        if ($tasks['total'] > 0 && ($tasks['overdue'] / $tasks['total']) > self::OVERDUE_TASK_SHARE) {
            $warnings[] = sprintf('%d of %d linked tasks are overdue', $tasks['overdue'], $tasks['total']);
        }
        if ($tasks['blocked'] > 0) {
            $warnings[] = $this->plural($tasks['blocked'], 'linked task is', 'linked tasks are') . ' blocked by an unfinished predecessor';
        }

        if ($warnings !== []) {
            return [
                'state'        => self::AT_RISK,
                'state_reason' => ucfirst($this->sentence($warnings)) . '.',
                'progress'     => $progress,
            ];
        }

        /*
         * ── MEASURED, OR MERELY PLANNED? ───────────────────────────────────
         *
         * Everything below here is passing. The remaining question is whether
         * anything has actually been READ. A workstream whose every KPI is
         * unmeasured and whose deliverables are all still NOT STARTED has not
         * demonstrated health — it has demonstrated that nobody has looked.
         */
        $anyKpiRead        = $kpis['total'] > 0 && $kpis['unmeasured'] < $kpis['total'];
        $anyDeliverableRun = $deliverables['total'] > 0 && $deliverables['done'] + $deliverables['in_flight'] > 0;
        $anyTaskRun        = $tasks['total'] > 0 && $tasks['completed'] > 0;

        if (! $anyKpiRead && ! $anyDeliverableRun && ! $anyTaskRun) {
            return [
                'state'        => self::UNMEASURED,
                'state_reason' => $this->unmeasuredReason($deliverables, $kpis),
                'progress'     => $progress,
            ];
        }

        return [
            'state'        => self::ON_TRACK,
            'state_reason' => $this->onTrackReason($deliverables, $kpis),
            'progress'     => $progress,
        ];
    }

    /**
     * Share of this workstream's work that is finished — or NULL when there is
     * none to measure.
     *
     * ── WHY TASKS COUNT NOW ─────────────────────────────────────────────────
     *
     * This used to divide deliverables alone, which meant a workstream with 20
     * of 20 tasks COMPLETED and four untouched deliverables reported 0% — and
     * reported it while the health rules said ON TRACK. The number and the
     * verdict disagreed because they read different columns.
     *
     * Deliverables and tasks are both units of work this workstream owes, so
     * both belong in the fraction. They are weighted BY COUNT rather than 50/50
     * on purpose: a workstream with 4 deliverables and 20 tasks should not have
     * its figure half-decided by the smaller set. One finished item moves the
     * number by the same amount whichever kind it is, which is the only rule
     * that stays explainable when the mix differs per workstream.
     *
     * Checkpoints are deliberately NOT counted. A checkpoint is a date a
     * deliverable must clear, not a separate piece of work — counting it would
     * bill the same work twice.
     *
     * ── NULL IS STILL NOT ZERO ──────────────────────────────────────────────
     *
     * Null now means no deliverables AND no tasks. Zero percent asserts that
     * work exists and none of it is done; null says nothing has been defined,
     * which is a different fact. The UI renders null as a sentence rather than
     * an empty bar, because an empty bar reads as 0%.
     */
    private function progress(array $deliverables, array $tasks): ?float
    {
        /*
         * A DROPPED deliverable leaves the denominator.
         *
         * It is already excluded from `open` — abandoned work is neither done
         * nor outstanding. But it stayed in `total`, so a workstream that
         * delivered two of four and dropped the other two was pinned at 50%
         * and could never reach 100% no matter what anyone did. `total` itself
         * is left whole because the health counters read it as "deliverables
         * defined", which remains true of one that was dropped.
         */
        $countable = ($deliverables['total'] - ($deliverables['dropped'] ?? 0)) + $tasks['total'];

        if ($countable <= 0) {
            return null;
        }

        return round((($deliverables['done'] + $tasks['completed']) / $countable) * 100, 1);
    }

    private function unmeasuredReason(array $deliverables, array $kpis): string
    {
        $parts = [];

        if ($kpis['unmeasured'] > 0) {
            $parts[] = $this->plural($kpis['unmeasured'], 'KPI has', 'KPIs have') . ' no reading yet';
        }
        if ($deliverables['total'] > 0 && $deliverables['done'] === 0) {
            $parts[] = 'no deliverable has been started';
        }

        return $parts === []
            ? 'This workstream has been planned but nothing has been measured yet.'
            : ucfirst($this->sentence($parts)) . '.';
    }

    private function onTrackReason(array $deliverables, array $kpis): string
    {
        $parts = [];

        if ($deliverables['total'] > 0) {
            $parts[] = sprintf('%d of %d deliverables complete', $deliverables['done'], $deliverables['total']);
        }
        if ($kpis['total'] > 0 && $kpis['unmeasured'] < $kpis['total']) {
            $parts[] = sprintf('%d of %d KPIs met or on track',
                $kpis['met'] + $kpis['on_track'], $kpis['total']);
        }

        return $parts === []
            ? 'Nothing is overdue, failing or at risk.'
            : ucfirst($this->sentence($parts)) . ', and nothing is overdue or failing.';
    }

    /** "1 deliverable is" / "3 deliverables are" — the count always leads. */
    private function plural(int $n, string $singular, string $plural): string
    {
        return $n . ' ' . ($n === 1 ? $singular : $plural);
    }

    /** "a", "a and b", "a, b and c" — never a bare comma list ending in a comma. */
    private function sentence(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }
}
