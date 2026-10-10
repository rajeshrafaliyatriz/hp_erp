<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The week inside an employee's schedule request - one row per weekday asked about.
 *
 * ── WHY SEVEN ROWS AND NOT 21 COLUMNS, OR ONE JSON BLOB ─────────────────────
 *
 * 1. **"Not requested" has to be distinguishable from "requested as off", and a
 *    NULL column cannot say which.** With `saturday`, `saturday_in_date`,
 *    `saturday_out_date` on the header row, a NULL Saturday is ambiguous between
 *    "I did not mention Saturday" and "I am asking for Saturday off". That
 *    matters because approving the request WRITES `tbluser`: a day the employee
 *    never mentioned must be left exactly as it is, and a day they asked to drop
 *    must be set to not-worked. The absence of a detail row is unambiguous; a
 *    NULL is not.
 *
 *    This is the common case, not a corner: 202 of 216 employees with a varying
 *    week vary on Saturday alone, so "Saturday only" is what most requests are.
 *
 * 2. **The weekday is a NAME, because it is also the `tbluser` column prefix.**
 *    `'saturday'` maps to `saturday`, `saturday_in_date`, `saturday_out_date`
 *    with no arithmetic. `hrms_department_schedules` made the same choice and
 *    recorded why (2026_10_07_120000, lines 38-45): two live endpoints select
 *    only `monday_in_date` and compare EVERY day of the week against it, and the
 *    Early Going Report reads Saturday's column for Thursday. Both are index
 *    arithmetic gone wrong. A name has no arithmetic to get wrong.
 *
 * 3. **A 21-column request table duplicates `tbluser`'s shape**, and that shape
 *    spreading through the schema is the thing this module keeps working to
 *    contain.
 *
 * 4. **`live` is MariaDB 10.1.48, which has no JSON column type**, so a single
 *    `week` JSON column is not available on both hosts. Detail rows are the
 *    10.1-safe normalisation.
 *
 * ── THE BEFORE-IMAGE IS HERE, NOT DERIVED LATER ─────────────────────────────
 *
 * `current_*` records what the employee's `tbluser` row said AT SUBMISSION. An
 * approver reading the request three weeks later needs to see what the employee
 * was proposing to change FROM - and by then `tbluser` may have moved, through a
 * department apply or an HR edit. Deriving it at approval time would show the
 * approver a diff the employee never saw.
 *
 * ── NULL TIMES ON A WORKING DAY ARE MEANINGFUL ──────────────────────────────
 *
 * `is_working = 1` with null times means "this day is worked, hours not stated",
 * which is a real thing to ask for and different from "not worked". The apply
 * then writes the flag and leaves the time columns alone, rather than clearing
 * somebody's hours to midnight - the same rule `DepartmentScheduleController`
 * follows for its template.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-employee-schedule-requests.sql
 *           (covers this table and its header - one reversal, one owner)
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, AFTER the header table:
 *   php artisan migrate --path=database/migrations/2026_10_09_100100_create_hrms_employee_schedule_request_days_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_100100_create_hrms_employee_schedule_request_days_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_employee_schedule_request_days')) {
            return;   // idempotent: this runs on two hosts
        }

        Schema::create('hrms_employee_schedule_request_days', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('request_id');

            // 'monday'..'sunday' - the tbluser column prefix. See the docblock.
            $table->string('weekday', 9);

            /* ---------------- what is being asked for ---------------- */

            $table->boolean('is_working')->default(true);
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();

            /* ---------------- what it was, at submission ---------------- */

            // Nullable tinyint rather than boolean: NULL means the tbluser flag
            // itself was NULL - "never set" - which is the state 2,008 of 2,283
            // active employees are in, and is different from a recorded 0.
            $table->tinyInteger('current_is_working')->nullable();
            $table->time('current_in_time')->nullable();
            $table->time('current_out_time')->nullable();

            $table->timestamps();

            // One row per weekday per request. This is what makes the writer an
            // upsert rather than something that accumulates seven more rows
            // every time the employee adjusts a pending request.
            $table->unique(['request_id', 'weekday'], 'hesrd_request_weekday_uniq');
            $table->index('request_id', 'hesrd_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_employee_schedule_request_days');
    }
};
