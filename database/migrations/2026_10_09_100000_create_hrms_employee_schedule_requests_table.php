<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee asking to change their own working days and hours.
 *
 * ── WHY THIS IS A REQUEST AND NOT A SETTING ─────────────────────────────────
 *
 * Office hours live as 21 columns on `tbluser` - seven weekday flags and
 * fourteen `time` columns - and they are a PAYROLL INPUT.
 * `PayrollController::getSaturdayLateCount()` reads `saturday_in_date` to count
 * 2nd-Saturday lateness, and lateness is subtracted from payable days. So an
 * employee writing those columns directly would be an employee adjusting an
 * input to their own pay.
 *
 * Hence: the employee's week is stored HERE as a proposal, and only an HR
 * approval writes `tbluser`. Nothing on this table affects pay until somebody
 * with the authority to decide says so.
 *
 * ── THE SHAPE: HEADER HERE, SEVEN DETAIL ROWS NEXT DOOR ─────────────────────
 *
 * The week lives in `hrms_employee_schedule_request_days`, one row per weekday,
 * rather than as 21 columns on this row or a single JSON blob. The reasons are
 * in that migration's docblock; the shortest one is that an absent detail row
 * means "not asked about" and a NULL column cannot say that - and since the
 * approval WRITES `tbluser`, it must never clear a day the employee did not
 * mention.
 *
 * One status on the header, not per weekday: a per-day decision would let three
 * days be approved and four rejected, which produces a week nobody asked for.
 *
 * ── applied_at ──────────────────────────────────────────────────────────────
 *
 * When the `tbluser` write actually landed, which is NOT the same instant as
 * `reviewed_at` if the write fails and is retried. It is also the "as of" that
 * roster provenance is ordered by, so a later department apply can tell whether
 * it is overwriting something an employee chose.
 *
 * ── NO FOREIGN KEYS ─────────────────────────────────────────────────────────
 *
 * Same reasoning the regularisation table (2026_09_05_140000, lines 89-94) and
 * the edits table (2026_10_07_100000, lines 38-40) both record: a record of a
 * decision must survive the deletion of what it decided about, or it stops
 * being a record.
 *
 * `status` is a varchar, not an enum - also matching the regularisation table,
 * which states why at lines 60-62. Enums on these two hosts diverge from their
 * own migrations, and `config/database.php` runs with `strict => false`, so a
 * value outside an enum coerces silently rather than erroring.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-employee-schedule-requests.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_09_100000_create_hrms_employee_schedule_requests_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_100000_create_hrms_employee_schedule_requests_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_employee_schedule_requests')) {
            return;   // idempotent: this runs on two hosts
        }

        Schema::create('hrms_employee_schedule_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');

            // Whose hours. ALWAYS the caller on store() - the endpoint has no
            // parameter for anybody else, which is what makes it safe for every
            // employee to reach.
            $table->unsignedBigInteger('user_id');

            // The department whose template this was compared against when the
            // request was raised, captured so an approver six weeks later sees
            // what the employee was actually looking at. Nullable: an employee
            // may have no department.
            $table->unsignedBigInteger('department_id')->nullable();

            /*
             * Required, like every other write in this module. A standing change
             * to somebody's working week with no stated reason is the kind of
             * record that satisfies an auditor and helps nobody.
             */
            $table->string('reason', 255);

            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');

            $table->string('reviewer_comment', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // When the tbluser write landed. See the docblock.
            $table->timestamp('applied_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();

            /*
             * Soft deletes, so a withdrawal leaves a trail.
             *
             * The regularisation path chose this for the same reason: an
             * employee withdrawing a request is itself a fact, and a hard delete
             * makes "did you ever ask for this" unanswerable.
             */
            $table->softDeletes();

            // "Does this employee have something pending" - the question the
            // self-service screen asks on every load.
            $table->index(['sub_institute_id', 'user_id', 'status'], 'hesr_tenant_user_status_idx');
            // "What is waiting on me" - the approver queue.
            $table->index(['sub_institute_id', 'status'], 'hesr_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_employee_schedule_requests');
    }
};
