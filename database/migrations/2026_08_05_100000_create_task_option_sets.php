<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant custom statuses and priorities for the task module.
 *
 * The four system statuses (PENDING / IN-PROGRESS / ON HOLD / COMPLETED) stay
 * the workflow engine: boards, summaries, approvals and the transition rules
 * all key off them, and task.status keeps storing them. A custom status is a
 * tenant-defined LABEL mapped onto one of those categories - "In Review" can
 * live on top of ON HOLD without breaking a single existing query. The label
 * a task was given is kept on task.status_label.
 *
 * Priorities are simpler: task.task_type already stores a free string, so a
 * custom priority is just a validated, ordered name with an optional SLA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_statuses')) {
            Schema::create('task_management_statuses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('name', 100);
                // Which system status this label behaves as.
                $table->string('category', 20); // PENDING | IN-PROGRESS | ON HOLD | COMPLETED
                $table->string('color', 30)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['sub_institute_id', 'name'], 'tm_statuses_tenant_name_unique');
            });
        }

        if (!Schema::hasTable('task_management_priorities')) {
            Schema::create('task_management_priorities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('name', 100);
                // Lower sorts first; system High/Medium/Low occupy 10/20/30.
                $table->unsignedInteger('sort_order')->default(50);
                $table->unsignedInteger('sla_hours')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['sub_institute_id', 'name'], 'tm_priorities_tenant_name_unique');
            });
        }

        if (!Schema::hasColumn('task', 'status_label')) {
            Schema::table('task', function (Blueprint $table) {
                // The tenant's display label for the status; null means the
                // system category name itself.
                $table->string('status_label', 100)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_priorities');
        Schema::dropIfExists('task_management_statuses');

        if (Schema::hasColumn('task', 'status_label')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropColumn('status_label');
            });
        }
    }
};
