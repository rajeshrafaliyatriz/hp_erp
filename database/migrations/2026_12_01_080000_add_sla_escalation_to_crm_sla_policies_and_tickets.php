<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 6: automated SLA escalation and
 * notifications on breach - the one piece Wave 4 explicitly deferred as
 * "nice to have, not baseline" when it shipped SLA *visibility*.
 *
 * `crm_sla_policies.escalate_to_user_id` - who gets told, in addition to the
 * ticket's own assignee, when that priority breaches. Nullable and admin-set,
 * same "a priority with no row/value is honestly not configured, never a
 * guessed default" rule Wave 4 already applied to the policy itself.
 *
 * `crm_sla_policies.auto_reassign_on_breach` - opt-in (default false). Telling
 * the escalation contact is always safe to do automatically; handing them the
 * ticket is a bigger behavioural change, so it stays off until an admin
 * deliberately turns it on - same "never silently widen what the system does"
 * rule as Wave 2's auto-assignment rotation toggle.
 *
 * `crm_tickets.sla_escalated_at` - the one-shot claim column the sweep command
 * uses exactly like `leave:escalate`/`approvals:escalate` already do
 * (`whereNull('sla_escalated_at')->update(...)` as the atomic claim): once set,
 * this ticket's current breach is never escalated a second time, concurrent
 * sweep runs included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_sla_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('escalate_to_user_id')->nullable()->after('response_hours');
            $table->boolean('auto_reassign_on_breach')->default(false)->after('escalate_to_user_id');
        });

        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->timestamp('sla_escalated_at')->nullable()->after('first_response_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->dropColumn('sla_escalated_at');
        });

        Schema::table('crm_sla_policies', function (Blueprint $table) {
            $table->dropColumn(['escalate_to_user_id', 'auto_reassign_on_breach']);
        });
    }
};
