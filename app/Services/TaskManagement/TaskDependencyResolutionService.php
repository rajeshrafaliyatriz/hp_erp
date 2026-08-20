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
    /**
     * PREDECESSORS OF THIS TASK THAT ARE STILL OPEN.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY THIS WARNS RATHER THAN REFUSES
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Nothing in this product has ever consulted the dependency graph when a
     * status changes — TaskStatusTransitionService is a fixed table of allowed
     * moves and never queries it. So a task whose predecessor had not been
     * touched could be started and completed in silence, and the graph was
     * decoration.
     *
     * The fix is a warning, not a block, and deliberately so: real work runs
     * out of order, and the data here is imperfect enough that a hard refusal
     * would strand people whose predecessor's owner is on leave. The caller
     * proceeds and is told what it just stepped over.
     *
     * TENANT SCOPING: dependency rows are filtered by the task's own tenant and
     * year. resolveAfterCompletion() below reads the same table unscoped —
     * pre-existing, and safe only because the write it leads to is scoped.
     *
     * @return array<int,array{id:string,title:string,status:string}>
     */
    public function openPredecessors(int $taskId, int $tenantId, string $syear): array
    {
        return DB::table('task_management_dependencies as d')
            ->join('task as p', 'p.id', '=', 'd.predecessor_task_id')
            ->where('d.successor_task_id', $taskId)
            ->where('d.sub_institute_id', $tenantId)
            ->where('d.syear', $syear)
            ->whereNull('p.deleted_at')
            ->whereRaw("UPPER(COALESCE(p.status, 'PENDING')) <> 'COMPLETED'")
            ->get(['p.id', 'p.task_title', 'p.status'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->task_title,
                'status' => $row->status ?: 'PENDING',
            ])
            ->all();
    }

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

            // T-01. The guard stays here - only a task ON HOLD *for a dependency*
            // is unblocked, and that condition belongs to this service. The WRITE
            // belongs to TaskStatusWriter, which owns the invariant and emits
            // task.status_changed with a `from` it actually observed.
            $successor = DB::table('task')
                ->where('id', $successorId)
                ->whereNull('deleted_at')
                ->whereRaw("UPPER(status) = 'ON HOLD'")
                ->where('delay_category', 'Dependency')
                ->first(['id', 'sub_institute_id']);

            $updated = 0;
            if ($successor) {
                $move = app(\App\Services\TaskManagement\TaskStatusWriter::class)->moveTo(
                    (int) $successor->id,
                    'PENDING',
                    (int) $successor->sub_institute_id,
                    (int) ($actorId ?? 0)
                );
                $updated = $move['ok'] ? 1 : 0;
            }

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
