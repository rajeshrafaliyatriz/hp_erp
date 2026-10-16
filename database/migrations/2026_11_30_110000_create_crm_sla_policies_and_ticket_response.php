<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 4: support SLA timers - the CRM gap
 * analysis confirmed this was explicitly absent (the tickets table's own
 * original migration docblock: "`hours`+`days`... NOT an SLA deadline...
 * confirmed no SLA/due-date field exists anywhere in legacy, not built here
 * either").
 *
 * `crm_sla_policies` - one row per tenant per priority (Normal/High/Urgent,
 * the real seeded picklist), admin-configurable response-time-in-hours.
 * A priority with no row simply has no SLA (honest "not configured" state,
 * not a guessed default) - matches this module's existing precedent
 * (Invoice payment-status, Lead auto-assignment) of never inventing a
 * default for something an admin hasn't actually decided.
 *
 * `crm_tickets.first_response_at` is the ONE new ticket column - a real
 * historical fact (when staff actually first responded, set once by
 * CrmTicketController::addComment() and never overwritten), not something
 * derivable from other data. The due-by timestamp itself is deliberately
 * NOT stored: it is computed on every read from `created_at + policy hours
 * for the ticket's current priority` (App\Domain\Crm\Support\
 * TicketSlaCalculator), same "server-computed, never a stale stored
 * derivative" rule this effort already applied to Invoices' balance due and
 * Opportunities' weighted revenue - a priority change immediately and
 * correctly changes what "on time" means for that ticket, with nothing to
 * recompute or drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sla_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('priority', 50);
            $table->decimal('response_hours', 8, 2);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();

            $table->unique(['sub_institute_id', 'priority'], 'crm_sla_policies_tenant_priority_unique');
        });

        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->timestamp('first_response_at')->nullable()->after('solution');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->dropColumn('first_response_at');
        });

        Schema::dropIfExists('crm_sla_policies');
    }
};
