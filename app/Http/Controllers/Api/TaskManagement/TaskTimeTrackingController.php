<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Start/stop time tracking against a task. One open timer per user per task;
 * stopping writes the duration and rolls it up into task.actual_hours.
 */
class TaskTimeTrackingController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly TaskAuditService $taskAudit)
    {
    }

    public function index(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('task_management_time_entries as e')
            ->leftJoin('tbluser as u', 'u.id', '=', 'e.user_id')
            ->where('e.task_id', $id)
            ->where('e.sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('e.id')
            ->limit(100)
            ->selectRaw("e.id, e.user_id, e.started_at, e.ended_at, e.minutes,
                TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as user_name")
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'user' => $row->user_name ?: null,
                'started_at' => $row->started_at,
                'ended_at' => $row->ended_at,
                'minutes' => $row->minutes !== null ? (int) $row->minutes : null,
                'running' => $row->ended_at === null,
            ]);

        return $this->ok('Time entries retrieved successfully.', [
            'entries' => $rows->all(),
            'total_minutes' => (int) $rows->sum(fn ($row) => $row['minutes'] ?? 0),
        ]);
    }

    public function start(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $taskExists = DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        if (!$taskExists) {
            return $this->fail('Task not found.', 404);
        }

        $open = DB::table('task_management_time_entries')
            ->where('task_id', $id)
            ->where('user_id', $context['user_id'])
            ->whereNull('ended_at')
            ->exists();

        if ($open) {
            return $this->fail('A timer is already running on this task.', 409);
        }

        $entryId = DB::table('task_management_time_entries')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $id,
            'user_id' => $context['user_id'],
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, 'timer_started', [
            'sub_institute_id' => $context['sub_institute_id'],
        ], $context['user_id']);

        return $this->ok('Timer started.', ['id' => (string) $entryId], 201);
    }

    public function stop(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $entry = DB::table('task_management_time_entries')
            ->where('task_id', $id)
            ->where('user_id', $context['user_id'])
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        if (!$entry) {
            return $this->fail('No running timer on this task.', 404);
        }

        $minutes = max(1, Carbon::parse($entry->started_at)->diffInMinutes(now()));

        DB::table('task_management_time_entries')->where('id', $entry->id)->update([
            'ended_at' => now(),
            'minutes' => $minutes,
        ]);

        // Roll the actuals up onto the task so reports read one column
        // instead of summing entries on every request.
        DB::table('task')->where('id', $id)->update([
            'actual_hours' => DB::raw("COALESCE(actual_hours, 0) + " . round($minutes / 60, 2)),
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, 'timer_stopped', [
            'sub_institute_id' => $context['sub_institute_id'],
            'detail' => $minutes . ' minutes logged',
        ], $context['user_id']);

        return $this->ok('Timer stopped.', ['id' => (string) $entry->id, 'minutes' => $minutes]);
    }
}
