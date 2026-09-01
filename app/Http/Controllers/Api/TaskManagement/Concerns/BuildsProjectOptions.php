<?php

namespace App\Http\Controllers\Api\TaskManagement\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * ONE definition of "a project, as an option in a picker".
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * `ProjectController::resource()` returns 26 fields per project — status,
 * priority, progress, task counts, member count, manager, sponsor, department,
 * dates, client, budget. Every picker in this module collapsed that into a
 * single string: `"CODE · Name"` at best, and usually just `Name`.
 *
 * The dependency and milestone screens were worse than a display problem: their
 * options were selected as `select('id', 'name')`, so the TRANSPORT carried two
 * fields and there was nothing richer to render even if a screen wanted it.
 *
 * Two lines are all a picker needs to stop being a guessing game — the name, and
 * a hint carrying the code and the manager. `SearchableSelect` already matches on
 * both, so typing a project code finds the project.
 *
 * ── ids ARE CAST TO STRINGS HERE ────────────────────────────────────────────
 *
 * `select('id')` returns an integer while the TypeScript interface has always
 * declared `string`, and `String(...)` wrappers at the call sites were hiding
 * the mismatch. Casting server-side matches what `resource()` already does for
 * every other project field, and lets those wrappers go.
 */
trait BuildsProjectOptions
{
    /**
     * Active projects for this tenant and year, as picker options.
     *
     * @return array<int, array<string, string|null>>
     */
    protected function projectOptions(array $context): array
    {
        return DB::table('task_management_projects as p')
            ->leftJoin('tbluser as manager', 'manager.id', '=', 'p.manager_id')
            ->leftJoin('hrms_departments as dept', 'dept.id', '=', 'p.department_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            // An archived project is not a place to file new work.
            ->whereNull('p.archived_at')
            ->orderBy('p.name')
            ->get([
                'p.id', 'p.code', 'p.name', 'p.status', 'p.start_date', 'p.due_date',
                'dept.department as department',
                DB::raw("TRIM(CONCAT_WS(' ', manager.first_name, manager.middle_name, manager.last_name)) as manager"),
            ])
            ->map(fn ($p) => [
                'id'         => (string) $p->id,
                'code'       => $p->code,
                'name'       => $p->name,
                'status'     => $p->status,
                // Null rather than an empty string: a project with no manager is
                // a different fact from one whose manager's name is blank.
                'manager'    => $p->manager ?: null,
                'department' => $p->department ?: null,
                'start_date' => $p->start_date,
                'due_date'   => $p->due_date,
            ])
            ->values()
            ->all();
    }
}
