<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * Count everything hanging off a project's workstreams, in a fixed number of
 * queries.
 *
 * ── SIX QUERIES FOR THE WHOLE PROJECT, NOT SIX PER WORKSTREAM ───────────────
 *
 * Every count below is one GROUP BY across all of the project's workstreams at
 * once, assembled in PHP. This is the shape DependencyController::withMilestoneCounts
 * already uses, and its reasoning applies unchanged: "counting happens in PHP so
 * the number of milestones does not become a number of queries." A project that
 * grows from four workstreams to forty issues exactly the same six queries.
 *
 * ── TENANCY LIVES IN THE PROJECT JOIN ───────────────────────────────────────
 *
 * None of the workstream child tables carries sub_institute_id or syear, by
 * design. Every query here therefore reaches the project — either by starting
 * from a workstream id set that was already scoped, or by joining
 * task_management_projects explicitly where it touches shared tables like `task`.
 *
 * ── WHAT IS DELIBERATELY NOT COUNTED ────────────────────────────────────────
 *
 * `severity` is read from the stored column rather than recomputed from
 * probability x impact at read time. The controller writes it on save precisely
 * so this can GROUP BY it, and so the register can ORDER BY it. Recomputing here
 * would be a second implementation of the matrix and the two would drift.
 */
class WorkstreamRollup
{
    /** Deliverable statuses that mean the thing exists and is accepted. */
    private const DELIVERED = ['DELIVERED', 'ACCEPTED'];

    /** Deliverable statuses that mean somebody is working on it. */
    private const IN_FLIGHT = ['IN PROGRESS', 'IN REVIEW'];

    /** Risk severities treated as failure rather than drift. */
    private const SEVERE = ['High', 'Regulated'];

    /**
     * @param  int[]  $workstreamIds  already tenant-scoped by the caller
     * @return array<int, array>  keyed by workstream id
     */
    public function forProject(array $context, int $projectId, array $workstreamIds): array
    {
        if ($workstreamIds === []) {
            return [];
        }

        $today = now()->toDateString();

        $deliverables = $this->deliverables($workstreamIds, $today);
        $kpis         = $this->kpis($workstreamIds);
        $risks        = $this->risks($workstreamIds);
        $checkpoints  = $this->checkpoints($workstreamIds, $today);
        $tasks        = $this->tasks($context, $projectId, $today);

        $out = [];

        foreach ($workstreamIds as $id) {
            $id = (int) $id;
            $out[$id] = [
                'deliverables' => $deliverables[$id] ?? $this->emptyDeliverables(),
                'kpis'         => $kpis[$id] ?? $this->emptyKpis(),
                'risks'        => $risks[$id] ?? $this->emptyRisks(),
                'milestones'   => $checkpoints[$id] ?? $this->emptyCheckpoints(),
                'tasks'        => $tasks[$id] ?? $this->emptyTasks(),
            ];
        }

        return $out;
    }

    /**
     * The work totals behind each project's percentage — for MANY projects.
     *
     * ── THREE QUERIES, WHATEVER THE PAGE SIZE ───────────────────────────────
     *
     * The projects list renders up to 100 rows. Asking per project would make
     * this an N+1 the moment somebody paginates, so every query below takes an
     * `IN (...)` of project ids and groups in PHP. The query count in this
     * method does not mention the number of projects or workstreams anywhere.
     *
     * Returns, per project id:
     *   deliverables         done/total across its DELIVERY workstreams only
     *   tasks                done/total for tasks filed under those workstreams
     *   unplaced             done/total for tasks linked to the project with no
     *                        workstream — still the project's work
     *   delivery_workstreams how many counted, so the UI can say so
     *
     * @param  int[]  $projectIds
     * @return array<int, array>  keyed by project id
     */
    public function projectTotals(array $context, array $projectIds): array
    {
        $out = [];
        foreach ($projectIds as $id) {
            $out[(int) $id] = [
                'deliverables'         => ['done' => 0, 'total' => 0],
                'tasks'                => ['done' => 0, 'total' => 0],
                'unplaced'             => ['done' => 0, 'total' => 0],
                'delivery_workstreams' => 0,
            ];
        }

        if ($projectIds === []) {
            return $out;
        }

        // ── 1 · which workstreams count, and for which project ──────────────
        // Governance spans the flow rather than advancing it, so it is filtered
        // out here — once, rather than in each caller.
        $workstreams = DB::table('task_management_workstreams as w')
            ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            ->whereIn('w.project_id', $projectIds)
            ->where('w.kind', 'DELIVERY')
            ->get(['w.id', 'w.project_id']);

        $projectOfWorkstream = [];
        foreach ($workstreams as $row) {
            $projectOfWorkstream[(int) $row->id] = (int) $row->project_id;
            $out[(int) $row->project_id]['delivery_workstreams']++;
        }

        // ── 2 · deliverables on those workstreams ───────────────────────────
        if ($projectOfWorkstream !== []) {
            $rows = DB::table('task_management_workstream_deliverables')
                ->whereIn('workstream_id', array_keys($projectOfWorkstream))
                ->groupBy('workstream_id', 'status')
                ->get([DB::raw('workstream_id'), DB::raw('status'), DB::raw('COUNT(*) as n')]);

            foreach ($rows as $row) {
                $project = $projectOfWorkstream[(int) $row->workstream_id] ?? null;
                if ($project === null) {
                    continue;
                }
                $status = strtoupper((string) $row->status);
                $n      = (int) $row->n;

                // A dropped deliverable leaves the denominator entirely — it is
                // neither done nor outstanding, and leaving it in would cap the
                // project below 100% forever.
                if ($status === 'DROPPED') {
                    continue;
                }

                $out[$project]['deliverables']['total'] += $n;
                if (in_array($status, self::DELIVERED, true)) {
                    $out[$project]['deliverables']['done'] += $n;
                }
            }
        }

        // ── 3 · every linked task, placed or not ────────────────────────────
        $tasks = DB::table('task_management_project_tasks as pt')
            ->join('task as t', 't.id', '=', 'pt.task_id')
            // project_tasks has no tenant column of its own; the project carries it.
            ->join('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            ->whereIn('pt.project_id', $projectIds)
            ->whereNull('t.deleted_at')
            ->get(['pt.project_id', 'pt.workstream_id', 't.status']);

        foreach ($tasks as $row) {
            $project = (int) $row->project_id;
            if (!isset($out[$project])) {
                continue;
            }

            // Placed under a DELIVERY workstream, or not placed at all — a task
            // filed under a GOVERNANCE workstream counts on neither side, the
            // same way that workstream's deliverables do not.
            $workstream = $row->workstream_id !== null ? (int) $row->workstream_id : null;
            if ($workstream !== null && !isset($projectOfWorkstream[$workstream])) {
                continue;
            }

            $bucket = $workstream === null ? 'unplaced' : 'tasks';
            $out[$project][$bucket]['total']++;

            if (strtoupper((string) $row->status) === 'COMPLETED') {
                $out[$project][$bucket]['done']++;
            }
        }

        return $out;
    }

    /**
     * Deliverables by status, plus how many are past their due date.
     *
     * Overdue is computed in SQL rather than by fetching rows, because it is the
     * one count that depends on today rather than on the stored status — a
     * deliverable nobody has touched becomes overdue on its own.
     */
    private function deliverables(array $ids, string $today): array
    {
        $rows = DB::table('task_management_workstream_deliverables')
            ->whereIn('workstream_id', $ids)
            ->selectRaw('workstream_id, status, COUNT(*) AS n')
            ->selectRaw('SUM(CASE WHEN due_date IS NOT NULL AND due_date < ?
                                   AND status NOT IN (?, ?) THEN 1 ELSE 0 END) AS overdue',
                [$today, ...self::DELIVERED])
            ->groupBy('workstream_id', 'status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->workstream_id;
            $out[$id] ??= $this->emptyDeliverables();

            $status = strtoupper((string) $row->status);
            $n      = (int) $row->n;

            $out[$id]['total']   += $n;
            $out[$id]['overdue'] += (int) $row->overdue;

            // Tracked separately so progress can divide by `total - dropped`.
            // `total` itself stays whole: the health counters and the UI read it
            // as "deliverables defined", which is still true of a dropped one.
            if ($status === 'DROPPED') {
                $out[$id]['dropped'] += $n;
            }

            if (in_array($status, self::DELIVERED, true)) {
                $out[$id]['done'] += $n;
            } elseif (in_array($status, self::IN_FLIGHT, true)) {
                $out[$id]['in_flight'] += $n;
                $out[$id]['open']      += $n;
            } elseif ($status !== 'DROPPED') {
                // DROPPED is neither done nor outstanding — it was abandoned, and
                // counting it as open would make a closed workstream look unfinished.
                $out[$id]['open'] += $n;
            }
        }

        return $out;
    }

    private function kpis(array $ids): array
    {
        $rows = DB::table('task_management_workstream_kpis')
            ->whereIn('workstream_id', $ids)
            ->selectRaw('workstream_id, status, COUNT(*) AS n')
            ->groupBy('workstream_id', 'status')
            ->get();

        $map = [
            'MET' => 'met', 'ON_TRACK' => 'on_track', 'AT_RISK' => 'at_risk',
            'OFF_TRACK' => 'off_track', 'UNMEASURED' => 'unmeasured',
        ];

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->workstream_id;
            $out[$id] ??= $this->emptyKpis();

            $out[$id]['total'] += (int) $row->n;

            // An unrecognised status counts as UNMEASURED, not as passing. A
            // value nobody planned for must not silently improve the verdict.
            $key = $map[strtoupper((string) $row->status)] ?? 'unmeasured';
            $out[$id][$key] += (int) $row->n;
        }

        return $out;
    }

    private function risks(array $ids): array
    {
        $rows = DB::table('task_management_workstream_risks')
            ->whereIn('workstream_id', $ids)
            ->selectRaw('workstream_id, status, severity, COUNT(*) AS n')
            ->groupBy('workstream_id', 'status', 'severity')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->workstream_id;
            $out[$id] ??= $this->emptyRisks();

            $n      = (int) $row->n;
            $open   = strtoupper((string) $row->status) === 'OPEN';
            $sev    = (string) $row->severity;

            if (! $open) {
                $out[$id]['closed'] += $n;
                continue;
            }

            $out[$id]['open'] += $n;

            /*
             * REGULATED IS COUNTED SEPARATELY FROM HIGH, and the health rules
             * treat them differently. An open High risk is something that MIGHT
             * fail; an open Regulated exposure is a compliance problem that
             * exists now. Collapsing the two made every workstream with a
             * well-documented risk register read as already failing.
             */
            if ($sev === 'Regulated') {
                $out[$id]['regulated_open'] += $n;
            } elseif ($sev === 'High') {
                $out[$id]['severe_open'] += $n;
            } elseif ($sev === 'Medium') {
                $out[$id]['moderate_open'] += $n;
            }
        }

        return $out;
    }

    /**
     * Checkpoints — reported under the `milestones` key.
     *
     * The health rules and the API speak of "milestones" because that is the
     * customer's word for a dated commitment. Internally these are workstream
     * checkpoints, which are a different table from project milestones for the
     * reasons the migration sets out.
     */
    private function checkpoints(array $ids, string $today): array
    {
        $rows = DB::table('task_management_workstream_checkpoints')
            ->whereIn('workstream_id', $ids)
            ->selectRaw('workstream_id, COUNT(*) AS n')
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(status,'')) = 'COMPLETED' THEN 1 ELSE 0 END) AS completed")
            ->selectRaw("SUM(CASE WHEN target_date IS NOT NULL AND target_date < ?
                                   AND UPPER(COALESCE(status,'')) <> 'COMPLETED' THEN 1 ELSE 0 END) AS overdue", [$today])
            ->groupBy('workstream_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->workstream_id] = [
                'total'     => (int) $row->n,
                'completed' => (int) $row->completed,
                'overdue'   => (int) $row->overdue,
            ];
        }

        return $out;
    }

    /**
     * Linked tasks per workstream, plus how many are blocked.
     *
     * Grouped in PHP rather than SQL because the blocked set is a second query
     * and intersecting it per workstream is cheaper in memory than a correlated
     * subquery per row. Lifted from withMilestoneCounts, which solves the
     * identical problem.
     */
    private function tasks(array $context, int $projectId, string $today): array
    {
        $rows = DB::table('task_management_project_tasks as pt')
            ->join('task as t', 't.id', '=', 'pt.task_id')
            // The tenant carrier: project_tasks has no tenant column of its own.
            ->join('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            ->where('pt.project_id', $projectId)
            ->whereNotNull('pt.workstream_id')
            ->whereNull('t.deleted_at')
            ->get(['pt.workstream_id', 't.id', 't.status', 't.task_date']);

        if ($rows->isEmpty()) {
            return [];
        }

        $blocked = DB::table('task_management_dependencies as d')
            ->join('task as pred', 'pred.id', '=', 'd.predecessor_task_id')
            ->where('d.sub_institute_id', $context['sub_institute_id'])
            ->where('d.syear', $context['syear'])
            ->whereNull('pred.deleted_at')
            ->whereRaw("UPPER(COALESCE(pred.status, 'PENDING')) <> 'COMPLETED'")
            ->pluck('d.successor_task_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->workstream_id;
            $out[$id] ??= $this->emptyTasks();

            $status = strtoupper((string) $row->status);
            $out[$id]['total']++;

            if ($status === 'COMPLETED') {
                $out[$id]['completed']++;
            } elseif ($row->task_date && $row->task_date < $today) {
                $out[$id]['overdue']++;
            }

            if ($blocked->has((int) $row->id)) {
                $out[$id]['blocked']++;
            }
        }

        return $out;
    }

    private function emptyDeliverables(): array
    {
        return ['total' => 0, 'done' => 0, 'dropped' => 0, 'in_flight' => 0, 'open' => 0, 'overdue' => 0];
    }

    private function emptyKpis(): array
    {
        return ['total' => 0, 'met' => 0, 'on_track' => 0, 'at_risk' => 0, 'off_track' => 0, 'unmeasured' => 0];
    }

    private function emptyRisks(): array
    {
        return ['open' => 0, 'closed' => 0, 'regulated_open' => 0, 'severe_open' => 0, 'moderate_open' => 0];
    }

    private function emptyCheckpoints(): array
    {
        return ['total' => 0, 'completed' => 0, 'overdue' => 0];
    }

    private function emptyTasks(): array
    {
        return ['total' => 0, 'completed' => 0, 'overdue' => 0, 'blocked' => 0];
    }
}
