<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled/emailed report runs (Docs/reports-engine/PLAN.md, Phase 4).
 *
 * `sub_institute_id` is denormalized onto this table (not read via a join
 * to `reports`) so the scheduled command's own "which schedules are due"
 * query stays a single tenant-scoped table scan, matching every other
 * module's own reporting tables in this engine.
 *
 * `recipients` is a JSON array of plain email strings, not a FK to
 * `tbluser` — mirrors the "Email Reports" folder's own `crm_emails.
 * to_address` precedent (a raw address, not a user reference), since a
 * report is just as likely to go to an external stakeholder with no login
 * as to an internal user.
 *
 * `last_sent_at` doubles as the idempotency guard the scheduled command
 * claims against (same atomic-update-returns-1 pattern
 * App\Services\Crm\CrmOutreachMailer uses for Queued -> Sending) — not a
 * separate "is_sending" flag, since "already sent today" is exactly the
 * condition that must hold to skip a schedule twice in the same run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('frequency', 20); // daily|weekly|monthly
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday..6=Saturday, weekly only
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-31, monthly only (clamped to the month's real length at run time)
            $table->time('time_of_day')->default('07:00:00');
            $table->text('recipients'); // JSON array of email strings
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->index(['is_active', 'frequency']);
            $table->index(['sub_institute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
