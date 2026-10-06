<?php

namespace App\Services\Org;

use App\Services\Org\Concerns\RepointsReferences;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Merging one job role into another.
 *
 * Two roles in the same department become one: everything the retired role
 * owned - its employees, tasks, skills, competency requirements, frameworks,
 * development plans, career steps - becomes the survivor's, and the source row
 * is soft-deleted.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT JUST DepartmentMergeService WITH A DIFFERENT COLUMN
 *
 * DepartmentMergeService::foldDuplicateJobRoles() already merges job roles -
 * but only ones with the SAME NAME, folded as a side effect of merging their
 * departments. It repoints eight id columns and stops, and that is correct
 * there for one reason: when both names are identical, every table that stores
 * the role's NAME instead of its id already holds the right string.
 *
 * A real merge joins roles with DIFFERENT names, and then the name columns are
 * most of the work. Measured on live:
 *
 *   s_user_skill_jobrole   84,380 rows   jobrole_id NULL on every one
 *   s_user_jobrole_task    91,539 rows   id on 85,173; NAME-ONLY on 6,364
 *
 * On the real duplicate pair "Production Manager" x2 in tenant 1, ZERO rows
 * reference either role by id, while 52 tasks and 67 skills reference them by
 * name. An id-only merge would have reported success and moved nothing.
 *
 * ---------------------------------------------------------------------------
 * WHY A NAME REWRITE CANNOT BE A PLAIN UPDATE
 *
 * A job role's name is not unique to a department. 90 role names in a single
 * live tenant exist in more than one department, and neither
 * s_user_skill_jobrole nor s_user_jobrole_task carries a department column, so
 *
 *     UPDATE ... SET jobrole = 'B' WHERE jobrole = 'A'
 *
 * would silently rewrite OTHER departments' roles. 3,605 of the 84,380 skill
 * rows are ambiguous this way. See rewriteNames() for the predicate that makes
 * this safe, and why ambiguous rows are reported rather than guessed.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 *
 * THE GLOBAL CATALOGUE. s_jobrole, s_jobrole_task (55,961 rows), s_jobrole_skills
 * (62,208) and s_jobrole_knowledge (2,775) have NO sub_institute_id. They are
 * shared by every tenant on the platform and are keyed by role name. One tenant
 * merging two of its own roles must never write them - that would rewrite
 * another organisation's library.
 *
 * KASBA. competency_kasba_item hangs off competency_id and competency_kasba_rating
 * off user_id; neither references a job role at all. They reach a role only
 * through jobrole_competency_map, and the same competency is required by roles
 * in other departments. Repointing the competency map is what carries KASBA
 * across - the atoms and every employee rating against them stay exactly as
 * they are, which is the correct outcome, not an omission.
 *
 * HISTORY. s_mobility_transfers and talent_mobility_requests record moves that
 * actually happened. Their role names are a point-in-time snapshot; rewriting
 * them would make the audit trail claim something false. This service WRITES
 * transfer rows (moveEmployees) and must therefore never rewrite them, or a
 * merge would immediately turn its own records into "moved from B to B".
 *
 * The rule all three share, inherited from DepartmentMergeService: repoint what
 * a role OWNS, never what it merely REACHES.
 */
class JobRoleMergeService
{
    /** repoint(), countIn() and hasColumn() - shared with DepartmentMergeService. */
    use RepointsReferences;

    /**
     * Tables owned by a job role through a plain id column, with no unique key
     * that a repoint could violate.
     *
     * Labelled because these counts are shown before a user confirms.
     *
     * @var array<string,array{0:string,1:string}> table => [column, label]
     */
    public const ROLE_ID_TABLES = [
        's_user_jobrole_task'             => ['jobrole_id', 'job role tasks'],
        's_competency_career_path_steps'  => ['jobrole_id', 'career path steps'],
        'user_rating_details'             => ['jobrole_id', 'rating snapshots'],
        'competency_assessment_test'      => ['jobrole_id', 'assessment tests'],
        's_skill_jobrole'                 => ['jobrole_id', 'skill links'],
        's_competency_frameworks'         => ['jobrole_id', 'competency frameworks'],
        's_competency_development_plans'  => ['jobrole_id', 'development plans'],
        's_mobility_succession_plans'     => ['critical_jobrole_id', 'succession plans'],
        's_user_skill_jobrole'            => ['jobrole_id', 'skill mappings (by id)'],
        /*
         * These six had NO id column until 2026_08_26_100000 added one, so the
         * merge could only ever reach them through the name pass. They are
         * re-pointed by id first now like everything else; the name pass has
         * become their fallback rather than their only route.
         */
        's_competency_assessments'                => ['jobrole_id', 'assessments'],
        's_competency_certifications'             => ['jobrole_id', 'certifications'],
        's_competency_certification_requirements' => ['jobrole_id', 'certification requirements'],
        's_competency_mapping_reviews'            => ['jobrole_id', 'mapping reviews'],
        's_performance_reviews'                   => ['jobrole_id', 'performance reviews'],
        's_performance_appraisals'                => ['jobrole_id', 'appraisals'],
        // Exists as a migration; the TABLE is absent from both dev and live.
        // hasColumn() skips it rather than failing every merge.
        'role_progressions'               => ['from_role_id', 'progression steps'],
    ];

    /**
     * Columns holding the role's NAME.
     *
     * Every one of these is rewritten through rewriteNames(), never with a
     * bare UPDATE - see the class docblock.
     *
     * s_mobility_transfers and talent_mobility_requests are ABSENT ON PURPOSE:
     * they are history. See the class docblock.
     *
     * @var array<string,array{0:string,1:string}> table => [column, label]
     */
    public const ROLE_NAME_TABLES = [
        's_user_skill_jobrole'                    => ['jobrole', 'skill mappings'],
        's_user_jobrole_task'                     => ['jobrole', 'job role tasks'],
        's_competency_frameworks'                 => ['jobrole', 'competency frameworks'],
        's_competency_development_plans'          => ['jobrole', 'development plans'],
        's_competency_career_path_steps'          => ['jobrole', 'career path steps'],
        's_competency_assessments'                => ['jobrole', 'assessments'],
        's_competency_certifications'             => ['jobrole', 'certifications'],
        's_competency_certification_requirements' => ['jobrole', 'certification requirements'],
        's_competency_mapping_reviews'            => ['jobrole', 'mapping reviews'],
        's_performance_reviews'                   => ['jobrole', 'performance reviews'],
        's_performance_appraisals'                => ['jobrole', 'appraisals'],
        's_mobility_succession_plans'             => ['critical_jobrole_name', 'succession plans'],
    ];

    /**
     * "Advanced" and "3" have to be comparable, because keeping the higher of
     * two levels is meaningless otherwise.
     *
     * 157 live s_user_skill_jobrole rows carry text: Intermediate (96),
     * Advanced (35), Basic (26). This mirrors the map already used by
     * RoleMappingController::normaliseLevel() - kept in step with it.
     */
    public const LEVEL_WORDS = [
        'awareness' => 1,
        'basic' => 2, 'foundational' => 2, 'working knowledge' => 2,
        'intermediate' => 3, 'applied' => 3, 'applied expertise' => 3,
        'advanced' => 4, 'advanced practice' => 4,
        'expert' => 5, 'strategic' => 5, 'strategic leadership' => 5,
    ];

    /**
     * What is attached to a role, and what will collide with the target.
     *
     * Unlike the department equivalent this takes a TARGET, because for a job
     * role the collisions change what the merge MEANS - which proficiency level
     * survives - not merely how many rows move. A preview that could not show
     * that would be showing the user the wrong thing.
     *
     * @return array{total:int, breakdown:list<array{label:string,count:int}>,
     *               level_raises:list<array{kind:string,name:string,from:mixed,to:mixed}>,
     *               duplicates:array{tasks:int,skills:int},
     *               ambiguous:list<array{table:string,count:int}>}
     */
    public function impact(int $fromId, ?int $toId, int $tenantId, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();

        $from = $this->role($db, $fromId, $tenantId);
        if (!$from) {
            return ['total' => 0, 'breakdown' => [], 'level_raises' => [],
                    'duplicates' => ['tasks' => 0, 'skills' => 0], 'ambiguous' => []];
        }
        $to = $toId ? $this->role($db, $toId, $tenantId) : null;

        $breakdown = [];
        $total = 0;

        foreach (self::ROLE_ID_TABLES as $table => [$column, $label]) {
            $n = $this->countIn($db, $table, $column, [$fromId]);
            if ($table === 'role_progressions') {
                $n += $this->countIn($db, $table, 'to_role_id', [$fromId]);
            }
            if ($n > 0) { $breakdown[] = ['label' => $label, 'count' => $n]; $total += $n; }
        }

        // The two-column ones the blanket pass does not cover.
        $employees = $this->employeeQuery($db, $fromId, $tenantId)->count();
        if ($employees > 0) { $breakdown[] = ['label' => 'employees', 'count' => $employees]; $total += $employees; }

        $competencies = $this->hasColumn($db, 'jobrole_competency_map', 'jobrole_id')
            ? $db->table('jobrole_competency_map')->where('jobrole_id', $fromId)->count() : 0;
        if ($competencies > 0) { $breakdown[] = ['label' => 'competency requirements', 'count' => $competencies]; $total += $competencies; }

        $courses = $this->countIn($db, 'course_jobrole_map', 'jobrole_id', [$fromId]);
        if ($courses > 0) { $breakdown[] = ['label' => 'course mappings', 'count' => $courses]; $total += $courses; }

        $journeys = $this->countIn($db, 'career_journey', 'jobrole_id', [$fromId])
                  + $this->countIn($db, 'career_journey', 'to_jobrole_id', [$fromId]);
        if ($journeys > 0) { $breakdown[] = ['label' => 'career journey steps', 'count' => $journeys]; $total += $journeys; }

        $libraryMaps = $this->hasColumn($db, 's_library_map', 'type_id')
            ? $db->table('s_library_map')->where('type', 'jobrole')->where('type_id', $fromId)->count() : 0;
        if ($libraryMaps > 0) { $breakdown[] = ['label' => 'library maps', 'count' => $libraryMaps]; $total += $libraryMaps; }

        // Name-keyed rows that carry no id - the ones only rewriteNames() reaches.
        $nameOnly = 0;
        foreach (self::ROLE_NAME_TABLES as $table => [$column, $label]) {
            $nameOnly += $this->nameOnlyQuery($db, $table, $column, $from, $tenantId)?->count() ?? 0;
        }
        if ($nameOnly > 0) { $breakdown[] = ['label' => 'rows linked by role name only', 'count' => $nameOnly]; $total += $nameOnly; }

        return [
            'total'        => $total,
            'breakdown'    => $breakdown,
            'level_raises' => $to ? $this->previewLevelRaises($db, $from, $to, $tenantId) : [],
            'duplicates'   => $to ? $this->previewDuplicates($db, $from, $to, $tenantId)
                                  : ['tasks' => 0, 'skills' => 0],
            'ambiguous'    => $this->previewAmbiguous($db, $from, $tenantId),
        ];
    }

    /**
     * Merge one role into another.
     *
     * Everything is inside ONE transaction: a merge either lands whole or not
     * at all. Collisions are settled FIRST, while both roles still exist and
     * their rows can still be told apart.
     *
     * @return array{moved:array<string,int>, employees:int, competencies_raised:int,
     *               skills_raised:int, tasks_folded:int, skills_folded:int,
     *               ambiguous:list<array{table:string,count:int}>}
     */
    public function merge(int $fromId, int $toId, int $tenantId, ?int $actorId, ?ConnectionInterface $db = null, bool $recordMobility = true): array
    {
        $db ??= DB::connection();

        return $db->transaction(function () use ($db, $fromId, $toId, $tenantId, $actorId, $recordMobility) {
            $from = $this->role($db, $fromId, $tenantId);
            $to   = $this->role($db, $toId, $tenantId);

            if (!$from || !$to) {
                throw new \RuntimeException('Both job roles must belong to this organisation.');
            }

            $moved = [];

            /*
             * WHAT WILL BE LEFT BEHIND, MEASURED BEFORE ANYTHING MOVES.
             *
             * This has to be captured first. Retiring the source at step 11
             * removes it from the count of live roles sharing its name, so a
             * name that WAS ambiguous stops looking ambiguous the moment the
             * merge finishes - and the report would come back empty precisely
             * when it matters most.
             */
            $ambiguous = $this->previewAmbiguous($db, $from, $tenantId);

            // 1-3. COLLISIONS FIRST, while both roles still exist.
            $competenciesRaised = $this->mergeCompetencyRequirements($db, $fromId, $toId, $tenantId);
            $this->dropCourseCollisions($db, $fromId, $toId);
            $skillMerge = $this->mergeSkillCells($db, $from, $to, $tenantId);

            // 4. Employees - both columns, and a mobility record each.
            $employees = $this->moveEmployees($db, $from, $to, $tenantId, $actorId, $recordMobility);

            // 5. Everything keyed on a plain id.
            foreach (self::ROLE_ID_TABLES as $table => [$column, $label]) {
                $n = $this->repoint($db, $table, $column, $fromId, $toId);
                if ($table === 'role_progressions') {
                    $n += $this->repoint($db, $table, 'to_role_id', $fromId, $toId);
                }
                if ($n) { $moved[$table] = ($moved[$table] ?? 0) + $n; }
            }
            foreach (['jobrole_competency_map', 'course_jobrole_map'] as $table) {
                $n = $this->repoint($db, $table, 'jobrole_id', $fromId, $toId);
                if ($n) { $moved[$table] = ($moved[$table] ?? 0) + $n; }
            }

            // 6. career_journey: BOTH ends, then clean up what the merge created.
            $n = $this->repoint($db, 'career_journey', 'jobrole_id', $fromId, $toId)
               + $this->repoint($db, 'career_journey', 'to_jobrole_id', $fromId, $toId);
            if ($n) { $moved['career_journey'] = $n; }
            $this->cleanCareerJourney($db, $toId);

            // 7. The polymorphic one a column sweep cannot find.
            if ($this->hasColumn($db, 's_library_map', 'type_id')) {
                $n = $db->table('s_library_map')->where('type', 'jobrole')
                        ->where('type_id', $fromId)->update(['type_id' => $toId]);
                if ($n) { $moved['s_library_map'] = $n; }
            }

            // 8. The names.
            $nameMoved = $this->rewriteNames($db, $from, $to, $tenantId);
            foreach ($nameMoved as $table => $count) {
                $moved[$table] = ($moved[$table] ?? 0) + $count;
            }

            // 9. Packed lists of names.
            $this->rewritePackedNames($db, $from, $to, $tenantId);

            // 10. Fold what is now visibly duplicated.
            $tasksFolded  = $this->foldDuplicateTasks($db, $to, $tenantId);
            // Anything mergeSkillCells did not already settle - cells the
            // survivor held twice before this merge ever ran.
            $skillsFolded = $skillMerge['folded'] + $this->foldDuplicateSkills($db, $to, $tenantId);

            // 11. Retire the source. SOFT - three FKs into s_user_jobrole are
            //     ON DELETE NO ACTION and would block, three are SET NULL and
            //     would silently orphan. A soft delete touches neither.
            $db->table('s_user_jobrole')
                ->where('id', $fromId)
                ->where('sub_institute_id', $tenantId)
                ->update([
                    'status'     => 'Inactive',
                    'deleted_by' => $actorId,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'moved'               => $moved,
                'employees'           => $employees,
                'competencies_raised' => $competenciesRaised,
                'skills_raised'       => $skillMerge['raised'],
                'tasks_folded'        => $tasksFolded,
                'skills_folded'       => $skillsFolded,
                'ambiguous'           => $ambiguous,
            ];
        });
    }

    /**
     * Create a role and merge several into it, in ONE transaction.
     *
     * Creating the role in its own transaction and then merging would, on a
     * failure, leave an empty orphan role behind that looks like a real one.
     *
     * @param list<int> $sourceIds
     * @return array{new_role_id:int, results:list<array<string,mixed>>}
     */
    public function mergeIntoNew(array $sourceIds, string $name, int $tenantId, ?int $actorId, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();

        return $db->transaction(function () use ($db, $sourceIds, $name, $tenantId, $actorId) {
            $first = $this->role($db, (int) $sourceIds[0], $tenantId);
            if (!$first) {
                throw new \RuntimeException('The first job role does not belong to this organisation.');
            }

            // Seeded from the first source so the new role is not born blank;
            // it stays editable in Capability Library afterwards.
            $newId = (int) $db->table('s_user_jobrole')->insertGetId([
                'jobrole'          => $name,
                'department'       => $first->department,
                'department_id'    => $first->department_id,
                'sub_department'   => $first->sub_department ?? null,
                'description'      => $first->description ?? null,
                'jobrole_category' => $first->jobrole_category ?? null,
                'job_level'        => $first->job_level ?? null,
                'industries'       => $first->industries ?? null,
                'status'           => 'Active',
                'sub_institute_id' => $tenantId,
                'created_by'       => $actorId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $results = [];
            foreach ($sourceIds as $sourceId) {
                // Same connection, so this joins the transaction already open
                // rather than starting a nested one.
                $results[] = $this->merge((int) $sourceId, $newId, $tenantId, $actorId, $db);
            }

            return ['new_role_id' => $newId, 'results' => $results];
        });
    }

    // ---------------------------------------------------------------- steps

    /**
     * jobrole_competency_map, guarded against uq_jcm.
     *
     * UNIQUE(sub_institute_id, jobrole_id, competency_id) means a competency
     * required by BOTH roles cannot simply have its row repointed - that throws
     * SQLSTATE 23000 and rolls the merge back. Eight competencies on live are
     * already mapped to more than one role in one tenant, so this is reachable
     * today, and DepartmentMergeService::foldDuplicateJobRoles() would hit it.
     *
     * The surviving row keeps the HIGHER requirement: the merged role does both
     * jobs, so it must satisfy the stricter of the two. Taking the lower would
     * silently mark people capable who are not. is_mandatory is OR-ed for the
     * same reason.
     *
     * @return int competencies whose required level was raised
     */
    private function mergeCompetencyRequirements($db, int $fromId, int $toId, int $tenantId): int
    {
        if (!$this->hasColumn($db, 'jobrole_competency_map', 'jobrole_id')) {
            return 0;
        }

        $sourceRows = $db->table('jobrole_competency_map')
            ->where('sub_institute_id', $tenantId)->where('jobrole_id', $fromId)->get();

        if ($sourceRows->isEmpty()) {
            return 0;
        }

        $targetRows = $db->table('jobrole_competency_map')
            ->where('sub_institute_id', $tenantId)->where('jobrole_id', $toId)
            ->get()->keyBy('competency_id');

        $raised = 0;

        foreach ($sourceRows as $row) {
            $survivor = $targetRows->get($row->competency_id);
            if (!$survivor) {
                continue; // no collision - the blanket repoint moves it
            }

            $keep = max((int) ($survivor->required_proficiency ?? 0), (int) ($row->required_proficiency ?? 0));
            $mandatory = ((int) ($survivor->is_mandatory ?? 0)) || ((int) ($row->is_mandatory ?? 0));

            if ($keep !== (int) ($survivor->required_proficiency ?? 0) || $mandatory !== (bool) ($survivor->is_mandatory ?? false)) {
                $db->table('jobrole_competency_map')->where('id', $survivor->id)->update([
                    'required_proficiency' => $keep ?: null,
                    'is_mandatory'         => $mandatory ? 1 : 0,
                    'updated_at'           => now(),
                ]);
                if ($keep > (int) ($survivor->required_proficiency ?? 0)) {
                    $raised++;
                }
            }

            // The loser's row would violate uq_jcm the moment it was repointed.
            $db->table('jobrole_competency_map')->where('id', $row->id)->delete();
        }

        return $raised;
    }

    /** course_jobrole_map has UNIQUE(course_id, jobrole_id). Same hazard, no attributes to merge. */
    private function dropCourseCollisions($db, int $fromId, int $toId): void
    {
        if (!$this->hasColumn($db, 'course_jobrole_map', 'jobrole_id')) {
            return;
        }

        $targetCourses = $db->table('course_jobrole_map')->where('jobrole_id', $toId)->pluck('course_id');
        if ($targetCourses->isEmpty()) {
            return;
        }

        $db->table('course_jobrole_map')
            ->where('jobrole_id', $fromId)
            ->whereIn('course_id', $targetCourses)
            ->delete();
    }

    /**
     * s_user_skill_jobrole, cell by cell.
     *
     * This table has NO database unique key - only the application one at
     * RoleMappingController::upsert. So rewriting the name in bulk does not
     * throw, it SILENTLY produces two rows for one (role, skill) carrying
     * different levels, and every later read picks whichever it finds first.
     * That is worse than an error.
     *
     * Levels are compared through levelValue() because 157 live rows hold text
     * ("Advanced") where others hold a number.
     *
     * @return array{raised:int, folded:int} levels raised, and duplicate cells consumed
     */
    private function mergeSkillCells($db, object $from, object $to, int $tenantId): array
    {
        if (!$this->hasColumn($db, 's_user_skill_jobrole', 'jobrole')) {
            return ['raised' => 0, 'folded' => 0];
        }

        $sourceRows = $this->skillRows($db, $from, $tenantId);
        if ($sourceRows->isEmpty()) {
            return ['raised' => 0, 'folded' => 0];
        }

        $targetRows = $this->skillRows($db, $to, $tenantId)
            ->keyBy(fn ($row) => mb_strtolower(trim((string) $row->skill)));

        $raised = 0;
        $folded = 0;

        foreach ($sourceRows as $row) {
            $key = mb_strtolower(trim((string) $row->skill));
            $survivor = $targetRows->get($key);
            if (!$survivor) {
                continue; // rewriteNames() carries it across
            }

            $sourceLevel = $this->levelValue($row->proficiency_level ?? null);
            $targetLevel = $this->levelValue($survivor->proficiency_level ?? null);

            if ($sourceLevel > $targetLevel) {
                $db->table('s_user_skill_jobrole')->where('id', $survivor->id)->update([
                    'proficiency_level' => $row->proficiency_level,
                    'updated_at'        => now(),
                ]);
                $raised++;
            }

            // The duplicate cell is removed rather than repointed. This IS the
            // skill fold - it has to happen here, before the name rewrite, or
            // the two cells would both end up on the survivor and no later pass
            // could tell which level was meant to win.
            $db->table('s_user_skill_jobrole')->where('id', $row->id)->delete();
            $folded++;
        }

        return ['raised' => $raised, 'folded' => $folded];
    }

    /**
     * Employees: BOTH columns, together, and a mobility record each.
     *
     * tbluser stores an employee's role twice - jobtitle_id and the TEXT column
     * allocated_standards - and 290 of 292 live rows have both set. The employee
     * edit form writes allocated_standards, which is why 95 of 98 employees
     * resolve through it; ResolvesEmployeeJobRole reads it as the fallback.
     * Writing one and not the other leaves the two disagreeing, with half the
     * application seeing a retired role.
     *
     * The transfer row is written BEFORE the columns change, so its "from" side
     * is still true - the same ordering DepartmentMergeService::moveEmployees()
     * uses.
     */
    private function moveEmployees($db, object $from, object $to, int $tenantId, ?int $actorId, bool $recordMobility = true): int
    {
        $employees = $this->employeeQuery($db, (int) $from->id, $tenantId)->get(['id']);
        if ($employees->isEmpty()) {
            return 0;
        }

        /*
         * $recordMobility is false when a DEPARTMENT merge folds two roles that
         * share a name. Nobody's job role changed - both are called the same
         * thing - and the department merge already writes one transfer row per
         * employee for the move that DID happen. A second row reading "from
         * Nurse to Nurse" would be noise in the one place that has to stay
         * readable.
         */
        if ($recordMobility && $this->hasColumn($db, 's_mobility_transfers', 'from_jobrole')) {
            foreach ($employees->chunk(200) as $chunk) {
                $db->table('s_mobility_transfers')->insert($chunk->map(fn ($user) => [
                    'sub_institute_id' => $tenantId,
                    'user_id'          => $user->id,
                    'from_jobrole'     => $from->jobrole,
                    'to_jobrole'       => $to->jobrole,
                    'effective_date'   => now()->toDateString(),
                    'status'           => 'Completed',
                    'remarks'          => sprintf('Job role "%s" merged into "%s".', $from->jobrole, $to->jobrole),
                    'created_by'       => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ])->all());
            }
        }

        $db->table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('jobtitle_id', $from->id)
            ->update(['jobtitle_id' => $to->id]);

        $db->table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('allocated_standards', (string) $from->id)
            ->update(['allocated_standards' => (string) $to->id]);

        return $employees->count();
    }

    /**
     * The name rewrite, in two passes.
     *
     * PASS A - rows carrying the id. Unambiguous: the id says exactly which
     *          role this row belongs to, whatever its name column says.
     *
     * PASS B - rows carrying only the name. Safe ONLY when that name resolves
     *          to exactly one live role in this tenant. 90 role names on live
     *          exist in more than one department, and these tables have no
     *          department column, so a name that is not unique cannot be
     *          attributed to a department at all.
     *
     * Pass B BACKFILLS jobrole_id as it goes, so every merge permanently
     * shrinks the ambiguity instead of leaving it for the next one. For
     * s_user_skill_jobrole - jobrole_id NULL on all 84,380 live rows - Pass B
     * is the only pass that reaches anything, which is why the backfill matters.
     *
     * Ambiguous rows are LEFT ALONE and reported. Guessing would rewrite
     * another department's data, which is the one thing this feature exists to
     * prevent.
     *
     * @return array<string,int>
     */
    private function rewriteNames($db, object $from, object $to, int $tenantId): array
    {
        $moved = [];

        foreach (self::ROLE_NAME_TABLES as $table => [$column, $label]) {
            if (!$this->hasColumn($db, $table, $column)) {
                continue;
            }

            $idColumn = $table === 's_mobility_succession_plans' ? 'critical_jobrole_id' : 'jobrole_id';
            $count = 0;

            // PASS A
            if ($this->hasColumn($db, $table, $idColumn)) {
                $count += $db->table($table)
                    ->where('sub_institute_id', $tenantId)
                    ->where($idColumn, $from->id)
                    ->update([$column => $to->jobrole]);
            }

            // PASS B
            $query = $this->nameOnlyQuery($db, $table, $column, $from, $tenantId);
            if ($query) {
                $update = [$column => $to->jobrole];
                if ($this->hasColumn($db, $table, $idColumn)) {
                    $update[$idColumn] = $to->id;
                }
                $count += $query->update($update);
            }

            if ($count) {
                $moved[$table] = $count;
            }
        }

        return $moved;
    }

    /**
     * The Pass B predicate, in one place so impact() and merge() cannot drift.
     *
     * Returns null when the source name is ambiguous in this tenant - i.e. it
     * belongs to live roles in more than one department - because then no row
     * carrying only that name can be attributed to either.
     */
    private function nameOnlyQuery($db, string $table, string $column, object $from, int $tenantId)
    {
        if (!$this->hasColumn($db, $table, $column) || $this->nameIsAmbiguous($db, $from, $tenantId)) {
            return null;
        }

        $query = $db->table($table)
            ->where('sub_institute_id', $tenantId)
            ->whereRaw("TRIM(LOWER($column)) = ?", [mb_strtolower(trim((string) $from->jobrole))]);

        $idColumn = $table === 's_mobility_succession_plans' ? 'critical_jobrole_id' : 'jobrole_id';
        if ($this->hasColumn($db, $table, $idColumn)) {
            $query->where(fn ($q) => $q->whereNull($idColumn)->orWhere($idColumn, 0));
        }

        return $query;
    }

    /** Does this role's name belong to live roles in more than one department? */
    private function nameIsAmbiguous($db, object $from, int $tenantId): bool
    {
        return $db->table('s_user_jobrole')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower(trim((string) $from->jobrole))])
            ->distinct()
            ->count('department_id') > 1;
    }

    /**
     * CSV columns holding role NAMES, which a column-level UPDATE cannot reach.
     *
     * s_user_jobrole.related_jobrole is non-empty on 1,895 of 4,888 live rows;
     * sub_std_map.jobrole is how the LMS links a course to a role.
     */
    private function rewritePackedNames($db, object $from, object $to, int $tenantId): void
    {
        $fromName = trim((string) $from->jobrole);
        $toName   = trim((string) $to->jobrole);
        if ($fromName === '' || $fromName === $toName) {
            return;
        }

        foreach ([['s_user_jobrole', 'related_jobrole'], ['sub_std_map', 'jobrole']] as [$table, $column]) {
            if (!$this->hasColumn($db, $table, $column)) {
                continue;
            }

            $rows = $db->table($table)
                ->where('sub_institute_id', $tenantId)
                ->whereNotNull($column)->where($column, '<>', '')
                ->whereRaw("LOWER($column) LIKE ?", ['%' . mb_strtolower($fromName) . '%'])
                ->get(['id', $column]);

            foreach ($rows as $row) {
                $names = array_map('trim', explode(',', (string) $row->$column));
                $rewritten = [];
                $changed = false;

                foreach ($names as $name) {
                    if ($name === '') { continue; }
                    if (mb_strtolower($name) === mb_strtolower($fromName)) {
                        $name = $toName;
                        $changed = true;
                    }
                    // De-duplicate: the survivor may already be in the list.
                    if (!in_array(mb_strtolower($name), array_map('mb_strtolower', $rewritten), true)) {
                        $rewritten[] = $name;
                    }
                }

                if ($changed) {
                    $db->table($table)->where('id', $row->id)->update([$column => implode(', ', $rewritten)]);
                }
            }
        }
    }

    /**
     * Fold tasks that are now visibly duplicated on the survivor.
     *
     * EXACT matches only, after trim and case-fold. Anything that differs at
     * all is two different tasks as far as this code is concerned, and is left
     * for a person to judge.
     *
     * jobrole_task_competency_map.user_jobrole_task_id is ON DELETE CASCADE, so
     * deleting the loser would take its competency mapping with it. The mapping
     * is carried onto the survivor FIRST, skipping anything that would violate
     * uq_jtcm_user. No live row keys on user_jobrole_task_id today, but the
     * feature that writes them exists, so the hazard is real.
     */
    private function foldDuplicateTasks($db, object $to, int $tenantId): int
    {
        if (!$this->hasColumn($db, 's_user_jobrole_task', 'jobrole')) {
            return 0;
        }

        $rows = $db->table('s_user_jobrole_task')
            ->where('sub_institute_id', $tenantId)
            ->where(fn ($q) => $q->where('jobrole_id', $to->id)
                ->orWhereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower(trim((string) $to->jobrole))]))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'task']);

        $seen = [];
        $folded = 0;

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->task));
            if ($key === '') { continue; }

            if (!isset($seen[$key])) {
                $seen[$key] = $row->id;
                continue;
            }

            $this->carryTaskCompetencies($db, (int) $row->id, (int) $seen[$key], $tenantId);
            $db->table('s_user_jobrole_task')->where('id', $row->id)->delete();
            $folded++;
        }

        return $folded;
    }

    private function carryTaskCompetencies($db, int $loserTaskId, int $survivorTaskId, int $tenantId): void
    {
        if (!$this->hasColumn($db, 'jobrole_task_competency_map', 'user_jobrole_task_id')) {
            return;
        }

        $existing = $db->table('jobrole_task_competency_map')
            ->where('sub_institute_id', $tenantId)
            ->where('user_jobrole_task_id', $survivorTaskId)
            ->pluck('competency_id')->all();

        $db->table('jobrole_task_competency_map')
            ->where('sub_institute_id', $tenantId)
            ->where('user_jobrole_task_id', $loserTaskId)
            ->whereNotIn('competency_id', $existing ?: [0])
            ->update(['user_jobrole_task_id' => $survivorTaskId, 'updated_at' => now()]);

        // Whatever is left would violate uq_jtcm_user; the survivor already has it.
        $db->table('jobrole_task_competency_map')
            ->where('sub_institute_id', $tenantId)
            ->where('user_jobrole_task_id', $loserTaskId)
            ->delete();
    }

    /** Same idea for skills; the higher level wins, as everywhere else here. */
    private function foldDuplicateSkills($db, object $to, int $tenantId): int
    {
        if (!$this->hasColumn($db, 's_user_skill_jobrole', 'jobrole')) {
            return 0;
        }

        $rows = $this->skillRows($db, $to, $tenantId);
        $seen = [];
        $folded = 0;

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->skill));
            if ($key === '') { continue; }

            if (!isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }

            $keeper = $seen[$key];
            if ($this->levelValue($row->proficiency_level ?? null) > $this->levelValue($keeper->proficiency_level ?? null)) {
                $db->table('s_user_skill_jobrole')->where('id', $keeper->id)
                    ->update(['proficiency_level' => $row->proficiency_level, 'updated_at' => now()]);
            }

            $db->table('s_user_skill_jobrole')->where('id', $row->id)->delete();
            $folded++;
        }

        return $folded;
    }

    /**
     * Remove the self-loops and duplicate edges a merge creates.
     *
     * A career_journey edge is a PROGRESSION, not containment - the live
     * in-department edges are ladders like Pharmacy Technician -> Senior
     * Pharmacy Technician. Merging a junior role into the senior one it leads
     * to is the most natural use of this feature, so it is allowed, and the
     * edge that becomes A -> A is deleted here rather than refused up front.
     *
     * There are no self-loops in career_journey today, so every one this finds
     * is one the merge just made.
     */
    private function cleanCareerJourney($db, int $toId): void
    {
        if (!$this->hasColumn($db, 'career_journey', 'to_jobrole_id')) {
            return;
        }

        $db->table('career_journey')->whereColumn('jobrole_id', 'to_jobrole_id')->delete();

        $edges = $db->table('career_journey')
            ->where(fn ($q) => $q->where('jobrole_id', $toId)->orWhere('to_jobrole_id', $toId))
            ->orderBy('id')->get(['id', 'jobrole_id', 'to_jobrole_id']);

        $seen = [];
        foreach ($edges as $edge) {
            $key = $edge->jobrole_id . '>' . $edge->to_jobrole_id;
            if (isset($seen[$key])) {
                $db->table('career_journey')->where('id', $edge->id)->delete();
                continue;
            }
            $seen[$key] = true;
        }
    }

    // ------------------------------------------------------------- previews

    /** @return list<array{kind:string,name:string,from:mixed,to:mixed}> */
    private function previewLevelRaises($db, object $from, object $to, int $tenantId): array
    {
        $raises = [];

        if ($this->hasColumn($db, 'jobrole_competency_map', 'jobrole_id')) {
            $rows = $db->select(
                'SELECT c.name AS name, a.required_proficiency AS src, b.required_proficiency AS tgt
                   FROM jobrole_competency_map a
                   JOIN jobrole_competency_map b
                     ON b.competency_id = a.competency_id
                    AND b.sub_institute_id = a.sub_institute_id
                    AND b.jobrole_id = ?
              LEFT JOIN competency c ON c.id = a.competency_id
                  WHERE a.sub_institute_id = ? AND a.jobrole_id = ?',
                [$to->id, $tenantId, $from->id]
            );
            foreach ($rows as $row) {
                if ((int) $row->src > (int) $row->tgt) {
                    $raises[] = ['kind' => 'competency', 'name' => $row->name ?? 'Competency',
                                 'from' => $row->tgt, 'to' => $row->src];
                }
            }
        }

        $targetSkills = $this->skillRows($db, $to, $tenantId)
            ->keyBy(fn ($row) => mb_strtolower(trim((string) $row->skill)));

        foreach ($this->skillRows($db, $from, $tenantId) as $row) {
            $survivor = $targetSkills->get(mb_strtolower(trim((string) $row->skill)));
            if ($survivor && $this->levelValue($row->proficiency_level) > $this->levelValue($survivor->proficiency_level)) {
                $raises[] = ['kind' => 'skill', 'name' => $row->skill,
                             'from' => $survivor->proficiency_level, 'to' => $row->proficiency_level];
            }
        }

        return $raises;
    }

    /** @return array{tasks:int,skills:int} */
    private function previewDuplicates($db, object $from, object $to, int $tenantId): array
    {
        $names = fn ($rows, $field) => array_filter(array_map(
            fn ($row) => mb_strtolower(trim((string) $row->$field)), $rows->all()
        ));

        $sourceTasks = $this->taskRows($db, $from, $tenantId);
        $targetTasks = $this->taskRows($db, $to, $tenantId);
        $tasks = count(array_intersect(array_unique($names($sourceTasks, 'task')), $names($targetTasks, 'task')));

        $sourceSkills = $this->skillRows($db, $from, $tenantId);
        $targetSkills = $this->skillRows($db, $to, $tenantId);
        $skills = count(array_intersect(array_unique($names($sourceSkills, 'skill')), $names($targetSkills, 'skill')));

        return ['tasks' => $tasks, 'skills' => $skills];
    }

    /**
     * Rows that will be LEFT BEHIND because the role's name is ambiguous.
     *
     * Reported rather than moved, and reported per table so the number is
     * actionable. A count the caller cannot act on is worse than no count.
     *
     * @return list<array{table:string,count:int}>
     */
    private function previewAmbiguous($db, object $from, int $tenantId): array
    {
        if (!$this->nameIsAmbiguous($db, $from, $tenantId)) {
            return [];
        }

        $out = [];
        foreach (self::ROLE_NAME_TABLES as $table => [$column, $label]) {
            if (!$this->hasColumn($db, $table, $column)) {
                continue;
            }

            $query = $db->table($table)
                ->where('sub_institute_id', $tenantId)
                ->whereRaw("TRIM(LOWER($column)) = ?", [mb_strtolower(trim((string) $from->jobrole))]);

            $idColumn = $table === 's_mobility_succession_plans' ? 'critical_jobrole_id' : 'jobrole_id';
            if ($this->hasColumn($db, $table, $idColumn)) {
                $query->where(fn ($q) => $q->whereNull($idColumn)->orWhere($idColumn, 0));
            }

            $n = $query->count();
            if ($n > 0) {
                $out[] = ['table' => $label, 'count' => $n];
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- helpers

    public function role($db, int $id, int $tenantId): ?object
    {
        return $db->table('s_user_jobrole')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Employees holding a role, through EITHER column.
     *
     * Never read jobtitle_id alone: it is set on 23 of 98 live employees, while
     * allocated_standards - what the employee form actually writes - is set on
     * 95. See ResolvesEmployeeJobRole.
     */
    private function employeeQuery($db, int $roleId, int $tenantId)
    {
        return $db->table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where(fn ($q) => $q->where('jobtitle_id', $roleId)
                                 ->orWhere('allocated_standards', (string) $roleId));
    }

    /** A role's skill cells, by id where it exists and by name where it does not. */
    private function skillRows($db, object $role, int $tenantId)
    {
        if (!$this->hasColumn($db, 's_user_skill_jobrole', 'jobrole')) {
            return collect();
        }

        return $db->table('s_user_skill_jobrole')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('jobrole_id', $role->id)
                ->orWhereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower(trim((string) $role->jobrole))]))
            ->orderBy('id')
            ->get(['id', 'skill', 'proficiency_level']);
    }

    private function taskRows($db, object $role, int $tenantId)
    {
        if (!$this->hasColumn($db, 's_user_jobrole_task', 'jobrole')) {
            return collect();
        }

        return $db->table('s_user_jobrole_task')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('jobrole_id', $role->id)
                ->orWhereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower(trim((string) $role->jobrole))]))
            ->orderBy('id')
            ->get(['id', 'task']);
    }

    /**
     * One comparable number for a proficiency level.
     *
     * Returns 0 for anything unrecognised, which is deliberate: an unreadable
     * level never WINS a comparison, so a merge can only ever raise a
     * requirement, never lower one to something nobody can interpret.
     */
    public function levelValue($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $text = mb_strtolower(trim((string) $value));

        if (is_numeric($text)) {
            return (int) $text;
        }

        if (preg_match('/level\s*([1-5])/', $text, $match)) {
            return (int) $match[1];
        }

        return self::LEVEL_WORDS[$text] ?? 0;
    }
}
