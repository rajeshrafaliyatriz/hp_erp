<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal mobility: an employee's transfer, promotion, deputation, or
 * application to an internal posting.
 *
 * RESTORED FILE. Recorded as run in the `migrations` table with the table present
 * in the shared database, but the file was lost from the repository, so a fresh
 * `migrate` created nothing - breaking the Talent Dashboard's Mobility KPI card
 * and every /api/talent/mobility/requests endpoint on any new environment.
 *
 * Transcribed from SHOW CREATE TABLE on the live database. internal_job_id is
 * included here because it already exists on the live table; the later
 * 2026_08_01_100000 migration adds it only when absent, so the two remain
 * compatible in either order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('talent_mobility_requests')) {
            return;
        }

        Schema::create('talent_mobility_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            /**
             * The internal posting applied to, when request_type is
             * 'internal-application'. Kept separate from job_posting_id, which
             * points at talent_job_postings (the external ATS) - overloading one
             * column to mean either would corrupt both readings.
             */
            $table->unsignedBigInteger('internal_job_id')->nullable()->index();
            /** MobilityRequest::TYPES. */
            $table->string('request_type', 30)->default('transfer');
            $table->unsignedBigInteger('job_posting_id')->nullable()->index();

            // Where the employee is moving from and to. The "from" side is
            // prefilled from tbluser / org_designation at creation time so the
            // record still reads correctly after the move lands.
            $table->unsignedBigInteger('from_department_id')->nullable();
            $table->unsignedBigInteger('to_department_id')->nullable();
            $table->string('from_jobrole', 191)->nullable();
            $table->string('to_jobrole', 191)->nullable();
            $table->string('from_location', 191)->nullable();
            $table->string('to_location', 191)->nullable();

            /** MobilityRequest::STATUSES; ACTIVE_STATUSES is what the KPI counts. */
            $table->string('status', 20)->default('pending');
            $table->date('requested_on')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'status'], 'mob_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_mobility_requests');
    }
};
