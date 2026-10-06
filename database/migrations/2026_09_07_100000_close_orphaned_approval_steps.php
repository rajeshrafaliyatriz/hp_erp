<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close approval steps whose request no longer exists. F-135.
 *
 * `escalateOverdue()` read hrms_leave_approval_steps alone, and the foreign key
 * to hrms_emp_leaves is ON DELETE CASCADE - which sounds like protection and is
 * inert, because this module SOFT-deletes everywhere. So the cascade never
 * fires, steps outlive their request, and the hourly sweep kept stamping the
 * one-shot escalated_at on requests nobody could open, notifying five HR users
 * about each.
 *
 * The query is fixed in LeaveApprovalWorkflow (it now joins the parent). This
 * clears the debris that accumulated before it was.
 *
 * WHAT THESE ROWS ARE, because it matters for the decision: all 26 belong to 17
 * soft-deleted leave requests, and every one of them was created by this audit's
 * own probes. The probes soft-deleted their leaves with raw SQL rather than
 * through destroy(), which would have called closeOpenSteps() for them. No
 * tenant data is touched here - this is cleaning up after myself.
 *
 * 'skipped' is the status closeOpenSteps() uses for exactly this: a step nobody
 * is being asked to act on any more, kept rather than deleted so the chain's
 * shape survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('hrms_leave_approval_steps as s')
            ->join('hrms_emp_leaves as l', 'l.id', '=', 's.leave_id')
            ->whereIn('s.status', ['pending', 'waiting'])
            ->where(function ($q) {
                $q->whereNotNull('l.deleted_at')
                  ->orWhere('l.status', '<>', 'pending');
            })
            ->pluck('s.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('hrms_leave_approval_steps')
            ->whereIn('id', $ids)
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    public function down(): void
    {
        /*
         * NOT REVERSED, and the reason is the point.
         *
         * Reopening these would put steps back into the escalation sweep for
         * requests that are deleted or already decided - which is the defect,
         * not the prior state worth restoring. A rollback that reinstates a bug
         * is not a rollback.
         *
         * The reversal script records every affected id and its original status
         * verbatim, so a genuine restore is possible by hand if one is ever
         * wanted: _reversals/REVERSAL-2026-09-07-orphaned-approval-steps.sql
         */
    }
};
