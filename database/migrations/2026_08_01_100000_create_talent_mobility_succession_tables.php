<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal job postings and succession planning.
 *
 * These three tables are the only part of the Talent Mobility & Succession
 * module with no table anywhere. The rest of the talent lifecycle -
 * talent_onboarding_journeys / _tasks / _documents / _journey_stages / _notes /
 * _activity_log, talent_mobility_requests, talent_offboarding_cases and
 * talent_offboarding_clearances - already exists and is deliberately NOT
 * recreated here: those tables are owned by migrations outside this branch, and
 * declaring them again would collide the moment those migrations merge.
 *
 * Every statement is guarded with hasTable / hasColumn so this is safe to run
 * against a database that already has some of it, and safe to re-run.
 *
 * internal_job_id is added to the existing talent_mobility_requests rather than
 * reusing its job_posting_id column: job_posting_id points at
 * talent_job_postings (the external ATS), and overloading it to sometimes mean
 * an internal posting would silently corrupt both readings. The column is
 * nullable and additive, so no existing row or consumer is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('talent_internal_jobs')) {
            Schema::create('talent_internal_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                /** Human reference shown in the table ("INT-2026-00018"). */
                $table->string('job_code', 60)->nullable()->index();
                $table->string('title', 191);
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('location', 191)->nullable();
                /** Pay grade / band the role sits at ("G7"). */
                $table->string('grade', 40)->nullable();
                $table->text('description')->nullable();
                $table->text('eligibility')->nullable();
                $table->unsignedInteger('positions')->default(1);
                $table->unsignedBigInteger('hiring_manager_id')->nullable();

                $table->date('posted_on')->nullable()->index();
                $table->date('closing_date')->nullable()->index();
                /** 'open' | 'in-review' | 'closed'. */
                $table->string('status', 20)->default('open')->index();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'mob_job_tenant_status_idx');
            });
        }

        if (!Schema::hasTable('talent_succession_plans')) {
            Schema::create('talent_succession_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->string('position_title', 191);
                $table->unsignedBigInteger('department_id')->nullable()->index();
                /** tbluser.id of whoever holds the role today. */
                $table->unsignedBigInteger('incumbent_id')->nullable()->index();
                $table->string('grade', 40)->nullable();
                $table->string('location', 191)->nullable();

                /**
                 * 'critical' | 'high' | 'medium' | 'low'. The Mobility screen's
                 * "Critical Roles" card counts the 'critical' rows.
                 */
                $table->string('criticality', 20)->default('medium')->index();
                /** 'high' | 'medium' | 'low' - likelihood the incumbent leaves. */
                $table->string('risk_of_loss', 20)->nullable();
                /** 'covered' | 'at-risk' | 'gap' - whether a ready successor exists. */
                $table->string('coverage_status', 20)->default('gap')->index();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'criticality'], 'succ_plan_tenant_crit_idx');
            });
        }

        if (!Schema::hasTable('talent_succession_candidates')) {
            Schema::create('talent_succession_candidates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('plan_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('employee_id')->index();

                /** 'ready-now' | 'ready-1-2-years' | 'ready-3-plus-years'. */
                $table->string('readiness', 30)->default('ready-1-2-years')->index();
                /** 9-box axes, 1-3 each, so the grid needs no extra join. */
                $table->unsignedTinyInteger('potential_score')->nullable();
                $table->unsignedTinyInteger('performance_score')->nullable();
                $table->text('development_notes')->nullable();
                $table->unsignedInteger('rank_order')->default(0);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['plan_id', 'employee_id'], 'succ_cand_plan_employee_uq');
            });
        }

        if (Schema::hasTable('talent_mobility_requests') && !Schema::hasColumn('talent_mobility_requests', 'internal_job_id')) {
            Schema::table('talent_mobility_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('internal_job_id')->nullable()->index()->after('employee_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('talent_mobility_requests') && Schema::hasColumn('talent_mobility_requests', 'internal_job_id')) {
            Schema::table('talent_mobility_requests', function (Blueprint $table) {
                $table->dropColumn('internal_job_id');
            });
        }

        Schema::dropIfExists('talent_succession_candidates');
        Schema::dropIfExists('talent_succession_plans');
        Schema::dropIfExists('talent_internal_jobs');
    }
};
