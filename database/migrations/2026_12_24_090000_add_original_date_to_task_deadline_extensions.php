<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK DEADLINE EXTENSIONS: REMEMBER THE DATE THE TASK HAD WHEN IT WAS ASKED FOR MORE TIME.
 *
 * Approving a request overwrites task.task_date with the new date, and nothing
 * kept the old one: once a request was approved, "how many days did this extend
 * by?" and "was the task already overdue when they asked?" could not be
 * answered from the table. (The old date survives only inside the audit event's
 * payload, with no link back to the request.)
 *
 *   original_date   the task's due date at the moment of the request.
 *   days_requested  requested_date minus original_date, in days. Stored rather
 *                   than computed so the queue can filter and sort on it with
 *                   plain portable SQL.
 *
 * ADDITIVE ONLY, and nullable: rows made before this have neither until the
 * `task:backfill-extension-dates` command fills what it can (data is never
 * written inside a schema migration). No index: the queue is read inside one
 * tenant, and the existing (sub_institute_id, status) index already narrows it.
 *
 * Guarded, so it is safe on a host that already has either column. Run one
 * file at a time with --path on each host (live is MariaDB 10.1, non-strict).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_deadline_extensions')) {
            return;
        }

        Schema::table('task_deadline_extensions', function (Blueprint $table) {
            if (!Schema::hasColumn('task_deadline_extensions', 'original_date')) {
                $table->date('original_date')->nullable()->after('requested_date');
            }
            if (!Schema::hasColumn('task_deadline_extensions', 'days_requested')) {
                $table->smallInteger('days_requested')->nullable()->after('original_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('task_deadline_extensions')) {
            return;
        }

        Schema::table('task_deadline_extensions', function (Blueprint $table) {
            foreach (['days_requested', 'original_date'] as $column) {
                if (Schema::hasColumn('task_deadline_extensions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
