<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Deadline extension requests: the executor asks for a new due date, the
 * observer approves or rejects; approval moves task.task_date.
 *
 * The old frontend had this whole flow (request modal, approve/reject
 * buttons) posting to /api/deadline-extension - an endpoint that never
 * existed in this repository, so the flow was wired to nothing. This is
 * its backend.
 */
class DeadlineExtensionController extends Controller
{
    use ResolvesTaskContext;

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $query = DB::table('task_deadline_extensions as e')
            ->join('task as t', 't.id', '=', 'e.task_id')
            ->leftJoin('tbluser as requester', 'requester.id', '=', 'e.requested_by')
            ->leftJoin('tbluser as decider', 'decider.id', '=', 'e.decided_by')
            ->where('e.sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('e.id')
            ->selectRaw("e.id, e.task_id, e.requested_date, e.reason, e.status, e.decided_on, e.decision_remarks, e.created_at,
                t.task_title, t.task_date as current_due_date,
                TRIM(CONCAT_WS(' ', requester.first_name, requester.middle_name, requester.last_name)) as requested_by_name,
                TRIM(CONCAT_WS(' ', decider.first_name, decider.middle_name, decider.last_name)) as decided_by_name");

        if ($request->filled('task_id')) {
            $query->where('e.task_id', $request->integer('task_id'));
        }
        if ($request->filled('status')) {
            $query->where('e.status', $request->input('status'));
        }

        $rows = $query->limit(200)->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'task_id' => (string) $row->task_id,
            'task_title' => (string) $row->task_title,
            'current_due_date' => $row->current_due_date,
            'requested_date' => $row->requested_date,
            'reason' => $row->reason,
            'status' => (string) $row->status,
            'requested_by' => $row->requested_by_name ?: null,
            'decided_by' => $row->decided_by_name ?: null,
            'decided_on' => $row->decided_on,
            'decision_remarks' => $row->decision_remarks,
            'created_at' => $row->created_at,
        ]);

        return $this->ok('Deadline extensions retrieved successfully.', ['extensions' => $rows->all()]);
    }

    /** The executor asks for more time. */
    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'task_id' => 'required|integer',
            'requested_date' => 'required|date|after:today',
            'reason' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $task = DB::table('task')
            ->where('id', $request->integer('task_id'))
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        // One open request per task: a second ask while the first is pending
        // is an edit of intent, not a new queue entry.
        $pending = DB::table('task_deadline_extensions')
            ->where('task_id', $task->id)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return $this->fail('A pending extension request already exists for this task.', 409);
        }

        $id = DB::table('task_deadline_extensions')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $task->id,
            'requested_by' => $context['user_id'],
            'requested_date' => $request->input('requested_date'),
            'reason' => $request->input('reason'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The task's owner decides, so they hear about it.
        NotificationController::notify(
            $context['sub_institute_id'],
            (int) ($task->task_allocated ?: $task->created_by),
            'deadline_extension_requested',
            'Deadline extension requested: ' . $task->task_title,
            $request->input('reason'),
            (int) $task->id
        );

        return $this->ok('Extension requested.', ['id' => (string) $id], 201);
    }

    /** The observer decides. Approval moves the task's due date. */
    public function decide(Request $request, int $id, TaskAuditService $audit)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $extension = DB::table('task_deadline_extensions')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        if (!$extension) {
            return $this->fail('Extension request not found.', 404);
        }

        if ($extension->status !== 'pending') {
            return $this->fail('This request has already been decided.', 409);
        }

        $approving = $request->input('decision') === 'approve';

        DB::table('task_deadline_extensions')->where('id', $id)->update([
            'status' => $approving ? 'approved' : 'rejected',
            'decided_by' => $context['user_id'],
            'decided_on' => now(),
            'decision_remarks' => $request->input('remarks'),
            'updated_at' => now(),
        ]);

        if ($approving) {
            $task = DB::table('task')->where('id', $extension->task_id)->first();

            DB::table('task')->where('id', $extension->task_id)->update([
                'task_date' => $extension->requested_date,
                'updated_by' => $context['user_id'],
                'updated_at' => now(),
            ]);

            if ($task) {
                $audit->taskChanged((int) $extension->task_id, 'deadline_extended', (array) $task, $context['user_id']);
            }
        }

        NotificationController::notify(
            $context['sub_institute_id'],
            (int) $extension->requested_by,
            $approving ? 'deadline_extension_approved' : 'deadline_extension_rejected',
            'Deadline extension ' . ($approving ? 'approved' : 'rejected'),
            $request->input('remarks'),
            (int) $extension->task_id
        );

        return $this->ok($approving ? 'Extension approved and due date updated.' : 'Extension rejected.');
    }
}
