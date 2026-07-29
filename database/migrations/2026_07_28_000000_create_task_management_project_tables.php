<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_management_projects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30);
            $table->string('name', 191);
            $table->string('category', 50)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->unsignedBigInteger('sponsor_id')->nullable()->index();
            $table->unsignedBigInteger('manager_id')->nullable()->index();
            $table->string('team_size', 20)->nullable();
            $table->string('priority', 20)->default('Medium');
            $table->string('status', 30)->default('PLANNING');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('budget_estimate', 15, 2)->nullable();
            $table->string('client_name', 191)->nullable();
            $table->json('regulatory_flags')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('syear', 50)->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedBigInteger('archived_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['sub_institute_id', 'code'], 'tm_projects_tenant_code_unique');
        });

        Schema::create('task_management_project_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role', 30)->default('MEMBER');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('task_management_projects')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id'], 'tm_project_member_unique');
        });

        Schema::create('task_management_workstreams', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('status', 30)->default('PLANNING');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('task_management_projects')->cascadeOnDelete();
        });

        Schema::create('task_management_project_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('workstream_id')->nullable();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('task_management_projects')->cascadeOnDelete();
            $table->foreign('workstream_id')->references('id')->on('task_management_workstreams')->nullOnDelete();
            $table->unique(['project_id', 'task_id'], 'tm_project_task_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_project_tasks');
        Schema::dropIfExists('task_management_workstreams');
        Schema::dropIfExists('task_management_project_members');
        Schema::dropIfExists('task_management_projects');
    }
};
