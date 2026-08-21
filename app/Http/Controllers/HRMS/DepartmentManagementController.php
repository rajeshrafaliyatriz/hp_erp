<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\Org\DepartmentMergeService;

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
    use ResolvesEmployeeJobRole;

    /**
     * Moving or releasing everything that points at a department.
     *
     * Shared with the dedupe command and the legacy Blade delete, so the three
     * paths cannot drift apart again - they previously disagreed on whether a
     * delete cascaded to children and whether it touched job roles.
     */
    public function __construct(private DepartmentMergeService $mergeService)
    {
    }

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
                // The 409 used to be a dead end. Merge repoints these rows to
                // another department, so it is the way out - and saying so is
                // the difference between a refusal and a suggestion.
                'message'      => 'This department is used as a standard by LMS content, so it cannot be deleted. Merge it into another department instead - that moves the content across.',
                'references'   => $blocking,
                'can_merge'    => true,
            ], 409);
        }

        /*
         * RELEASE, DO NOT STRAND.
         *
         * This used to soft-delete the department and stop, leaving every
         * reference in ~30 other tables pointing at a row the UI can no longer
         * show. That is how live stranded 77 skill records and seven employees
         * when nine departments were destroyed in tenant 6.
         *
         * release() sets those references to NULL instead. An employee with no
         * department is visible in the unassigned pool and fixable; an employee
         * pointing at a deleted one is neither. Employees are never deleted.
         */
        $result = $this->mergeService->release($affectedIds, $tenantId, $actorId);

        return response()->json([
            'status'    => 1,
            'message'   => 'Department deleted. ' . $result['employees'] . ' employee(s) are now unassigned.',
            'deleted'   => count($affectedIds),
            'employees' => $result['employees'],
            'released'  => $result['released'],
        ]);
    }

    /**
     * What is attached to this department, labelled, before anything is done
     * to it.
     *
     * `mode` matters and the two answers genuinely differ: DELETE cascades to
     * the whole subtree, so it must count the subtree. MERGE moves only this
     * department - its children are re-parented and keep their own data - so
     * counting theirs would overstate what is about to move.
     */
    public function impact(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $department = $this->findForTenant($id, $tenantId);
        if (!$department) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $mode = strtolower((string) $request->query('mode', 'delete'));

        $impact = $this->mergeService->impact(
            (int) $department->id,
            $tenantId,
            null,
            $mode !== 'merge'
        );

        return response()->json([
            'status' => 1,
            'data'   => $impact + ['department' => $department->department],
        ]);
    }

    /**
     * Move everything from this department into another, then retire this one.
     *
     * The alternative to deleting. Nothing is lost: employees, job roles,
     * skills, competency, performance, tasks and LMS content all become the
     * target's.
     */
    public function merge(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'target_department_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $source = $this->findForTenant($id, $tenantId);
        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $targetId = (int) $request->input('target_department_id');
        $target   = $this->findForTenant($targetId, $tenantId);

        if (!$target) {
            return response()->json(['status' => 0, 'message' => 'Target department not found'], 422);
        }

        if ((int) $source->id === $targetId) {
            return response()->json([
                'status'  => 0,
                'message' => 'A department cannot be merged into itself.',
            ], 422);
        }

        // Merging into your own descendant would leave the branch pointing at a
        // department that no longer exists. Same guard the parent change uses.
        if (in_array($targetId, $this->descendantIds((int) $source->id, $tenantId), true)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Cannot merge a department into one of its own sub-departments.',
            ], 422);
        }

        $result = $this->mergeService->merge((int) $source->id, $targetId, $tenantId, $actorId);

        $this->recordMerge($tenantId, $actorId, $source, $target, $result);

        return response()->json([
            'status'  => 1,
            'message' => sprintf(
                '"%s" merged into "%s". %d employee(s) moved, %d job role(s) folded, %d sub-department(s) re-parented.',
                $source->department,
                $target->department,
                $result['employees'],
                $result['job_roles_folded'],
                $result['children']
            ),
            'data' => $result,
        ]);
    }

    /**
     * A merge moves data across dozens of tables and cannot be undone with a
     * button, so it leaves a record of itself.
     *
     * Goes through EventRecorder rather than writing g2g_audit_log directly.
     * That table's `event_id` is a bigint pointing into the g2g_event store,
     * not a UUID - inserting a UUID silently truncates to 0, which is exactly
     * what my first attempt did. EventRecorder owns writing the event and its
     * audit row together, so this cannot get the pairing wrong.
     *
     * Failing to record must not fail a merge that already succeeded, hence
     * the catch - but the reason is logged rather than swallowed in silence.
     */
    private function recordMerge(int $tenantId, ?int $actorId, $source, $target, array $result): void
    {
        try {
            app(\App\Services\Events\EventRecorder::class)->record(
                'department.merged',
                $tenantId,
                'department',
                (int) $source->id,
                $actorId,
                [
                    'from'             => ['id' => (int) $source->id, 'name' => $source->department],
                    'to'               => ['id' => (int) $target->id, 'name' => $target->department],
                    'employees'        => $result['employees'],
                    'job_roles_folded' => $result['job_roles_folded'],
                    'children'         => $result['children'],
                    'moved'            => $result['moved'],
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('Department merge recorded in the database but not in the event store', [
                'from'  => $source->id,
                'to'    => $target->id,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Employees of the caller's organisation.
     *
     * Serves three callers: the head-of-department picker (no filter), the
     * "transfer from another department" list (?department_id=), and the
     * "employees with no department" pool (?unassigned=1).
     *
     * That last one had no way to be answered anywhere in the application:
     * /table_data builds `where($column, $value)` and so cannot express
     * "department_id IS NULL".
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

        if ($request->boolean('unassigned')) {
            $query->where(function ($q) {
                $q->whereNull('u.department_id')->orWhere('u.department_id', 0);
            });
        } elseif ($departmentId = (int) $request->query('department_id')) {
            $query->where('u.department_id', $departmentId);
        }

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

    /**
     * Move employees into this department.
     *
     * Creating a department was only ever half the job: there was no way to put
     * anyone in one. The only existing route that writes tbluser.department_id
     * for a deliberate move is MobilityTransferController, and it handles one
     * employee at a time and demands a job role that a department transfer does
     * not have.
     *
     * EVERY MOVE IS LOGGED. Each employee gets an s_mobility_transfers row
     * recording where they came from and where they went. That table already
     * existed, empty, on both databases with exactly the right columns, so the
     * history costs no schema change - and without it a headcount can change
     * with nothing to say who moved or when.
     *
     * ONE BAD ROW NEVER ABORTS THE BATCH. Shape copied from
     * ReportingLineController::bulkAssign: per-row verdicts, always 200, so a
     * caller can see exactly which employees moved and why the rest did not.
     */
    public function assignEmployees(Request $request, $id)
    {
        return $this->applyEmployeeAssignment($request, $id, true);
    }

    /**
     * Remove employees from this department, leaving them unassigned.
     */
    public function unassignEmployees(Request $request, $id)
    {
        return $this->applyEmployeeAssignment($request, $id, false);
    }

    private function applyEmployeeAssignment(Request $request, $id, bool $assigning)
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
            'user_ids'       => 'required|array|min:1|max:1000',
            'user_ids.*'     => 'required|integer|min:1',
            // Optional. When given, every employee in the batch takes this job
            // role; it must belong to the destination department.
            'jobrole_id'     => 'nullable|integer|min:1',
            'effective_date' => 'nullable|date',
            'remarks'        => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $effectiveDate = $request->input('effective_date') ?: now()->toDateString();
        $remarks       = $this->normalise($request->input('remarks'));

        // Validated once for the batch rather than per employee: it is the same
        // role for everyone, and a role from another tenant or another
        // department should refuse the whole request, not 1,000 rows one at a
        // time.
        $requestedRoleId = $request->input('jobrole_id') ? (int) $request->input('jobrole_id') : null;

        if ($requestedRoleId !== null) {
            if (!$assigning) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'A job role cannot be set while removing employees from a department.',
                ], 422);
            }

            $role = DB::table('s_user_jobrole')
                ->where('id', $requestedRoleId)
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->first(['id', 'department_id']);

            if (!$role) {
                return response()->json(['status' => 0, 'message' => 'Job role not found'], 422);
            }

            if ((int) $role->department_id !== (int) $department->id) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'That job role belongs to a different department.',
                ], 422);
            }
        }

        $applied = 0;
        $rows    = [];

        foreach ($request->input('user_ids') as $index => $userId) {
            $userId = (int) $userId;

            // Checked per employee, not once for the batch: a mixed request
            // must move the caller's own people and refuse the rest, rather
            // than being accepted or rejected wholesale.
            $employee = DB::table('tbluser')
                ->where('id', $userId)
                ->where('sub_institute_id', $tenantId)
                // The job role columns come along because a department move
                // that leaves the role behind is how the two halves of
                // department -> job role -> employee drift apart.
                ->first(['id', 'department_id', 'jobtitle_id', 'allocated_standards']);

            if (!$employee) {
                $rows[] = ['index' => $index, 'user_id' => $userId, 'ok' => false, 'reason' => 'Employee not found in this organisation'];
                continue;
            }

            $fromDepartmentId = $employee->department_id ? (int) $employee->department_id : null;
            $toDepartmentId   = $assigning ? (int) $department->id : null;

            // Already here is only a no-op if there is nothing else to change.
            // Setting the job role of somebody who is already in the department
            // is a real operation - refusing it would mean the only way to fix
            // a person's role was to move them out and back again.
            if ($fromDepartmentId === $toDepartmentId) {
                $roleAlreadyCorrect = $requestedRoleId === null
                    || $requestedRoleId === $this->resolveJobRoleId($employee);

                if ($roleAlreadyCorrect) {
                    $rows[] = ['index' => $index, 'user_id' => $userId, 'ok' => false, 'reason' => $assigning ? 'Already in this department' : 'Not in this department'];
                    continue;
                }
            }

            if (!$assigning && $fromDepartmentId !== (int) $department->id) {
                $rows[] = ['index' => $index, 'user_id' => $userId, 'ok' => false, 'reason' => 'Not in this department'];
                continue;
            }

            /*
             * The job role has to move with the person.
             *
             * An employee's role is an s_user_jobrole row, and every one of
             * those BELONGS to a department. Writing department_id on its own
             * left the role pointing at the department they just left, so the
             * chain department -> job role -> employee contradicted itself the
             * moment anybody used this endpoint.
             *
             * MobilityTransferController::completeTransferInProfile has always
             * written both. This now matches it.
             *
             * Explicit jobrole_id wins. Otherwise the caller's current role is
             * kept if it already belongs to the destination, and cleared if it
             * does not - a stale role is worse than none, because it reads as
             * fact.
             */
            $roleWarning = null;
            $newRoleId   = $requestedRoleId;

            if ($newRoleId === null) {
                $currentRoleId = $this->resolveJobRoleId($employee);

                if ($currentRoleId !== null && $assigning) {
                    $currentRoleDept = DB::table('s_user_jobrole')
                        ->where('id', $currentRoleId)
                        ->where('sub_institute_id', $tenantId)
                        ->value('department_id');

                    if ((int) $currentRoleDept === (int) $department->id) {
                        $newRoleId = $currentRoleId;
                    } else {
                        $roleWarning = 'Job role cleared: it belongs to a different department';
                    }
                }
            }

            DB::transaction(function () use ($userId, $tenantId, $toDepartmentId, $fromDepartmentId, $effectiveDate, $remarks, $actorId, $newRoleId) {
                DB::table('tbluser')
                    ->where('id', $userId)
                    ->where('sub_institute_id', $tenantId)
                    ->update([
                        'department_id' => $toDepartmentId,
                        // Both columns, so the two readers of an employee's
                        // role cannot disagree afterwards.
                        'jobtitle_id'         => $newRoleId ?: 0,
                        'allocated_standards' => $newRoleId !== null ? (string) $newRoleId : null,
                        'updated_at'          => now(),
                    ]);

                DB::table('s_mobility_transfers')->insert([
                    'sub_institute_id'   => $tenantId,
                    'user_id'            => $userId,
                    'from_department_id' => $fromDepartmentId,
                    // Names are denormalised alongside the ids so the history
                    // still reads correctly after a department is renamed or
                    // retired - which is the point of keeping a history.
                    'from_department'    => $this->departmentName($fromDepartmentId),
                    'to_department_id'   => $toDepartmentId,
                    'to_department'      => $this->departmentName($toDepartmentId),
                    'effective_date'     => $effectiveDate,
                    'status'             => 'Completed',
                    'remarks'            => $remarks,
                    'created_by'         => $actorId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            });

            $applied++;
            // `reason` carries the role warning on success too - the move
            // worked, but the caller should know the role was dropped.
            $rows[] = ['index' => $index, 'user_id' => $userId, 'ok' => true, 'reason' => $roleWarning];
        }

        return response()->json([
            'status'  => 1,
            'message' => $assigning
                ? $applied . ' employee(s) moved into ' . $department->department
                : $applied . ' employee(s) removed from ' . $department->department,
            'applied' => $applied,
            'refused' => count($rows) - $applied,
            'rows'    => $rows,
        ]);
    }

    private function departmentName(?int $departmentId): ?string
    {
        if (!$departmentId) {
            return null;
        }

        return DB::table('hrms_departments')->where('id', $departmentId)->value('department');
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
        // Matches WorkforceMetricsService::__construct's rule exactly -
        // status = 1 AND terminated_date IS NULL AND deleted_at IS NULL.
        //
        // This filtered terminated_date alone, so deactivated and soft-deleted
        // users inflated the column: it read 98 across live where the dashboard
        // tile read 92. Two screens, same question, two answers.
        $employeeCount = DB::table('tbluser')
            ->selectRaw('COUNT(*)')
            ->whereColumn('tbluser.department_id', 'd.id')
            ->where('tbluser.sub_institute_id', $tenantId)
            ->where('tbluser.status', 1)
            ->whereNull('tbluser.terminated_date')
            ->whereNull('tbluser.deleted_at');

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
