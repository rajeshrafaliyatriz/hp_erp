<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;

/**
 * PROJECTOR — task_status_history. (`05-data-flow-contracts.md` §5, kind = P)
 *
 * The store's FIRST consumer, per M2: it proves the pattern on something small
 * and immediately useful.
 *
 * PURE. Writes only its own projection, touches nothing outside the database,
 * and never invokes a reactor - which is what makes it replayable.
 *
 * WHAT IT MAKES POSSIBLE (F2). A reopen is a transition INTO an active status
 * FROM a terminal one. It cannot be seen from the task row, which holds only the
 * CURRENT state - by the time a task is reopened, the row that said "completed"
 * has been overwritten. The transition only exists in the event stream, so
 * detecting it is exactly what a projection is for.
 */
class TaskStatusProjector
{
    public const CONSUMER = 'task_status_projector';

    /** A task is terminal when it is completed, or when approval has been granted. */
    private const TERMINAL_STATUSES = ['COMPLETED', 'COMPLETE', 'DONE', 'CLOSED'];

    public function handles(string $type): bool
    {
        return in_array($type, ['task.status_changed', 'task.rejected', 'task.reopened'], true);
    }

    private function isTerminal(?string $status, ?string $approveStatus): bool
    {
        if ($status !== null && in_array(strtoupper(trim($status)), self::TERMINAL_STATUSES, true)) {
            return true;
        }

        return $approveStatus !== null && strtolower(trim($approveStatus)) === 'approved';
    }

    private function isActive(?string $status, ?string $approveStatus): bool
    {
        if ($status === null && $approveStatus === null) {
            return false;
        }

        return !$this->isTerminal($status, $approveStatus);
    }

    /**
     * $target lets a DRY RUN write into a shadow table without the live table
     * being touched at all (§6.2 step 1). Threading the table through is the
     * honest way to do it: the alternative - writing live then moving the row -
     * touches the live table transiently, which is exactly what the dry run
     * exists to avoid.
     */
    public function project(object $event, ?string $target = null): void
    {
        $target ??= 'task_status_history';

        $payload = json_decode((string) $event->payload, true) ?: [];
        $before  = $payload['before'] ?? [];
        $after   = $payload['after'] ?? [];

        $fromStatus  = $before['status'] ?? null;
        $toStatus    = $after['status'] ?? null;
        $fromApprove = $before['approve_status'] ?? null;
        $toApprove   = $after['approve_status'] ?? null;

        $fromTerminal = $this->isTerminal($fromStatus, $fromApprove);
        $toActive     = $this->isActive($toStatus, $toApprove);

        DB::table($target)->updateOrInsert(
            ['event_id' => (int) $event->id],
            [
                'sub_institute_id'    => (int) $event->sub_institute_id,
                'task_id'             => (int) ($event->entity_id ?? 0),
                'from_status'         => $fromStatus,
                'to_status'           => $toStatus,
                'from_approve_status' => $fromApprove,
                'to_approve_status'   => $toApprove,
                'from_terminal'       => $fromTerminal,
                'to_active'           => $toActive,
                // F2, DETECTABLE: into active, out of terminal.
                'is_reopen'           => $fromTerminal && $toActive,
                'actor_id'            => $event->actor_id,
                'occurred_at'         => $event->occurred_at,
            ]
        );

        // A shadow run must leave no trace in the delivery ledger either -
        // otherwise the dry run would mark events as delivered that the live
        // projection has never seen.
        if ($target === 'task_status_history') {
            DB::table('g2g_event_delivery')->updateOrInsert(
                ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
                ['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'completed_at' => now()]
            );
        }
    }

    public function catchUp(int $limit = 500): int
    {
        $types = ['task.status_changed', 'task.rejected', 'task.reopened'];

        $events = DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')->where('d.consumer', '=', self::CONSUMER);
            })
            ->whereNull('d.id')
            ->whereIn('e.type', $types)
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.*']);

        foreach ($events as $event) {
            $this->project($event);
        }

        return $events->count();
    }
}
