<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2. `ProcessController::update()` has always overwritten `source_text` and
 * `spec` in place with no trace of what they were — the same gap
 * `g2g_platform_workflow_versions` closed for workflow chains this round, for the
 * same reason: a procedure that gets edited after tasks have already been raised
 * against its old wording is a real thing that happens, and "what did this used to
 * say" should have an answer.
 *
 * One row per edit, holding the PREVIOUS `source_text` and `spec` — the state being
 * overwritten, not the new state (which the process row itself already holds, so
 * duplicating it here would be a second copy of the current value rather than a
 * record of history).
 *
 * Not a foreign key to `g2g_process` — a process can be deleted
 * (`ProcessController::destroy()` is a hard delete) while its edit history is still
 * worth keeping, same reasoning as the workflow version table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_process_version')) {
            return;
        }

        Schema::create('g2g_process_version', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('process_id');
            $table->unsignedBigInteger('sub_institute_id');

            // The PREVIOUS text and spec — what was true before this edit overwrote it.
            $table->longText('source_text');
            $table->longText('spec');

            $table->string('changed_by', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['process_id', 'created_at'], 'g2g_pv_process_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_process_version');
    }
};
