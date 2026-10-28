<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A custom display color per outgoing share, mirroring
 * task_management_statuses.color / task_management_priorities.color exactly
 * (same type/width) - CRM's own Shared Calendar lets an owner pick a color
 * per person they've shared with; this is that same capability. Null means
 * "no custom color" - the frontend falls back to its existing automatic
 * by-index palette.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_management_calendar_shares', 'color')) {
            Schema::table('task_management_calendar_shares', function (Blueprint $table) {
                $table->string('color', 30)->nullable()->after('can_edit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_management_calendar_shares', 'color')) {
            Schema::table('task_management_calendar_shares', fn (Blueprint $table) => $table->dropColumn('color'));
        }
    }
};
