<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DepartmentManagementController extends Controller
{
    /**
     * G-SEC-29. THE TENANT COMES FROM THE TOKEN.
     *
     * Every `$request->...sub_institute_id` in this controller was replaced with
     * `$this->apiTenantId($request)`. Confirmed by execution before the change: a
     * tenant-7 caller asking for `sub_institute_id=3` received tenant 3's rows.
     *
     * THIS CONTROLLER HAD NO SESSION FALLBACK. Nine of the eighteen leaking
     * controllers read `session() ?? $request`, which leaks only when the session
     * is absent. This one read the request and nothing else, so it leaked on
     * EVERY call - the worst of the three shapes, which is why it went first.
     *
     * ONE IMPLEMENTATION, NOT THIRTEEN. `apiTenantId()` lives in
     * ResolvesApiIdentity and is called directly. A private wrapper per
     * controller would put the resolution in thirteen places, which is how four
     * identity resolvers happened.
     *
     * ---------------------------------------------------------------------
     * WHAT THIS CONTROLLER RETURNS, AND WHY IT CHANGED
     *
     * The Department Management screen rendered four columns that this
     * controller never sent: Code, Head, Employees and Status. The frontend
     * filled the gap by inventing them - a hardcoded code lookup table,
     * `hod: null`, `employees: 0`, and a Status that read "Active" for every
     * row because `index()` filtered `status = 1` before the UI ever saw the
     * data. Those four columns were decoration, not information.
     *
     * So `index()` now selects what the screen actually displays: the head's
     * name resolved through head_user_id, a live headcount from tbluser, the
     * stored code and description, and BOTH statuses. The response envelope
     * (main_departments / sub_departments) is unchanged, because the frontend
     * splits on it.
     *
     * Two bugs fixed in passing:
     *   - No `whereNull('deleted_at')`. Soft-deleted departments were filtered
     *     out only incidentally, by `status = 1`, and any row that lost that
     *     coincidence became visible again. Three such rows existed on dev.
     *   - A bare `->get()` returned every column, including created_by,
     *     updated_by, deleted_by and sub_institute_id, to a screen that used
     *     five of them.
     */
    use ResolvesApiIdentity;

    /**
     * Tables that point at hrms_departments.id through `standard_id`.
     *
     * The LMS reuses departments as academic "standards" - seven tables carry a
     * real, enforced foreign key to hrms_departments, and on live they hold
     * hundreds of rows of question banks, chapters and content. Deleting a
     * department out from under them either fails on the constraint or strands
     * the content, and neither delete path checked.
     */
    private const LMS_REFERENCE_TABLES = [
        'lms_question_master',
        'chapter_master',
        'content_master',
        'sub_std_map',
        'lms_curriculum',
        'lms_lesson_plan',
        'lms_flashcard',
    ];

    /**
     * Departments for the caller's organisation.
     *
     * Query string:
     *   status=active|inactive|all   (default: all)
     *   search=<text>                optional name/code match
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $query = $this->departmentSelect($tenantId);

        $status = strtolower((string) $request->query('status', 'all'));
        if ($status === 'active') {
            $query->where('d.status', 1);
        } elseif ($status === 'inactive') {
            $query->where('d.status', 0);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('d.department', 'like', '%' . $search . '%')
                  ->orWhere('d.code', 'like', '%' . $search . '%');
            });
        }

        $departments = $query
            ->orderBy('d.sort_order')
            ->orderBy('d.department')
            ->get();

        // The frontend splits the list into a root set and a parent-keyed map of
        // children. Keeping that shape means this richer payload drops into the
        // existing screen without a migration on the client.
        $mainDepartments = [];
        $subDepartments = [];

        foreach ($departments as $department) {
            if ((int) $department->parent_id === 0) {
                $mainDepartments[] = $department;
            } else {
                $subDepartments[$department->parent_id][] = $department;
            }
        }

        return response()->json([
            'main_departments' => $mainDepartments,
            'sub_departments'  => $subDepartments,
            // Flat list alongside the split one. The tree needs the split; the
            // table, the filters and the CSV all want every row in order, and
            // rebuilding that on the client means re-flattening what was just
            // grouped.
            'departments'      => $departments,
        ]);
    }

    /**
     * One department, with the counts the details panel shows.
     */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $department = $this->departmentSelect($tenantId)
            ->where('d.id', $id)
            ->first();

        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $department->sub_department_count = DB::table('hrms_departments')
            ->where('parent_id', $department->id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();

        foreach (['department_sops' => 'sop_count', 'department_policies' => 'policy_count', 'department_rules' => 'rule_count'] as $table => $key) {
            $department->{$key} = DB::table($table)
                ->where('department_id', $department->id)
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->count();
        }

        return response()->json(['status' => 1, 'data' => $department]);
    }

    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'department'  => 'required|string|max:191',
            'parent_id'   => 'nullable|integer|min:0',
            'code'        => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $parentId = (int) ($request->input('parent_id') ?: 0);

        // A parent from another organisation would silently graft this
        // department onto a tree its owner cannot see.
        if ($parentId > 0 && !$this->departmentBelongsToTenant($parentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Parent department not found'], 422);
        }

        $name = trim((string) $request->input('department'));

        $duplicate = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('parent_id', $parentId)
            ->where('department', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status'  => 0,
                'message' => 'A department with this name already exists at this level',
            ], 409);
        }

        $nextSortOrder = (int) DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('parent_id', $parentId)
            ->max('sort_order');

        $id = DB::table('hrms_departments')->insertGetId([
            'department'       => $name,
            'parent_id'        => $parentId,
            'code'             => $this->normalise($request->input('code')),
            'description'      => $this->normalise($request->input('description')),
            'sort_order'       => $nextSortOrder + 1,
            'tasks'            => null,
            // Previously this wrote `roles_responsibility => $department`,
            // stamping the department's own name into the responsibilities
            // field on every create. It is a distinct field with its own
            // meaning, so it now starts empty.
            'roles_responsibility' => null,
            'status'           => 1,
            'is_calculated'    => 0,
            'sub_institute_id' => $tenantId,
            'created_by'       => $actorId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Department created successfully',
            'data'    => ['id' => $id],
        ], 201);
    }

    /**
     * Previously this wrote exactly one column - `department`. Everything else
     * the edit form offered was discarded on save.
     */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $department = $this->findForTenant($id, $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'department'           => 'sometimes|required|string|max:191',
            'code'                 => 'nullable|string|max:50',
            'description'          => 'nullable|string',
            'roles_responsibility' => 'nullable|string',
            'status'               => 'sometimes|integer|in:0,1',
            'parent_id'            => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updates = ['updated_by' => $actorId, 'updated_at' => now()];

        if ($request->has('department')) {
            $name     = trim((string) $request->input('department'));
            $parentId = $request->has('parent_id')
                ? (int) $request->input('parent_id')
                : (int) $department->parent_id;

            $duplicate = DB::table('hrms_departments')
                ->where('sub_institute_id', $tenantId)
                ->where('parent_id', $parentId)
                ->where('department', $name)
                ->where('id', '!=', $department->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'A department with this name already exists at this level',
                ], 409);
            }

            $updates['department'] = $name;
        }

        foreach (['code', 'description', 'roles_responsibility'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $this->normalise($request->input($field));
            }
        }

        if ($request->has('status')) {
            $updates['status'] = (int) $request->input('status');
        }

        if ($request->has('parent_id')) {
            $error = $this->validateParentChange($department, (int) $request->input('parent_id'), $tenantId);
            if ($error) {
                return $error;
            }
            $updates['parent_id'] = (int) $request->input('parent_id');
        }

        DB::table('hrms_departments')
            ->where('id', $department->id)
            ->where('sub_institute_id', $tenantId)
            ->update($updates);

        return response()->json(['status' => 1, 'message' => 'Department updated successfully']);
    }

    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $department = $this->findForTenant($id, $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        // The department and everything under it goes in one operation, so the
        // reference check has to cover the children too - otherwise deleting a
        // parent quietly takes a referenced child with it.
        $affectedIds = array_merge([(int) $department->id], $this->descendantIds((int) $department->id, $tenantId));

        $blocking = $this->lmsReferenceCounts($affectedIds);
        if ($blocking !== []) {
            return response()->json([
                'status'     => 0,
                'message'    => 'This department is used as a standard by LMS content and cannot be deleted.',
                'references' => $blocking,
            ], 409);
        }

        DB::transaction(function () use ($affectedIds, $tenantId, $actorId) {
            DB::table('hrms_departments')
                ->whereIn('id', $affectedIds)
                ->where('sub_institute_id', $tenantId)
                ->update([
                    'status'     => 0,
                    'deleted_by' => $actorId,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Department deleted successfully',
            'deleted' => count($affectedIds),
        ]);
    }

    /**
     * Assign, change or clear the head of department.
     *
     * head_user_id has existed on the table for a while and had exactly one
     * writer, in a different controller, reachable from no screen. On live it
     * was set for zero of 1,274 departments - which is why the UI's Head column
     * had nothing to show and gave up, hardcoding "Unassigned".
     */
    public function setHead(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $department = $this->findForTenant($id, $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'head_user_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $headUserId = $request->input('head_user_id');
        $headUserId = ($headUserId === null || $headUserId === '' || (int) $headUserId === 0)
            ? null
            : (int) $headUserId;

        // Naming a user from another organisation would put their name on this
        // tenant's screen, which is a disclosure even though it writes only an id.
        if ($headUserId !== null) {
            $belongs = DB::table('tbluser')
                ->where('id', $headUserId)
                ->where('sub_institute_id', $tenantId)
                ->exists();

            if (!$belongs) {
                return response()->json(['status' => 0, 'message' => 'Employee not found'], 422);
            }
        }

        DB::table('hrms_departments')
            ->where('id', $department->id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'head_user_id' => $headUserId,
                'updated_by'   => $actorId,
                'updated_at'   => now(),
            ]);

        return response()->json([
            'status'  => 1,
            'message' => $headUserId === null ? 'Department head cleared' : 'Department head updated',
        ]);
    }

    /**
     * Move a department to a different parent.
     */
    public function setParent(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $department = $this->findForTenant($id, $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'parent_id' => 'present|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $parentId = (int) $request->input('parent_id');

        $error = $this->validateParentChange($department, $parentId, $tenantId);
        if ($error) {
            return $error;
        }

        // Moving into a new sibling group means the old sort_order is a
        // position in a list this row has left. Append it instead.
        $nextSortOrder = (int) DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('parent_id', $parentId)
            ->where('id', '!=', $department->id)
            ->max('sort_order');

        DB::table('hrms_departments')
            ->where('id', $department->id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'parent_id'  => $parentId,
                'sort_order' => $nextSortOrder + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 1, 'message' => 'Parent department updated']);
    }

    /**
     * Move a department one place up or down among its siblings.
     *
     * Swaps sort_order with the neighbour rather than renumbering the group, so
     * two people reordering different pairs at once cannot overwrite each
     * other's positions.
     */
    public function reorder(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer',
            'direction'     => 'required|string|in:up,down',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $department = $this->findForTenant($request->input('department_id'), $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $movingUp = $request->input('direction') === 'up';

        $neighbour = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('parent_id', $department->parent_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $department->id)
            ->when($movingUp,
                fn ($q) => $q->where('sort_order', '<=', $department->sort_order)
                             ->orderByDesc('sort_order')->orderByDesc('id'),
                fn ($q) => $q->where('sort_order', '>=', $department->sort_order)
                             ->orderBy('sort_order')->orderBy('id')
            )
            ->first();

        if (!$neighbour) {
            return response()->json([
                'status'  => 1,
                'message' => $movingUp ? 'Already first' : 'Already last',
                'moved'   => false,
            ]);
        }

        DB::transaction(function () use ($department, $neighbour, $tenantId, $movingUp) {
            // Equal sort_order values would swap to no effect. Force them apart
            // in the requested direction instead of pretending the move worked.
            $ownOrder       = (int) $department->sort_order;
            $neighbourOrder = (int) $neighbour->sort_order;

            if ($ownOrder === $neighbourOrder) {
                $ownOrder       = $movingUp ? $neighbourOrder - 1 : $neighbourOrder + 1;
                $neighbourOrder = (int) $neighbour->sort_order;
            }

            DB::table('hrms_departments')->where('id', $department->id)
                ->where('sub_institute_id', $tenantId)
                ->update(['sort_order' => $neighbourOrder, 'updated_at' => now()]);

            DB::table('hrms_departments')->where('id', $neighbour->id)
                ->where('sub_institute_id', $tenantId)
                ->update(['sort_order' => $ownOrder, 'updated_at' => now()]);
        });

        return response()->json(['status' => 1, 'message' => 'Order updated', 'moved' => true]);
    }

    /**
     * CSV of the department list.
     *
     * The Export button on the screen had no click handler at all. This gives
     * it one, over the same rows and columns the table shows.
     */
    public function export(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $departments = $this->departmentSelect($tenantId)
            ->orderBy('d.sort_order')
            ->orderBy('d.department')
            ->get();

        $parentNames = $departments->pluck('department', 'id');

        $filename = 'departments-' . date('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($departments, $parentNames) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Code', 'Department', 'Parent Department', 'Head of Department',
                'Employees', 'Status', 'Description', 'Created On',
            ]);

            foreach ($departments as $row) {
                fputcsv($handle, [
                    $row->code,
                    $row->department,
                    (int) $row->parent_id === 0 ? '' : ($parentNames[$row->parent_id] ?? ''),
                    $row->head_name ?: '',
                    (int) $row->employee_count,
                    (int) $row->status === 1 ? 'Active' : 'Inactive',
                    $row->description,
                    $row->created_at,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Employees of the caller's organisation, for the head-of-department picker.
     */
    public function employees(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $query = DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', function ($join) use ($tenantId) {
                $join->on('d.id', '=', 'u.department_id')
                     ->where('d.sub_institute_id', '=', $tenantId);
            })
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.terminated_date')
            ->select([
                'u.id',
                'u.employee_no',
                'u.department_id',
                'd.department as department_name',
                DB::raw($this->fullNameExpression('u') . ' as name'),
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('u.first_name', 'like', '%' . $search . '%')
                  ->orWhere('u.last_name', 'like', '%' . $search . '%')
                  ->orWhere('u.user_name', 'like', '%' . $search . '%')
                  ->orWhere('u.employee_no', 'like', '%' . $search . '%');
            });
        }

        return response()->json([
            'status' => 1,
            'data'   => $query->orderBy('u.first_name')->orderBy('u.last_name')->limit(500)->get(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * The one place the department payload is shaped.
     *
     * index(), show() and export() all need identical columns; three separate
     * select lists would drift the moment one of them gained a field.
     */
    private function departmentSelect(int $tenantId)
    {
        // Correlated count rather than a GROUP BY. A LEFT JOIN onto tbluser
        // would multiply department rows by their employees and need a GROUP BY
        // over every selected column to collapse again.
        $employeeCount = DB::table('tbluser')
            ->selectRaw('COUNT(*)')
            ->whereColumn('tbluser.department_id', 'd.id')
            ->where('tbluser.sub_institute_id', $tenantId)
            ->whereNull('tbluser.terminated_date');

        return DB::table('hrms_departments as d')
            ->leftJoin('tbluser as h', 'h.id', '=', 'd.head_user_id')
            ->where('d.sub_institute_id', $tenantId)
            // The filter that was missing. Without it a soft-deleted row
            // reappears the moment its status is anything but 0.
            ->whereNull('d.deleted_at')
            ->select([
                'd.id',
                'd.department',
                'd.code',
                'd.description',
                'd.parent_id',
                'd.status',
                'd.sort_order',
                'd.roles_responsibility',
                'd.head_user_id',
                'd.created_at',
                'd.updated_at',
                DB::raw('d.head_user_id as head_id'),
                DB::raw($this->fullNameExpression('h') . ' as head_name'),
            ])
            ->selectSub($employeeCount, 'employee_count');
    }

    /**
     * tbluser stores names in three parts and also carries a user_name.
     * CONCAT_WS skips nulls but not empty strings, so the TRIM/NULLIF pair is
     * what stops a record with blank name parts rendering as whitespace.
     */
    private function fullNameExpression(string $alias): string
    {
        return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.middle_name, {$alias}.last_name)), ''), {$alias}.user_name)";
    }

    private function findForTenant($id, int $tenantId)
    {
        return DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function departmentBelongsToTenant(int $id, int $tenantId): bool
    {
        return DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function normalise($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A parent must exist, belong to the caller, and not be the department
     * itself or one of its own descendants - the last of which would detach the
     * whole branch into a cycle that no tree walk can render or escape.
     */
    private function validateParentChange($department, int $parentId, int $tenantId)
    {
        if ($parentId === 0) {
            return null;
        }

        if ($parentId === (int) $department->id) {
            return response()->json([
                'status'  => 0,
                'message' => 'A department cannot be its own parent',
            ], 422);
        }

        if (!$this->departmentBelongsToTenant($parentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Parent department not found'], 422);
        }

        if (in_array($parentId, $this->descendantIds((int) $department->id, $tenantId), true)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Cannot move a department beneath one of its own sub-departments',
            ], 422);
        }

        return null;
    }

    /**
     * Every department below this one.
     *
     * Iterative rather than recursive SQL: live runs MariaDB 10.1, which has no
     * recursive CTEs. The visited set also means the 14 rows already holding a
     * broken parent_id cannot spin this into an infinite loop.
     */
    private function descendantIds(int $departmentId, int $tenantId): array
    {
        $found   = [];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $children = DB::table('hrms_departments')
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

    /**
     * How many LMS rows point at any of these departments, per table.
     * Empty array means the delete is safe.
     */
    private function lmsReferenceCounts(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $counts = [];

        foreach (self::LMS_REFERENCE_TABLES as $table) {
            try {
                $count = DB::table($table)->whereIn('standard_id', $departmentIds)->count();
            } catch (\Throwable $e) {
                // A table absent on this database is not a reference. The seven
                // LMS tables exist on both today, but this endpoint should not
                // start failing if one is dropped.
                continue;
            }

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }
}
