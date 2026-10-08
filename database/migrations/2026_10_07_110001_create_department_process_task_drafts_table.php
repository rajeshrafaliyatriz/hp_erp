<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What "Assign and Publish" on a converted department process has raised.
 *
 * Mirrors `g2g_process_task` (Platform Services' equivalent, see
 * 2026_09_26_130000_create_g2g_process_tables.php) column-for-column - same
 * publisher (App\Services\Tasks\TaskPublisher), same reason to exist: a
 * second publish of the same process must tell "already raised" from
 * "not raised yet" per task-ref, not re-create a row every time the button
 * is pressed. `department_id` is carried here because `task` itself has no
 * department_id column (confirmed before writing this migration) - this
 * table is the only place a published task's department is recorded.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_10_07_110001_create_department_process_task_drafts_table.php
 *     php artisan migrate --database=live --path=database/migrations/2026_10_07_110001_create_department_process_task_drafts_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('department_process_task_drafts')) {
            return;
        }

        Schema::create('department_process_task_drafts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('process_id')->index();
            $table->unsignedBigInteger('department_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();

            // The row in `task` this publish created.
            $table->unsignedBigInteger('task_id');

            // Which derived task draft it came from - e.g. "precondition-1",
            // "gate-3", "step-2", "handover-1" - so a re-publish can tell what
            // it has already raised rather than raising it again.
            $table->string('task_ref', 64);

            // readiness | human_gate | workflow_step | handover
            $table->string('category', 30);

            $table->string('title', 255);
            $table->unsignedBigInteger('assignee_id')->nullable();

            // The key passed to TaskPublisher::publish() - kept so a retried
            // request can be proven to have replayed rather than duplicated.
            $table->string('idempotency_key', 100);

            $table->timestamps();

            $table->unique(['process_id', 'task_ref'], 'dept_process_task_drafts_process_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_process_task_drafts');
    }

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
