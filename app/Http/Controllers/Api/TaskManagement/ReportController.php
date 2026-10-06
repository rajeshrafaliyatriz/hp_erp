<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Management reports over the task table. Everything here is a single
 * aggregate query per report - the database is remote, so per-user or
 * per-category loops would multiply ~300ms round trips.
 */
class ReportController extends Controller
{
    use ResolvesTaskContext;

    /** Per-assignee throughput: open vs completed, and overdue right now. */
    public function productivity(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $today = Carbon::today()->toDateString();

        $rows = DB::table('task as t')
            ->join('tbluser as u', 'u.id', '=', 't.task_allocated_to')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.SYEAR', $context['syear'])
            ->whereNull('t.deleted_at')
            ->groupBy('t.task_allocated_to', 'u.first_name', 'u.middle_name', 'u.last_name')
            ->selectRaw("t.task_allocated_to as user_id,
                TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as name,
                COUNT(*) as total,
                SUM(CASE WHEN UPPER(COALESCE(t.status,'PENDING')) = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN t.task_date < ? AND UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED' THEN 1 ELSE 0 END) as overdue",
                [$today])
            ->orderByDesc('total')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (string) $row->user_id,
                'name' => (string) $row->name,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'open' => (int) $row->open,
                'overdue' => (int) $row->overdue,
                'completion_rate' => $row->total > 0 ? round($row->completed / $row->total * 100, 1) : 0.0,
            ]);

        return $this->ok('Productivity report.', ['rows' => $rows->all()]);
    }

    /** Why work stalls: ON HOLD grouped by delay category, plus the overdue list. */
    public function delays(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $today = Carbon::today()->toDateString();

        $byCategory = DB::table('task')
            ->where('sub_institute_id', $sid)
            ->where('SYEAR', $context['syear'])
            ->whereNull('deleted_at')
            ->whereRaw("UPPER(COALESCE(status,'')) = 'ON HOLD'")
            ->selectRaw("COALESCE(delay_category, 'Uncategorised') as category, COUNT(*) as total")
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category' => (string) $row->category, 'total' => (int) $row->total]);

        $overdue = DB::table('task as t')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->where('t.sub_institute_id', $sid)
            ->where('t.SYEAR', $context['syear'])
            ->whereNull('t.deleted_at')
            ->where('t.task_date', '<', $today)
            ->whereRaw("UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED'")
            ->orderBy('t.task_date')
            ->limit(100)
            ->selectRaw("t.id, t.task_title, t.task_date, t.status, t.delay_category,
                TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee")
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->task_title,
                'due_date' => $row->task_date,
                'status' => $row->status,
                'delay_category' => $row->delay_category,
                'assignee' => $row->assignee ?: 'Unassigned',
                'days_overdue' => Carbon::parse($row->task_date)->diffInDays(Carbon::today()),
            ]);

        return $this->ok('Delay report.', [
            'by_category' => $byCategory->all(),
            'overdue' => $overdue->all(),
        ]);
    }
}
