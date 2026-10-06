<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the Task Management API layer already selects and writes.
 *
 * MyTasksController (and the My Tasks screen it serves) were written against
 * these columns, but the migration that should have added them was never
 * committed - so every request 500ed with "Unknown column" against the live
 * table. All seven are nullable additions: 2,267 existing rows stay valid and
 * the legacy /task screens, which never touch these columns, are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task', function (Blueprint $table) {
            // Planning: when work should start, and the effort envelope.
            $table->date('planned_start_date')->nullable()->after('task_date');
            $table->decimal('estimated_hours', 8, 2)->nullable()->after('planned_start_date');
            $table->decimal('actual_hours', 8, 2)->nullable()->after('estimated_hours');
            $table->decimal('remaining_hours', 8, 2)->nullable()->after('actual_hours');

            // Definition of done, stored as a JSON array of criterion strings.
            $table->longText('acceptance_criteria')->nullable()->after('remaining_hours');

            // Why a task went ON HOLD - feeds the delay reports.
            $table->string('delay_category', 50)->nullable()->after('taskcompletation_remarks');
            $table->text('delay_reason')->nullable()->after('delay_category');
        });
    }

    public function down(): void
    {
        Schema::table('task', function (Blueprint $table) {
            $table->dropColumn([
                'planned_start_date', 'estimated_hours', 'actual_hours',
                'remaining_hours', 'acceptance_criteria', 'delay_category', 'delay_reason',
            ]);
        });
    }
};
