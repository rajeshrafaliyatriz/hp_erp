<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITEM 6, SLICE 4 — task_status_history, the event store's FIRST consumer.
 *
 * `05-data-flow-contracts.md` §5. A PROJECTION: derived from g2g_event, dropped
 * and rebuilt by TaskStatusProjector, never written independently.
 *
 * NOT BACKFILLED. There is no historical transition data to recover and inventing
 * it would fabricate evidence - the same principle that refused to infer a
 * competency for the 33% null task.skill_id.
 *
 * `is_reopen` is the point of the table: F2 becomes DETECTABLE rather than
 * describable. A reopen is a transition INTO an active status FROM a terminal one
 * (COMPLETED, or approve_status='approved'), which cannot be seen from the task
 * row alone because the task row only holds its CURRENT state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_status_history')) {
            return;
        }

        Schema::create('task_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');

            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64)->nullable();
            $table->string('from_approve_status', 64)->nullable();
            $table->string('to_approve_status', 64)->nullable();

            // Derived at projection time, from the transition itself.
            $table->boolean('from_terminal')->default(false);
            $table->boolean('to_active')->default(false);
            $table->boolean('is_reopen')->default(false);

            $table->unsignedBigInteger('actor_id')->nullable();   // NULL = SYSTEM
            $table->dateTime('occurred_at', 3);

            // One row per event: re-projecting cannot duplicate a transition.
            $table->unique('event_id', 'uq_tsh_event');
            $table->index(['task_id', 'occurred_at'], 'idx_tsh_task_time');
            $table->index(['sub_institute_id', 'occurred_at'], 'idx_tsh_tenant_time');
            $table->index(['is_reopen', 'occurred_at'], 'idx_tsh_reopen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_status_history');
    }
};
