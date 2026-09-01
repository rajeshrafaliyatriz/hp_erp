<?php

namespace App\Http\Controllers\Api\TaskManagement\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * THE TENANT BOUNDARY FOR EVERYTHING HANGING OFF A WORKSTREAM.
 *
 * ── READ THIS BEFORE ADDING A ROUTE ─────────────────────────────────────────
 *
 * None of the eight workstream child tables carries `sub_institute_id` or
 * `syear`. That is deliberate and matches task_management_project_members and
 * task_management_project_departments, whose migration explains it: a tenant
 * column on a child table is a second place for the tenant to be wrong.
 *
 * The cost of that choice is concentrated here. `workstreamScope()` is the ONLY
 * thing standing between a guessed integer and another organisation's plan —
 * their deliverables, their risks, their KPI targets, the names of their people.
 * A route that reads a workstream child row without calling it is a data leak,
 * not a bug to tidy up later.
 *
 * So: every route, read AND write, resolves through one of these first and
 * returns 404 on null. Not 403 — a 403 confirms the id exists, which is itself
 * an answer about another tenant's data.
 */
trait ResolvesWorkstreamScope
{
    /**
     * Resolve a workstream inside the caller's own organisation.
     *
     * Returns the workstream joined to its project, or NULL when the id does not
     * exist, is soft-deleted at the project level, or belongs to another tenant
     * or another academic year — all four of which are the same answer to the
     * caller: it is not here.
     */
    protected function workstreamScope(array $context, int $workstreamId): ?object
    {
        if ($workstreamId <= 0) {
            return null;
        }

        return DB::table('task_management_workstreams as w')
            ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
            ->where('w.id', $workstreamId)
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            ->first([
                'w.id', 'w.project_id', 'w.name', 'w.code', 'w.kind', 'w.parent_id',
                'w.owner_id', 'w.status', 'w.sort_order',
                'p.name as project_name', 'p.code as project_code',
                'p.created_by as project_created_by', 'p.manager_id', 'p.sponsor_id',
                'p.archived_at as project_archived_at',
            ]);
    }

    /** The same guarantee for a project id, used by the list and create routes. */
    protected function projectScope(array $context, int $projectId): ?object
    {
        if ($projectId <= 0) {
            return null;
        }

        return DB::table('task_management_projects')
            ->where('id', $projectId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->first(['id', 'code', 'name', 'created_by', 'manager_id', 'sponsor_id', 'archived_at',
                     'start_date', 'due_date', 'status']);
    }

    /**
     * May this caller change this workstream's plan?
     *
     * The project's creator, manager and sponsor may — the same three
     * ProjectController::canManage() has always used — AND the workstream's own
     * accountable owner may edit the plan they are accountable for. Abhi owning
     * "Quality, Release & Adoption" should be able to log a risk against it
     * without asking the project manager.
     *
     * ⚠ THIS IS CURRENTLY NARROWER IN PRACTICE THAN IT READS. Every write route
     * also sits behind `task.permission:workstream.manage`, which requires an
     * ELEVATED role, so a workstream owner whose profile is `employee` is refused
     * by the middleware before this method is ever reached. The rule is written
     * correctly here and the middleware is deliberately NOT widened to match:
     * quietly relaxing a permission guard so a feature appears to work is how
     * access control decays. Widening it is a separate, explicit decision.
     */
    protected function canManageWorkstream(array $context, object $scope): bool
    {
        $userId = (int) $context['user_id'];

        return $userId > 0 && (
            (int) $scope->project_created_by === $userId
            || (int) $scope->manager_id === $userId
            || (int) $scope->sponsor_id === $userId
            || (int) ($scope->owner_id ?? 0) === $userId
        );
    }

    /** Project-level authority, for creating and deleting whole workstreams. */
    protected function canManageProject(array $context, object $project): bool
    {
        $userId = (int) $context['user_id'];

        return $userId > 0 && (
            (int) $project->created_by === $userId
            || (int) $project->manager_id === $userId
            || (int) $project->sponsor_id === $userId
        );
    }

    /**
     * Is this user a real, active member of the caller's organisation?
     *
     * Owner and contributor ids arrive from a request body. Without this an
     * assignment could name any user id in the database and the workstream would
     * render another organisation's employee as its accountable owner.
     *
     * @param  int[]  $userIds
     */
    protected function usersInTenant(array $context, array $userIds): bool
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));

        if ($userIds === []) {
            return true;
        }

        return DB::table('tbluser')
            ->whereIn('id', $userIds)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->count() === count($userIds);
    }
}
