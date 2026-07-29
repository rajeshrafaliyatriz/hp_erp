<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow an enrolment to sit awaiting approval.
 *
 * The Course Builder can mark a course "Approval Required", but
 * lms_course_enroll.status was enum('in-progress','completed','enrolled') -
 * there was no value meaning "asked, not yet granted", so an approval-gated
 * course had nowhere to put the request and enrolled the learner immediately.
 *
 * Purely additive: the three existing values keep their meaning and no row
 * changes. MySQL appends the new member without rewriting existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `lms_course_enroll`
             MODIFY `status` ENUM('in-progress','completed','enrolled','pending')
             NOT NULL DEFAULT 'enrolled'"
        );
    }

    public function down(): void
    {
        // Anything still pending becomes a plain enrolment rather than being
        // lost, since the column can no longer represent it.
        DB::table('lms_course_enroll')->where('status', 'pending')->update(['status' => 'enrolled']);

        DB::statement(
            "ALTER TABLE `lms_course_enroll`
             MODIFY `status` ENUM('in-progress','completed','enrolled')
             NOT NULL DEFAULT 'enrolled'"
        );
    }
};
