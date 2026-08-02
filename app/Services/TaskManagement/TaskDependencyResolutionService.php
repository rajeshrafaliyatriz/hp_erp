<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unblocks dependent tasks when their predecessor completes.
 *
 * Scope is deliberately narrow: only successors that were parked ON HOLD with
 * delay_category 'Dependency' are touched - that combination is the system's
 * own marker for "waiting on another task", so flipping it back to PENDING
 * when the wait ends is completing the workflow, not overriding a human. A
 * task someone put ON HOLD for a resource or scope reason stays exactly where
 * they left it.
 */
class TaskDependencyResolutionService
{
    public function resolveAfterCompletion(int $taskId, ?int $actorId): void
    {
        $successorIds = DB::table('task_management_dependencies')
            ->where('predecessor_task_id', $taskId)
            ->pluck('successor_task_id');

        foreach ($successorIds as $successorId) {
            // Still waiting on someone else? Then it stays blocked.
            $openPredecessors = DB::table('task_management_dependencies as d')
                ->join('task as p', 'p.id', '=', 'd.predecessor_task_id')
                ->where('d.successor_task_id', $successorId)
                ->whereNull('p.deleted_at')
                ->whereRaw("UPPER(COALESCE(p.status, 'PENDING')) <> 'COMPLETED'")
                ->count();

            if ($openPredecessors > 0) {
                continue;
            }

            $updated = DB::table('task')
                ->where('id', $successorId)
                ->whereNull('deleted_at')
                ->whereRaw("UPPER(status) = 'ON HOLD'")
                ->where('delay_category', 'Dependency')
                ->update([
                    'status' => 'PENDING',
                    'delay_category' => null,
                    'delay_reason' => null,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            if ($updated) {
                Log::channel('single')->info('task.dependency_resolved', [
                    'unblocked_task_id' => $successorId,
                    'completed_predecessor_id' => $taskId,
                    'actor_id' => $actorId,
                ]);
            }
        }
    }
}
