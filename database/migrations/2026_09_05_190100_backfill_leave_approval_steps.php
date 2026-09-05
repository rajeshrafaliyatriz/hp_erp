<?php

use App\Services\Leave\LeaveApprovalWorkflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the requests already in flight a chain to sit in. F-124.
 *
 * Without this, every open request in the product predates the chain and falls
 * through decision()'s "no steps" branch for ever - the fix would be live and
 * invisible on the only requests anyone currently has.
 *
 * WHAT THIS DOES NOT DO: it does not invent history. A request that is already
 * approved keeps its approval; its steps are written as approved, attributed to
 * whoever hrms_emp_leaves.approved_by names, at the timestamp already recorded.
 * A pending request gets a live chain from step one, because nobody has in fact
 * approved it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workflow = app(LeaveApprovalWorkflow::class);
        $now = now();

        $existing = DB::table('hrms_leave_approval_steps')->distinct()->pluck('leave_id')->all();

        $leaves = DB::table('hrms_emp_leaves')
            ->whereNull('deleted_at')
            ->when($existing, fn ($q) => $q->whereNotIn('id', $existing))
            ->get(['id', 'sub_institute_id', 'status', 'approved_by', 'updated_at', 'created_at']);

        $rows = [];

        foreach ($leaves as $leave) {
            $chain = $workflow->chainFor((int) $leave->sub_institute_id);

            foreach ($chain as $index => $role) {
                $isFirst = $index === 0;

                // Decided requests: the whole chain is closed, and only the first
                // step carries the approver - that is the only decision that was
                // actually made, and attributing it to three people would be a lie.
                if ($leave->status === 'pending') {
                    $status = $isFirst ? 'pending' : 'waiting';
                } elseif (in_array($leave->status, ['approved', 'approved_lwp'], true)) {
                    $status = 'approved';
                } elseif ($isFirst) {
                    $status = 'rejected';
                } else {
                    $status = 'skipped';
                }

                $decided = $leave->status === 'pending'
                    ? null
                    : ($leave->updated_at ?: $leave->created_at);

                $rows[] = [
                    'leave_id'         => $leave->id,
                    'sub_institute_id' => $leave->sub_institute_id,
                    'step_order'       => $index + 1,
                    'approver_role'    => $role,
                    'status'           => $status,
                    'approver_id'      => null,
                    'approver_name'    => ($isFirst && $leave->status !== 'pending') ? $leave->approved_by : null,
                    'comment'          => null,
                    'decided_at'       => $status === 'waiting' || $status === 'pending' ? null : $decided,
                    // Pending steps get a clock so escalation can start measuring.
                    // It starts NOW, not at created_at: escalating a three-month-old
                    // request the instant this deploys would be a false alarm about a
                    // rule that was not in force while it was waiting.
                    'pending_since'    => $status === 'pending' ? $now : null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('hrms_leave_approval_steps')->insert($chunk);
        }
    }

    public function down(): void
    {
        /*
         * Delete ONLY what this migration inserted, not the whole table.
         *
         * The first version was `DB::table('hrms_leave_approval_steps')->delete()`,
         * which would have destroyed every approval recorded through the API
         * since it ran - real decisions, with real approvers and timestamps -
         * on a rollback that was only ever meant to undo a backfill. Caught by
         * review before anyone ran it.
         *
         * The backfill is identifiable: it could name an approver (copied from
         * hrms_emp_leaves.approved_by) but never identify one, so approver_id is
         * NULL on every row it wrote. A step decided through decision() always
         * has approver_id set.
         */
        DB::table('hrms_leave_approval_steps')
            ->whereNull('approver_id')
            ->delete();
    }
};
