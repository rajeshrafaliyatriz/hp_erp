<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendees (accept-only invitations, matching CRM — no decline/tentative;
 * CRM's own invite flow never built either) and the `ical_uid` columns
 * .ics import/export needs for dedupe.
 *
 * No library: this codebase has no iCalendar package installed and no
 * network access to add one mid-session, so CalendarIcsService hand-writes
 * RFC 5545 text directly — the same choice CRM itself made
 * (generateIcsAttachment() is custom code there too, not a library).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_calendar_attendees')) {
            Schema::create('task_management_calendar_attendees', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('event_id');
                // Exactly one of these two is set - enforced in the service,
                // not the schema, matching this family's no-cross-table-FK
                // precedent (a check constraint would need MariaDB 10.2+;
                // live runs 10.1.48).
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('external_email', 191)->nullable();
                $table->string('status', 10)->default('invited'); // invited | accepted
                $table->string('accept_token', 64)->nullable()->unique();
                $table->dateTime('responded_at')->nullable();
                $table->timestamps();

                $table->index('event_id', 'tm_cal_att_event_idx');
                $table->foreign('event_id', 'tm_cal_att_event_fk')
                    ->references('id')->on('task_management_calendar_events')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('task_management_calendar_events', 'ical_uid')) {
            Schema::table('task_management_calendar_events', function (Blueprint $table) {
                $table->string('ical_uid', 191)->nullable()->after('recurrence_id');
            });
        }
        if (!Schema::hasColumn('task', 'ical_uid')) {
            Schema::table('task', function (Blueprint $table) {
                $table->string('ical_uid', 191)->nullable()->after('visibility');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task', 'ical_uid')) {
            Schema::table('task', fn (Blueprint $table) => $table->dropColumn('ical_uid'));
        }
        if (Schema::hasColumn('task_management_calendar_events', 'ical_uid')) {
            Schema::table('task_management_calendar_events', fn (Blueprint $table) => $table->dropColumn('ical_uid'));
        }
        Schema::dropIfExists('task_management_calendar_attendees');
    }
};
