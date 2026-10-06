<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single approval queue for the competency module.
 *
 * Role mappings already had one (s_competency_mapping_reviews) and it works, but
 * it is mapping-shaped: jobrole, department and framework_id are columns, so it
 * cannot carry a competency or a framework. The result was that competencies
 * self-approved through a free dropdown (every one of the 3,972 rows on this
 * tenant sits at "Approved") and frameworks could never leave draft because the
 * UI hardcoded it.
 *
 * This table is subject-agnostic so one queue can govern both, and the reader
 * unions the existing mapping reviews in rather than migrating them - that queue
 * is in use and its shape carries mapping-specific context worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('s_competency_approvals')) {
            return;
        }

        Schema::create('s_competency_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            // 'competency' -> s_users_skills, 'framework' -> s_competency_frameworks
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            // Denormalised so a rejected row still reads sensibly after the
            // subject is renamed or archived.
            $table->string('subject_name', 191)->nullable();

            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('submitted_by_name', 191)->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_name', 191)->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The queue reads by tenant + status, and a subject's history reads
            // by subject - one composite index covers both entry points.
            $table->index(['sub_institute_id', 'status'], 's_competency_approvals_tenant_status_index');
            $table->index(['subject_type', 'subject_id'], 's_competency_approvals_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_approvals');
    }
};
