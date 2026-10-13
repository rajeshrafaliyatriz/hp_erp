<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `task` has always been date-only (`task_date`, `planned_start_date` are
 * both `date`, never `datetime`/`time`) - there has never been a way to
 * record WHEN during the day a task happened. CRM's own equivalent entity
 * (`vtiger_activity`) carries real `time_start`/`time_end` alongside its
 * dates; this is that same capability, added the same way `visibility` and
 * `recurrence_id` were added to this table earlier in this project - a
 * purely additive, nullable column nothing existing reads or writes.
 *
 * Only the new calendar self-entry quick-add path (Phase 9.4) will ever
 * populate these. Every other reader of `task` - the legacy /task routes,
 * WorkspaceController, MyTasksController, the Dashboard, My Tasks - is
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task', 'time_start')) {
            Schema::table('task', function (Blueprint $table) {
                $table->time('time_start')->nullable()->after('task_date');
            });
        }
        if (!Schema::hasColumn('task', 'time_end')) {
            Schema::table('task', function (Blueprint $table) {
                $table->time('time_end')->nullable()->after('time_start');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task', 'time_end')) {
            Schema::table('task', fn (Blueprint $table) => $table->dropColumn('time_end'));
        }
        if (Schema::hasColumn('task', 'time_start')) {
            Schema::table('task', fn (Blueprint $table) => $table->dropColumn('time_start'));
        }
    }
};
