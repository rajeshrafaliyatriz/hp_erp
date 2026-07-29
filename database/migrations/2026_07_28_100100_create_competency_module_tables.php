<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competency Management domain tables.
 *
 * Backs the Competency Command Center dashboard (and the sibling competency
 * screens) with real, tenant-scoped data. Prior to this the whole module was a
 * read-only analytics layer over the Skill / Job-Role / Task tables with no
 * domain models of its own.
 *
 * Conventions mirror the 2026 HRMS/Leave migrations: bigIncrements PK,
 * indexed unsignedBigInteger sub_institute_id, nullable indexed audit columns,
 * timestamps + softDeletes, string-based status enums, and loose joins
 * (jobrole string / department_id int) with NO foreign-key constraints — the
 * same style s_user_jobrole / s_jobrole_skills already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NB: "Competencies" are intentionally NOT a new table. In this ERP a
        // competency IS an approved skill (existing s_users_skills catalog), and
        // the competency analytics already treat skills as competencies. The
        // command center reads/writes s_users_skills for the competency concept,
        // so only the tables below (which have no existing equivalent) are new.

        // 1. Competency frameworks -------------------------------------------
        Schema::create('s_competency_frameworks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('version', 30)->default('v1.0');
            // draft | active | archived
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('jobrole', 191)->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Framework -> competency mapping ---------------------------------
        Schema::create('s_competency_framework_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('framework_id')->index();
            $table->unsignedBigInteger('competency_id')->index();
            $table->string('required_proficiency', 50)->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Assessment cycles (drives the Assessment Calendar bar) ----------
        Schema::create('s_competency_assessment_cycles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // scheduled | active | closed
            $table->string('status', 30)->default('scheduled')->index();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 5. Assessments ------------------------------------------------------
        Schema::create('s_competency_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('title', 191);
            $table->unsignedBigInteger('framework_id')->nullable()->index();
            $table->unsignedBigInteger('cycle_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // employee assessed (tbluser.id)
            $table->unsignedBigInteger('assessor_id')->nullable();        // tbluser.id of assessor
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('jobrole', 191)->nullable();
            // open | in_progress | completed | overdue
            $table->string('status', 30)->default('open')->index();
            // null | pending_review | reviewed
            $table->string('review_status', 30)->nullable()->index();
            $table->decimal('score', 5, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 6. Certifications ---------------------------------------------------
        Schema::create('s_competency_certifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('name', 191);
            $table->unsignedBigInteger('user_id')->nullable()->index();   // employee (tbluser.id)
            $table->unsignedBigInteger('competency_id')->nullable()->index();
            $table->string('issuing_body', 191)->nullable();
            $table->string('credential_id', 191)->nullable();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('jobrole', 191)->nullable();
            // valid | expiring | expired | revoked
            $table->string('status', 30)->default('valid')->index();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 7. Development plans ------------------------------------------------
        Schema::create('s_competency_development_plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('title', 191);
            $table->unsignedBigInteger('user_id')->nullable()->index();   // employee (tbluser.id)
            $table->unsignedBigInteger('competency_id')->nullable()->index();
            $table->unsignedBigInteger('framework_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('jobrole', 191)->nullable();
            // active | completed | overdue | on_hold
            $table->string('status', 30)->default('active')->index();
            $table->unsignedTinyInteger('progress')->default(0);          // 0..100
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('approver_id')->nullable()->index();
            // null | pending_approval | approved | rejected
            $table->string('approval_status', 30)->nullable()->index();
            $table->unsignedBigInteger('mentor_id')->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 8. Activity log (drives the Recent Activity feed) ------------------
        Schema::create('s_competency_activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // actor (tbluser.id)
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 191);
            $table->text('description')->nullable();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_activity_log');
        Schema::dropIfExists('s_competency_development_plans');
        Schema::dropIfExists('s_competency_certifications');
        Schema::dropIfExists('s_competency_assessments');
        Schema::dropIfExists('s_competency_assessment_cycles');
        Schema::dropIfExists('s_competency_framework_items');
        Schema::dropIfExists('s_competency_frameworks');
    }
};
