<?php

namespace App\Services\Org;

use App\Services\Org\Concerns\RepointsReferences;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Moving or releasing everything that points at a department.
 *
 * Deleting a department used to soft-delete the row and stop, leaving every
 * reference in ~34 other tables pointing at something invisible. That is how
 * live lost nine departments in tenant 6 and stranded 77 skill records and
 * seven employees, which had to be reconstructed by hand from denormalised
 * name columns.
 *
 * Two operations live here:
 *
 *   merge()   - department A's references become department B's, then A is
 *               retired. Nothing is lost.
 *   release() - the references are set to NULL, so they are unassigned rather
 *               than dangling. Used by delete.
 *
 * Extracted from DepartmentsDedupe, which had the repointing engine but three
 * behaviours that are wrong for an HTTP endpoint: it swallowed failures into a
 * console warning, it prompted on stdin, and it was tenant-unscoped. All three
 * are fixed here; the command now calls this.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 *
 * competency_kasba_item is reachable from a department in two hops
 * (competency_id -> jobrole_competency_map.jobrole_id -> s_user_jobrole
 * .department_id) and it is TEMPTING to move it. It must not be moved: the
 * same competency is required by job roles in many departments, so for one
 * live department 14 items look "reachable" and moving them would rewrite
 * other departments' requirements. It is tenant-shared, not department-owned.
 *
 * competency_kasba_rating is reachable two ways that disagree - for one live
 * department, 4 rows via user_id and 28 via the job-role chain. Only the 4 are
 * really that department's. It is not repointed at all: it hangs off user_id,
 * and moving tbluser.department_id carries it correctly for free.
 *
 * The rule both cases share: repoint what a department OWNS, never what it
 * merely REACHES.
 */
class DepartmentMergeService
{
    /** repoint(), countIn() and hasColumn() - shared with JobRoleMergeService. */
    use RepointsReferences;

    /**
     * Tables owned by a department through `department_id`.
     *
     * Explicit rather than discovered at runtime: a merge that silently skipped
     * a table nobody remembered would strand those rows. Each carries a label
     * because these counts are shown to a user before they confirm.
     *
     * @var array<string,string> table => human label
     */
    public const DEPARTMENT_ID_TABLES = [
        'tbluser'                                 => 'employees',
        's_user_jobrole'                          => 'job roles',
        's_users_skills'                          => 'skills',
        'discliplinary_management'                => 'disciplinary records',
        'hrms_emp_leaves'                         => 'leave records',
        'hrms_leave_allocation'                   => 'leave allocations',
        'hrms_salary_certificate'                 => 'salary certificates',
        'task_management_projects'                => 'projects',
        'task_management_project_departments'     => 'project links',
        's_competency_frameworks'                 => 'competency frameworks',
        's_competency_assessments'                => 'competency assessments',
        's_competency_certifications'             => 'certifications',
        's_competency_development_plans'          => 'development plans',
        's_competency_mapping_reviews'            => 'mapping reviews',
        's_competency_career_paths'               => 'career paths',
        's_competency_certification_requirements' => 'certification requirements',
        's_performance_goals'                     => 'performance goals',
        's_performance_reviews'                   => 'performance reviews',
        's_performance_appraisals'                => 'appraisals',
        's_performance_compensation_revisions'    => 'compensation revisions',
        's_performance_bonus_awards'              => 'bonus awards',
        's_performance_calibration_sessions'      => 'calibration sessions',
        'talent_onboarding_journeys'              => 'onboarding journeys',
        'talent_offboarding_cases'                => 'offboarding cases',
        'talent_internal_jobs'                    => 'internal jobs',
        'talent_succession_plans'                 => 'succession plans',
        'talent_team_members'                     => 'team members',
        'talent_job_postings'                     => 'job postings',
        's_mobility_jobs'                         => 'mobility jobs',
        // Added after the dedupe command was written. Empty today, but a merge
        // without them would orphan a department's own documents.
        'department_sops'                         => 'SOPs',
        'department_policies'                     => 'policies',
        'department_rules'                        => 'rules',
    ];

    /**
     * The LMS reuses departments as academic "standards", through `standard_id`
     * and a REAL foreign key - so these cannot be released to NULL and cannot
     * be left behind. A merge repoints them; a delete is refused because of
     * them.
     *
     * @var array<string,string>
     */
    public const STANDARD_ID_TABLES = [
        'lms_question_master' => 'question bank entries',
        'chapter_master'      => 'chapters',
        'content_master'      => 'content items',
        'sub_std_map'         => 'subject mappings',
        'lms_curriculum'      => 'curriculum entries',
        'lms_lesson_plan'     => 'lesson plans',
        'lms_flashcard'       => 'flashcards',
    ];

    /** From/to department columns, which move independently. */
    public const PAIRED_COLUMNS = [
        ['s_mobility_transfers',     'from_department_id', 'transfers out'],
        ['s_mobility_transfers',     'to_department_id',   'transfers in'],
        ['talent_mobility_requests', 'from_department_id', 'mobility requests out'],
        ['talent_mobility_requests', 'to_department_id',   'mobility requests in'],
    ];

    /**
     * Columns holding a department NAME rather than an id.
     *
     * A repoint by id leaves these reading the old department's name, which is
     * how a report grouped by name and a report grouped by id end up
     * disagreeing. They are rewritten to the surviving department's name.
     *
     * @var array<int,array{0:string,1:string}>
     */
    public const NAME_COLUMNS = [
        ['s_user_jobrole',               'department'],
        ['s_users_skills',               'department'],
        ['s_competency_career_paths',    'department'],
        ['s_competency_mapping_reviews', 'department'],
        ['s_mobility_jobs',              'department'],
        ['s_industries',                 'department'],
    ];

    /** Name columns that come in from/to pairs. */
    public const PAIRED_NAME_COLUMNS = [
        ['s_mobility_transfers',     'from_department_id', 'from_department'],
        ['s_mobility_transfers',     'to_department_id',   'to_department'],
        ['talent_mobility_requests', 'from_department_id', 'from_department'],
        ['talent_mobility_requests', 'to_department_id',   'to_department'],
    ];

    /*
     * JOBROLE_ID_TABLES used to live here. It has moved to
     * JobRoleMergeService::ROLE_ID_TABLES, which owns the whole question of
     * what a job role points at - including the name columns and the two
     * unique keys this list never accounted for.
     */

    /**
     * Everything attached to a department, labelled, for the confirmation
     * dialog.
     *
     * Counts only what the department OWNS. A count the caller cannot verify is
     * worse than no count, so a table that does not exist on this database is
     * omitted rather than reported as zero.
     *
     * $includeDescendants distinguishes the two callers, and they genuinely
     * differ: DELETE cascades to the whole subtree, so its preview must count
     * the subtree. MERGE moves only this department - its children are
     * re-parented to the survivor and keep their own data - so counting their
     * rows would overstate what is about to move.
     *
     * @return array{total:int, breakdown:array<int,array{label:string,count:int,blocking:bool}>, lms_blocking:int}
     */
    public function impact(int $departmentId, int $tenantId, ?ConnectionInterface $db = null, bool $includeDescendants = true): array
    {
        $db  = $db ?: DB::connection();
        $ids = $includeDescendants
            ? array_merge([$departmentId], $this->descendantIds($departmentId, $tenantId, $db))
            : [$departmentId];

        $breakdown   = [];
        $total       = 0;
        $lmsBlocking = 0;

        foreach (self::DEPARTMENT_ID_TABLES as $table => $label) {
            $count = $this->countIn($db, $table, 'department_id', $ids);
            if ($count > 0) {
                $breakdown[] = ['label' => $label, 'count' => $count, 'blocking' => false];
                $total += $count;
            }
        }

        foreach (self::PAIRED_COLUMNS as [$table, $column, $label]) {
            $count = $this->countIn($db, $table, $column, $ids);
            if ($count > 0) {
                $breakdown[] = ['label' => $label, 'count' => $count, 'blocking' => false];
                $total += $count;
            }
        }

        // LMS rows are flagged separately: they are the reason a delete is
        // refused, and the reason merge is offered as the way out.
        foreach (self::STANDARD_ID_TABLES as $table => $label) {
            $count = $this->countIn($db, $table, 'standard_id', $ids);
            if ($count > 0) {
                $breakdown[] = ['label' => $label, 'count' => $count, 'blocking' => true];
                $total       += $count;
                $lmsBlocking += $count;
            }
        }

        return [
            'total'         => $total,
            'breakdown'     => $breakdown,
            'lms_blocking'  => $lmsBlocking,
            'sub_departments' => count($ids) - 1,
        ];
    }

    /**
     * Move everything from one department to another, then retire the source.
     *
     * The caller must already have established that both departments belong to
     * the tenant. Runs in a single transaction: a merge either lands whole or
     * not at all.
     *
     * @return array{moved:array<string,int>, employees:int, job_roles_folded:int, children:int}
     */
    public function merge(int $fromId, int $toId, int $tenantId, ?int $actorId, ?ConnectionInterface $db = null): array
    {
        $db = $db ?: DB::connection();

        $toName   = $db->table('hrms_departments')->where('id', $toId)->value('department');
        $fromName = $db->table('hrms_departments')->where('id', $fromId)->value('department');

        $moved           = [];
        $employeesMoved  = 0;
        $rolesFolded     = 0;
        $childrenMoved   = 0;

        $db->transaction(function () use ($db, $fromId, $toId, $tenantId, $actorId, $toName, $fromName, &$moved, &$employeesMoved, &$rolesFolded, &$childrenMoved) {
            // 1. Employees first, so each move can be recorded individually
            //    before the department they came from is retired.
            $employeesMoved = $this->moveEmployees($db, $fromId, $toId, $tenantId, $actorId, $fromName, $toName);

            // 2. Same-named job roles fold together, so B does not end up with
            //    two "Senior Engineer" rows.
            $rolesFolded = $this->foldDuplicateJobRoles($db, $fromId, $toId, $tenantId);

            // 3. The project pivot has UNIQUE(project_id, department_id), and a
            //    project linked to BOTH departments collides. Resolve before
            //    the blanket repoint below, or the whole merge fails.
            $this->resolveProjectPivotCollisions($db, $fromId, $toId);

            // 4. Everything else that carries department_id.
            foreach (array_keys(self::DEPARTMENT_ID_TABLES) as $table) {
                $n = $this->repoint($db, $table, 'department_id', $fromId, $toId);
                if ($n > 0) {
                    $moved[$table] = $n;
                }
            }

            foreach (self::PAIRED_COLUMNS as [$table, $column]) {
                $n = $this->repoint($db, $table, $column, $fromId, $toId);
                if ($n > 0) {
                    $moved["{$table}.{$column}"] = $n;
                }
            }

            // 5. LMS content. These carry a hard foreign key, which is exactly
            //    why merge is the only way to resolve a blocked delete.
            foreach (array_keys(self::STANDARD_ID_TABLES) as $table) {
                $n = $this->repoint($db, $table, 'standard_id', $fromId, $toId);
                if ($n > 0) {
                    $moved[$table] = $n;
                }
            }

            // 6. A project keeps its department in TWO places - the projects
            //    row and the is_primary pivot row. Step 4 moved both; this
            //    makes sure they still agree.
            $this->syncProjectPrimaryDepartments($db, $toId);

            // 7. Name strings, or a report grouped by name disagrees with the
            //    same report grouped by id.
            $this->rewriteNames($db, $fromId, $toId, (string) $toName);

            // 8. Departments whose id is packed into a CSV or JSON column.
            $this->rewritePackedReferences($db, $fromId, $toId);

            // 9. A's children become B's, rather than being orphaned by the
            //    soft delete below.
            $childrenMoved = $db->table('hrms_departments')
                ->where('parent_id', $fromId)
                ->where('sub_institute_id', $tenantId)
                ->update(['parent_id' => $toId, 'updated_at' => now()]);

            // 10. Retire the source.
            $db->table('hrms_departments')
                ->where('id', $fromId)
                ->where('sub_institute_id', $tenantId)
                ->update([
                    'status'     => 0,
                    'deleted_by' => $actorId,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return [
            'moved'            => $moved,
            'employees'        => $employeesMoved,
            'job_roles_folded' => $rolesFolded,
            'children'         => $childrenMoved,
        ];
    }

    /**
     * Release everything pointing at a department instead of moving it.
     *
     * Used by delete. The references become NULL - unassigned and visible as
     * such - rather than pointing at a row the UI can no longer show. That
     * distinction is the whole point: an unassigned employee is fixable, a
     * dangling one is invisible.
     *
     * Employees are NEVER deleted, only unassigned.
     *
     * @return array{released:array<string,int>, employees:int}
     */
    public function release(array $departmentIds, int $tenantId, ?int $actorId, ?ConnectionInterface $db = null): array
    {
        $db = $db ?: DB::connection();

        $released  = [];
        $employees = 0;

        $db->transaction(function () use ($db, $departmentIds, $tenantId, $actorId, &$released, &$employees) {
            // Employees keep every record they have; they simply have no
            // department until someone assigns one.
            $employees = $db->table('tbluser')
                ->whereIn('department_id', $departmentIds)
                ->where('sub_institute_id', $tenantId)
                ->update(['department_id' => null, 'updated_at' => now()]);

            // The department's own content goes with it - these belong to the
            // department and mean nothing without it.
            foreach (['s_user_jobrole', 'department_sops', 'department_policies', 'department_rules'] as $owned) {
                try {
                    $n = $db->table($owned)
                        ->whereIn('department_id', $departmentIds)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => now(), 'updated_at' => now()]);
                    if ($n > 0) {
                        $released[$owned] = $n;
                    }
                } catch (\Throwable $e) {
                    // Table absent on this database - not an error.
                }
            }

            // Everything else is released rather than deleted: a performance
            // review still happened, it just no longer names a department.
            foreach (array_keys(self::DEPARTMENT_ID_TABLES) as $table) {
                if (in_array($table, ['tbluser', 's_user_jobrole', 'department_sops', 'department_policies', 'department_rules'], true)) {
                    continue;
                }

                $n = $this->repoint($db, $table, 'department_id', $departmentIds, null);
                if ($n > 0) {
                    $released[$table] = $n;
                }
            }

            foreach (self::PAIRED_COLUMNS as [$table, $column]) {
                $n = $this->repoint($db, $table, $column, $departmentIds, null);
                if ($n > 0) {
                    $released["{$table}.{$column}"] = $n;
                }
            }

            $db->table('hrms_departments')
                ->whereIn('id', $departmentIds)
                ->where('sub_institute_id', $tenantId)
                ->update([
                    'status'     => 0,
                    'deleted_by' => $actorId,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return ['released' => $released, 'employees' => $employees];
    }

    /**
     * Every department below this one. Iterative, because live runs MariaDB
     * 10.1 which has no recursive CTEs, and because a visited set is what stops
     * the handful of rows with a broken parent_id spinning forever.
     *
     * @return list<int>
     */
    public function descendantIds(int $departmentId, int $tenantId, ?ConnectionInterface $db = null): array
    {
        $db = $db ?: DB::connection();

        $found    = [];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $children = $db->table('hrms_departments')
                ->whereIn('parent_id', $frontier)
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $found, [$departmentId]));
            $found    = array_merge($found, $frontier);
        }

        return $found;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Move employees and write one transfer record each.
     *
     * Both the department AND the job role move: a role belongs to exactly one
     * department, so leaving allocated_standards behind would leave the
     * employee holding a role from the department they just left.
     */
    private function moveEmployees($db, int $fromId, int $toId, int $tenantId, ?int $actorId, ?string $fromName, ?string $toName): int
    {
        $employees = $db->table('tbluser')
            ->where('department_id', $fromId)
            ->where('sub_institute_id', $tenantId)
            ->get(['id']);

        if ($employees->isEmpty()) {
            return 0;
        }

        $today = now()->toDateString();
        $rows  = [];

        foreach ($employees as $employee) {
            $rows[] = [
                'sub_institute_id'   => $tenantId,
                'user_id'            => $employee->id,
                'from_department_id' => $fromId,
                'from_department'    => $fromName,
                'to_department_id'   => $toId,
                'to_department'      => $toName,
                'effective_date'     => $today,
                'status'             => 'Completed',
                'remarks'            => 'Department merged into ' . $toName,
                'created_by'         => $actorId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        // Written before tbluser is repointed below, so from_department_id is
        // still the truth at the moment it is recorded.
        foreach (array_chunk($rows, 200) as $chunk) {
            $db->table('s_mobility_transfers')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Fold A's job roles into B's where the names match.
     *
     * Without this, merging leaves B holding two rows called "Senior Engineer"
     * and every screen that lists roles shows the duplicate. The retired role's
     * id-keyed mappings are repointed at the survivor first, or they would be
     * orphaned.
     *
     * Name-keyed tables need nothing: the name is identical on both sides,
     * which is the whole reason they are being folded.
     */
    /**
     * Two departments merging can each own a job role with the SAME NAME.
     *
     * This used to repoint eight id columns and soft-delete the loser, which
     * was incomplete in ways that only showed up when JobRoleMergeService was
     * written and the data was measured:
     *
     *   - tbluser was never touched, so employees kept pointing at the folded
     *     role - and 95 of 98 live employees are attached through
     *     allocated_standards, which nothing here wrote at all.
     *   - a competency required by BOTH roles violates
     *     uq_jcm(sub_institute_id, jobrole_id, competency_id) on the repoint.
     *     Eight competencies on live are already mapped to more than one role
     *     in one tenant, so this could abort a department merge outright.
     *   - career_journey.to_jobrole_id, s_competency_frameworks and
     *     s_competency_development_plans were all missing from the list.
     *
     * It now calls the job role merge, so there is ONE answer to "what does
     * folding a job role mean" instead of two that disagree.
     *
     * Mobility rows are suppressed: both roles have the same name, so no
     * employee's job role is changing, and the department move is already
     * recorded by moveEmployees().
     */
    private function foldDuplicateJobRoles($db, int $fromId, int $toId, int $tenantId): int
    {
        $sourceRoles = $db->table('s_user_jobrole')
            ->where('department_id', $fromId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['id', 'jobrole']);

        if ($sourceRoles->isEmpty()) {
            return 0;
        }

        $targetRoles = $db->table('s_user_jobrole')
            ->where('department_id', $toId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['id', 'jobrole'])
            ->keyBy(fn ($role) => mb_strtolower(trim((string) $role->jobrole)));

        $merges = app(JobRoleMergeService::class);
        $folded = 0;

        foreach ($sourceRoles as $role) {
            $key      = mb_strtolower(trim((string) $role->jobrole));
            $survivor = $targetRoles->get($key);

            if (!$survivor || (int) $survivor->id === (int) $role->id) {
                continue;
            }

            $merges->merge((int) $role->id, (int) $survivor->id, $tenantId, null, $db, false);
            $folded++;
        }

        return $folded;
    }

    /**
     * UNIQUE(project_id, department_id) means a project linked to BOTH
     * departments cannot simply have its A row repointed to B.
     *
     * This is live today: project 7 is linked to departments 117, 123 and 322.
     * The old command hit the duplicate-key error, swallowed it into a console
     * warning, and soft-deleted the department anyway - leaving the pivot row
     * pointing at a department that no longer exists.
     *
     * Where both rows exist, A's is deleted and its is_primary flag carried
     * over if B's did not already have it.
     */
    private function resolveProjectPivotCollisions($db, int $fromId, int $toId): void
    {
        $table = 'task_management_project_departments';

        try {
            $colliding = $db->table($table . ' as a')
                ->join($table . ' as b', function ($join) use ($toId) {
                    $join->on('b.project_id', '=', 'a.project_id')
                         ->where('b.department_id', '=', $toId);
                })
                ->where('a.department_id', $fromId)
                ->get(['a.id as a_id', 'a.is_primary as a_primary', 'b.id as b_id', 'b.is_primary as b_primary']);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($colliding as $row) {
            if ($row->a_primary && !$row->b_primary) {
                $db->table($table)->where('id', $row->b_id)->update(['is_primary' => 1, 'updated_at' => now()]);
            }

            $db->table($table)->where('id', $row->a_id)->delete();
        }
    }

    /**
     * A project stores its department twice - on the project row and on the
     * pivot row flagged is_primary. Keep them agreeing.
     */
    private function syncProjectPrimaryDepartments($db, int $toId): void
    {
        try {
            $primaries = $db->table('task_management_project_departments')
                ->where('department_id', $toId)
                ->where('is_primary', 1)
                ->pluck('project_id');

            if ($primaries->isNotEmpty()) {
                $db->table('task_management_projects')
                    ->whereIn('id', $primaries)
                    ->update(['department_id' => $toId, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Pivot absent on this database.
        }
    }

    /** Rewrite stored department NAMES to the surviving department's name. */
    private function rewriteNames($db, int $fromId, int $toId, string $toName): void
    {
        $fromName = $db->table('hrms_departments')->where('id', $fromId)->value('department');

        foreach (self::PAIRED_NAME_COLUMNS as [$table, $idColumn, $nameColumn]) {
            try {
                $db->table($table)
                    ->where($idColumn, $toId)
                    ->update([$nameColumn => $toName]);
            } catch (\Throwable $e) {
                // absent on this database
            }
        }

        foreach (self::NAME_COLUMNS as [$table, $column]) {
            try {
                // Only rows that already point at the surviving department -
                // matching on name alone would cross tenants, and two tenants
                // genuinely do share department names on live.
                if ($this->hasColumn($db, $table, 'department_id')) {
                    $db->table($table)->where('department_id', $toId)->update([$column => $toName]);
                } elseif ($fromName !== null) {
                    $db->table($table)->where($column, $fromName)->update([$column => $toName]);
                }
            } catch (\Throwable $e) {
                // absent on this database
            }
        }
    }

    /**
     * Departments whose id is packed inside a CSV or JSON column.
     *
     * A repoint by column cannot reach these - the id is one entry in a list.
     * hrms_holidays.department is a comma-separated list of ids;
     * lms_course_settings.restrict_departments is a JSON array of them.
     */
    private function rewritePackedReferences($db, int $fromId, int $toId): void
    {
        try {
            $holidays = $db->table('hrms_holidays')
                ->whereRaw('FIND_IN_SET(?, department)', [$fromId])
                ->get(['id', 'department']);

            foreach ($holidays as $holiday) {
                $ids = array_values(array_unique(array_filter(
                    array_map('intval', explode(',', (string) $holiday->department)),
                    fn ($id) => $id > 0
                )));

                $ids = array_values(array_unique(array_map(
                    fn ($id) => $id === $fromId ? $toId : $id,
                    $ids
                )));

                $db->table('hrms_holidays')
                    ->where('id', $holiday->id)
                    ->update(['department' => implode(',', $ids)]);
            }
        } catch (\Throwable $e) {
            // absent on this database
        }

        try {
            $settings = $db->table('lms_course_settings')
                ->whereNotNull('restrict_departments')
                ->where('restrict_departments', '<>', '')
                ->get(['id', 'restrict_departments']);

            foreach ($settings as $setting) {
                $ids = json_decode((string) $setting->restrict_departments, true);
                if (!is_array($ids) || !in_array($fromId, array_map('intval', $ids), true)) {
                    continue;
                }

                $ids = array_values(array_unique(array_map(
                    fn ($id) => (int) $id === $fromId ? $toId : (int) $id,
                    $ids
                )));

                $db->table('lms_course_settings')
                    ->where('id', $setting->id)
                    ->update(['restrict_departments' => json_encode($ids)]);
            }
        } catch (\Throwable $e) {
            // absent on this database
        }
    }

}
