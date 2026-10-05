<?php

namespace App\Services\Tasks;

use Illuminate\Support\Facades\DB;

/**
 * The one place an automated feature raises a `task` row.
 *
 * Extracted from Platform\ProcessController::raiseTask() rather than
 * duplicated for the Department Process run engine (DepartmentProcessRunController),
 * which needs the exact same thing: a task that is indistinguishable from one
 * raised by the Task screen itself, created at most once no matter how many
 * times the caller asks.
 *
 * Column names and defaults are copied from LegacyTaskController::payload() -
 * getting these wrong produces rows that exist and never appear in anybody's
 * list, which is the worst outcome available here: the caller reports success
 * and the work is invisible.
 */
class TaskPublisher
{
    /**
     * Idempotent: a second call with the same $idempotencyKey returns the
     * same task id rather than raising a duplicate - the guard a retried
     * request (a timeout where the row was written and the response lost)
     * needs. Pass null to skip the guard for a caller that never retries.
     */
    public function publish(
        string $title,
        string $description,
        \DateTimeInterface $dueDate,
        int $assigneeId,
        int $allocatedBy,
        int $subInstituteId,
        ?int $syear = null,
        ?string $idempotencyKey = null,
        string $priority = 'Medium',
    ): int {
        return DB::transaction(function () use (
            $title, $description, $dueDate, $assigneeId, $allocatedBy, $subInstituteId, $syear, $idempotencyKey, $priority
        ) {
            if ($idempotencyKey !== null) {
                $existing = DB::table('task_management_idempotency_keys')
                    ->where('sub_institute_id', $subInstituteId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->value('task_id');

                if ($existing) {
                    return (int) $existing;
                }
            }

            $taskId = DB::table('task')->insertGetId([
                'task_title'        => mb_substr($title, 0, 255),
                'task_description'  => mb_substr($description, 0, 65535),
                'task_date'         => $dueDate->format('Y-m-d'),
                'task_type'         => $priority,
                'task_allocated_to' => $assigneeId,
                'task_allocated'    => $allocatedBy,
                'status'            => 'PENDING',
                'sub_institute_id'  => $subInstituteId,
                'SYEAR'             => $syear ?? now()->year,
                'created_by'        => $allocatedBy,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            if ($idempotencyKey !== null) {
                DB::table('task_management_idempotency_keys')->insert([
                    'sub_institute_id'  => $subInstituteId,
                    'idempotency_key'   => $idempotencyKey,
                    'task_id'           => $taskId,
                    'created_at'        => now(),
                ]);
            }

            return (int) $taskId;
        });
    }
}
