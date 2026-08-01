<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee offboarding: the exit case and its per-department clearances.
 *
 * RESTORED FILE. Recorded as run in the `migrations` table with both tables
 * present in the shared database, but the file was lost from the repository, so
 * a fresh `migrate` created neither - breaking the Talent Dashboard's
 * Offboarding KPI card and its "Clearances Pending" action item on any new
 * environment.
 *
 * Transcribed from SHOW CREATE TABLE on the live database rather than re-derived
 * from the models, so this reproduces the schema that actually exists. Note that
 * the exit interview lives on the case row (exit_interview_done / _date /
 * _notes) rather than in its own table - one home for one fact.
 *
 * Guarded with hasTable throughout, so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('talent_offboarding_cases')) {
            Schema::create('talent_offboarding_cases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /** tbluser.id of the departing employee - always known, so not nullable. */
                $table->unsignedBigInteger('employee_id')->index();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('location', 191)->nullable();

                /** OffboardingCase::EXIT_TYPES. */
                $table->string('exit_type', 30)->default('resignation');
                $table->text('exit_reason')->nullable();
                /** When notice was given; last_working_day is notice + notice period. */
                $table->date('notice_date')->nullable();
                $table->date('last_working_day')->nullable();

                /**
                 * OffboardingCase::STATUSES. Held at 20 chars, which every value
                 * in that list fits - a longer status would be truncated on
                 * write and then never match the ACTIVE_STATUSES filter the
                 * dashboard's Offboarding card counts with.
                 */
                $table->string('status', 20)->default('initiated');

                $table->boolean('exit_interview_done')->default(false);
                $table->date('exit_interview_date')->nullable();
                $table->text('exit_interview_notes')->nullable();

                $table->unsignedBigInteger('manager_id')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'offb_case_tenant_status_idx');
            });
        }

        if (!Schema::hasTable('talent_offboarding_clearances')) {
            Schema::create('talent_offboarding_clearances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('case_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /**
                 * Which department signs off: 'it' | 'finance' | 'hr' | 'admin' |
                 * 'manager' | 'library'. One case normally has five or six rows,
                 * which is why the dashboard counts clearances rather than cases.
                 */
                $table->string('department_key', 50);
                /** What the sign-off covers ("IT assets and access revocation"). */
                $table->string('label', 191);
                $table->unsignedBigInteger('owner_id')->nullable();
                /** OffboardingClearance::STATUSES. */
                $table->string('status', 20)->default('pending');
                $table->text('remarks')->nullable();
                $table->timestamp('cleared_at')->nullable();
                $table->unsignedBigInteger('cleared_by')->nullable();
                $table->unsignedInteger('sort_order')->default(0);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'offb_clear_tenant_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Clearances reference a case, so they go first.
        Schema::dropIfExists('talent_offboarding_clearances');
        Schema::dropIfExists('talent_offboarding_cases');
    }
};
