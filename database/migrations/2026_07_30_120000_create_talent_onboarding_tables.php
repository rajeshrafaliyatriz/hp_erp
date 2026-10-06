<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee onboarding: the journey, its checklist and its documents.
 *
 * RESTORED FILE. This migration is recorded as run in the `migrations` table and
 * all three tables exist in the shared database, but the file itself was lost
 * from the repository, leaving the schema unreproducible - a fresh `migrate`
 * created none of these tables, so /api/talent/dashboard and every
 * /api/talent/onboarding/* endpoint failed on any new environment.
 *
 * The definitions below are transcribed from SHOW CREATE TABLE on the live
 * database, not re-derived from the models, so column widths, defaults,
 * nullability and index names match what already exists byte for byte. Every
 * statement is guarded with hasTable so re-running against a database that
 * already has these tables is a no-op.
 *
 * Read by TalentDashboardController (the Onboarding KPI card, the Onboarding
 * Progress donut and the "Onboarding Tasks Pending" action item) and by
 * OnboardingJourneyController / OnboardingTaskController /
 * OnboardingDocumentController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('talent_onboarding_journeys')) {
            Schema::create('talent_onboarding_journeys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /** Human reference ("OHB-2026-00004"), issued by nextTalentCode(). */
                $table->string('journey_code', 40)->nullable()->index();
                /**
                 * tbluser.id of the hire. Nullable because a journey can start
                 * during preboarding, before the user record is created - which
                 * is why candidate_name / _email / _phone exist alongside it.
                 */
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('offer_id')->nullable()->index();
                $table->unsignedBigInteger('application_id')->nullable()->index();
                $table->string('candidate_name', 191)->nullable();
                $table->string('candidate_email', 191)->nullable();
                $table->string('candidate_phone', 50)->nullable();

                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('location', 191)->nullable();
                $table->string('position', 191)->nullable();
                $table->date('joining_date')->nullable();

                /** OnboardingJourney::STAGES - 'preboarding' precedes joining_date. */
                $table->string('stage', 30)->default('preboarding');
                /** OnboardingJourney::STATUSES - 'completed' is the only terminal state. */
                $table->string('status', 20)->default('not-started');

                $table->unsignedBigInteger('buddy_id')->nullable();
                $table->unsignedBigInteger('manager_id')->nullable();
                /** Stamped when the journey closes; the donut's completion basis. */
                $table->date('completed_at')->nullable();

                $table->date('probation_start')->nullable();
                $table->date('probation_end')->nullable()->index();
                $table->date('extension_end')->nullable();
                /** OnboardingJourney::CONFIRMATION_STATUSES - the probation outcome. */
                $table->string('confirmation_status', 20)->default('pending')->index();
                $table->date('confirmed_on')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable()->index();
                $table->text('confirmation_notes')->nullable();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Covers the dashboard's tenant+status counts, which are the
                // hottest read on this table.
                $table->index(['sub_institute_id', 'status'], 'onb_journey_tenant_status_idx');
            });
        }

        if (!Schema::hasTable('talent_onboarding_tasks')) {
            Schema::create('talent_onboarding_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->string('title', 191);
                $table->text('description')->nullable();
                /** OnboardingTask::CATEGORIES. */
                $table->string('category', 50)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                /**
                 * Free-text owner for a task nobody holds an account for yet
                 * ("IT Helpdesk"); owner_id wins when both are set.
                 */
                $table->string('owner_label', 100)->nullable();
                $table->date('due_date')->nullable();
                /** OnboardingTask::STATUSES. */
                $table->string('status', 20)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('sort_order')->default(0);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'onb_task_tenant_status_idx');
            });
        }

        if (!Schema::hasTable('talent_onboarding_documents')) {
            Schema::create('talent_onboarding_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('document_type_id')->nullable()->index();
                $table->string('title', 191);
                $table->string('file_name', 191)->nullable();
                /** Path on the `public` disk; 500 chars to fit nested tenant folders. */
                $table->string('file_path', 500)->nullable();
                /** OnboardingDocument::STATUSES. */
                $table->string('status', 20)->default('pending')->index();
                $table->boolean('is_mandatory')->default(true);
                $table->date('due_date')->nullable();

                // The request -> submit -> verify lifecycle, each step stamped
                // separately so an audit can show when it happened.
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable()->index();

                $table->text('remarks')->nullable();
                $table->unsignedInteger('sort_order')->default(0);

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // Children first - tasks and documents both reference a journey.
        Schema::dropIfExists('talent_onboarding_documents');
        Schema::dropIfExists('talent_onboarding_tasks');
        Schema::dropIfExists('talent_onboarding_journeys');
    }
};
