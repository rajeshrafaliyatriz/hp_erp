<?php

namespace App\Services\TaskManagement;

/**
 * One project, one percentage, and the arithmetic behind it.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Progress used to be three unrelated numbers. The project counted completed
 * task links. A workstream counted delivered deliverables and ignored its own
 * tasks entirely. Completing a task wrote `task.status` and nothing else. So a
 * workstream with 20 of 20 tasks done reported 0%, and finishing every task in
 * a project moved the workstream not at all.
 *
 * This class owns the project half of the answer. WorkstreamHealth owns the
 * workstream half. Between them there is exactly one place where each number
 * is decided, which is the only way they can be made to agree.
 *
 * ── ITEM-WEIGHTED, NOT AN AVERAGE OF PERCENTAGES ────────────────────────────
 *
 * The obvious reading of "average across workstreams" is the mean of their
 * percentages. One counterexample kills it:
 *
 *     WS-A   1 deliverable,  1 done   -> 100%
 *     WS-B  40 items,        4 done   ->  10%
 *     mean = 55%.  item-weighted = 5/41 = 12%.
 *
 * 55% is not a number anyone would defend to the team doing WS-B's forty
 * items. Worse, the mean is gameable — adding a one-item finished workstream
 * raises the project — and it breaks the causal chain this whole change is
 * for: under a mean, finishing one of WS-B's forty tasks moves the project by
 * 1.25%, while finishing WS-A's single deliverable moves it 50%.
 *
 * Item-weighting is still a mean; every workstream's vote is simply
 * proportional to how much work it is carrying. The result always sits between
 * the lowest and highest workstream percentage, so it still reads as an
 * average on screen.
 *
 * ── WHY UNPLACED TASKS COUNT ────────────────────────────────────────────────
 *
 * A task linked to the project but not filed under any workstream is still the
 * project's work. Counting it buys one property worth protecting:
 *
 *     FILING A TASK NEVER CHANGES THE PROJECT'S PERCENTAGE.
 *
 * Moving a task from Unassigned into a workstream changes WHERE it counts, not
 * whether it counts. The alternative — ignore unplaced tasks — means the
 * number lurches the first time anyone touches the workstream dropdown, with
 * no work having been done. That is how a metric stops being believed. It
 * matters immediately: today every task on the live tenant is unplaced.
 *
 * ── GOVERNANCE IS EXCLUDED ──────────────────────────────────────────────────
 *
 * A governance layer spans the delivery flow rather than advancing it, so its
 * deliverables do not move the project. Its own card still shows its own
 * percentage — that asymmetry is deliberate, not an oversight.
 *
 * ── NO STORED COLUMN ────────────────────────────────────────────────────────
 *
 * Computed per request, for the same reason health is: a stored percentage
 * goes stale the moment a task is soft-deleted or a deliverable is dropped,
 * and nothing in those paths would know to recompute it.
 */
class ProjectProgress
{
    /**
     * @param  array{deliverables:array{done:int,total:int},tasks:array{done:int,total:int},unplaced:array{done:int,total:int},delivery_workstreams:int}  $totals
     * @return array{percent:int, basis:array}
     */
    public function evaluate(array $totals): array
    {
        $deliverables = $totals['deliverables'];
        $placed       = $totals['tasks'];
        $unplaced     = $totals['unplaced'];

        $done  = $deliverables['done'] + $placed['done'] + $unplaced['done'];
        $total = $deliverables['total'] + $placed['total'] + $unplaced['total'];

        /*
         * `source` is what lets a reader — and a support ticket — tell "0%
         * because nothing is done" apart from "0% because there is nothing to
         * measure". The frontend renders NONE as a sentence rather than an
         * empty bar, mirroring the null rule on a workstream.
         *
         * The percentage itself stays a plain int and never null: it is typed
         * `number` on the client and rendered bare in two places.
         */
        $source = 'NONE';
        if ($total > 0) {
            $source = $deliverables['total'] > 0 ? 'WORKSTREAMS' : 'TASKS';
        }

        return [
            'percent' => $total > 0 ? (int) round($done * 100 / $total) : 0,
            'basis'   => [
                'done'         => $done,
                'total'        => $total,
                'deliverables' => $deliverables,
                'tasks'        => [
                    'done'  => $placed['done'] + $unplaced['done'],
                    'total' => $placed['total'] + $unplaced['total'],
                ],
                'unplaced_tasks'       => $unplaced,
                'delivery_workstreams' => $totals['delivery_workstreams'],
                'source'               => $source,
            ],
        ];
    }
}
