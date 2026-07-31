<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Onboarding Center's supporting tables: per-journey stage timeline,
 * notes, and the module's audit log.
 *
 * RESTORED FILE. Recorded as run in the `migrations` table with all three tables
 * present in the shared database, but the file was lost from the repository.
 * Transcribed from SHOW CREATE TABLE on the live database.
 *
 * These three sit alongside the core tables created by
 * 2026_07_30_120000_create_talent_onboarding_tables. None of them feeds the
 * Talent Dashboard - the dashboard reads journeys and tasks directly - but they
 * are restored together because the same loss event took all four files, and a
 * recorded-but-absent migration leaves a fresh environment silently short of
 * tables the Onboarding module writes to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('talent_onboarding_journey_stages')) {
            Schema::create('talent_onboarding_journey_stages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /** One of OnboardingJourney::STAGES, materialised as a timeline row. */
                $table->string('stage_key', 50)->index();
                $table->string('title', 191);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('talent_onboarding_notes')) {
            Schema::create('talent_onboarding_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->text('note');
                /** 'internal' keeps the note off anything the hire can see. */
                $table->string('visibility', 20)->default('internal')->index();
                /**
                 * Author name captured at write time, so the note still reads
                 * correctly if the account is later renamed or removed.
                 */
                $table->string('author_name', 191)->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('talent_onboarding_activity_log')) {
            Schema::create('talent_onboarding_activity_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /** The actor who performed the action, never the subject of it. */
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('actor_name', 191)->nullable();
                $table->string('action', 191)->index();
                $table->text('description')->nullable();

                // Polymorphic subject, stored loosely rather than as a real
                // morph so a deleted journey or task still renders in the log.
                $table->string('subject_type', 100)->nullable()->index();
                $table->unsignedBigInteger('subject_id')->nullable()->index();
                $table->string('subject_name', 191)->nullable();
                $table->json('changes')->nullable();

                $table->unsignedBigInteger('journey_id')->nullable()->index();
                // An audit row is never edited or soft-deleted, so no
                // updated_by / deleted_at here - only the write timestamps.
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_onboarding_activity_log');
        Schema::dropIfExists('talent_onboarding_notes');
        Schema::dropIfExists('talent_onboarding_journey_stages');
    }
};
