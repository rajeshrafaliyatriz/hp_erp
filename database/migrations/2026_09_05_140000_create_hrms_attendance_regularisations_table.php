<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance regularisation — the request an employee raises when a punch is
 * wrong or missing. F-107.
 *
 * The capability was half-built: `POST update_user_att`
 * (HrmsController@updateUserAttendance) already corrects an attendance row, and
 * nothing in the frontend called it. The control that should have was the
 * `regularize` Quick Action, whose handler was `onClick: () => {}`. Meanwhile
 * the dashboard's alert panel showed a hardcoded "Regularization Pending (1)"
 * to every employee in every tenant, for a feature that did not exist.
 *
 * This table is the missing middle: request -> approve/reject -> apply.
 *
 * Deliberately NOT a second attendance table. The approved correction is
 * written back to `hrms_attendances`, which stays the single record of when
 * people worked; this holds the ASK and its outcome, which is a different
 * thing and is what an audit trail needs.
 *
 * Columns follow the conventions of the tables around it: `sub_institute_id`
 * for tenancy, `day` (date) to match hrms_attendances, soft deletes, and
 * created_by / updated_by / deleted_by stamped from identity rather than from
 * a request parameter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_attendance_regularisations')) {
            return;
        }

        Schema::create('hrms_attendance_regularisations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            // The day being corrected. Matches hrms_attendances.day.
            $table->date('day');

            // What the employee says the times should have been. Either may be
            // null - a missing punch-out is corrected by supplying only the out.
            $table->time('requested_in_time')->nullable();
            $table->time('requested_out_time')->nullable();

            // What was actually recorded when the request was raised, captured
            // so the approver sees the before/after without re-deriving it and
            // so the trail survives later edits to the attendance row.
            $table->time('original_in_time')->nullable();
            $table->time('original_out_time')->nullable();

            $table->string('reason', 255);

            // pending | approved | rejected | cancelled
            // varchar, not enum: hrms_emp_leaves.status had to be widened by a
            // later migration for exactly this reason.
            $table->string('status', 20)->default('pending')->index();

            $table->string('reviewer_comment', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The query every screen runs: this tenant's requests for one
            // employee, newest first; and the approver queue, by status.
            $table->index(['sub_institute_id', 'user_id', 'day'], 'har_tenant_user_day_idx');
            $table->index(['sub_institute_id', 'status'], 'har_tenant_status_idx');

            // One open request per employee per day. A second ask for the same
            // day is an edit of the first, not a new row - which is what stops
            // an approver seeing three contradictory versions of one morning.
            //
            // Partial indexes do not exist in MySQL, so this is enforced in
            // AttendanceRegularisationApiController::store() as an upsert on
            // (user_id, day, status='pending') rather than as a unique key.
        });

        // No foreign keys. hrms_emp_leaves has them and one of them names the
        // WRONG PARENT TABLE - leave_type_id references tbluser (F-94) - which
        // is how 15 of 29 live rows ended up with a leave type that is actually
        // an employee. Adding constraints here would be a second chance to make
        // that mistake; tenancy and ownership are enforced in the controller,
        // where they are visible and testable.
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_attendance_regularisations');
    }
};
