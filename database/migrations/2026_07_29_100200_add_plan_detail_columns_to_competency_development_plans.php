<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan-detail columns for the workspace's "Plan Overview" tab.
 *
 * The detail panel shows an Objective paragraph, a Key Focus Areas list and a
 * Linked Career Path - none of which s_competency_development_plans could hold.
 *
 * All three are nullable with no default change, so every existing consumer
 * (DevelopmentPlanController@index/store/destroy, the Command Center counts,
 * EmployeeCompetencyProfileController@developmentPlans) keeps working unchanged:
 * they select explicit columns or `*` and never insert these.
 */
return new class extends Migration
{
    /**
     * Portable column check.
     *
     * Schema::hasColumn() asks information_schema for `generation_expression`,
     * a column that only exists on MySQL >= 5.7.6 / MariaDB >= 10.2. The
     * production server runs an older engine, so that call dies there with
     * "1054 Unknown column 'generation_expression'" even though the migration
     * itself is fine. SHOW COLUMNS has existed in every MySQL/MariaDB release,
     * so this behaves identically everywhere.
     */
    private function hasColumnPortable(string $table, string $column): bool
    {
        return count(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column])) > 0;
    }

    public function up(): void
    {
        Schema::table('s_competency_development_plans', function (Blueprint $table) {
            if (!$this->hasColumnPortable('s_competency_development_plans', 'objective')) {
                $table->text('objective')->nullable()->after('title');
            }
            if (!$this->hasColumnPortable('s_competency_development_plans', 'focus_areas')) {
                // Comma-separated competency/theme labels, same style as
                // s_user_jobrole.related_jobrole.
                $table->text('focus_areas')->nullable()->after('objective');
            }
            if (!$this->hasColumnPortable('s_competency_development_plans', 'career_path_id')) {
                $table->unsignedBigInteger('career_path_id')->nullable()->index()->after('framework_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_development_plans', function (Blueprint $table) {
            foreach (['objective', 'focus_areas', 'career_path_id'] as $column) {
                if ($this->hasColumnPortable('s_competency_development_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
