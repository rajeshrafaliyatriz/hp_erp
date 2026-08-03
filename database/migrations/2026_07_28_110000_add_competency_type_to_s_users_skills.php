<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `competency_type` (KASA) column to the existing skills
 * catalog (s_users_skills). A competency IS an approved skill in this ERP, and
 * the new Competency Library screen exposes a first-class "Type (KASA)" field
 * (Behaviour / Skill / Ability / Attitude / Knowledge) that had no home on the
 * table. Purely additive: nullable, indexed, defaulted to NULL, so every
 * existing consumer of s_users_skills (Skill Library, competency analytics,
 * seeders) is unaffected.
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
        Schema::table('s_users_skills', function (Blueprint $table) {
            if (!$this->hasColumnPortable('s_users_skills', 'competency_type')) {
                $table->string('competency_type', 50)->nullable()->index()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_users_skills', function (Blueprint $table) {
            if ($this->hasColumnPortable('s_users_skills', 'competency_type')) {
                $table->dropColumn('competency_type');
            }
        });
    }
};
