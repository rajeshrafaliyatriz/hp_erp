<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The queryable audit trail, fed by TaskAuditService on every task write.
 * Backs the Administration > Audit Logs screen and the CSV export.
 */
class AuditLogController extends Controller
{
    use ResolvesTaskContext;

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'task_id' => 'nullable|integer',
            'actor_id' => 'nullable|integer',
            'event' => 'nullable|string|max:50',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $logs = $this->query($request, $context)
            ->paginate((int) $request->input('per_page', 50));

        return $this->ok('Audit logs retrieved successfully.', [
            'logs' => collect($logs->items())->map(fn ($row) => $this->resource($row))->all(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /** Same filters as index, streamed as CSV so exports do not buffer in memory. */
    public function export(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->query($request, $context)->limit(10000)->get();

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'task_id', 'task_title', 'event', 'actor', 'before', 'created_at']);

            foreach ($rows as $row) {
                $line = $this->resource($row);
                fputcsv($out, array_map([$this, 'csvSafe'], [
                    $line['id'], $line['task_id'], $line['task_title'], $line['event'],
                    $line['actor'], json_encode($line['before']), $line['created_at'],
                ]));
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="task-audit-logs.csv"',
        ]);
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * A task titled `=HYPERLINK("http://evil","x")` is stored verbatim (which
     * is correct - it is just text), but Excel and Sheets execute any cell
     * whose value starts with = + - or @ when the CSV is opened. Prefixing a
     * single quote makes the cell literal text; the quote is not displayed.
     */
    private function csvSafe($value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@", $value[0])
            ? "'" . $value
            : $value;
    }

    private function query(Request $request, array $context)
    {
        $query = DB::table('task_management_audit_logs as a')
            ->leftJoin('task as t', 't.id', '=', 'a.task_id')
            ->leftJoin('tbluser as actor', 'actor.id', '=', 'a.actor_id')
            ->where('a.sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('a.id')
            ->selectRaw("a.id, a.task_id, a.event, a.actor_id, a.before, a.created_at,
                t.task_title,
                TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) as actor_name");

        if ($request->filled('task_id')) {
            $query->where('a.task_id', $request->integer('task_id'));
        }
        if ($request->filled('actor_id')) {
            $query->where('a.actor_id', $request->integer('actor_id'));
        }
        if ($request->filled('event')) {
            $query->where('a.event', $request->input('event'));
        }
        if ($request->filled('from')) {
            $query->whereDate('a.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('a.created_at', '<=', $request->input('to'));
        }

        return $query;
    }

    private function resource(object $row): array
    {
        $before = $row->before ? json_decode((string) $row->before, true) : null;

        return [
            'id' => (string) $row->id,
            'task_id' => (string) $row->task_id,
            // Configuration events carry no task, so their subject stands in
            // for the title rather than rendering as "Task #0".
            'task_title' => $row->task_title ?? ($before['subject'] ?? null),
            'event' => (string) $row->event,
            'actor_id' => $row->actor_id ? (string) $row->actor_id : null,
            'actor' => $row->actor_name ?: null,
            'before' => $before,
            'created_at' => $row->created_at,
        ];
    }
}
