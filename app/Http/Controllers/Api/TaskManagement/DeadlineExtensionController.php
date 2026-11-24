<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\DeadlineExtensionService;
use App\Services\TaskManagement\TaskAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Deadline extension requests: the assignee asks for a new due date, the task's
 * owner (or an administrator) approves or declines; approval moves
 * task.task_date.
 *
 * The old frontend had this whole flow (request modal, approve/reject
 * buttons) posting to /api/deadline-extension - an endpoint that never
 * existed in this repository, so the flow was wired to nothing. This is
 * its backend.
 *
 * The rules - who sees a request, who decides it - live in
 * DeadlineExtensionService, so the task panel, the queue and the bulk bar
 * cannot disagree about them.
 */
class DeadlineExtensionController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly DeadlineExtensionService $extensions)
    {
    }

    /**
     * One task's requests, for its detail panel.
     *
     * `task_id` is REQUIRED. This used to list the whole organisation's requests
     * - reasons included - to any logged-in user who left it out, capped at 200
     * rows. The organisation-wide list is the queue below, and it is scoped by
     * role.
     */
    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        if (!$request->filled('task_id')) {
            return $this->fail('task_id is required. The organisation-wide list is /deadline-extensions/queue.', 422);
        }

        $userId = $context['user_id'];
        $privileged = $this->extensions->isPrivileged($userId);

        $query = $this->extensions->base($context)->where('e.task_id', $request->integer('task_id'));
        $this->extensions->limitToVisible($query, $userId, $privileged);

        $rows = $this->extensions->select($query)->orderByDesc('e.id')->limit(200)->get()
            ->map(fn ($row) => $this->extensions->resource($row, $userId, $privileged));

        return $this->ok('Deadline extensions retrieved successfully.', ['extensions' => $rows->all()]);
    }

    /** The organisation-wide queue: paginated, filtered, scoped by role, with its headline numbers. */
    public function queue(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = $this->queueValidator($request);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $userId = $context['user_id'];
        $privileged = $this->extensions->isPrivileged($userId);

        $query = $this->extensions->select($this->extensions->queueQuery($request, $context, $privileged));
        $page = $query->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return $this->ok('Deadline extension requests retrieved successfully.', [
            'requests' => collect($page->items())->map(fn ($row) => $this->extensions->resource($row, $userId, $privileged))->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => $this->extensions->summary($context, $privileged),
            // Which scope the viewer got, so the screen can say so.
            'scope' => $privileged ? 'organisation' : 'own',
        ]);
    }

    /** The same rows the queue shows, as CSV. Capped, and neutralised against spreadsheet formulas. */
    public function export(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = $this->queueValidator($request);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $userId = $context['user_id'];
        $privileged = $this->extensions->isPrivileged($userId);
        $rows = $this->extensions->select($this->extensions->queueQuery($request, $context, $privileged))
            ->limit(5000)->get()
            ->map(fn ($row) => $this->extensions->resource($row, $userId, $privileged));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Request', 'Task', 'Requester', 'Department', 'Priority', 'Original due', 'Current due', 'Requested due',
                'Days', 'Extension no.', 'Status', 'Reason', 'Decided by', 'Decided on', 'Decision remarks', 'Submitted',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'csvSafe'], [
                    $row['id'], $row['task_title'], $row['requested_by'], $row['department'], $row['priority'],
                    $row['original_date'], $row['current_due_date'], $row['requested_date'],
                    $row['days_requested'], $row['nth'], $row['status'], $row['reason'],
                    $row['decided_by'], $row['decided_on'], $row['decision_remarks'], $row['created_at'],
                ]));
            }
            fclose($out);
        }, 'deadline-extension-requests-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The assignee asks for more time. */
    public function store(Request $request, TaskAuditService $audit)
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

        // Only the person doing the work asks for more time to do it.
        if ((int) $task->task_allocated_to !== $context['user_id']) {
            return $this->fail('Only the person this task is assigned to can ask for more time.', 403);
        }

        if (strtoupper((string) $task->status) === 'COMPLETED') {
            return $this->fail('This task is already finished, so there is nothing to extend.', 422);
        }

        // An extension has to be later than the date it replaces, not only later than today.
        $current = $task->task_date ? Carbon::parse($task->task_date)->startOfDay() : null;
        $requested = Carbon::parse($request->input('requested_date'))->startOfDay();
        if ($current && $requested->lte($current)) {
            return $this->fail('The new date has to be later than the current due date (' . $current->toDateString() . ').', 422);
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
            'requested_date' => $requested->toDateString(),
            // The date the task has NOW, kept because approval overwrites it.
            'original_date' => $current?->toDateString(),
            'days_requested' => $current ? (int) $current->diffInDays($requested) : null,
            'reason' => $request->input('reason'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit->taskChanged(
            (int) $task->id,
            'deadline_extension_requested',
            array_merge((array) $task, ['detail' => 'Due ' . ($current?->toDateString() ?? '?') . ' → requested ' . $requested->toDateString()]),
            $context['user_id']
        );

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

    /** The task's owner, or an administrator, decides. Approval moves the task's due date; declining needs a reason. */
    public function decide(Request $request, int $id)
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

        [$extension, $task] = $this->load($context, $id);
        if (!$extension) {
            return $this->fail('Extension request not found.', 404);
        }

        if (!$this->extensions->canDecide($task, $context['user_id'], $this->extensions->isPrivileged($context['user_id']))) {
            return $this->fail('Only the task\'s owner or an administrator can decide this request.', 403);
        }

        $outcome = $this->extensions->decide($context, $extension, $task, (string) $request->input('decision'), $request->input('remarks'));

        return $outcome['ok'] ? $this->ok($outcome['message']) : $this->fail($outcome['message'], $outcome['code']);
    }

    /** Several at once; every row is authorised and reported on its own. */
    public function bulkDecide(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:' . DeadlineExtensionService::BULK_LIMIT,
            'ids.*' => 'integer',
            'decision' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $decision = (string) $request->input('decision');
        if ($decision === 'reject' && trim((string) $request->input('remarks')) === '') {
            return $this->fail('Say why the requests are declined: the requesters are owed a reason.', 422);
        }

        $results = $this->extensions->decideMany($context, array_map('intval', $request->input('ids')), $decision, $request->input('remarks'));
        $succeeded = count(array_filter($results, fn ($row) => $row['ok']));

        return $this->ok(
            $succeeded . ' of ' . count($results) . ' request' . (count($results) === 1 ? '' : 's') . ' ' . ($decision === 'approve' ? 'approved' : 'declined') . '.',
            ['results' => $results, 'succeeded' => $succeeded, 'failed' => count($results) - $succeeded]
        );
    }

    /** The requester takes a pending request back. */
    public function withdraw(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        [$extension, $task] = $this->load($context, $id);
        if (!$extension) {
            return $this->fail('Extension request not found.', 404);
        }

        if ((int) $extension->requested_by !== $context['user_id']) {
            return $this->fail('Only the person who made the request can withdraw it.', 403);
        }

        $outcome = $this->extensions->withdraw($context, $extension, $task, $request->input('remarks'));

        return $outcome['ok'] ? $this->ok($outcome['message']) : $this->fail($outcome['message'], $outcome['code']);
    }

    /** @return array{0: ?object, 1: ?object} the request and its task, both inside the caller's organisation. */
    private function load(array $context, int $id): array
    {
        $extension = DB::table('task_deadline_extensions')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        $task = $extension
            ? DB::table('task')->where('id', $extension->task_id)->where('sub_institute_id', $context['sub_institute_id'])->first()
            : null;

        return $extension && $task ? [$extension, $task] : [null, null];
    }

    private function queueValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'view' => 'nullable|in:awaiting_me,mine,all,decided',
            'requested_from' => 'nullable|date',
            'requested_to' => 'nullable|date|after_or_equal:requested_from',
            'decided_from' => 'nullable|date',
            'decided_to' => 'nullable|date|after_or_equal:decided_from',
            'min_days' => 'nullable|integer',
            'max_days' => 'nullable|integer',
            'sort' => 'nullable|in:default,newest,oldest,days_desc,due_asc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    /**
     * A reason or a title starting with = + - or @ is executed by Excel and Sheets
     * when the CSV is opened. A leading quote makes the cell literal text.
     */
    private function csvSafe($value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains('=+-@', $value[0]) ? "'" . $value : $value;
    }
}
