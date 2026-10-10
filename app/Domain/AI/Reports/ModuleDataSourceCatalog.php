<?php

namespace App\Domain\AI\Reports;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The read-only data sources each G2G module's AI can draw on.
 *
 * WHAT THIS IS
 *
 * G2G's equivalent of LMS_K12's `ReportDataSourceCatalog` (a view over its MCP tool
 * registry). The shared AI Stack uses one catalogue for four things, exactly as LMS_K12
 * does:
 *
 *   Templates      a report layout binds to one source and is filled from its rows
 *   Knowledge Base lists the sources a module's AI reads, each checkable live
 *   Guardrails     shows them as the module's read tools
 *   Automations    an agent is allowed a subset of them as its tools
 *
 * Every source is a query against G2G's own tables, verified column by column against
 * the live schema. Each is scoped to the caller's organisation from its token — the
 * scope is never an argument, and no argument can widen it. Every one is read-only: a
 * source that could write would not be listed.
 *
 * ROWS COME BACK FLAT
 *
 * `['rows' => [...scalar-only rows...], 'total' => n]`, the shape LMS_K12's report
 * generator reads (`firstList`), so a layout's `<<rows>>` block and a column
 * placeholder behave the same in both products.
 */
final class ModuleDataSourceCatalog
{
    /** Hard ceiling on rows, whatever `limit` asks for. A report is not an export. */
    private const MAX_ROWS = 500;

    private const DEFAULT_LIMIT = 200;

    /**
     * @return array<int, array{name:string, module:string, label:string, description:string, arguments:array<int, array{key:string,type:string,description:string,required:bool}>}>
     */
    public function all(): array
    {
        return array_map(fn (array $s) => [
            'name' => $s['name'],
            'module' => $s['module'],
            // The top-level module this source's screen sits under (null when the source
            // IS a top-level module's own). Read from the menu catalogue, never listed here.
            'rolls_up_to' => $this->topLevelModuleOf($s['module']),
            'label' => $s['label'],
            'description' => $s['description'],
            'arguments' => $s['arguments'],
        ], $this->definitions());
    }

    /**
     * Sources for one `ai_modules` key.
     *
     * A top-level module (Talent Management, LMS, …) also owns every source of the screens
     * beneath it, so the Centralized AI Stack for a module reads its whole module's data
     * rather than only the screens that happen to have a per-screen stack of their own.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $s) => $s['module'] === $module || $s['rolls_up_to'] === $module
        ));
    }

    /** @var array<string, string|null> */
    private array $rollUpCache = [];

    /**
     * The `ai_modules` key of the level-1 menu above a module's screen, or null.
     *
     * Derived from `ai_modules.menu_id` and the `tblmenumaster_g2g.parent_id` chain, so a
     * new screen's source lands under the right module without anybody restating the tree.
     */
    private function topLevelModuleOf(string $moduleKey): ?string
    {
        if (array_key_exists($moduleKey, $this->rollUpCache)) {
            return $this->rollUpCache[$moduleKey];
        }

        $menuId = DB::table('ai_modules')->where('module_key', $moduleKey)->whereNull('sub_institute_id')->value('menu_id');
        $root = null;

        for ($hops = 0; $menuId !== null && $hops < 6; $hops++) {
            $row = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['id', 'parent_id', 'level']);

            if ($row === null) {
                break;
            }

            if ((int) $row->parent_id === 0 || (int) $row->level === 1) {
                $root = (int) $row->id;
                break;
            }

            $menuId = $row->parent_id;
        }

        $key = $root === null
            ? null
            : DB::table('ai_modules')->where('menu_id', $root)->whereNull('sub_institute_id')->value('module_key');

        // A top-level module's own sources do not "roll up" to themselves.
        return $this->rollUpCache[$moduleKey] = ($key === null || $key === $moduleKey) ? null : (string) $key;
    }

    public function exists(string $name): bool
    {
        return $this->definition($name) !== null;
    }

    /** @return array<string, mixed>|null */
    public function describe(string $name): ?array
    {
        foreach ($this->all() as $source) {
            if ($source['name'] === $name) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Run one source for the caller's organisation.
     *
     * @param  array<string, mixed>  $arguments  Only declared keys are read; anything else is ignored.
     * @return array{rows: array<int, array<string, scalar|null>>, total: int, truncated: bool}
     */
    public function run(string $name, AiRequestScope $scope, array $arguments = []): array
    {
        $definition = $this->definition($name);

        if ($definition === null) {
            throw new InvalidArgumentException("There is no data source called {$name}.");
        }

        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_ROWS, $limit > 0 ? $limit : self::DEFAULT_LIMIT));

        /** @var Builder $query */
        $query = ($definition['query'])($scope, $arguments);

        $rows = $query->limit($limit + 1)->get()->map(fn ($row) => $this->flatten((array) $row))->all();

        $truncated = count($rows) > $limit;

        return [
            'rows' => array_slice($rows, 0, $limit),
            'total' => min(count($rows), $limit),
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, mixed>|null */
    private function definition(string $name): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @return array<string, scalar|null> */
    private function flatten(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null ? $value : json_encode($value);
        }

        return $out;
    }

    private function int(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(array $arguments, string $key): ?string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private static function arg(string $key, string $type, string $description, bool $required = false): array
    {
        return ['key' => $key, 'type' => $type, 'description' => $description, 'required' => $required];
    }

    /**
     * The sources. Table and column names are G2G's, checked against the live schema;
     * the "active" convention differs by table and each query uses its own table's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        $limitArg = self::arg('limit', 'integer', 'Maximum rows to return (1–500, default 200).');

        $core = [
            [
                'name' => 'lms.course_builder',
                'module' => 'lms_course_builder',
                'label' => 'Courses — build status',
                'description' => 'Every course with its department, category and how many modules and lessons it has been built with.',
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only courses for this department.'),
                    self::arg('status', 'integer', '1 for active courses, 0 for inactive.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('sub_std_map as s')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                        ->leftJoin('lms_course_settings as cs', function ($join) {
                            $join->on('cs.course_id', '=', 's.id')->whereNull('cs.deleted_at');
                        })
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('s.deleted_at')
                        ->select([
                            's.id as course_id', 's.display_name as course', 's.subject_category as category',
                            'd.department', 's.status', 'cs.duration_minutes', 'cs.passing_score',
                        ])
                        ->selectRaw('(SELECT COUNT(*) FROM chapter_master ch WHERE ch.subject_id = s.id AND ch.deleted_at IS NULL) as modules')
                        ->selectRaw('(SELECT COUNT(*) FROM content_master c WHERE c.subject_id = s.id AND c.deleted_at IS NULL) as lessons')
                        ->addSelect('s.updated_at')
                        ->orderByDesc('s.updated_at');

                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('s.standard_id', $d);
                    }
                    if (isset($a['status']) && $a['status'] !== '' && $a['status'] !== null) {
                        $q->where('s.status', (int) $a['status']);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.assessment_cycles',
                'module' => 'lms_assessments',
                'label' => 'Assessment cycles',
                'description' => 'Each assessment cycle with its dates, participants, completions, overdue count and average score.',
                'arguments' => [
                    self::arg('status', 'string', 'active, scheduled or closed.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_competency_assessment_cycles as c')
                        ->leftJoin('s_competency_assessments as a', function ($join) {
                            $join->on('a.cycle_id', '=', 'c.id')
                                ->on('a.sub_institute_id', '=', 'c.sub_institute_id')
                                ->whereNull('a.deleted_at');
                        })
                        ->where('c.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('c.deleted_at')
                        ->groupBy('c.id', 'c.name', 'c.status', 'c.start_date', 'c.end_date')
                        ->select(['c.id as cycle_id', 'c.name as cycle', 'c.status', 'c.start_date', 'c.end_date'])
                        ->selectRaw('COUNT(a.id) as participants')
                        ->selectRaw("SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed")
                        ->selectRaw("SUM(CASE WHEN a.status = 'overdue' THEN 1 ELSE 0 END) as overdue")
                        ->selectRaw('ROUND(AVG(a.score), 1) as average_score')
                        ->orderByDesc('c.start_date');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.my_enrolments',
                'module' => 'lms_my_learning',
                'label' => 'Learner enrolments',
                'description' => 'Course enrolments with the learner, their department, the course and where the enrolment stands.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this learner. Omit for every learner in the organisation.'),
                    self::arg('status', 'string', 'enrolled, in-progress, completed or pending.'),
                    self::arg('department_id', 'integer', "Only learners in this department."),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_course_enroll as e')
                        ->join('sub_std_map as s', 's.id', '=', 'e.course_id')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'e.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('s.sub_institute_id', $tenant)
                        ->where('e.sub_institute_id', $tenant)
                        ->whereNull('e.deleted_at')
                        ->whereNull('s.deleted_at')
                        ->select(['e.id as enrolment_id', 'e.user_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as learner")
                        ->addSelect(['d.department', 's.display_name as course', 'e.status', 'e.start_date', 'e.end_date'])
                        ->orderByDesc('e.id');

                    // "My" enrolments: a caller who is not an administrator sees only their own,
                    // whatever `user_id` they pass. An administrator keeps the organisation-wide
                    // view the report builder needs, optionally narrowed to one learner.
                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('e.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('e.status', $s);
                    }
                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('u.department_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.catalog',
                'module' => 'lms_learning_catalog',
                'label' => 'Course catalogue',
                'description' => 'Active catalogue courses with category, job role, proficiency, department and how many people are learning each.',
                'arguments' => [
                    self::arg('category', 'string', 'Only this course category (subject_category).'),
                    self::arg('department_id', 'integer', 'Only courses for this department.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('sub_std_map as s')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                        ->leftJoin('lms_course_enroll as e', function ($join) {
                            $join->on('e.course_id', '=', 's.id')->whereNull('e.deleted_at');
                        })
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('s.deleted_at')
                        ->where('s.status', 1)
                        ->groupBy('s.id', 's.display_name', 's.subject_category', 's.jobrole', 's.proficiency', 'd.department')
                        ->select(['s.id as course_id', 's.display_name as course', 's.subject_category as category', 's.jobrole as job_role', 's.proficiency', 'd.department'])
                        ->selectRaw('COUNT(DISTINCT e.user_id) as learners')
                        ->selectRaw("COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.user_id END) as completed_learners")
                        ->orderBy('s.display_name');

                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('s.subject_category', $c);
                    }
                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('s.standard_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.jobroles',
                'module' => 'capability_library',
                'label' => 'Job roles and their tasks',
                'description' => "The organisation's job roles with category, level, department and how many tasks and mapped competencies each has.",
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only job roles in this department.'),
                    self::arg('category', 'string', 'Only this job role category.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_user_jobrole as j')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'j.department_id')
                        ->where('j.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('j.deleted_at')
                        // s_user_jobrole's own convention: enum 'Active', or NULL on older rows.
                        ->where(fn ($w) => $w->where('j.status', 'Active')->orWhereNull('j.status'))
                        ->select(['j.id as jobrole_id', 'j.jobrole as job_role', 'j.jobrole_category as category', 'j.job_level'])
                        ->selectRaw('COALESCE(d.department, j.department) as department')
                        ->selectRaw('(SELECT COUNT(*) FROM s_user_jobrole_task t WHERE t.jobrole_id = j.id AND t.deleted_at IS NULL) as tasks')
                        ->selectRaw('(SELECT COUNT(*) FROM jobrole_competency_map m WHERE m.jobrole_id = j.id AND m.sub_institute_id = j.sub_institute_id) as competencies')
                        ->orderBy('j.jobrole');

                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('j.department_id', $d);
                    }
                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('j.jobrole_category', $c);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.competencies',
                'module' => 'capability_library',
                'label' => 'Competencies',
                'description' => 'Active and published competencies with their code, type, criticality and framework.',
                'arguments' => [
                    self::arg('status', 'string', 'active, published or draft. Omit for active and published.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('competency as c')
                        ->leftJoin('s_competency_frameworks as f', 'f.id', '=', 'c.framework_id')
                        ->where('c.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('c.deleted_at')
                        ->select(['c.id as competency_id', 'c.code', 'c.name as competency', 'c.competency_type as type', 'c.criticality', 'c.status', 'f.name as framework'])
                        ->orderBy('c.name');

                    // competency.status is varchar 'active' / 'published' / 'draft'.
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    } else {
                        $q->whereIn('c.status', ['active', 'published']);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.entity_mappings',
                'module' => 'capability_explorer',
                'label' => 'Entity mappings (knowledge graph)',
                'description' => "How this organisation's source records map onto the shared knowledge graph's universal entities.",
                'arguments' => [
                    self::arg('universal_entity', 'string', 'Only mappings onto this universal entity.'),
                    self::arg('source_system', 'string', 'Only mappings from this source system.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    // hpbrain_* scope on a tenant_id STRING, with '*' meaning every tenant.
                    $tenant = (string) $scope->selectedInstituteId;
                    $q = DB::table('hpbrain_entity_mappings')
                        ->where(fn ($w) => $w->where('tenant_id', $tenant)->orWhere('tenant_id', '*')->orWhereNull('tenant_id'))
                        ->where('is_active', 1)
                        ->select(['source_system', 'source_entity', 'source_field', 'universal_entity', 'universal_field', 'mapping_type'])
                        ->orderBy('universal_entity')
                        ->orderBy('universal_field');

                    if (($u = $this->text($a, 'universal_entity')) !== null) {
                        $q->where('universal_entity', $u);
                    }
                    if (($s = $this->text($a, 'source_system')) !== null) {
                        $q->where('source_system', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.pipeline',
                'module' => 'talent_recruitment',
                'label' => 'Candidate pipeline',
                'description' => 'Applications with the candidate, the job, department, stage, latest interview and offer status.',
                'arguments' => [
                    self::arg('job_id', 'integer', 'Only applications to this job posting.'),
                    self::arg('status', 'string', 'Application status, e.g. Shortlisted, Hired, Pending Review.'),
                    self::arg('department_id', 'integer', "Only jobs in this department."),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('talent_job_applications as a')
                        ->join('talent_job_postings as p', function ($join) {
                            $join->on('p.id', '=', 'a.job_id')
                                ->on('p.sub_institute_id', '=', 'a.sub_institute_id')
                                ->whereNull('p.deleted_at');
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'p.department_id')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->select(['a.id as application_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''))) as candidate")
                        ->addSelect(['p.title as job', 'd.department', 'a.status as stage', 'a.applied_date', 'a.experience'])
                        ->selectRaw('(SELECT MAX(i.interview_date) FROM talent_interview_schedules i WHERE i.applicant_id = a.id AND i.deleted_at IS NULL) as last_interview')
                        ->selectRaw('(SELECT o.status FROM talent_offers o WHERE o.application_id = a.id ORDER BY o.id DESC LIMIT 1) as offer_status')
                        ->orderByDesc('a.applied_date');

                    if (($j = $this->int($a, 'job_id')) !== null) {
                        $q->where('a.job_id', $j);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('a.status', $s);
                    }
                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('p.department_id', $d);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.job_postings',
                'module' => 'talent_recruitment',
                'label' => 'Job postings',
                'description' => 'Job postings with department, status, positions, deadline and how many people applied.',
                'arguments' => [
                    self::arg('status', 'string', 'Active, Draft, Closed or Inactive.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('talent_job_postings as p')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'p.department_id')
                        ->where('p.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('p.deleted_at')
                        ->select(['p.id as job_id', 'p.title as job', 'd.department', 'p.status', 'p.positions', 'p.deadline'])
                        ->selectRaw('(SELECT COUNT(*) FROM talent_job_applications x WHERE x.job_id = p.id AND x.deleted_at IS NULL) as applicants')
                        ->orderByDesc('p.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('p.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.workflows',
                'module' => 'talent_administration',
                'label' => 'Hiring and talent workflows',
                'description' => 'Talent workflows with the process they govern, status, version and how many stages each has.',
                'arguments' => [
                    self::arg('module', 'string', 'Recruitment, Onboarding, Offboarding, Mobility or Performance.'),
                    self::arg('status', 'string', 'Active or Draft.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('talent_workflows as w')
                        ->where('w.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('w.deleted_at')
                        ->select(['w.id as workflow_id', 'w.name as workflow', 'w.module as process', 'w.status', 'w.version'])
                        ->selectRaw('(SELECT COUNT(*) FROM talent_workflow_stages s WHERE s.workflow_id = w.id) as stages')
                        ->addSelect('w.updated_at')
                        ->orderBy('w.module')
                        ->orderBy('w.name');

                    if (($m = $this->text($a, 'module')) !== null) {
                        $q->where('w.module', $m);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('w.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'tasks.my_tasks',
                'module' => 'task_my_tasks',
                'label' => 'Tasks',
                'description' => 'Tasks with their date, priority, status, approval, assignee, allocator and department.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only tasks allocated to this person. Omit for every task in the organisation.'),
                    self::arg('status', 'string', 'PENDING, IN-PROGRESS or COMPLETED.'),
                    self::arg('priority', 'string', 'High, Medium or Low.'),
                    self::arg('syear', 'string', 'Only this year (e.g. 2026).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('task as t')
                        ->leftJoin('tbluser as u', function ($join) {
                            $join->on('u.id', '=', 't.task_allocated_to')->on('u.sub_institute_id', '=', 't.sub_institute_id');
                        })
                        ->leftJoin('tbluser as b', 'b.id', '=', 't.task_allocated')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('t.sub_institute_id', $tenant)
                        ->whereNull('t.deleted_at')
                        ->select(['t.id as task_id', 't.task_title as task', 't.task_date', 't.task_type as priority'])
                        // task.status is upper-case varchar with NULLs; NULL reads as pending.
                        ->selectRaw("UPPER(COALESCE(t.status, 'PENDING')) as status")
                        ->addSelect(['t.status_label', 't.approve_status'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as assignee")
                        ->selectRaw("TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, ''))) as allocated_by")
                        ->addSelect('d.department')
                        ->orderByDesc('t.task_date');

                    if (($u = $this->int($a, 'user_id')) !== null) {
                        $q->where('t.task_allocated_to', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->whereRaw("UPPER(COALESCE(t.status, 'PENDING')) = ?", [strtoupper($s)]);
                    }
                    if (($p = $this->text($a, 'priority')) !== null) {
                        $q->where('t.task_type', $p);
                    }
                    if (($y = $this->text($a, 'syear')) !== null) {
                        $q->where('t.SYEAR', $y);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'organization.employees',
                'module' => 'organizational_management',
                'label' => 'Employees',
                'description' => 'Employees with their job title, department, reporting manager, join date and status. No pay, bank or identity-document fields.',
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only employees in this department.'),
                    self::arg('status', 'string', 'Employee status as stored, e.g. 1 for active.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('tbluser as u')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->leftJoin('hrms_job_titles as j', 'j.id', '=', 'u.jobtitle_id')
                        ->leftJoin('tbluser as m', 'm.id', '=', 'u.reporting_manager_id')
                        ->where('u.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('u.deleted_at')
                        ->select(['u.id as user_id', 'u.employee_no', 'u.email', 'u.joined_date', 'u.status'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->addSelect(['d.department', 'j.title as job_title'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))) as reporting_manager")
                        ->orderBy('u.first_name');

                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('u.department_id', $d);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('u.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'organization.departments',
                'module' => 'organizational_management',
                'label' => 'Departments',
                'description' => 'Departments with their code, parent department, head and how many employees sit in each.',
                'arguments' => [
                    self::arg('status', 'string', 'Department status as stored.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_departments as d')
                        ->leftJoin('hrms_departments as p', 'p.id', '=', 'd.parent_id')
                        ->leftJoin('tbluser as h', 'h.id', '=', 'd.head_user_id')
                        ->where('d.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('d.deleted_at')
                        ->select(['d.id as department_id', 'd.department', 'd.code', 'd.status', 'p.department as parent_department'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(h.first_name, ''), ' ', COALESCE(h.last_name, ''))) as head")
                        ->selectRaw('(select count(*) from tbluser eu where eu.department_id = d.id and eu.sub_institute_id = d.sub_institute_id and eu.deleted_at is null) as employees')
                        ->orderBy('d.department');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('d.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.leave_requests',
                'module' => 'hrit_management',
                'label' => 'Leave requests',
                'description' => 'Leave requests with the employee, leave type, dates, days charged and approval status.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this employee\'s requests.'),
                    self::arg('status', 'string', 'Request status as stored, e.g. approved.'),
                    self::arg('from_date', 'string', 'Only requests starting on or after this date (YYYY-MM-DD).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_emp_leaves as l')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'l.user_id')
                        ->leftJoin('hrms_leave_types as t', 't.id', '=', 'l.leave_type_id')
                        ->where('l.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('l.deleted_at')
                        ->select(['l.id as request_id', 'l.from_date', 'l.to_date', 'l.chargeable_days', 'l.status', 'l.approved_by', 't.leave_type'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderByDesc('l.from_date');

                    if (($u = $this->int($a, 'user_id')) !== null) {
                        $q->where('l.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('l.status', $s);
                    }
                    if (($f = $this->text($a, 'from_date')) !== null) {
                        $q->where('l.from_date', '>=', $f);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'hrms.attendance',
                'module' => 'hrit_management',
                'label' => 'Attendance',
                'description' => 'Daily punch-in and punch-out records with the employee, work mode and status.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this employee\'s records.'),
                    self::arg('from_date', 'string', 'Only days on or after this date (YYYY-MM-DD).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('hrms_attendances as a')
                        ->leftJoin('tbluser as u', 'u.id', '=', 'a.user_id')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->select(['a.id as attendance_id', 'a.day', 'a.punchin_time', 'a.punchout_time', 'a.work_mode', 'a.status'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->orderByDesc('a.day');

                    if (($u = $this->int($a, 'user_id')) !== null) {
                        $q->where('a.user_id', $u);
                    }
                    if (($f = $this->text($a, 'from_date')) !== null) {
                        $q->where('a.day', '>=', $f);
                    }

                    return $q;
                },
            ],
        ];

        // Every module's own sources (one group per module), beside the ones above.
        return array_merge($core, \App\Domain\AI\Examples\Sources\SourceRegistry::definitions());
    }
}
