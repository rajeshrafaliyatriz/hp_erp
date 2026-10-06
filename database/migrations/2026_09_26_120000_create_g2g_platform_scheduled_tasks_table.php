<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant overrides for the scheduled tasks that can honour one.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ONLY TWO OF THE SIX TASKS CAN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `leave:escalate` and `readiness:recompute` take `--tenant`. The other four —
 * `events:project`, `events:react`, `certifications:scan-expiry` and
 * `app:sync-data-cron` — process the whole installation in one pass, so there is
 * no sense in which one organisation's copy could run on a different schedule.
 *
 * A row here for one of those four would be a setting that silently does
 * nothing, which is the defect this whole piece of work exists to remove. The
 * API refuses to write one, `config/platform_services.php` marks which is which,
 * and the console shows the other four as installation-wide with the reason.
 *
 * ── ONLY OVERRIDES ARE STORED ───────────────────────────────────────────────
 *
 * No row means "follow the schedule the application ships with". Resetting a task
 * DELETES its row rather than writing the current default into it, so improving a
 * shipped default reaches every tenant that never disagreed with it — and a
 * tenant that did disagree keeps their answer. Seeding defaults into this table
 * would freeze today's schedule for everybody, permanently and invisibly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_platform_scheduled_tasks')) {
            return;
        }

        Schema::create('g2g_platform_scheduled_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');

            // `module.component.task` from config('platform_services.tasks').
            // Validated against that catalogue on write, so a row can never name
            // a task the application does not have.
            $table->string('task_key', 191);

            /*
             * The five cron fields, stored separately rather than as one
             * expression.
             *
             * The screen edits them individually, and a single string would mean
             * parsing and re-serialising on every read and write — with the parse
             * being the thing that has to be right for a task to run at all.
             */
            $table->string('minute', 64)->default('0');
            $table->string('hour', 64)->default('0');
            $table->string('day', 64)->default('*');
            $table->string('month', 64)->default('*');
            $table->string('day_of_week', 64)->default('*');

            /*
             * Switched off for this organisation.
             *
             * Separate from deleting the row: `disabled` is an opinion ("not for
             * us"), whereas no row is the absence of one ("whatever you ship").
             * Collapsing them would make "off" indistinguishable from "default",
             * and the default is on.
             */
            $table->boolean('disabled')->default(false);

            // Denormalised "Name (id)" so the record survives a rename or a
            // deactivation, as g2g_platform_workflows does.
            $table->string('updated_by', 191)->nullable();

            $table->timestamps();

            // One override per task per tenant. Makes a save an upsert and stops
            // two saves racing into two rows that a read would have to choose
            // between.
            $table->unique(['sub_institute_id', 'task_key'], 'g2g_pst_tenant_task_unique');
            $table->index(['task_key', 'disabled'], 'g2g_pst_task_disabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_platform_scheduled_tasks');
    }
};
