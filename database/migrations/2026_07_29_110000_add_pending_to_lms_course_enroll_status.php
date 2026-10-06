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
        /*
         * Only where the column is still an ENUM.
         *
         * This migration used to run unconditionally against a table that no
         * migration created — so on a fresh database it failed outright, and
         * the failure read like "the LMS database is not built". The missing
         * CREATE now exists (2026_07_28_000000) and defines `status` as
         * VARCHAR(20), per the house rule that adding a value to an ENUM later
         * costs an ALTER TABLE rebuild.
         *
         * So on a NEW database there is nothing to widen and this is a no-op.
         * On dev and live, where the column really is an ENUM, it adds
         * 'pending' exactly as before.
         */
        $column = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            ['lms_course_enroll', 'status']
        );

        if (! $column || strtolower($column->DATA_TYPE) !== 'enum') {
            return;
        }

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
