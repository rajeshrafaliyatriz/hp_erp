<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Priorities get a `color`, mirroring task_management_statuses.color exactly
 * (same type/width) - statuses have had one since the option-sets table was
 * created; priorities never did, which is the gap this closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_management_priorities', 'color')) {
            Schema::table('task_management_priorities', function (Blueprint $table) {
                $table->string('color', 30)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_management_priorities', 'color')) {
            Schema::table('task_management_priorities', fn (Blueprint $table) => $table->dropColumn('color'));
        }
    }
};
