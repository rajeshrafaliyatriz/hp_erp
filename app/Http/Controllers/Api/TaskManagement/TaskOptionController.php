<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskOptionSetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD for a tenant's custom statuses and priorities.
 *
 * System entries are constants and cannot be edited or removed - they are the
 * workflow engine. Custom statuses are labels mapped onto a system category;
 * custom priorities are ordered names with an optional SLA. Delete is a
 * deactivation: tasks may still carry the label, so the row must survive for
 * display.
 */
class TaskOptionController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(
        private readonly TaskOptionSetService $options,
        private readonly TaskAuditService $taskAudit,
    )
    {
    }

    /* ---------------- statuses ---------------- */

    public function statuses(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return $this->ok('Statuses retrieved successfully.', [
            'statuses' => $this->options->statuses($context['sub_institute_id'], false),
            'categories' => TaskOptionSetService::CATEGORIES,
        ]);
    }

    public function storeStatus(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'category' => 'required|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
            'color' => 'nullable|string|max:30',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if ($this->statusNameTaken($context['sub_institute_id'], (string) $request->input('name'))) {
            return $this->fail('A status with this name already exists.', 409);
        }

        $id = DB::table('task_management_statuses')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'name' => trim((string) $request->input('name')),
            'category' => $request->input('category'),
            'color' => $request->input('color'),
            'sort_order' => (int) $request->input('sort_order', 50),
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskAudit->configChanged(
            $context['sub_institute_id'],
            'status_created',
            'Status "' . trim((string) $request->input('name')) . '"',
            $context['user_id'],
        );

        return $this->ok('Status created.', ['id' => (string) $id], 201);
    }

    public function updateStatus(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'category' => 'required|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
            'color' => 'nullable|string|max:30',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $row = DB::table('task_management_statuses')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        if (!$row) {
            return $this->fail('Status not found.', 404);
        }

        $newName = trim((string) $request->input('name'));

        if (strcasecmp($newName, $row->name) !== 0
            && $this->statusNameTaken($context['sub_institute_id'], $newName)) {
            return $this->fail('A status with this name already exists.', 409);
        }

        DB::table('task_management_statuses')->where('id', $id)->update([
            'name' => $newName,
            'category' => $request->input('category'),
            'color' => $request->input('color'),
            'sort_order' => (int) $request->input('sort_order', $row->sort_order),
            'active' => $request->boolean('active', (bool) $row->active),
            'updated_at' => now(),
        ]);

        // Tasks carrying the old label follow the rename, so their display
        // never points at a name that no longer exists.
        if ($newName !== $row->name) {
            DB::table('task')
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('status_label', $row->name)
                ->update(['status_label' => $newName]);
        }

        $this->taskAudit->configChanged(
            $context['sub_institute_id'],
            'status_updated',
            'Status "' . $row->name . '"' . ($newName !== $row->name ? ' renamed to "' . $newName . '"' : ''),
            $context['user_id'],
        );

        return $this->ok('Status updated.');
    }

    public function destroyStatus(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_statuses')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        $updated = $row
            ? DB::table('task_management_statuses')
                ->where('id', $id)
                ->update(['active' => false, 'updated_at' => now()])
            : 0;

        if ($updated) {
            $this->taskAudit->configChanged(
                $context['sub_institute_id'],
                'status_deactivated',
                'Status "' . $row->name . '"',
                $context['user_id'],
            );
        }

        return $updated
            ? $this->ok('Status deactivated. Tasks already using it keep their label.')
            : $this->fail('Status not found.', 404);
    }

    /* ---------------- priorities ---------------- */

    public function priorities(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return $this->ok('Priorities retrieved successfully.', [
            'priorities' => $this->options->priorities($context['sub_institute_id'], false),
        ]);
    }

    public function storePriority(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'sla_hours' => 'nullable|integer|min:1|max:8760',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if ($this->priorityNameTaken($context['sub_institute_id'], (string) $request->input('name'))) {
            return $this->fail('A priority with this name already exists.', 409);
        }

        $id = DB::table('task_management_priorities')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'name' => trim((string) $request->input('name')),
            'sort_order' => (int) $request->input('sort_order', 50),
            'sla_hours' => $request->input('sla_hours'),
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskAudit->configChanged(
            $context['sub_institute_id'],
            'priority_created',
            'Priority "' . trim((string) $request->input('name')) . '"',
            $context['user_id'],
        );

        return $this->ok('Priority created.', ['id' => (string) $id], 201);
    }

    public function updatePriority(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'sla_hours' => 'nullable|integer|min:1|max:8760',
            'active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $row = DB::table('task_management_priorities')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        if (!$row) {
            return $this->fail('Priority not found.', 404);
        }

        $newName = trim((string) $request->input('name'));

        if (strcasecmp($newName, $row->name) !== 0
            && $this->priorityNameTaken($context['sub_institute_id'], $newName)) {
            return $this->fail('A priority with this name already exists.', 409);
        }

        DB::table('task_management_priorities')->where('id', $id)->update([
            'name' => $newName,
            'sort_order' => (int) $request->input('sort_order', $row->sort_order),
            'sla_hours' => $request->input('sla_hours', $row->sla_hours),
            'active' => $request->boolean('active', (bool) $row->active),
            'updated_at' => now(),
        ]);

        // Tasks storing the old name follow the rename.
        if ($newName !== $row->name) {
            DB::table('task')
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('task_type', $row->name)
                ->update(['task_type' => $newName]);
        }

        $this->taskAudit->configChanged(
            $context['sub_institute_id'],
            'priority_updated',
            'Priority "' . $row->name . '"' . ($newName !== $row->name ? ' renamed to "' . $newName . '"' : ''),
            $context['user_id'],
        );

        return $this->ok('Priority updated.');
    }

    public function destroyPriority(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_priorities')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        $updated = $row
            ? DB::table('task_management_priorities')
                ->where('id', $id)
                ->update(['active' => false, 'updated_at' => now()])
            : 0;

        if ($updated) {
            $this->taskAudit->configChanged(
                $context['sub_institute_id'],
                'priority_deactivated',
                'Priority "' . $row->name . '"',
                $context['user_id'],
            );
        }

        return $updated
            ? $this->ok('Priority deactivated. Tasks already using it keep it for display.')
            : $this->fail('Priority not found.', 404);
    }

    /* ---------------- shared ---------------- */

    /** System names are reserved; custom names must be unique per tenant. */
    private function statusNameTaken(int $sid, string $name): bool
    {
        foreach (TaskOptionSetService::CATEGORIES as $category) {
            if (strcasecmp($name, $category) === 0) {
                return true;
            }
        }

        return DB::table('task_management_statuses')
            ->where('sub_institute_id', $sid)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    private function priorityNameTaken(int $sid, string $name): bool
    {
        foreach (['High', 'Medium', 'Low'] as $system) {
            if (strcasecmp($name, $system) === 0) {
                return true;
            }
        }

        return DB::table('task_management_priorities')
            ->where('sub_institute_id', $sid)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }
}
