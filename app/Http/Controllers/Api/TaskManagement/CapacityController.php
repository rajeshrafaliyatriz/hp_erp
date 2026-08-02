<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Pre-assignment capacity check: how loaded is each proposed assignee before
 * the task is created, so over-allocation is a warning at assignment time
 * rather than a discovery at the deadline.
 */
class CapacityController extends Controller
{
    use ResolvesTaskContext;

    /** Open tasks above which an assignee is flagged, unless the caller overrides. */
    private const DEFAULT_THRESHOLD = 10;

    public function check(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'assignee_ids' => 'required|array|min:1|max:100',
            'assignee_ids.*' => 'integer',
            'threshold' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $threshold = (int) $request->input('threshold', self::DEFAULT_THRESHOLD);
        $ids = array_map('intval', $request->input('assignee_ids'));

        // One aggregate query for the lot, not one per assignee.
        $counts = DB::table('task')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('SYEAR', $context['syear'])
            ->whereNull('deleted_at')
            ->whereIn('task_allocated_to', $ids)
            ->whereRaw("UPPER(COALESCE(status, 'PENDING')) <> 'COMPLETED'")
            ->selectRaw('task_allocated_to as assignee_id, COUNT(*) as open_tasks')
            ->groupBy('task_allocated_to')
            ->pluck('open_tasks', 'assignee_id');

        $names = DB::table('tbluser')
            ->whereIn('id', $ids)
            ->selectRaw("id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name")
            ->pluck('name', 'id');

        $result = array_map(function (int $id) use ($counts, $names, $threshold) {
            $open = (int) ($counts[$id] ?? 0);

            return [
                'assignee_id' => (string) $id,
                'name' => (string) ($names[$id] ?? ''),
                'open_tasks' => $open,
                'threshold' => $threshold,
                'over_capacity' => $open >= $threshold,
            ];
        }, $ids);

        return $this->ok('Capacity checked.', ['assignees' => $result]);
    }
}
