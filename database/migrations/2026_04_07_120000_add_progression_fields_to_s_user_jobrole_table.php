<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('s_user_jobrole')) {
            return;
        }

        if (!Schema::hasColumn('s_user_jobrole', 'job_level')) {
            Schema::table('s_user_jobrole', function (Blueprint $table) {
                $table->string('job_level', 191)->after('description')->nullable();
            });
        }

        if (!Schema::hasColumn('s_user_jobrole', 'has_vertical_progression')) {
            Schema::table('s_user_jobrole', function (Blueprint $table) {
                $table->boolean('has_vertical_progression')->after('job_level')->nullable();
            });
        }

        if (!Schema::hasColumn('s_user_jobrole', 'has_lateral_movement')) {
            Schema::table('s_user_jobrole', function (Blueprint $table) {
                $table->boolean('has_lateral_movement')->after('has_vertical_progression')->nullable();
            });
        }

        if (!Schema::hasColumn('s_user_jobrole', 'progression_type')) {
            Schema::table('s_user_jobrole', function (Blueprint $table) {
                $table->string('progression_type', 191)->after('has_lateral_movement')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('s_user_jobrole')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('s_user_jobrole', 'job_level') ? 'job_level' : null,
            Schema::hasColumn('s_user_jobrole', 'has_vertical_progression') ? 'has_vertical_progression' : null,
            Schema::hasColumn('s_user_jobrole', 'has_lateral_movement') ? 'has_lateral_movement' : null,
            Schema::hasColumn('s_user_jobrole', 'progression_type') ? 'progression_type' : null,
        ]));

        if (!empty($columns)) {
            Schema::table('s_user_jobrole', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
