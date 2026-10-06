<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The scheduled-task run ledger this application has never had.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS FIXES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Nothing records that a scheduled task ran. `routes/console.php` registers six of
 * them with no `onSuccess` or `onFailure` hook, so "when did the event store last
 * drain" has had no answer — which is precisely how `events:project` came to be
 * believed to run every five minutes while in fact only running when somebody typed
 * it. That went unnoticed for a release because there was nowhere to look.
 *
 * Until this table exists, the Scheduler console reports `last_run` as UNAVAILABLE
 * rather than "never", because those are different claims and only one of them is true.
 * This is what lets it start answering.
 *
 * ── `sub_institute_id` IS NULLABLE, AND THAT IS NOT AN OVERSIGHT ────────────
 *
 * A scheduled command is the application's, not a tenant's. `events:project` drains
 * every tenant's events in one pass; there is no organisation it ran "for". NULL means
 * estate-wide, which is the honest value, and the console labels it that way rather
 * than attributing a global job to whoever happens to be looking.
 *
 * The column exists at all because a future per-tenant task is plausible, and adding
 * the column later would mean backfilling rows whose scope nobody can reconstruct.
 *
 * ── APPEND-ONLY IN USE ──────────────────────────────────────────────────────
 *
 * A run happened or it did not. Rows are never edited; a long history is pruned by
 * deleting old rows, never by rewriting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_platform_task_runs')) {
            return;
        }

        Schema::create('g2g_platform_task_runs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The artisan command name — 'events:project'. Matches the key
            // ScheduleReader derives, so the join needs no translation.
            $table->string('task_key', 191);

            // NULL = the whole installation. See the class note.
            $table->unsignedBigInteger('sub_institute_id')->nullable();

            $table->dateTime('started_at', 3);
            $table->dateTime('finished_at', 3)->nullable();

            // ok | failed. Nullable so a run that started and never reported can be
            // distinguished from one that reported success — a crashed task leaves a
            // row with no status, which is itself the finding.
            $table->string('status', 16)->nullable();

            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // The first part of whatever the command printed. Bounded on purpose: a
            // command that writes megabytes must not be able to fill this table.
            $table->text('output_head')->nullable();

            // Which server ran it, for an estate where onOneServer() decides.
            $table->string('host', 191)->nullable();

            $table->index(['task_key', 'started_at'], 'g2g_ptr_task_time_idx');
            $table->index(['status', 'started_at'], 'g2g_ptr_status_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_platform_task_runs');
    }
};
