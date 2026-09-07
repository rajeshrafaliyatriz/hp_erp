<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to Sprint 6's own work, found by reviewing it. F-124.
 *
 * The approval chain shipped three hours before this migration. An adversarial
 * pass over it raised 36 candidate defects, 15 of which survived verification.
 * Four of those are data or schema problems rather than code problems, and this
 * is where they are fixed. They are recorded rather than quietly corrected
 * because the mistakes are instructive:
 *
 * 1. A STEP COULD NOT SAY WHAT WAS ACTUALLY DECIDED. `status` was
 *    approved/rejected only, so 'sent_back' and 'cancelled' were both flattened
 *    to 'rejected'. The screen then told the employee their request had been
 *    REJECTED when it had been sent back for amendment - a materially different
 *    thing to be told. `decision` now records the real DECISION_STATUSES value
 *    beside the step's own lifecycle state.
 *
 * 2. THE BACKFILL RECORDED APPROVALS THAT NEVER HAPPENED. For an approved
 *    request with a two-step chain it marked BOTH steps 'approved'. Only one
 *    decision was ever made. Steps after the first are now 'skipped' - the
 *    request was approved under the old single-decision rule, and the honest
 *    record is that the later stages were never asked, not that they agreed.
 *
 * 3. THE BACKFILL CALLED CANCELLATION A REJECTION, and attributed it to the
 *    approver. A cancelled request was very often cancelled by the EMPLOYEE.
 *
 * 4. `sent_back` NEEDED A STEP STATE OF ITS OWN so the chain can restart
 *    instead of being destroyed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
            // What was actually decided, verbatim: approved, rejected,
            // sent_back, cancelled, approved_lwp. `status` stays the step's own
            // lifecycle (waiting/pending/approved/rejected/sent_back/skipped);
            // this is the decision that produced it.
            $table->string('decision', 20)->nullable()->after('status');
        });

        // Existing decided steps: the decision matches the status we had, which
        // is the best that can be said - the distinction did not exist when they
        // were written, so inventing a finer one now would be a fabrication.
        DB::statement(
            "UPDATE hrms_leave_approval_steps
                SET decision = status
              WHERE status IN ('approved', 'rejected')
                AND decision IS NULL"
        );

        /*
         * Correction 2 and 3. Only the backfilled rows are touched: they are the
         * ones with decided_at set and approver_id NULL, because the backfill
         * could name an approver (from hrms_emp_leaves.approved_by) but never
         * identify one. A step decided through the API always has approver_id.
         */
        $backfilled = DB::table('hrms_leave_approval_steps as s')
            ->join('hrms_emp_leaves as l', 'l.id', '=', 's.leave_id')
            ->whereNull('s.approver_id')
            ->whereNotNull('s.decided_at');

        // Steps 2..n of a backfilled APPROVED request never actually approved
        // anything. Mark them skipped and strip the attribution.
        (clone $backfilled)
            ->where('s.step_order', '>', 1)
            ->whereIn('s.status', ['approved', 'rejected'])
            ->update([
                's.status'        => 'skipped',
                's.decision'      => null,
                's.approver_name' => null,
                's.decided_at'    => null,
                's.updated_at'    => now(),
            ]);

        // A cancelled request was not rejected by its approver.
        (clone $backfilled)
            ->where('l.status', 'cancelled')
            ->update([
                's.status'        => 'skipped',
                's.decision'      => 'cancelled',
                's.approver_name' => null,
                's.updated_at'    => now(),
            ]);

        // A sent-back request is waiting on its first approver again, not refused.
        (clone $backfilled)
            ->where('l.status', 'sent_back')
            ->update([
                's.status'        => 'sent_back',
                's.decision'      => 'sent_back',
                's.updated_at'    => now(),
            ]);
    }

    public function down(): void
    {
        // The status corrections are not reversed: restoring them would mean
        // re-asserting that approvals happened which did not. Only the column
        // goes, and that is said out loud rather than silently done.
        Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
            $table->dropColumn('decision');
        });
    }
};
