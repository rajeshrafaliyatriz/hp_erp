<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE DEPENDENCY FORM'S PROJECT AND WORKSTREAM WERE BEING THROWN AWAY.
 *
 * The Create Dependency modal has always asked for a Project and a Workstream,
 * and the client has always sent them — but `task_management_dependencies` has
 * no such columns, and the controller's validator and payload never mentioned
 * them. Both values were silently discarded on every save. The screen then
 * showed a `project` on each row anyway, derived by joining
 * task_management_project_tasks on the SUCCESSOR task, which is why nobody
 * noticed the stored value was missing.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THIS CREATES A SECOND SOURCE FOR ONE FACT — AND THAT IS THE RISK
 * ═══════════════════════════════════════════════════════════════════════
 *
 * A dependency's project is DERIVABLE from its tasks. Storing it as well means
 * the two can disagree. The controller therefore validates the submitted
 * project against the project both tasks actually share and refuses a mismatch,
 * so the column can only ever hold the same answer the join would give.
 *
 * The stored value earns its place by being usable in a WHERE clause: the
 * derived form needs a join through a table with no tenant column, which is
 * exactly the kind of query that leaks across tenants when written carelessly.
 *
 * BACKFILL: existing rows get the project their successor task belongs to —
 * the same value the read path was already displaying, so nothing on screen
 * changes for historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_management_dependencies', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('successor_task_id')->index();
            $table->unsignedBigInteger('workstream_id')->nullable()->after('project_id')->index();
        });

        // Backfill from the successor's project link. MIN() because the link
        // table permits a task in several projects; the read path already
        // collapses it the same way.
        DB::statement("
            UPDATE task_management_dependencies d
            JOIN (
                SELECT task_id, MIN(project_id) AS project_id
                FROM task_management_project_tasks
                GROUP BY task_id
            ) pt ON pt.task_id = d.successor_task_id
            SET d.project_id = pt.project_id
            WHERE d.project_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('task_management_dependencies', function (Blueprint $table) {
            $table->dropColumn(['project_id', 'workstream_id']);
        });
    }
};
