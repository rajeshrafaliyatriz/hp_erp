<?php

namespace App\Services\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\NotificationController;
use App\Support\SubjectAuthority;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deadline-extension requests: who may see one, who may decide it, how it is
 * decided, and the filtered queue the Task Dashboard's Extensions tab reads.
 *
 * Written once here because three doors lead to the same rules - the task's own
 * panel, the queue, and the bulk bar - and a rule that lives in a controller
 * method is a rule one of the other two forgets.
 *
 * WHO SEES WHAT
 *   TASK_PRIVILEGED  every request in the organisation
 *   a task's owner   the requests on tasks they own
 *   anyone else      their own requests (and requests on tasks assigned to them)
 *
 * WHO DECIDES
 *   TASK_PRIVILEGED, or the task's owner. The owner is who the request is
 *   addressed to and who is notified, and it is whom the task panel's buttons
 *   were always shown to - the route used to demand an elevated role as well, so
 *   an owner on the Employee profile saw buttons that answered 403.
 */
class DeadlineExtensionService
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn'];

    /** Rows one bulk call may decide, so a slip cannot approve a whole queue. */
    public const BULK_LIMIT = 50;

    public function __construct(private readonly TaskAuditService $audit)
    {
    }

    public function isPrivileged(int $userId): bool
    {
        return SubjectAuthority::userSatisfies($userId, SubjectAuthority::TASK_PRIVILEGED);
    }

    public function canDecide(object $task, int $userId, bool $privileged): bool
    {
        return $privileged || (int) $task->task_allocated === $userId;
    }

    /** Restrict a query over `e` (extension) and `t` (task) to what this user may see. */
    public function limitToVisible(Builder $query, int $userId, bool $privileged): void
    {
        if ($privileged) {
            return;
        }

        $query->where(function (Builder $visible) use ($userId) {
            $visible->where('e.requested_by', $userId)
                ->orWhere('t.task_allocated', $userId)
                ->orWhere('t.task_allocated_to', $userId);
        });
    }

    /** The base query: extension joined to its task, the people on it, and the assignee's department. */
    public function base(array $context): Builder
    {
        return DB::table('task_deadline_extensions as e')
            ->join('task as t', 't.id', '=', 'e.task_id')
            ->leftJoin('tbluser as requester', 'requester.id', '=', 'e.requested_by')
            ->leftJoin('tbluser as decider', 'decider.id', '=', 'e.decided_by')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->leftJoin('hrms_departments as dept', 'dept.id', '=', 'assignee.department_id')
            ->where('e.sub_institute_id', $context['sub_institute_id'])
            ->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at');
    }

    /* ── Writing ─────────────────────────────────────────────────────── */

    /**
     * Decide one pending request. The status change is guarded on `status =
     * 'pending'`, so two people deciding at once cannot both win: the second
     * finds nothing to update and is told so.
     *
     * @return array{ok:bool, code:int, message:string}
     */
    public function decide(array $context, object $extension, object $task, string $decision, ?string $remarks): array
    {
        $approving = $decision === 'approve';
        $remarks = $remarks !== null ? trim($remarks) : null;

        if (!$approving && ($remarks === null || $remarks === '')) {
            return ['ok' => false, 'code' => 422, 'message' => 'Say why the request is declined: the requester is owed a reason.'];
        }

        $applied = DB::transaction(function () use ($context, $extension, $task, $approving, $remarks) {
            $changed = DB::table('task_deadline_extensions')
                ->where('id', $extension->id)
                ->where('status', 'pending')
                ->update([
                    'status' => $approving ? 'approved' : 'rejected',
                    'decided_by' => $context['user_id'],
                    'decided_on' => now(),
                    'decision_remarks' => $remarks !== '' ? $remarks : null,
                    'updated_at' => now(),
                ]);

            if ($changed === 0) {
                return false;
            }

            if ($approving) {
                DB::table('task')->where('id', $extension->task_id)->update([
                    'task_date' => $extension->requested_date,
                    'updated_by' => $context['user_id'],
                    'updated_at' => now(),
                ]);
            }

            return true;
        });

        if (!$applied) {
            return ['ok' => false, 'code' => 409, 'message' => 'This request has already been decided.'];
        }

        $this->audit->taskChanged(
            (int) $extension->task_id,
            $approving ? 'deadline_extended' : 'deadline_extension_rejected',
            array_merge((array) $task, ['detail' => $this->describe($extension, $remarks)]),
            $context['user_id'],
            $approving ? ['task_date' => $extension->requested_date] : [],
        );

        NotificationController::notify(
            $context['sub_institute_id'],
            (int) $extension->requested_by,
            $approving ? 'deadline_extension_approved' : 'deadline_extension_rejected',
            'Deadline extension ' . ($approving ? 'approved' : 'rejected') . ': ' . $task->task_title,
            $remarks,
            (int) $extension->task_id
        );

        return [
            'ok' => true,
            'code' => 200,
            'message' => $approving ? 'Extension approved and due date updated.' : 'Extension rejected.',
        ];
    }

    /**
     * The requester takes a pending request back. Only while it is pending: once
     * decided it is history, and a changed mind is a new request.
     *
     * @return array{ok:bool, code:int, message:string}
     */
    public function withdraw(array $context, object $extension, object $task, ?string $remarks): array
    {
        $remarks = $remarks !== null ? trim($remarks) : null;

        $changed = DB::table('task_deadline_extensions')
            ->where('id', $extension->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'withdrawn',
                'decided_by' => $context['user_id'],
                'decided_on' => now(),
                'decision_remarks' => $remarks !== '' ? $remarks : null,
                'updated_at' => now(),
            ]);

        if ($changed === 0) {
            return ['ok' => false, 'code' => 409, 'message' => 'Only a pending request can be withdrawn.'];
        }

        $this->audit->taskChanged(
            (int) $extension->task_id,
            'deadline_extension_withdrawn',
            array_merge((array) $task, ['detail' => $this->describe($extension, $remarks)]),
            $context['user_id'],
        );

        NotificationController::notify(
            $context['sub_institute_id'],
            (int) ($task->task_allocated ?: $task->created_by),
            'deadline_extension_withdrawn',
            'Deadline extension withdrawn: ' . $task->task_title,
            $remarks,
            (int) $extension->task_id
        );

        return ['ok' => true, 'code' => 200, 'message' => 'Request withdrawn.'];
    }

    /**
     * Decide many at once, each on its own merits. Every row is authorised,
     * applied and reported separately - a refused row never stops the rest, and
     * the count the caller shows is the count that actually changed.
     *
     * @param  int[]  $ids
     * @return array<int, array{id:string, ok:bool, reason:?string}>
     */
    public function decideMany(array $context, array $ids, string $decision, ?string $remarks): array
    {
        $privileged = $this->isPrivileged($context['user_id']);
        $results = [];

        foreach (array_slice(array_values(array_unique($ids)), 0, self::BULK_LIMIT) as $id) {
            $row = $this->base($context)
                ->where('e.id', $id)
                ->first(['e.*', 't.task_title', 't.task_allocated', 't.task_allocated_to', 't.created_by', 't.status as task_status', 't.task_date', 't.sub_institute_id as task_sub_institute_id']);

            if (!$row) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'Request not found.'];
                continue;
            }
            if (!$this->canDecide($row, $context['user_id'], $privileged)) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => 'You cannot decide this request.'];
                continue;
            }

            $task = (object) [
                'id' => $row->task_id, 'task_title' => $row->task_title, 'task_allocated' => $row->task_allocated,
                'task_allocated_to' => $row->task_allocated_to, 'created_by' => $row->created_by,
                'status' => $row->task_status, 'task_date' => $row->task_date, 'sub_institute_id' => $row->task_sub_institute_id,
            ];
            $outcome = $this->decide($context, $row, $task, $decision, $remarks);
            $results[] = ['id' => (string) $id, 'ok' => $outcome['ok'], 'reason' => $outcome['ok'] ? null : $outcome['message']];
        }

        return $results;
    }

    private function describe(object $extension, ?string $remarks): string
    {
        $text = 'Due ' . ($extension->original_date ?? '?') . ' → requested ' . $extension->requested_date;

        return $remarks ? $text . ' - ' . mb_substr($remarks, 0, 200) : $text;
    }

    /* ── Reading ─────────────────────────────────────────────────────── */

    public function queueQuery(Request $request, array $context, bool $privileged): Builder
    {
        $userId = $context['user_id'];
        $query = $this->base($context);
        $this->limitToVisible($query, $userId, $privileged);

        switch ($request->input('view')) {
            case 'awaiting_me':
                $query->where('e.status', 'pending');
                if (!$privileged) {
                    $query->where('t.task_allocated', $userId);
                }
                break;
            case 'mine':
                $query->where('e.requested_by', $userId);
                break;
            case 'decided':
                $query->whereIn('e.status', ['approved', 'rejected', 'withdrawn']);
                break;
        }

        $statuses = array_values(array_intersect(self::STATUSES, $this->list($request, 'status')));
        if ($statuses) {
            $query->whereIn('e.status', $statuses);
        }
        if ($request->filled('requester_id')) {
            $query->where('e.requested_by', $request->integer('requester_id'));
        }
        if ($request->filled('decided_by')) {
            $query->where('e.decided_by', $request->integer('decided_by'));
        }
        if ($request->filled('department_id')) {
            $query->where('assignee.department_id', $request->integer('department_id'));
        }
        if ($request->filled('project_id')) {
            $projectId = $request->integer('project_id');
            $query->whereIn('e.task_id', fn ($sub) => $sub->select('task_id')->from('task_management_project_tasks')->where('project_id', $projectId));
        }
        if ($request->filled('priority')) {
            $query->where('t.task_type', $request->input('priority'));
        }
        if ($request->filled('requested_from')) {
            $query->whereDate('e.created_at', '>=', $request->input('requested_from'));
        }
        if ($request->filled('requested_to')) {
            $query->whereDate('e.created_at', '<=', $request->input('requested_to'));
        }
        if ($request->filled('decided_from')) {
            $query->whereDate('e.decided_on', '>=', $request->input('decided_from'));
        }
        if ($request->filled('decided_to')) {
            $query->whereDate('e.decided_on', '<=', $request->input('decided_to'));
        }
        if ($request->filled('min_days')) {
            $query->where('e.days_requested', '>=', $request->integer('min_days'));
        }
        if ($request->filled('max_days')) {
            $query->where('e.days_requested', '<=', $request->integer('max_days'));
        }
        if ($request->boolean('overdue_at_request')) {
            // Already past its date on the day they asked. Rows from before the
            // original date was kept have no date to compare, so they never match.
            $query->whereNotNull('e.original_date')->whereRaw('e.original_date < DATE(e.created_at)');
        }
        if ($request->boolean('repeat')) {
            $query->whereRaw($this->nthSql() . ' >= 2');
        }
        if ($request->filled('q')) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->input('q'))) . '%';
            $query->where(function (Builder $search) use ($like) {
                $search->where('e.reason', 'like', $like)
                    ->orWhere('e.decision_remarks', 'like', $like)
                    ->orWhere('t.task_title', 'like', $like);
            });
        }

        $this->sort($query, (string) $request->input('sort', 'default'));

        return $query;
    }

    /** How many requests this task has had up to and including this one (withdrawn ones do not count). */
    private function nthSql(): string
    {
        return "(SELECT COUNT(*) FROM task_deadline_extensions x WHERE x.task_id = e.task_id AND x.id <= e.id AND x.status <> 'withdrawn')";
    }

    private function sort(Builder $query, string $sort): void
    {
        switch ($sort) {
            case 'newest':
                $query->orderByDesc('e.id');
                break;
            case 'oldest':
                $query->orderBy('e.id');
                break;
            case 'days_desc':
                $query->orderByRaw('e.days_requested IS NULL')->orderByDesc('e.days_requested')->orderByDesc('e.id');
                break;
            case 'due_asc':
                $query->orderBy('e.requested_date')->orderBy('e.id');
                break;
            default:
                // Pending first, the one waiting longest on top; then what was
                // decided most recently.
                $query->orderByRaw("CASE WHEN e.status = 'pending' THEN 0 ELSE 1 END")
                    ->orderByRaw("CASE WHEN e.status = 'pending' THEN e.created_at END ASC")
                    ->orderByDesc('e.decided_on')
                    ->orderByDesc('e.id');
        }
    }

    /** The columns the queue and its export read. */
    public function select(Builder $query): Builder
    {
        return $query->selectRaw(
            "e.id, e.task_id, e.requested_by, e.requested_date, e.original_date, e.days_requested, e.reason, e.status,
             e.decided_by, e.decided_on, e.decision_remarks, e.created_at,
             t.task_title, t.task_date, t.task_type, t.task_allocated, t.task_allocated_to,
             requester.first_name as rq_first, requester.middle_name as rq_middle, requester.last_name as rq_last,
             decider.first_name as dc_first, decider.middle_name as dc_middle, decider.last_name as dc_last,
             assignee.first_name as as_first, assignee.middle_name as as_middle, assignee.last_name as as_last,
             dept.department as department_name,
             {$this->nthSql()} as nth"
        );
    }

    /** @return array<string, mixed> */
    public function resource(object $row, int $userId, bool $privileged): array
    {
        $created = $row->created_at ? Carbon::parse($row->created_at) : null;
        $pending = $row->status === 'pending';
        // Until a request is approved the task still has the date it was asked
        // about, so a row from before original_date was kept can borrow it.
        $original = $row->original_date ?? ($row->status !== 'approved' ? $row->task_date : null);
        $days = $row->days_requested;
        if ($days === null && $original && $row->requested_date) {
            $days = Carbon::parse($original)->diffInDays(Carbon::parse($row->requested_date), false);
        }

        return [
            'id' => (string) $row->id,
            'task_id' => (string) $row->task_id,
            'task_title' => (string) $row->task_title,
            'priority' => $row->task_type ?: null,
            'status' => (string) $row->status,
            'reason' => $row->reason,
            'requested_by_id' => $row->requested_by ? (string) $row->requested_by : null,
            'requested_by' => $this->name($row->rq_first, $row->rq_middle, $row->rq_last),
            'assignee_id' => $row->task_allocated_to ? (string) $row->task_allocated_to : null,
            'assignee' => $this->name($row->as_first, $row->as_middle, $row->as_last),
            'department' => $row->department_name ?: null,
            'original_date' => $original ? Carbon::parse($original)->toDateString() : null,
            'current_due_date' => $row->task_date ? Carbon::parse($row->task_date)->toDateString() : null,
            'requested_date' => $row->requested_date ? Carbon::parse($row->requested_date)->toDateString() : null,
            'days_requested' => $days !== null ? (int) $days : null,
            'overdue_at_request' => $row->original_date && $created ? Carbon::parse($row->original_date)->lt($created->copy()->startOfDay()) : false,
            'nth' => (int) $row->nth,
            'age_days' => $pending && $created ? (int) $created->diffInDays(now()) : null,
            'decided_by_id' => $row->decided_by ? (string) $row->decided_by : null,
            'decided_by' => $this->name($row->dc_first, $row->dc_middle, $row->dc_last),
            'decided_on' => $row->decided_on,
            'decision_remarks' => $row->decision_remarks,
            'created_at' => $row->created_at,
            'can_decide' => $pending && ($privileged || (int) $row->task_allocated === $userId),
            'can_withdraw' => $pending && (int) $row->requested_by === $userId,
        ];
    }

    /**
     * Headline numbers for the visible set - not narrowed by the filters, so the
     * tiles and tab counts do not shift while someone is searching.
     *
     * Average, not median, decision time: a median wants window functions, which
     * the live database (MariaDB 10.1) does not have.
     *
     * @return array<string, mixed>
     */
    public function summary(array $context, bool $privileged): array
    {
        $userId = $context['user_id'];
        $scope = fn (): Builder => tap($this->base($context), fn (Builder $q) => $this->limitToVisible($q, $userId, $privileged));

        // Every count in ONE pass. The database is remote and each round trip costs
        // about 300 ms, so ten separate COUNT queries would put three seconds on
        // every load of the queue.
        $row = $scope()->selectRaw(
            "SUM(CASE WHEN e.status = 'pending' THEN 1 ELSE 0 END) as pending,
             MIN(CASE WHEN e.status = 'pending' THEN e.created_at END) as oldest,
             SUM(CASE WHEN e.status = 'approved' THEN 1 ELSE 0 END) as approved,
             SUM(CASE WHEN e.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
             SUM(CASE WHEN e.status IN ('approved', 'rejected', 'withdrawn') THEN 1 ELSE 0 END) as decided,
             SUM(CASE WHEN e.requested_by = ? THEN 1 ELSE 0 END) as mine,
             SUM(CASE WHEN e.status = 'pending' AND (? = 1 OR t.task_allocated = ?) THEN 1 ELSE 0 END) as awaiting,
             COUNT(*) as total",
            [$userId, $privileged ? 1 : 0, $userId]
        )->first();

        $approved = (int) ($row->approved ?? 0);
        $rejected = (int) ($row->rejected ?? 0);

        $hours = $scope()->whereIn('e.status', ['approved', 'rejected'])->whereNotNull('e.decided_on')
            ->orderByDesc('e.id')->limit(500)->get(['e.created_at', 'e.decided_on'])
            ->map(fn ($r) => Carbon::parse($r->created_at)->diffInMinutes(Carbon::parse($r->decided_on)) / 60);

        return [
            'pending' => (int) ($row->pending ?? 0),
            'oldest_pending_days' => $row->oldest ? (int) Carbon::parse($row->oldest)->diffInDays(now()) : null,
            'approved' => $approved,
            'rejected' => $rejected,
            // Decided requests only; withdrawn ones are neither a yes nor a no.
            'approval_rate' => ($approved + $rejected) > 0 ? round($approved / ($approved + $rejected), 3) : null,
            'avg_decision_hours' => $hours->isNotEmpty() ? round($hours->avg(), 1) : null,
            'repeat_extenders' => $scope()->where('e.status', '<>', 'withdrawn')->select('e.task_id')->groupBy('e.task_id')->havingRaw('COUNT(*) >= 2')->get()->count(),
            'counts' => [
                'awaiting_me' => (int) ($row->awaiting ?? 0),
                'mine' => (int) ($row->mine ?? 0),
                'all' => (int) ($row->total ?? 0),
                'decided' => (int) ($row->decided ?? 0),
            ],
        ];
    }

    /**
     * The values the filter dropdowns can offer - only people, departments and
     * priorities that actually appear in what this viewer can see, so a manager
     * is never offered a department with no requests. Asked for once, on the
     * first load, not on every page turn.
     *
     * @return array<string, array<int, mixed>>
     */
    public function filterOptions(array $context, bool $privileged): array
    {
        $userId = $context['user_id'];
        $scope = fn (): Builder => tap($this->base($context), fn (Builder $q) => $this->limitToVisible($q, $userId, $privileged));
        $person = fn ($r) => ['id' => (string) $r->id, 'name' => $this->name($r->f, $r->m, $r->l) ?? ('User ' . $r->id)];

        return [
            'requesters' => $scope()->selectRaw('e.requested_by as id, requester.first_name as f, requester.middle_name as m, requester.last_name as l')
                ->groupBy('e.requested_by', 'requester.first_name', 'requester.middle_name', 'requester.last_name')->limit(300)->get()->map($person)->all(),
            'deciders' => $scope()->whereNotNull('e.decided_by')->selectRaw('e.decided_by as id, decider.first_name as f, decider.middle_name as m, decider.last_name as l')
                ->groupBy('e.decided_by', 'decider.first_name', 'decider.middle_name', 'decider.last_name')->limit(300)->get()->map($person)->all(),
            'departments' => $scope()->whereNotNull('assignee.department_id')->selectRaw('assignee.department_id as id, dept.department as name')
                ->groupBy('assignee.department_id', 'dept.department')->limit(300)->get()
                ->map(fn ($r) => ['id' => (string) $r->id, 'name' => (string) ($r->name ?: ('Department ' . $r->id))])->all(),
            'priorities' => $scope()->whereNotNull('t.task_type')->where('t.task_type', '<>', '')->distinct()->pluck('t.task_type')->map(fn ($v) => (string) $v)->all(),
        ];
    }

    /** Pending requests this user can decide - the badge on the Dashboard's Extensions tab. */
    public function awaitingCount(array $context): int
    {
        $privileged = $this->isPrivileged($context['user_id']);
        $query = $this->base($context)->where('e.status', 'pending');
        if (!$privileged) {
            $query->where('t.task_allocated', $context['user_id']);
        }

        return $query->count();
    }

    private function name(?string $first, ?string $middle, ?string $last): ?string
    {
        $name = trim(implode(' ', array_filter([$first, $middle, $last], fn ($part) => $part !== null && $part !== '')));

        return $name !== '' ? $name : null;
    }

    /** @return string[] */
    private function list(Request $request, string $key): array
    {
        $value = $request->input($key);
        $parts = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }
}
