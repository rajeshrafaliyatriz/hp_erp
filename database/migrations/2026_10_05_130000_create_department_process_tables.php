<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backing storage for the Process tab's builder: a department defines a named
 * process as a graph of steps (department_process_steps) connected by edges
 * (department_process_edges), and publishing snapshots that graph into
 * department_process_versions.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_10_05_130000_create_department_process_tables.php
 *     php artisan migrate --database=live --path=database/migrations/2026_10_05_130000_create_department_process_tables.php
 *
 * Shape notes, consistent with department_sops/policies/rules
 * (see 2026_08_21_091000_create_department_content_tables.php):
 *
 * - No foreign keys on department_id, process_id, or the linked SOP/Policy/
 *   Rule columns. hrms_departments and department_sops/policies/rules are all
 *   soft-deleted and their rows get merged/retired over time; a hard FK turns
 *   each of those routine operations into a failed write. Ownership is
 *   verified in the controller instead (departmentBelongsToTenant()).
 *
 * - sub_institute_id is stored on every table even though every row is
 *   reachable through process_id -> department_id. Keeping it local means a
 *   query cannot leak across tenants by forgetting a join.
 *
 * - JSON-shaped columns (trigger_config, canvas_meta, config, condition,
 *   snapshot) are `longText`, not the native `json` column type. Live runs
 *   MariaDB 10.1, which Laravel 11's JSON column support does not target.
 *
 * - department_process_steps/edges carry no soft-delete column. They are not
 *   an independent resource - they are the working copy of one process's
 *   graph, replaced wholesale on every canvas save (see
 *   DepartmentProcessController::updateCanvas()). History lives in
 *   department_process_versions instead, which snapshots the whole graph at
 *   publish time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('department_processes')) {
            Schema::create('department_processes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('name', 191);
                $table->string('code', 50)->nullable();
                $table->string('category', 100)->nullable()->index();
                $table->text('description')->nullable();

                $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
                $table->unsignedInteger('current_version')->default(0);

                // How a run of this process begins. Only "manual" (the Start
                // Process button) is wired up at first; event/scheduled are
                // reserved so trigger_config has somewhere to live once a
                // process can be launched by an event or a cron.
                $table->enum('trigger_type', ['manual', 'event', 'scheduled'])->default('manual');
                $table->longText('trigger_config')->nullable();

                // Canvas zoom/pan defaults etc. - presentation only, never
                // read by the execution engine.
                $table->longText('canvas_meta')->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(
                    ['sub_institute_id', 'department_id', 'deleted_at'],
                    'department_processes_tenant_dept_index'
                );
            });
        }

        if (!$this->tableExists('department_process_steps')) {
            Schema::create('department_process_steps', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('process_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                // Client-assigned, stable across edits - the canvas, the edges
                // table, and run-step snapshots all address a step by this key
                // rather than the database id, so a step keeps its identity
                // through a save even though update() replaces rows wholesale.
                $table->string('node_key', 36);

                // Open string, not an enum: config/department_processes.php
                // is the editable source of truth for which step types the
                // builder's palette offers, the same way document_type is an
                // open column driven by config/documents.php rather than a
                // locked lookup table.
                $table->string('step_type', 30);

                $table->string('title', 191);
                $table->text('description')->nullable();

                $table->string('assignee_type', 30)->nullable();
                $table->string('assignee_value', 191)->nullable();

                $table->unsignedInteger('sla_value')->nullable();
                $table->string('sla_unit', 10)->nullable();

                // References into the department's own SOPs/Policies/Rules
                // tabs - the mechanism that makes "a policy is part of a
                // process" true without copying policy content into the step.
                $table->unsignedBigInteger('linked_sop_id')->nullable()->index();
                $table->unsignedBigInteger('linked_policy_id')->nullable()->index();
                $table->unsignedBigInteger('linked_rule_id')->nullable()->index();

                // Step-type-specific detail: approval thresholds, decision
                // branch config, notification template key, wait duration,
                // sub-process id.
                $table->longText('config')->nullable();

                $table->decimal('position_x', 10, 2)->default(0);
                $table->decimal('position_y', 10, 2)->default(0);

                $table->boolean('is_required')->default(true);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['process_id', 'node_key'], 'department_process_steps_process_node_unique');
            });
        }

        if (!$this->tableExists('department_process_edges')) {
            Schema::create('department_process_edges', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('process_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('source_node_key', 36);
                $table->string('target_node_key', 36);

                // E.g. "Approved" / "Rejected" / "Yes" / "No" - what a
                // decision or approval step's outgoing edges are chosen by.
                // Null on an ordinary linear connection.
                $table->string('label', 100)->nullable();
                $table->longText('condition')->nullable();
                $table->unsignedInteger('order')->default(0);

                $table->timestamps();
            });
        }

        if (!$this->tableExists('department_process_versions')) {
            Schema::create('department_process_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('process_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->unsignedInteger('version_number');

                // Full {steps:[...], edges:[...], meta:{...}} at publish time.
                // A run pins this version_number so editing the process later
                // never changes what an in-flight run is executing.
                $table->longText('snapshot');
                $table->longText('diff_summary')->nullable();

                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['process_id', 'version_number'], 'department_process_versions_process_version_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_process_versions');
        Schema::dropIfExists('department_process_edges');
        Schema::dropIfExists('department_process_steps');
        Schema::dropIfExists('department_processes');
    }

    /**
     * Not Schema::hasTable() - see the note of the same name in
     * 2026_08_21_091000_create_department_content_tables.php. Live runs
     * MariaDB 10.1, whose information_schema.columns lacks a column Laravel
     * 11's schema introspection selects, so Schema::hasTable() fails there
     * before this migration's own logic runs.
     */
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
