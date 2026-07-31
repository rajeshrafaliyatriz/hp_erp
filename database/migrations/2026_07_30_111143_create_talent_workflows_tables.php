<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talent Management workflows configuration.
 *
 * Configures workflows (like 'Job Requisition Approval'), their stages,
 * and the specific approvers tied to those workflows. Used by the Talent
 * Administration & Governance module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('talent_workflows')) {
            Schema::create('talent_workflows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->index();
                
                $table->string('name', 191);
                $table->string('module', 50)->index(); // e.g., 'Recruitment', 'Onboarding'
                $table->string('status', 20)->default('Draft')->index(); // 'Active', 'Draft', 'Inactive'
                $table->string('version', 20)->default('1.0');
                $table->text('description')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('talent_workflow_stages')) {
            Schema::create('talent_workflow_stages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workflow_id')->index();
                $table->unsignedInteger('step');
                $table->string('label', 191);

                $table->timestamps();
            });
        }

        if (!Schema::hasTable('talent_workflow_approvers')) {
            Schema::create('talent_workflow_approvers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workflow_id')->index();
                
                $table->string('role', 191); // e.g., 'Manager', 'HR'
                $table->string('title', 191)->nullable(); // 'Immediate Manager'
                $table->string('approval_type', 50)->default('Mandatory'); // 'Mandatory', 'Optional'
                $table->string('escalation', 50)->nullable(); // 'After 2 Days'
                $table->unsignedInteger('sort_order')->default(0);

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_workflow_approvers');
        Schema::dropIfExists('talent_workflow_stages');
        Schema::dropIfExists('talent_workflows');
    }
};
