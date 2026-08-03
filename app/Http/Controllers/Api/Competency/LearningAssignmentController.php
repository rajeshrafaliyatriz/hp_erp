<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Learning assignments for the Development & Career Path Workspace
 * ("Assign Learning" + the Learning Assignments tab + the Learning Assigned KPI).
 *
 * Reuses the existing `lms_assignments` table rather than adding a parallel
 * competency-owned one: it already models manager-assigned learning
 * (user_id, course_id, assignment_type, due_date, status, progress,
 * assigned_by, approval workflow). Courses come from `sub_std_map`, the same
 * catalog LmsCourseEnrollController joins against.
 *
 * Every row this controller writes is tagged source='competency', and every
 * read filters on it, so LMS-owned assignments and competency-owned ones stay
 * separate even though they share a table. `lms_course_enroll` is deliberately
 * NOT used here - that table records a learner enrolling themselves, which is a
 * different action from a manager assigning learning to close a competency gap.
 */
class LearningAssignmentController extends Controller
{
    use ResolvesCompetencyContext;

    private const TABLE = 'lms_assignments';
    private const SOURCE = 'competency';

    private const STATUSES = ['Not Started', 'In Progress', 'Completed', 'Overdue'];
    private const TYPES = ['Mandatory', 'Optional', 'Recommended'];

    /** GET /competency/learning-assignments */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $perPage = min(max((int) $request->input('per_page', 15), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $query = DB::table(self::TABLE . ' as a')
            ->leftJoin('sub_std_map as c', 'c.id', '=', 'a.course_id')
            ->where('a.sub_institute_id', $sid)
            ->where('a.source', self::SOURCE)
            ->whereNull('a.deleted_at');

        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('a.status', $status);
        }
        if ($type = $this->activeFilter($request->input('assignment_type'))) {
            $query->where('a.assignment_type', $type);
        }
        if ($planId = $this->activeFilter($request->input('development_plan_id'))) {
            $query->where('a.development_plan_id', $planId);
        }
        if ($employeeId = $this->activeFilter($request->input('employee_id'))) {
            $query->where('a.user_id', $employeeId);
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('c.display_name', 'like', "%{$search}%");
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('a.id')->forPage($page, $perPage)
            ->get([
                'a.*',
                'c.display_name as course_name',
                'c.subject_type as course_type',
                'c.subject_category as course_category',
            ]);

        $users = $this->userMap(
            $rows->pluck('user_id')->merge($rows->pluck('assigned_by_id'))->filter()->all()
        );

        $planTitles = [];
        $planIds = $rows->pluck('development_plan_id')->filter()->unique()->all();
        if ($planIds) {
            $planTitles = DB::table('s_competency_development_plans')
                ->where('sub_institute_id', $sid)->whereIn('id', $planIds)
                ->pluck('title', 'id')->all();
        }

        $competencyNames = [];
        $competencyIds = $rows->pluck('competency_id')->filter()->unique()->all();
        if ($competencyIds) {
            $competencyNames = DB::table('s_users_skills')
                ->whereIn('id', $competencyIds)->pluck('title', 'id')->all();
        }

        $data = $rows->map(function ($r) use ($users, $planTitles, $competencyNames) {
            $employee = $r->user_id ? ($users[$r->user_id] ?? null) : null;

            return [
                'id'                  => (int) $r->id,
                'user_id'             => (int) $r->user_id,
                'employee_name'       => $employee['name'] ?? null,
                'employee_initials'   => $employee['initials'] ?? null,
                'course_id'           => (int) $r->course_id,
                'course_name'         => $r->course_name,
                'course_type'         => $r->course_type,
                'course_category'     => $r->course_category,
                'assignment_type'     => $r->assignment_type,
                'status'              => $r->status,
                'progress'            => (int) $r->progress,
                'approval_status'     => $r->approval_status,
                'due_date'            => $r->due_date,
                'due_date_label'      => $r->due_date ? date('d M Y', strtotime($r->due_date)) : null,
                'assigned_by'         => $r->assigned_by ?: (($r->assigned_by_id ?? null) ? ($users[$r->assigned_by_id]['name'] ?? null) : null),
                'assigned_on'         => $r->assigned_on ? date('d M Y', strtotime($r->assigned_on)) : null,
                'development_plan_id' => $r->development_plan_id ? (int) $r->development_plan_id : null,
                'plan_title'          => $r->development_plan_id ? ($planTitles[$r->development_plan_id] ?? null) : null,
                'competency_id'       => $r->competency_id ? (int) $r->competency_id : null,
                'competency_name'     => $r->competency_id ? ($competencyNames[$r->competency_id] ?? null) : null,
            ];
        })->all();

        return response()->json([
            'status'     => 1,
            'message'    => 'Learning assignments fetched successfully',
            'data'       => $data,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /**
     * POST /competency/learning-assignments
     * Accepts either user_ids[] (bulk assign one course to several employees)
     * or a single employee_id.
     */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'course_id'           => 'required|integer|exists:sub_std_map,id',
            'employee_id'         => 'required_without:user_ids|integer',
            'user_ids'            => 'required_without:employee_id|array|min:1',
            'user_ids.*'          => 'integer',
            'assignment_type'     => 'nullable|in:' . implode(',', self::TYPES),
            'status'              => 'nullable|in:' . implode(',', self::STATUSES),
            'due_date'            => 'nullable|date',
            'development_plan_id' => 'nullable|integer',
            'competency_id'       => 'nullable|integer',
            'progress'            => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $targets = $request->input('user_ids') ?: [$request->input('employee_id')];
        $targets = array_values(array_unique(array_filter(array_map('intval', (array) $targets))));

        if (!$targets) {
            return response()->json(['status' => 0, 'message' => 'Select at least one employee'], 422);
        }

        $actor = $context['user_id'] ? $this->userMap([$context['user_id']]) : [];
        $actorName = $actor[$context['user_id']]['name'] ?? null;

        $courseName = DB::table('sub_std_map')->where('id', $request->input('course_id'))->value('display_name');

        $rows = [];
        foreach ($targets as $userId) {
            $rows[] = [
                'sub_institute_id'    => $sid,
                'user_id'             => $userId,
                'course_id'           => (int) $request->input('course_id'),
                'assignment_type'     => $request->input('assignment_type', 'Mandatory'),
                'status'              => $request->input('status', 'Not Started'),
                'progress'            => (int) $request->input('progress', 0),
                'approval_status'     => 'approved',
                'due_date'            => $request->input('due_date'),
                'assigned_by'         => $actorName,
                'assigned_by_id'      => $context['user_id'],
                'assigned_on'         => now(),
                'development_plan_id' => $request->input('development_plan_id'),
                'competency_id'       => $request->input('competency_id'),
                'source'              => self::SOURCE,
                'created_at'          => now(),
                'updated_at'          => now(),
            ];
        }

        DB::table(self::TABLE)->insert($rows);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'assigned_learning',
            'Assigned "' . ($courseName ?: 'course') . '" to ' . count($rows) . ' employee(s)',
            // Assigning through a plan logs against the plan, so the plan's own
            // "Notes & History" tab picks it up.
            $request->input('development_plan_id') ? 'development_plan' : 'learning_assignment',
            $request->input('development_plan_id') ? (int) $request->input('development_plan_id') : null,
            $courseName ?: 'Course'
        );

        return response()->json([
            'status'  => 1,
            'message' => count($rows) === 1
                ? 'Learning assigned successfully'
                : 'Learning assigned to ' . count($rows) . ' employees',
            'data'    => ['assigned' => count($rows)],
        ], 201);
    }

    /** PUT /competency/learning-assignments/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $row = $this->find($id, $sid);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Learning assignment not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'assignment_type'     => 'nullable|in:' . implode(',', self::TYPES),
            'status'              => 'nullable|in:' . implode(',', self::STATUSES),
            'progress'            => 'nullable|integer|min:0|max:100',
            'due_date'            => 'nullable|date',
            'development_plan_id' => 'nullable|integer',
            'competency_id'       => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $update = ['updated_at' => now()];
        foreach (['assignment_type', 'due_date', 'development_plan_id', 'competency_id'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }
        if ($request->has('progress')) {
            $update['progress'] = (int) $request->input('progress');
        }
        if ($request->has('status')) {
            $update['status'] = $request->input('status');
            if ($request->input('status') === 'Completed') {
                $update['progress'] = 100;
            }
        }

        DB::table(self::TABLE)->where('id', $row->id)->update($update);

        $courseName = DB::table('sub_std_map')->where('id', $row->course_id)->value('display_name');

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            ($request->input('status') === 'Completed' && $row->status !== 'Completed')
                ? 'completed_learning'
                : 'updated_learning',
            ($request->input('status') === 'Completed' && $row->status !== 'Completed' ? 'Completed' : 'Updated')
                . ' learning assignment "' . ($courseName ?: 'course') . '"',
            'learning_assignment',
            (int) $row->id,
            $courseName ?: ('Assignment #' . $row->id),
            $this->diffChanges($row, $update, [
                'assignment_type'     => 'Assignment Type',
                'status'              => 'Status',
                'progress'            => 'Progress',
                'due_date'            => 'Due Date',
                'development_plan_id' => 'Development Plan',
                'competency_id'       => 'Linked Competency',
            ])
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Learning assignment updated successfully',
            'data'    => ['id' => (int) $row->id],
        ]);
    }

    /** DELETE /competency/learning-assignments/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $row = $this->find($id, $sid);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Learning assignment not found'], 404);
        }

        DB::table(self::TABLE)->where('id', $row->id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        $courseName = DB::table('sub_std_map')->where('id', $row->course_id)->value('display_name');

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'unassigned_learning',
            'Removed learning assignment "' . ($courseName ?: 'course') . '"',
            'learning_assignment',
            (int) $row->id,
            $courseName ?: ('Assignment #' . $row->id)
        );

        return response()->json(['status' => 1, 'message' => 'Learning assignment removed successfully']);
    }

    /**
     * GET /competency/learning-assignments/courses
     * The course picker for "Assign Learning" - the tenant's active catalog.
     */
    public function courses(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $query = DB::table('sub_std_map')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->whereNotNull('display_name')
            ->where('display_name', '!=', '');

        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('display_name', 'like', "%{$search}%");
        }

        $data = $query->orderBy('display_name')->limit(300)
            ->get(['id', 'display_name', 'subject_type', 'subject_category'])
            ->map(fn ($c) => [
                'id'       => (int) $c->id,
                'name'     => $c->display_name,
                'type'     => $c->subject_type,
                'category' => $c->subject_category,
            ])->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Courses fetched successfully',
            'data'    => $data,
        ]);
    }

    private function find($id, int $sid)
    {
        return DB::table(self::TABLE)
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->where('source', self::SOURCE)
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<int, array{name:string, initials:string}> */
    private function userMap(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids) {
            return [];
        }

        return DB::table('tbluser')
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(function ($u) {
                $first = trim((string) $u->first_name);
                $last = trim((string) $u->last_name);
                $name = trim($first . ' ' . $last) ?: ('User ' . $u->id);
                $initials = strtoupper(substr($first ?: $name, 0, 1) . substr($last, 0, 1));

                return [(int) $u->id => ['name' => $name, 'initials' => $initials ?: strtoupper(substr($name, 0, 2))]];
            })
            ->all();
    }
}
