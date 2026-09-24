<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three more frozen facts a leave approval step has to carry.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SECOND MIGRATION AND NOT AN EDIT TO THE FIRST
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 2026_09_26_100000 was already applied when these three were found missing.
 * The obvious move is `migrate:rollback`, edit, re-apply — and on THIS database
 * that is not safe. It is shared with other working copies: rolling back one
 * step here reached for batch 358, a migration belonging to another checkout
 * whose file does not exist in this tree. It happened to be a no-op for exactly
 * that reason, and relying on that twice would be luck rather than method.
 *
 * Migration batches interleave across checkouts. Forward-only is the only safe
 * direction on a shared database, so a correction is a new migration.
 *
 * ── WHAT THESE THREE ARE FOR ────────────────────────────────────────────────
 *
 * All three are facts the step must remember because the chain it came from can
 * be edited or deleted while a request is still in flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hrms_leave_approval_steps')) {
            return;
        }

        Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
            /*
             * The named approver's name, denormalised at freeze time.
             *
             * Same reasoning as `g2g_platform_workflows.created_by`: an id alone
             * stops resolving the day the person is deactivated, and a step that
             * cannot say whose approval it is waiting for is not much of a record.
             */
            if (! Schema::hasColumn('hrms_leave_approval_steps', 'approver_user_name')) {
                $table->string('approver_user_name', 191)->nullable()->after('approver_user_id');
            }

            /*
             * Whether this step demands a justification.
             *
             * Frozen, because turning it off in the console mid-flight would
             * silently drop an audit requirement from a request that was
             * submitted under it.
             */
            if (! Schema::hasColumn('hrms_leave_approval_steps', 'require_comment')) {
                $table->boolean('require_comment')->default(false)->after('on_breach');
            }

            /*
             * ONE-SHOT, AND DELIBERATELY NOT `escalated_at`.
             *
             * `escalated_at` means "the escalation target may now decide this too"
             * — it WIDENS who holds the decision. A reminder must not do that: a
             * nudge that quietly hands HR the right to approve is not a reminder,
             * it is an escalation wearing the wrong label.
             *
             * It still has to be one-shot, or the hourly sweep sends the same
             * reminder every hour to up to five people for as long as the step
             * stays open.
             */
            if (! Schema::hasColumn('hrms_leave_approval_steps', 'reminded_at')) {
                $table->timestamp('reminded_at')->nullable()->after('escalated_to');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hrms_leave_approval_steps')) {
            return;
        }

        foreach (['approver_user_name', 'require_comment', 'reminded_at'] as $column) {
            if (Schema::hasColumn('hrms_leave_approval_steps', $column)) {
                Schema::table('hrms_leave_approval_steps', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
