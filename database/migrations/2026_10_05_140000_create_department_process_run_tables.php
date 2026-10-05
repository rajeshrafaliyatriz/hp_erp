<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The execution engine: launching a published department process as a real,
 * trackable run.
 *
 * A run is pinned to the PUBLISHED version it started from
 * (department_process_versions.snapshot), never the live, possibly-still-
 * being-edited graph on department_process_steps/edges - so editing a
 * process after a run has started cannot retroactively change what that run
 * is executing. `department_process_run_steps` snapshots each step's
 * title/type too, for the same reason: a run's history must read the same
 * after the process it came from has since changed.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_10_05_140000_create_department_process_run_tables.php
 *     php artisan migrate --database=live --path=database/migrations/2026_10_05_140000_create_department_process_run_tables.php
 *
 * Same shape conventions as 2026_10_05_130000_create_department_process_tables.php:
 * no hard FKs, sub_institute_id stored on every table, JSON columns are
 * `longText` (MariaDB 10.1 on live).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('department_process_runs')) {
            Schema::create('department_process_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('process_id')->index();
                $table->unsignedInteger('process_version');
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                // What this run is "about" - a candidate, employee, lead,
                // ticket... Nullable: an ad-hoc run (someone just clicked
                // Start Process) is not required to name a subject.
                $table->string('subject_type', 60)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();

                $table->string('name', 191)->nullable();
                $table->enum('status', ['running', 'completed', 'cancelled'])->default('running');

                // Node keys of every step currently awaiting action - a JSON
                // array because a fan-out (one step activating two branches
                // at once) can leave more than one step "current" together.
                $table->longText('current_step_node_keys')->nullable();

                // Free-form data bag a dynamic_field assignee or a decision
                // condition can read at run time (candidate_name, amount, ...).
                $table->longText('context')->nullable();

                $table->unsignedBigInteger('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['sub_institute_id', 'department_id', 'status'], 'department_process_runs_tenant_dept_status_index');
            });
        }

        if (!$this->tableExists('department_process_run_steps')) {
            Schema::create('department_process_run_steps', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('step_node_key', 36);
                $table->string('step_title_snapshot', 191);
                $table->string('step_type_snapshot', 30);

                $table->unsignedBigInteger('assignee_user_id')->nullable()->index();
                $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped', 'cancelled'])->default('pending');

                // Set once a task-type step actually raises a row in `task` -
                // see App\Services\Tasks\TaskPublisher.
                $table->unsignedBigInteger('task_id')->nullable();

                $table->timestamp('due_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();

                // Which outgoing edge this step's completion took - matched
                // against department_process_edges.label on the published
                // snapshot. Null on a step with only one way forward.
                $table->string('outcome', 100)->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();

                $table->unique(['run_id', 'step_node_key'], 'department_process_run_steps_run_node_unique');
            });
        }

        if (!$this->tableExists('department_process_run_events')) {
            Schema::create('department_process_run_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('run_step_id')->nullable()->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('event_type', 60);
                // Null for a system-generated transition (e.g. a wait_delay
                // auto-completing via the SLA scan command).
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->longText('payload')->nullable();

                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_process_run_events');
        Schema::dropIfExists('department_process_run_steps');
        Schema::dropIfExists('department_process_runs');
    }

    /** See the identically-named method in 2026_10_05_130000_create_department_process_tables.php. */
    private function tableExists(string $table): bool
    {
        $found = DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
              LIMIT 1',
            [$table]
        );

        return $found !== [];
    }
};
