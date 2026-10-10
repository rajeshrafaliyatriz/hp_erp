<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Shared per-row loop for bulk delete/reassign across the 4 CRM modules,
 * matching TaskBulkActionController's results/summary shape rather than an
 * all-or-nothing transaction - one bad id in a batch must not fail every
 * other id in it.
 */
trait HasCrmBulkActions
{
    /**
     * @param (Closure(int): (string|null))|null $guard Returns a failure
     *   reason to skip this id (e.g. "has children"), or null to allow it.
     */
    protected function bulkDeleteRows(
        Request $request,
        string $table,
        int $tenantId,
        int $userId,
        string $label,
        ?Closure $guard = null,
    ): JsonResponse {
        $ids = $this->validatedBulkIds($request);

        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $results = [];

        foreach ($ids as $id) {
            $existing = DB::table($table)
                ->where('id', $id)
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->first();

            if (! $existing) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => "{$label} not found."];
                continue;
            }

            if ($guard && ($reason = $guard($id)) !== null) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => $reason];
                continue;
            }

            DB::table($table)->where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $userId,
            ]);

            $results[] = ['id' => (string) $id, 'ok' => true];
        }

        return $this->bulkResponse($results, "{$label}s moved to Recycle Bin.");
    }

    protected function bulkAssignRows(
        Request $request,
        string $table,
        int $tenantId,
        int $userId,
        string $label,
    ): JsonResponse {
        $ids = $this->validatedBulkIds($request);

        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $validator = Validator::make($request->all(), ['assignedTo' => 'required|integer']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $assignedTo = (int) $request->input('assignedTo');
        $results = [];

        foreach ($ids as $id) {
            $exists = DB::table($table)
                ->where('id', $id)
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'reason' => "{$label} not found."];
                continue;
            }

            DB::table($table)->where('id', $id)->update([
                'assigned_to' => $assignedTo,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

            $results[] = ['id' => (string) $id, 'ok' => true];
        }

        return $this->bulkResponse($results, 'Ownership transferred.');
    }

    /** @return array<int, int>|JsonResponse */
    private function validatedBulkIds(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        return array_values(array_unique(array_map('intval', $request->input('ids'))));
    }

    /** @param array<int, array{id:string, ok:bool, reason?:string}> $results */
    private function bulkResponse(array $results, string $successMessage): JsonResponse
    {
        $succeeded = count(array_filter($results, fn ($r) => $r['ok']));
        $failed = count($results) - $succeeded;

        return response()->json([
            'status' => 1,
            'message' => $failed === 0 ? $successMessage : "{$succeeded} succeeded, {$failed} failed.",
            'data' => [
                'results' => $results,
                'summary' => ['succeeded' => $succeeded, 'failed' => $failed],
            ],
        ]);
    }
}
