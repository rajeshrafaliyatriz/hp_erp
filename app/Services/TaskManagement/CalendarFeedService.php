<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * "The calendar" as a read-model, not a shared row.
 *
 * Merges four independently-owned sources for one date range into one
 * normalised shape — the same "computed per request, never stored" pattern
 * ProjectProgress/WorkstreamRollup already use. Each source keeps its own
 * write path, permissions and audit trail; this class only reads and
 * normalises: {kind, id, title, start, end, all_day, status, owner_id}.
 *
 * Visibility is enforced here via CalendarVisibilityService rather than
 * CRM's two-layer row-access + "Busy" masking: a source row the viewer may
 * not see is simply absent from the merged feed, never a masked placeholder.
 */
class CalendarFeedService
{
    public function __construct(private readonly CalendarVisibilityService $visibility)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function range(string $from, string $to, int $viewerId, int $subInstituteId, string $syear): array
    {
        $visibleOwners = $this->visibility->visibleOwnerIds($viewerId, $subInstituteId);

        $entries = [
            ...$this->tasks($from, $to, $subInstituteId, $syear, $visibleOwners, $viewerId),
            ...$this->events($from, $to, $subInstituteId, $syear, $visibleOwners, $viewerId),
            ...$this->milestones($from, $to, $subInstituteId, $syear, $visibleOwners),
            ...$this->checkpoints($from, $to, $subInstituteId, $syear, $visibleOwners),
        ];

        usort($entries, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return $entries;
    }

    /** @param array<int>|null $visibleOwners */
    private function tasks(string $from, string $to, int $subInstituteId, string $syear, ?array $visibleOwners, int $viewerId): array
    {
        $query = DB::table('task as t')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'assignee.department_id')
            // One project per task for display, same MIN(project_id) collapse
            // WorkspaceController::baseQuery() already uses — the link table
            // allows several projects per task, and joining it raw here would
            // duplicate calendar entries.
            ->leftJoin(DB::raw('(SELECT task_id, MIN(project_id) AS project_id FROM task_management_project_tasks GROUP BY task_id) pt'), 'pt.task_id', '=', 't.id')
            ->leftJoin('task_management_projects as proj', 'proj.id', '=', 'pt.project_id')
            ->where('t.sub_institute_id', $subInstituteId)
            ->where('t.syear', $syear)
            ->whereNull('t.deleted_at')
            // A task occupies a point (its due date), not a span — matching
            // its existing semantics everywhere else in this codebase.
            ->whereRaw('COALESCE(t.task_date, t.planned_start_date) between ? and ?', [$from, $to])
            ->select([
                't.id', 't.task_title', 't.task_date', 't.planned_start_date', 't.status', 't.task_allocated_to',
                'pt.project_id', 'proj.name as project_name',
                'department.id as department_id', 'department.department as department_name',
            ]);

        if ($visibleOwners !== null) {
            $query->whereIn('t.task_allocated_to', $visibleOwners);
            // PRIVATE is invisible to everyone but its own assignee, even a
            // manager it was otherwise shared with — see CalendarVisibilityService.
            $query->where(fn ($q) => $q->whereNull('t.visibility')->orWhere('t.visibility', '!=', 'PRIVATE')->orWhere('t.task_allocated_to', $viewerId));
        }

        return $query->get()->map(function ($row) {
            $date = $row->task_date ?? $row->planned_start_date;

            return [
                'kind' => 'TASK',
                'id' => (string) $row->id,
                'title' => (string) $row->task_title,
                'start' => (string) $date,
                'end' => (string) $date,
                'all_day' => true,
                'status' => (string) ($row->status ?: 'PENDING'),
                'owner_id' => $row->task_allocated_to ? (string) $row->task_allocated_to : null,
                'project_id' => $row->project_id ? (string) $row->project_id : null,
                'project_name' => $row->project_name,
                'department_id' => $row->department_id ? (string) $row->department_id : null,
                'department_name' => $row->department_name,
            ];
        })->all();
    }

    /** @param array<int>|null $visibleOwners */
    private function events(string $from, string $to, int $subInstituteId, string $syear, ?array $visibleOwners, int $viewerId): array
    {
        $query = DB::table('task_management_calendar_events as e')
            // Only resolves when the event is linked directly to a project —
            // WORKSTREAM/TASK/BACKLOG_ITEM links don't trace to one here, same
            // scope CalendarController itself validates against.
            ->leftJoin('task_management_projects as proj', function ($join) {
                $join->on('proj.id', '=', 'e.linked_id')
                    ->where('e.linked_type', '=', 'PROJECT');
            })
            ->leftJoin('tbluser as owner', 'owner.id', '=', 'e.owner_id')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'owner.department_id')
            ->where('e.sub_institute_id', $subInstituteId)
            ->where('e.syear', $syear)
            ->whereNull('e.deleted_at')
            ->whereRaw('e.start_at <= ? and e.end_at >= ?', [$to, $from])
            ->select([
                'e.id', 'e.title', 'e.start_at', 'e.end_at', 'e.all_day', 'e.status', 'e.owner_id', 'e.visibility',
                'proj.id as project_id', 'proj.name as project_name',
                'department.id as department_id', 'department.department as department_name',
            ]);

        if ($visibleOwners !== null) {
            $query->whereIn('e.owner_id', $visibleOwners);
            $query->where(fn ($q) => $q->where('e.visibility', '!=', 'PRIVATE')->orWhere('e.owner_id', $viewerId));
        }

        return $query->get()->map(fn ($row) => [
            'kind' => 'EVENT',
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'start' => (string) $row->start_at,
            'end' => (string) $row->end_at,
            'all_day' => (bool) $row->all_day,
            'status' => (string) $row->status,
            'owner_id' => (string) $row->owner_id,
            'visibility' => (string) $row->visibility,
            'project_id' => $row->project_id ? (string) $row->project_id : null,
            'project_name' => $row->project_name,
            'department_id' => $row->department_id ? (string) $row->department_id : null,
            'department_name' => $row->department_name,
        ])->all();
    }

    /** @param array<int>|null $visibleOwners */
    private function milestones(string $from, string $to, int $subInstituteId, string $syear, ?array $visibleOwners): array
    {
        // No owner_id of its own — a milestone is a project-level event, not
        // an individually-owned one. created_by stands in for "owner" for
        // visibility purposes only. Department prefers the project's own,
        // falling back to the creator's when the project has none set.
        $query = DB::table('task_management_milestones as m')
            ->join('task_management_projects as proj', 'proj.id', '=', 'm.project_id')
            ->leftJoin('tbluser as creator', 'creator.id', '=', 'm.created_by')
            ->leftJoin('hrms_departments as proj_department', 'proj_department.id', '=', 'proj.department_id')
            ->leftJoin('hrms_departments as creator_department', 'creator_department.id', '=', 'creator.department_id')
            ->where('m.sub_institute_id', $subInstituteId)
            ->where('m.syear', $syear)
            ->whereBetween('m.target_date', [$from, $to])
            ->select([
                'm.id', 'm.name', 'm.target_date', 'm.status', 'm.created_by',
                'm.project_id', 'proj.name as project_name',
                DB::raw('COALESCE(proj_department.id, creator_department.id) as department_id'),
                DB::raw('COALESCE(proj_department.department, creator_department.department) as department_name'),
            ]);

        if ($visibleOwners !== null) {
            $query->whereIn('m.created_by', $visibleOwners);
        }

        return $query->get()->map(fn ($row) => [
            'kind' => 'MILESTONE',
            'id' => (string) $row->id,
            'title' => (string) $row->name,
            'start' => (string) $row->target_date,
            'end' => (string) $row->target_date,
            'all_day' => true,
            'status' => (string) $row->status,
            'owner_id' => (string) $row->created_by,
            'project_id' => (string) $row->project_id,
            'project_name' => $row->project_name,
            'department_id' => $row->department_id ? (string) $row->department_id : null,
            'department_name' => $row->department_name,
        ])->all();
    }

    /** @param array<int>|null $visibleOwners */
    private function checkpoints(string $from, string $to, int $subInstituteId, string $syear, ?array $visibleOwners): array
    {
        // Tenancy is inherited checkpoint -> workstream -> project, same as
        // every other workstream child table (none of them carry their own
        // sub_institute_id/syear). owner falls back to the workstream's
        // owner_id, then created_by, since a checkpoint carries no owner of
        // its own. Department comes from the same already-joined project.
        $query = DB::table('task_management_workstream_checkpoints as c')
            ->join('task_management_workstreams as w', 'w.id', '=', 'c.workstream_id')
            ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'p.department_id')
            ->where('p.sub_institute_id', $subInstituteId)
            ->where('p.syear', $syear)
            ->whereNotNull('c.target_date')
            ->whereBetween('c.target_date', [$from, $to])
            ->select([
                'c.id', 'c.name', 'c.target_date', 'c.status',
                'p.id as project_id', 'p.name as project_name',
                'department.id as department_id', 'department.department as department_name',
                DB::raw('COALESCE(w.owner_id, c.created_by) as effective_owner_id'),
            ]);

        if ($visibleOwners !== null) {
            $query->whereIn(DB::raw('COALESCE(w.owner_id, c.created_by)'), $visibleOwners);
        }

        return $query->get()->map(fn ($row) => [
            'kind' => 'CHECKPOINT',
            'id' => (string) $row->id,
            'title' => (string) $row->name,
            'start' => (string) $row->target_date,
            'end' => (string) $row->target_date,
            'all_day' => true,
            'status' => (string) $row->status,
            'owner_id' => $row->effective_owner_id ? (string) $row->effective_owner_id : null,
            'project_id' => (string) $row->project_id,
            'project_name' => $row->project_name,
            'department_id' => $row->department_id ? (string) $row->department_id : null,
            'department_name' => $row->department_name,
        ])->all();
    }
}
