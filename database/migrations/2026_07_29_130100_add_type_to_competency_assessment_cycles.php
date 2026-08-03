<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive `type` column on s_competency_assessment_cycles.
 *
 * The Assessment Workspace's campaigns table has always shown an "Assessment
 * Type" column, but AssessmentCycleController::index() hardcoded it to
 * 'Self + Manager' because the table had nowhere to store it. The campaign
 * detail panel's "Edit Campaign" action needs a real field to edit, so the
 * hardcoded display value becomes a real, per-campaign column.
 *
 * Nullable with no default: existing rows read NULL and the controller falls
 * back to 'Self + Manager', so the campaigns table renders exactly as before
 * until someone edits a campaign.
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
        Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
            if (!$this->hasColumnPortable('s_competency_assessment_cycles', 'type')) {
                $table->string('type', 100)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
            if ($this->hasColumnPortable('s_competency_assessment_cycles', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
