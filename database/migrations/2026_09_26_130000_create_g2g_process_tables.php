<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A written procedure, turned into something the product can act on.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS FIXES ABOUT THE SHAPE LMS K-12 USES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * K-12's Add Process saves its converted procedure into `requirement_gathering`
 * — a general-purpose key/value table — as a JSON envelope, with
 * `sub_institute_id` HARDCODED TO 0 in its API client. Every school therefore
 * writes to, and overwrites, the same rows. Nothing reads the envelope back
 * except the builder screen that wrote it, and publishing records nothing, so
 * re-opening a process cannot tell you whether its tasks exist or how many times
 * they have been raised.
 *
 * The conversion and the publish are the parts worth having, and they are
 * genuinely good there. The storage is not. So:
 *
 *   g2g_process        one row per procedure, scoped to the organisation that
 *                      wrote it, in columns rather than a JSON blob in a TEXT
 *                      column with a 60 KB ceiling.
 *
 *   g2g_process_task   what a publish actually created. This is the table K-12
 *                      has no equivalent of, and it is what lets the screen say
 *                      "published, 14 tasks" instead of forgetting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_process')) {
            Schema::create('g2g_process', function (Blueprint $table) {
                $table->bigIncrements('id');

                // NOT NULL, and the whole reason this table exists rather than
                // reusing requirement_gathering.
                $table->unsignedBigInteger('sub_institute_id');

                $table->string('name', 191);

                // Which part of the product the procedure belongs to, from
                // config('platform_services.modules'). Validated on write.
                $table->string('module', 64);

                /*
                 * The procedure as somebody wrote it, kept verbatim.
                 *
                 * The structured form below is DERIVED from this, and a derivation
                 * you cannot re-run against its input is a one-way door: improving
                 * the parser would have nothing to re-parse, and a mis-parse could
                 * never be diagnosed against what was actually written.
                 */
                $table->longText('source_text');

                /*
                 * The structured result: objective, trigger, completion criteria,
                 * the workflow steps and the derived tasks.
                 *
                 * JSON because it is read and written whole and never queried
                 * field-by-field — the same reasoning as g2g_platform_workflows.steps.
                 */
                $table->json('spec');

                // draft | published. A draft has raised no tasks.
                $table->string('status', 16)->default('draft');

                // Denormalised "Name (id)", so the record survives a rename or a
                // deactivation.
                $table->string('created_by', 191)->nullable();
                $table->string('updated_by', 191)->nullable();

                $table->timestamps();

                $table->index(['sub_institute_id', 'module'], 'g2g_process_tenant_module_idx');
                $table->index(['sub_institute_id', 'status'], 'g2g_process_tenant_status_idx');
            });
        }

        if (! Schema::hasTable('g2g_process_task')) {
            Schema::create('g2g_process_task', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('process_id');
                $table->unsignedBigInteger('sub_institute_id');

                // The row in the task tables this publish created.
                $table->unsignedBigInteger('task_id');

                // Which derived task it came from, so a re-publish can tell what it
                // has already raised rather than raising it again.
                $table->string('task_ref', 64);

                $table->string('title', 255);
                $table->unsignedBigInteger('assignee_id')->nullable();

                /*
                 * The key sent to the idempotent task endpoint.
                 *
                 * Kept so a retry after a timeout can be proven to have replayed
                 * rather than duplicated — "did that create one task or two" is
                 * otherwise unanswerable after the fact.
                 */
                $table->string('idempotency_key', 100);

                $table->timestamps();

                // One task per derived step per process. This is what makes a
                // second publish a no-op rather than a second set of tasks.
                $table->unique(['process_id', 'task_ref'], 'g2g_process_task_unique');
                $table->index(['sub_institute_id', 'process_id'], 'g2g_process_task_tenant_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_process_task');
        Schema::dropIfExists('g2g_process');
    }
};
