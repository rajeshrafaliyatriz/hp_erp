<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITEM 6, SLICE 2 — g2g_audit_log, a PROJECTION.
 *
 * Per 05-data-flow-contracts.md §1 and amendment A1. This table is DERIVED: it is
 * dropped and rebuilt from g2g_event, and nothing writes to it except
 * AuditLogProjector. It supersedes task_management_audit_logs, whose writer
 * (TaskAuditService) is converted to emit events in the same slice - because a
 * projection alongside a surviving direct writer is the defect §1 exists to
 * prevent, dressed as compliance with it.
 *
 * event_id carries the provenance: every row can be traced to the event it came
 * from, which is what makes "drop and rebuild" verifiable rather than asserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_audit_log')) {
            return;
        }

        Schema::create('g2g_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('type', 96);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();   // NULL = SYSTEM
            $table->unsignedBigInteger('acting_for_id')->nullable();
            $table->json('detail')->nullable();
            $table->dateTime('occurred_at', 3);

            // One row per event. Makes the projector idempotent by construction:
            // replaying an event cannot duplicate its audit row.
            $table->unique('event_id', 'uq_audit_event');
            $table->index(['sub_institute_id', 'occurred_at'], 'idx_audit_tenant_time');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index(['actor_id', 'occurred_at'], 'idx_audit_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_audit_log');
    }
};
