<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITEM 6, SLICE 1 — the event store and its delivery ledger.
 *
 * Built exactly to `docs/phase3/05-data-flow-contracts.md` §1. Nothing here is
 * adapted: that document is the contract for six other items, and a silent
 * change to it would be a change to all of them.
 *
 * WHY THIS SLICE ONLY. §1's model names three projections, and one of them -
 * g2g_audit_log - does not exist, while `task_management_audit_logs` (6 rows) and
 * `tbl_user_journey_logs` (5,234 rows) are live INDEPENDENT WRITERS that the
 * document does not mention. That is unresolved and was raised rather than
 * decided. These two tables are required under every resolution of it, so they
 * are safe to build now; the projections are not.
 *
 * PRECONDITION MET: G-SEC-12 closed, so `actor_id` can be trusted. §1.9 blocked
 * this document until it was, which is why the item sits here in the order.
 *
 * Strictly additive. No existing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('g2g_event')) {
            Schema::create('g2g_event', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('event_uuid', 36);
                $table->string('type', 96);                       // 'task.rejected'

                // TENANT ON EVERY EVENT, NOT NULL. This is the one table that will
                // hold every tenant's history, so it is the worst possible place
                // for G-DATA-08 to recur. Resolved from the caller's identity at
                // write time, never from a request field.
                $table->unsignedBigInteger('sub_institute_id');

                $table->string('entity_type', 64);                // task | user | enrolment
                $table->unsignedBigInteger('entity_id')->nullable();

                // NULL means SYSTEM - a real value, not "unknown". Any non-null
                // value comes from the resolved identity (ResolvesApiIdentity),
                // never from the request. G-SEC-12 is what makes that true.
                $table->unsignedBigInteger('actor_id')->nullable();

                // A4: delegation - "B acting for A".
                $table->unsignedBigInteger('acting_for_id')->nullable();

                $table->json('payload');
                $table->json('metadata')->nullable();             // ip, user agent, request id

                $table->char('correlation_id', 36)->nullable();   // one action, many events
                $table->char('causation_id', 36)->nullable();     // which event caused this

                $table->string('idempotency_key', 191)->nullable();

                // occurred_at and recorded_at are SEPARATE: imports and offline
                // actions happen before they are recorded, and conflating them
                // silently reorders history. DATETIME(3) so two events in one
                // request have a deterministic order.
                $table->dateTime('occurred_at', 3);
                $table->dateTime('recorded_at', 3);

                $table->unique('event_uuid', 'uq_event_uuid');
                $table->unique(['sub_institute_id', 'idempotency_key'], 'uq_event_idem');
                $table->index(['sub_institute_id', 'occurred_at'], 'idx_event_tenant_time');
                $table->index(['type', 'occurred_at'], 'idx_event_type');
                $table->index(['entity_type', 'entity_id'], 'idx_event_entity');
            });
        }

        if (!Schema::hasTable('g2g_event_delivery')) {
            Schema::create('g2g_event_delivery', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('event_id');
                $table->string('consumer', 96);                   // projector or subscriber name

                // Delivery state is per CONSUMER, which is why it is not a column
                // on the event: one event succeeding for the notifier and failing
                // for the projector cannot be expressed in a single status.
                $table->enum('status', ['pending', 'done', 'failed', 'skipped'])->default('pending');
                $table->integer('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->dateTime('completed_at')->nullable();

                $table->unique(['event_id', 'consumer'], 'uq_delivery');
                $table->index(['status', 'consumer'], 'idx_delivery_pending');
            });
        }
    }

    public function down(): void
    {
        // Append-only in USE - a mistake is corrected by a compensating event,
        // never by editing history. down() exists for the migration, not as an
        // operational tool.
        Schema::dropIfExists('g2g_event_delivery');
        Schema::dropIfExists('g2g_event');
    }
};
