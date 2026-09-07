<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use App\Models\school_setup\sub_std_mapModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\Lms\EnrolmentWriter;

/**
 * Learning Catalog API.
 *
 * Courses live in `sub_std_map`. The existing school_setup/sub_std_map web
 * routes cannot serve a token client:
 *   - update() builds its $data array only inside the display_image branch, so
 *     saving without an upload is a fatal error;
 *   - it reads sub_institute_id/user_id from the session only, so an API caller
 *     would null out the course's tenant;
 *   - it expects standard_id as an array and hardcodes status = 1;
 *   - destroy() validates no token and is not scoped by sub_institute_id, so
 *     any caller can delete any tenant's course by id.
 *
 * Rather than change those routes (the Blade admin UI still uses them with a
 * real session), this controller adds a token-authenticated, tenant-scoped API
 * for the catalog, following the same conventions as LmsCourseEnrollController.
 *
 * Reads for the catalogue listing stay here too, because lms/course_master
 * returns every course grouped by category with no pagination, no sorting and
 * none of the learner/completion aggregates the catalog table displays.
 */
class LmsCourseController extends Controller
{
    use ResolvesLmsIdentity;

    /**
     * Assigning a course has to create the enrolment too, or the learner never
     * sees it - the same bridge every other assignment path now goes through.
     */
    public function __construct(private readonly EnrolmentWriter $enrolments)
    {
    }

    /** Columns a caller may sort by, mapped to their qualified SQL name. */
    private const SORTABLE = [
        'title'      => 's.display_name',
        'category'   => 's.subject_category',
        'type'       => 's.subject_type',
        'status'     => 's.status',
        'learners'   => 'learners',
        'completion' => 'completion_rate',
        'updated_at' => 's.updated_at',
    ];

    /**
     * Validate the Sanctum token on API calls.
     *
     * Mirrors LmsCourseEnrollController: when the caller identifies itself with
     * type=API a valid token must accompany the request.
     */
    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    /**
     * Per-course enrolment aggregates, as a joinable subquery.
     *
     * lms_course_enroll holds one row per enrolment; a learner may appear more
     * than once for the same course, so learners counts DISTINCT user_id.
     */
    private function enrolmentAggregates()
    {
        return DB::table('lms_course_enroll')
            ->whereNull('deleted_at')
            ->select(
                'course_id',
                DB::raw('COUNT(DISTINCT user_id) as learners'),
                DB::raw("COUNT(DISTINCT CASE WHEN status = 'completed' THEN user_id END) as completed_learners")
            )
            ->groupBy('course_id');
    }

    /**
     * Decode `settings` and `prerequisites` when they arrive as JSON strings.
     *
     * The builder posts multipart when a thumbnail is attached, and FormData
     * cannot express a nested object - so those two keys are JSON-encoded into
     * single fields. JSON requests send them as real arrays and are left alone.
     * Runs before validation so the settings.* rules see the same shape either way.
     */
    private function normalizeBuilderInput(Request $request): void
    {
        foreach (['settings', 'prerequisites'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $request->merge([$key => $decoded]);
                }
            }
        }

        /*
         * Trim the free-text identifiers before anything compares them (F-76).
         *
         * `display_name` is half of the duplicate-course check
         * (sub_institute_id + display_name + standard_id), and an untrimmed
         * value defeats it: "Fire Safety" and "Fire Safety " are different
         * strings and both save, leaving two courses that look identical in
         * every list and cannot be told apart. Learners then enrol on whichever
         * the sort happened to put first, and their progress splits across two
         * rows that were never meant to exist.
         *
         * Done here rather than at the write so validation sees the trimmed
         * value too - otherwise a title of nothing but spaces passes `required`
         * and creates a course with a blank name.
         */
        foreach (['display_name', 'subject_category', 'subject_code', 'subject_type',
                  'short_name', 'jobrole', 'proficiency'] as $key) {
            $value = $request->input($key);

            if (is_string($value)) {
                $request->merge([$key => trim($value)]);
            }
        }
    }

    /**
     * Validation for the Course Builder settings block.
     *
     * Every rule is 'nullable' or 'sometimes': a caller that sends no settings
     * at all - which is every existing consumer of store/update - passes
     * validation untouched and writes no settings row.
     */
    private function settingsRules(): array
    {
        return [
            'settings.description'          => 'nullable|string|max:2000',
            'settings.duration_minutes'     => 'nullable|integer|min:0|max:100000',
            'settings.language'             => 'nullable|string|max:50',
            'settings.is_mandatory'         => 'nullable|boolean',
            'settings.discussion_enabled'   => 'nullable|boolean',
            'settings.visibility'           => 'nullable|string|in:all,restricted',
            'settings.passing_score'        => 'nullable|integer|min:0|max:100',
            'settings.max_attempts'         => 'nullable|integer|min:1|max:100',
            // The flag that decides whether passing this course moves the
            // learner's capability record without a person reviewing it. It has
            // existed since 2026-09-05 and until now had no writer reachable
            // from the product: only the `lms:seed-demo-quiz` artisan command
            // ever set it, so the entire quiz -> competency loop was off for
            // every course anyone could author.
            'settings.auto_apply_rating'    => 'nullable|boolean',
            'settings.issue_certificate'    => 'nullable|boolean',
            'settings.certificate_template' => 'nullable|string|max:50',
            'settings.recert_alerts'        => 'nullable|boolean',
            'settings.enrollment_rule'      => 'nullable|string|in:open,approval',
            'settings.restrict_departments' => 'nullable|array',
            'settings.restrict_departments.*' => 'integer',
            'settings.restrict_roles'       => 'nullable|array',
            'settings.restrict_roles.*'     => 'string|max:191',
            'settings.available_from'       => 'nullable|date',
            'settings.available_until'      => 'nullable|date|after_or_equal:settings.available_from',
            'prerequisites'                 => 'nullable|array',
            'prerequisites.*'               => 'integer',
        ];
    }

    /**
     * Upsert the settings row for a course.
     *
     * A no-op when the request carries no settings key, which keeps the older
     * course-create path (and the catalogue's course form) working unchanged.
     */
    private function saveSettings(Request $request, int $courseId, $subInstituteId): void
    {
        if (!$request->has('settings')) {
            return;
        }

        $settings = (array) $request->input('settings', []);
        $userId = $this->contextUserId($request);

        $payload = [
            'sub_institute_id'     => $subInstituteId,
            'description'          => $settings['description'] ?? null,
            'duration_minutes'     => $settings['duration_minutes'] ?? null,
            'language'             => $settings['language'] ?? null,
            'is_mandatory'         => !empty($settings['is_mandatory']),
            'discussion_enabled'   => !empty($settings['discussion_enabled']),
            'visibility'           => $settings['visibility'] ?? 'all',
            'passing_score'        => $settings['passing_score'] ?? null,
            'max_attempts'         => $settings['max_attempts'] ?? null,
            // Defaults OFF, like the column. Turning it on is a deliberate act
            // per course: reasonable for mandatory compliance training, wrong
            // for an optional short course.
            'auto_apply_rating'    => !empty($settings['auto_apply_rating']),
            // Unlike the other flags this defaults to true, matching the
            // wizard's pre-checked "Issue Certificate upon Completion".
            'issue_certificate'    => array_key_exists('issue_certificate', $settings)
                ? (bool) $settings['issue_certificate']
                : true,
            'certificate_template' => $settings['certificate_template'] ?? null,
            'recert_alerts'        => !empty($settings['recert_alerts']),
            'enrollment_rule'      => $settings['enrollment_rule'] ?? 'open',
            // Stored as JSON; null rather than an empty array so "no restriction"
            // and "restricted to nothing" stay distinguishable.
            'restrict_departments' => empty($settings['restrict_departments'])
                ? null
                : json_encode(array_values(array_map('intval', $settings['restrict_departments']))),
            'restrict_roles'       => empty($settings['restrict_roles'])
                ? null
                : json_encode(array_values($settings['restrict_roles'])),
            'available_from'       => $settings['available_from'] ?? null,
            'available_until'      => $settings['available_until'] ?? null,
            'updated_by'           => $userId,
            'updated_at'           => now(),
        ];

        $exists = DB::table('lms_course_settings')->where('course_id', $courseId)->exists();

        if ($exists) {
            DB::table('lms_course_settings')->where('course_id', $courseId)->update($payload);
        } else {
            DB::table('lms_course_settings')->insert($payload + [
                'course_id'  => $courseId,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Replace the prerequisite list for a course.
     *
     * Sent as a whole list rather than add/remove deltas because that is how
     * the builder edits it, and it keeps the write idempotent.
     */
    private function savePrerequisites(Request $request, int $courseId, $subInstituteId): void
    {
        if (!$request->has('prerequisites')) {
            return;
        }

        $wanted = collect((array) $request->input('prerequisites', []))
            ->map(fn ($id) => (int) $id)
            // A course cannot be its own prerequisite.
            ->reject(fn ($id) => $id === $courseId || $id <= 0)
            ->unique()
            ->values();

        // Only courses that actually exist in this tenant.
        $valid = DB::table('sub_std_map')
            ->whereIn('id', $wanted)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->pluck('id');

        DB::table('lms_course_prerequisites')->where('course_id', $courseId)->delete();

        if ($valid->isNotEmpty()) {
            DB::table('lms_course_prerequisites')->insert(
                $valid->map(fn ($id) => [
                    'course_id'              => $courseId,
                    'prerequisite_course_id' => $id,
                    'sub_institute_id'       => $subInstituteId,
                    'created_by'             => $this->contextUserId($request),
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ])->all()
            );
        }
    }

    /** Settings row plus prerequisite list, shaped for the builder. */
    private function loadSettings(int $courseId): array
    {
        $settings = DB::table('lms_course_settings')->where('course_id', $courseId)->first();

        if ($settings) {
            // Booleans arrive from MySQL as 0/1 and the JSON columns as strings;
            // decode both so the client never has to.
            foreach ([
                'is_mandatory', 'discussion_enabled', 'issue_certificate',
                'recert_alerts', 'auto_apply_rating',
            ] as $flag) {
                $settings->$flag = (bool) $settings->$flag;
            }
            foreach (['restrict_departments', 'restrict_roles'] as $list) {
                $decoded = $settings->$list ? json_decode($settings->$list, true) : null;
                $settings->$list = is_array($decoded) ? $decoded : null;
            }
        }

        $prerequisites = DB::table('lms_course_prerequisites as p')
            ->join('sub_std_map as s', 's.id', '=', 'p.prerequisite_course_id')
            ->where('p.course_id', $courseId)
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->get(['p.prerequisite_course_id as id', 's.display_name as title']);

        return ['settings' => $settings, 'prerequisites' => $prerequisites];
    }

    /**
     * GET /api/lms/courses
     *
     * Catalogue listing with search, filters, sorting, pagination and the
     * learner/completion aggregates the table renders.
     */
    public function index(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        try {
            $query = DB::table('sub_std_map as s')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                ->leftJoinSub($this->enrolmentAggregates(), 'e', function ($join) {
                    $join->on('e.course_id', '=', 's.id');
                })
                ->where('s.sub_institute_id', $subInstituteId)
                ->whereNull('s.deleted_at');

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('s.display_name', 'like', "%{$search}%")
                      ->orWhere('s.subject_category', 'like', "%{$search}%")
                      ->orWhere('s.subject_type', 'like', "%{$search}%")
                      ->orWhere('s.subject_code', 'like', "%{$search}%")
                      ->orWhere('s.short_name', 'like', "%{$search}%")
                      ->orWhere('s.jobrole', 'like', "%{$search}%");
                });
            }

            if ($category = $request->input('category')) {
                $query->where('s.subject_category', $category);
            }

            if ($subjectType = $request->input('subject_type')) {
                $query->where('s.subject_type', $subjectType);
            }

            // sub_std_map.status is an int flag: 1 = active, 0 = inactive.
            if (($status = $request->input('status')) !== null && $status !== '') {
                $query->where('s.status', (int) $status);
            }

            if ($jobrole = $request->input('jobrole')) {
                $query->where('s.jobrole', $jobrole);
            }

            $total = (clone $query)->count();

            $sortBy = $request->input('sort_by', 'updated_at');
            $sortColumn = self::SORTABLE[$sortBy] ?? self::SORTABLE['updated_at'];
            $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            $perPage = min(max((int) $request->input('per_page', 10), 1), 100);
            $page = max((int) $request->input('page', 1), 1);

            $courses = $query
                ->select(
                    's.id',
                    's.display_name',
                    's.display_image',
                    's.subject_category',
                    's.subject_type',
                    's.subject_code',
                    's.short_name',
                    's.jobrole',
                    's.proficiency',
                    's.sort_order',
                    's.certificate_validity_months',
                    's.status',
                    's.standard_id',
                    's.created_at',
                    's.updated_at',
                    'd.department as standard_name',
                    DB::raw('COALESCE(e.learners, 0) as learners'),
                    DB::raw('COALESCE(e.completed_learners, 0) as completed_learners'),
                    DB::raw('ROUND(COALESCE(e.completed_learners, 0) / NULLIF(e.learners, 0) * 100) as completion_rate')
                )
                ->orderBy($sortColumn, $sortDir)
                ->forPage($page, $perPage)
                ->get()
                ->map(function ($course) {
                    $course->completion_rate = (int) ($course->completion_rate ?? 0);
                    $course->learners = (int) $course->learners;
                    $course->completed_learners = (int) $course->completed_learners;
                    $course->status = (int) $course->status;
                    return $course;
                });

            return response()->json([
                'status' => true,
                'data' => $courses,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve courses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/lms/courses/kpis
     *
     * Headline counts for the catalog KPI row. Every figure is derived from
     * sub_std_map / lms_course_enroll - there are no stored aggregates.
     */
    public function kpis(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        try {
            $courses = DB::table('sub_std_map')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active')
                ->selectRaw('SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END) as inactive')
                ->selectRaw('COUNT(DISTINCT subject_category) as categories')
                ->first();

            $enrolments = DB::table('lms_course_enroll')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as total_enrolments')
                ->selectRaw('COUNT(DISTINCT user_id) as learners')
                ->selectRaw("COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed")
                ->first();

            $totalEnrolments = (int) ($enrolments->total_enrolments ?? 0);
            $completed = (int) ($enrolments->completed ?? 0);

            return response()->json([
                'status' => true,
                'data' => [
                    'total_courses' => (int) ($courses->total ?? 0),
                    'active_courses' => (int) ($courses->active ?? 0),
                    'inactive_courses' => (int) ($courses->inactive ?? 0),
                    'categories' => (int) ($courses->categories ?? 0),
                    'total_learners' => (int) ($enrolments->learners ?? 0),
                    'total_enrolments' => $totalEnrolments,
                    'avg_completion_rate' => $totalEnrolments > 0
                        ? (int) round($completed / $totalEnrolments * 100)
                        : 0,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve catalog KPIs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/lms/courses/filters
     *
     * The distinct values actually present in this tenant's catalogue, so the
     * filter dropdowns offer real options instead of a hardcoded list.
     */
    public function filters(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        try {
            $distinct = function (string $column) use ($subInstituteId) {
                return DB::table('sub_std_map')
                    ->where('sub_institute_id', $subInstituteId)
                    ->whereNull('deleted_at')
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->distinct()
                    ->orderBy($column)
                    ->pluck($column)
                    ->values();
            };

            // Grouped by department id, not DISTINCT over the joined rows.
            // DISTINCT deduplicated (id, department) pairs, so two departments
            // sharing a name - which this tenant has - both survived and the
            // select rendered the same label twice. Grouping collapses on the
            // identity that actually matters, and course_count gives the UI a
            // way to tell same-named departments apart.
            /*
             * ── EVERY DEPARTMENT, NOT ONLY THE ONES THAT ALREADY HAVE COURSES ──
             *
             * This joined FROM sub_std_map, so a department appeared only once
             * it already owned a course. The consequence was circular and
             * total: you could never create the FIRST course for a new
             * department, because the department was not in the dropdown and
             * standard_id is required on both sides.
             *
             * A department created this morning in Organization > Department
             * Management was invisible here all afternoon, and this same
             * response feeds four separate dropdowns.
             *
             * hrms_departments is the authority - the same table and the same
             * predicate the Organization module lists from - and the join is
             * now LEFT, so course_count still distinguishes two departments
             * that share a name and a department with none reads "(0)".
             */
            $departments = DB::table('hrms_departments as d')
                ->leftJoin('sub_std_map as s', function ($join) use ($subInstituteId) {
                    $join->on('s.standard_id', '=', 'd.id')
                         ->where('s.sub_institute_id', '=', $subInstituteId)
                         ->whereNull('s.deleted_at');
                })
                ->where('d.sub_institute_id', $subInstituteId)
                ->whereNull('d.deleted_at')
                ->groupBy('d.id', 'd.department')
                ->orderBy('d.department')
                ->get(['d.id', 'd.department', DB::raw('COUNT(s.id) as course_count')]);

            /*
             * ── JOB ROLES FROM THE JOB-ROLE TABLE ───────────────────────────
             *
             * `jobroles` below is DISTINCT sub_std_map.jobrole - free text
             * somebody typed into this same box previously. It is empty on a
             * fresh tenant, it propagates typos ("Staff Nurse" and "staff
             * nurse" become two permanent options), and it never consults the
             * organisation's actual roles.
             *
             * `job_roles` is the real list, from the same table and the same
             * predicate JobroleApiController@getDepartmentWise uses - so the
             * course form and the task-assignment cascade agree by
             * construction. department_id is included so the form can narrow
             * roles to the chosen department.
             *
             * The old key stays for now: the legacy free-text datalist still
             * reads it, and removing it would break that control before its
             * replacement has shipped everywhere.
             */
            $jobRoles = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->orderBy('jobrole')
                ->get(['id', 'jobrole', 'department_id']);

            return response()->json([
                'status' => true,
                'data' => [
                    'categories' => $distinct('subject_category'),
                    /*
                     * DELIVERY TYPES, plus whatever is already in use.
                     *
                     * This returned only DISTINCT sub_std_map.subject_type -
                     * free text somebody had typed - so tenant 6's entire
                     * vocabulary was "E-learning" and "Mutual Fund", the second
                     * of which is a subject rather than a type. An author had
                     * no way to say "Workshop" without inventing it.
                     *
                     * The standard list comes from config; values already in
                     * use are merged so no existing course silently loses its
                     * type when the form is next saved.
                     */
                    'subject_types' => collect(config('lms.course_types', []))
                        ->concat($distinct('subject_type'))
                        ->filter()
                        ->unique()
                        ->values(),
                    'jobroles' => $distinct('jobrole'),
                    'job_roles' => $jobRoles,
                    'departments' => $departments,
                    // Fixed lists, but served from config rather than hardcoded
                    // in the component: adding a language or template is a
                    // server-side change that the builder picks up with no
                    // frontend edit. Templates expose value/label only - `view`
                    // is a server-side implementation detail.
                    'languages' => config('lms.languages', []),
                    'certificate_templates' => collect(config('lms.certificate_templates', []))
                        ->map(fn ($template) => [
                            'value' => $template['value'],
                            'label' => $template['label'],
                        ])
                        ->values(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve catalog filters',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/lms/courses/{id}
     *
     * One course with its aggregates plus the chapter count the details sheet
     * shows. chapter_master.subject_id references sub_std_map.id.
     */
    public function show(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        try {
            $course = DB::table('sub_std_map as s')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 's.standard_id')
                ->leftJoinSub($this->enrolmentAggregates(), 'e', function ($join) {
                    $join->on('e.course_id', '=', 's.id');
                })
                ->where('s.id', $id)
                ->where('s.sub_institute_id', $subInstituteId)
                ->whereNull('s.deleted_at')
                ->select(
                    's.*',
                    'd.department as standard_name',
                    DB::raw('COALESCE(e.learners, 0) as learners'),
                    DB::raw('COALESCE(e.completed_learners, 0) as completed_learners'),
                    DB::raw('ROUND(COALESCE(e.completed_learners, 0) / NULLIF(e.learners, 0) * 100) as completion_rate')
                )
                ->first();

            if (!$course) {
                return response()->json([
                    'status' => false,
                    'message' => 'Course not found'
                ], 404);
            }

            $course->completion_rate = (int) ($course->completion_rate ?? 0);
            $course->learners = (int) $course->learners;
            $course->completed_learners = (int) $course->completed_learners;
            $course->status = (int) $course->status;
            $course->chapter_count = DB::table('chapter_master')
                ->where('subject_id', $course->id)
                ->count();

            return response()->json([
                'status' => true,
                'data' => $course,
            ] + $this->loadSettings((int) $course->id));

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve the course',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/courses
     *
     * Create a course.
     *
     * school_setup/master_setup (formType=course) already does this, but it is
     * a web route: Laravel's CSRF guard rejects a cross-origin POST with 419,
     * and the csrf `except` list matches request paths, so its localhost
     * entries never apply. An API route is stateless and CSRF-exempt, so this
     * mirrors master_setup's rules - department must belong to the tenant, the
     * display_name + standard_id pair must be unique - over a usable transport.
     */
    public function store(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $this->normalizeBuilderInput($request);
        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'display_name'     => 'required|string|max:191',
                'standard_id'      => 'required|integer',
                'subject_category' => 'nullable|string|max:191',
                'subject_code'     => 'nullable|string|max:100',
                'subject_type'     => 'nullable|string|max:100',
                'short_name'       => 'nullable|string|max:100',
                'jobrole'          => 'nullable|string|max:191',
                'proficiency'      => 'nullable|string|max:191',
                'sort_order'       => 'nullable|integer',
                'certificate_validity_months' => 'nullable|integer|min:1|max:600',
                'status'           => 'required|integer|in:0,1',
                'display_image'    => 'nullable|image',
            ] + $this->settingsRules()
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $departmentExists = DB::table('hrms_departments')
            ->where('id', $request->standard_id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$departmentExists) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Department ID'
            ], 422);
        }

        $duplicate = sub_std_mapModel::where('sub_institute_id', $subInstituteId)
            ->where('display_name', $request->display_name)
            ->where('standard_id', $request->standard_id)
            ->whereNull('deleted_at')
            ->first();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'A course with this name already exists in that department',
                'course_id' => $duplicate->id,
            ], 422);
        }

        try {
            $data = [
                'display_name'     => $request->display_name,
                'standard_id'      => $request->standard_id,
                'subject_category' => $request->subject_category,
                'subject_code'     => $request->subject_code,
                'subject_type'     => $request->subject_type,
                'short_name'       => $request->short_name,
                'jobrole'          => $request->jobrole,
                'proficiency'      => $request->proficiency,
                'sort_order'       => $request->sort_order ?? 1,
                // Null = the certificate for this course never expires.
                'certificate_validity_months' => $request->certificate_validity_months,
                'status'           => (int) $request->status,
                'sub_institute_id' => $subInstituteId,
                // Defaults matching what master_setup writes for a course.
                'allow_grades'     => 'Yes',
                'allow_content'    => 'Yes',
                'elective_subject' => 'No',
                'add_content'      => 'chapterwise',
                'created_by'       => $this->contextUserId($request),
                'created_at'       => now(),
            ];

            // Same upload target and naming as masterSetupController@store.
            if ($request->hasFile('display_image')) {
                $file = $request->file('display_image');
                $fileName = date('YmdHis') . '.' . $file->getClientOriginalExtension();
                $path = \Illuminate\Support\Facades\Storage::disk('digitalocean')
                    ->putFileAs('hp_course', $file, $fileName, 'public');
                $data['display_image'] = \Illuminate\Support\Facades\Storage::disk('digitalocean')->url($path);
            }

            /*
             * One transaction, because the three writes are one course.
             *
             * insertGetId committed immediately, so a failure in saveSettings or
             * savePrerequisites left the sub_std_map row behind — a course with
             * no settings, invisible as a failure. Worse, the obvious recovery
             * (press Save again) then hit the duplicate-name check above and
             * answered 422, so the author was told their course already existed
             * while looking at a form that had never saved.
             */
            $courseId = DB::transaction(function () use ($request, $data, $subInstituteId) {
                $id = sub_std_mapModel::insertGetId($data);

                // Course Builder extras. Both are no-ops when the keys are
                // absent, so the catalogue's simpler course form is unaffected.
                $this->saveSettings($request, $id, $subInstituteId);
                $this->savePrerequisites($request, $id, $subInstituteId);

                return $id;
            });

            return response()->json([
                'status' => true,
                'message' => 'Course created successfully',
                'data' => sub_std_mapModel::find($courseId),
                'course_id' => $courseId,
            ] + $this->loadSettings($courseId), 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create the course',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/lms/courses/{id}
     *
     * Tenant-scoped course update. Only the fields the catalog edits are
     * writable; subject_id, sub_institute_id and the enrolment data are not.
     */
    public function update(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $this->normalizeBuilderInput($request);
        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'display_name'     => 'required|string|max:191',
                'standard_id'      => 'required|integer',
                'subject_category' => 'nullable|string|max:191',
                'subject_code'     => 'nullable|string|max:100',
                'subject_type'     => 'nullable|string|max:100',
                'short_name'       => 'nullable|string|max:100',
                'jobrole'          => 'nullable|string|max:191',
                'proficiency'      => 'nullable|string|max:191',
                'sort_order'       => 'nullable|integer',
                'certificate_validity_months' => 'nullable|integer|min:1|max:600',
                'status'           => 'required|integer|in:0,1',
            ] + $this->settingsRules()
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scoped by tenant so one institute cannot edit another's courses.
        $course = sub_std_mapModel::where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$course) {
            return response()->json([
                'status' => false,
                'message' => 'Course not found'
            ], 404);
        }

        // The department must belong to this tenant - same rule masterSetupController
        // applies when a course is created.
        $departmentExists = DB::table('hrms_departments')
            ->where('id', $request->standard_id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$departmentExists) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Department ID'
            ], 422);
        }

        // Mirrors the create-time uniqueness rule in masterSetupController@store.
        $duplicate = sub_std_mapModel::where('sub_institute_id', $subInstituteId)
            ->where('display_name', $request->display_name)
            ->where('standard_id', $request->standard_id)
            ->where('id', '!=', $id)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'A course with this name already exists in that department'
            ], 422);
        }

        try {
            /*
             * ── WHY THIS IS A QUERY-BUILDER UPDATE AND NOT $course->update() ──
             *
             * sub_std_mapModel::$fillable does not list subject_code,
             * subject_type, short_name, certificate_validity_months or
             * updated_by. Eloquent drops unfillable keys SILENTLY, so those
             * five were accepted by validation, sent by the form, and thrown
             * away - while store() writes through insertGetId(), which bypasses
             * $fillable entirely. The result: those fields saved once at
             * creation and could never be changed again, with no error anywhere.
             *
             * Widening $fillable would fix it and change mass-assignment
             * behaviour for every other consumer of sub_std_map - competency,
             * the job-role library, enrolment - which is a much larger blast
             * radius than this bug deserves. A query-builder update is local,
             * cannot leak, and matches how the row was created.
             *
             * ── AND ONLY WHAT WAS ACTUALLY SENT ─────────────────────────────
             *
             * $request->x is null for a key the caller omitted, so the previous
             * version wrote null over any field the payload did not carry. The
             * catalogue sheet never sends `proficiency` and the builder never
             * sends `sort_order`, and the builder saves on every "Continue" -
             * so stepping through the wizard silently nulled sort_order each
             * time. Building from the keys the request HAS makes a partial
             * update a partial update.
             */
            $editable = [
                'display_name', 'standard_id', 'subject_category', 'subject_code',
                'subject_type', 'short_name', 'jobrole', 'proficiency', 'sort_order',
                'certificate_validity_months', 'status',
            ];

            $changes = [];
            foreach ($editable as $field) {
                if ($request->has($field)) {
                    $changes[$field] = $field === 'status'
                        ? (int) $request->input($field)
                        : $request->input($field);
                }
            }

            $changes['updated_by'] = $this->contextUserId($request);
            $changes['updated_at'] = now();

            DB::table('sub_std_map')->where('id', $course->id)->update($changes);

            $this->saveSettings($request, (int) $course->id, $subInstituteId);
            $this->savePrerequisites($request, (int) $course->id, $subInstituteId);

            return response()->json([
                'status' => true,
                'message' => 'Course updated successfully',
                'data' => $course->fresh(),
            ] + $this->loadSettings((int) $course->id));

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update the course',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/lms/courses/{id}
     *
     * Tenant-scoped soft delete (sub_std_mapModel uses SoftDeletes).
     */
    public function destroy(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required'
            ], 422);
        }

        $course = sub_std_mapModel::where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$course) {
            return response()->json([
                'status' => false,
                'message' => 'Course not found'
            ], 404);
        }

        try {
            $course->update([
                'deleted_by' => $this->contextUserId($request),
                'updated_at' => now(),
            ]);
            $course->delete();

            return response()->json([
                'status' => true,
                'message' => 'Course deleted successfully',
                'data' => ['id' => (int) $id],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete the course',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/courses/bulk
     *
     * Bulk activate / deactivate / delete for the catalog's selection toolbar.
     *
     * sub_std_map.status is a 0/1 flag, so "activate" and "deactivate" are the
     * only publication states this schema supports - there is no draft or
     * under-review state to move a course into.
     */
    public function bulk(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'action'           => 'required|in:activate,deactivate,delete',
                'ids'              => 'required|array|min:1',
                'ids.*'            => 'integer',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Scoped to the tenant, so ids belonging to another institute are
            // silently skipped rather than acted on.
            $scoped = sub_std_mapModel::whereIn('id', $request->ids)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at');

            $matchedIds = (clone $scoped)->pluck('id')->all();

            if (empty($matchedIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching courses found'
                ], 404);
            }

            $action = $request->action;

            if ($action === 'delete') {
                sub_std_mapModel::whereIn('id', $matchedIds)->update([
                    'deleted_by' => $this->contextUserId($request),
                    'updated_at' => now(),
                ]);
                sub_std_mapModel::whereIn('id', $matchedIds)->delete();
                $message = count($matchedIds) . ' course(s) deleted successfully';
            } else {
                sub_std_mapModel::whereIn('id', $matchedIds)->update([
                    'status'     => $action === 'activate' ? 1 : 0,
                    'updated_by' => $this->contextUserId($request),
                    'updated_at' => now(),
                ]);
                $message = count($matchedIds) . ' course(s) '
                    . ($action === 'activate' ? 'activated' : 'deactivated')
                    . ' successfully';
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => [
                    'affected' => count($matchedIds),
                    'ids' => $matchedIds,
                    'skipped' => count($request->ids) - count($matchedIds),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to complete the bulk action',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Who is this course for.
     *
     * ── WHY THE EXPANSION IS SERVER-SIDE ────────────────────────────────────
     *
     * "Everyone in Nursing" has to become a list of people somewhere. Doing it
     * in the browser would mean shipping the roster down first, and the
     * learner endpoint caps at 200 rows — so a 400-person department would be
     * silently half-assigned. It also means the number shown in the preview is
     * computed by the same code that does the writing, so the count cannot
     * disagree with the outcome.
     *
     * ── HOW A PERSON IS FOUND ───────────────────────────────────────────────
     *
     * By department: `tbluser.department_id`.
     * By job role:   `tbluser.allocated_standards` -> `s_user_jobrole.id`.
     *
     * That second one is a numeric id held in a text column, NOT a role name.
     * Verified before writing this: on tenant 3, joining it to the role NAME
     * matches 0 of 108 users and joining it to the role ID matches 108 of 108.
     * A name-based expansion would have assigned nobody, silently.
     */
    private function expandAudience(Request $request, int $tenant): array
    {
        $userIds = array_map('intval', (array) $request->input('user_ids', []));
        $departmentIds = array_map('intval', (array) $request->input('department_ids', []));
        $jobroleIds = array_map('intval', (array) $request->input('jobrole_ids', []));

        $query = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where(function ($q) use ($userIds, $departmentIds, $jobroleIds) {
                if ($userIds) {
                    $q->orWhereIn('id', $userIds);
                }
                if ($departmentIds) {
                    $q->orWhereIn('department_id', $departmentIds);
                }
                if ($jobroleIds) {
                    $q->orWhereIn(DB::raw('CAST(allocated_standards AS UNSIGNED)'), $jobroleIds);
                }
            });

        // Nothing selected means nobody, never everybody. An audience step left
        // untouched must not assign the whole organisation by accident.
        if (! $userIds && ! $departmentIds && ! $jobroleIds) {
            return [];
        }

        return $query->distinct()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * GET /api/lms/courses/{id}/audience/preview
     *
     * How many people the current selection would reach, and a few of them by
     * name — so "assign to Nursing" is a decision somebody can check before
     * they make it, rather than after.
     */
    public function audiencePreview(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $tenant = $this->tenantId($request);
        $userIds = $this->expandAudience($request, (int) $tenant);

        $sample = empty($userIds) ? [] : DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->leftJoin('s_user_jobrole as j', 'j.id', '=', DB::raw('CAST(u.allocated_standards AS UNSIGNED)'))
            ->whereIn('u.id', array_slice($userIds, 0, 8))
            ->selectRaw("u.id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as name, d.department, j.jobrole")
            ->get();

        // Who already has it, so the preview does not promise work it will skip.
        $already = empty($userIds) ? 0 : DB::table('lms_course_enroll')
            ->whereIn('user_id', $userIds)
            ->where('course_id', $id)
            ->whereNull('deleted_at')
            ->distinct()
            ->count('user_id');

        return response()->json([
            'status' => true,
            'data' => [
                'count' => count($userIds),
                'already_enrolled' => $already,
                'will_assign' => max(0, count($userIds) - $already),
                'sample' => $sample,
            ],
        ]);
    }

    /**
     * POST /api/lms/courses/{id}/audience
     *
     * Assign the course to everyone the selection resolves to. Idempotent:
     * re-submitting the same audience assigns nobody twice.
     */
    public function assignAudience(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        // Assigning work to other people is an admin/HR act, and the role
        // comes from the token's owner - never from the request.
        if ($denied = $this->guardLmsProfile($request, ['admin', 'hr'],
            'Your profile is not permitted to assign courses.')) {
            return $denied;
        }

        $tenant = (int) $this->tenantId($request);

        $course = DB::table('sub_std_map')
            ->where('id', $id)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->first();

        if (! $course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        $userIds = $this->expandAudience($request, $tenant);

        if (empty($userIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Choose at least one person, department or job role.',
            ], 422);
        }

        $assignedBy = DB::table('tbluser')
            ->where('id', $this->contextUserId($request))
            ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) as full_name")
            ->value('full_name') ?: 'Admin';

        $type = $request->input('assignment_type', 'Mandatory');
        $dueDate = $request->input('due_date');

        $assigned = 0;
        $alreadyHad = 0;

        DB::transaction(function () use ($userIds, $id, $tenant, $type, $dueDate, $assignedBy, &$assigned, &$alreadyHad) {
            foreach ($userIds as $userId) {
                $exists = DB::table('lms_assignments')
                    ->where('user_id', $userId)
                    ->where('course_id', $id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    $alreadyHad++;
                } else {
                    DB::table('lms_assignments')->insert([
                        'user_id' => $userId,
                        'course_id' => $id,
                        'assignment_type' => $type,
                        'due_date' => $dueDate,
                        'status' => 'Not Started',
                        'progress' => 0,
                        'approval_status' => 'approved',
                        'assigned_by' => $assignedBy,
                        'assigned_on' => now(),
                        'sub_institute_id' => $tenant,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $assigned++;
                }

                // Always — an assignment that predates the enrolment bridge has
                // the row but not the enrolment, and that is the state every
                // pre-existing row was in.
                $this->enrolments->ensureEnrolment($userId, (int) $id, $tenant);
            }
        });

        // Role-based assignment is also a fact about the course, not only about
        // the people it reached today. `course_jobrole_map` has existed with 71
        // rows and no writer and no route; this is the writer.
        foreach (array_map('intval', (array) $request->input('jobrole_ids', [])) as $roleId) {
            DB::table('course_jobrole_map')->insertOrIgnore([
                'sub_institute_id' => $tenant,
                'course_id' => (int) $id,
                'jobrole_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => "Assigned to {$assigned} " . ($assigned === 1 ? 'person' : 'people') . '.',
            'data' => [
                'assigned' => $assigned,
                'already_had_it' => $alreadyHad,
                'reached' => count($userIds),
            ],
        ]);
    }
}
