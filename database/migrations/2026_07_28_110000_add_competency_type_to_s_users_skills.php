<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    public function up(): void
    {
        Schema::table('s_users_skills', function (Blueprint $table) {
            if (!Schema::hasColumn('s_users_skills', 'competency_type')) {
                $table->string('competency_type', 50)->nullable()->index()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_users_skills', function (Blueprint $table) {
            if (Schema::hasColumn('s_users_skills', 'competency_type')) {
                $table->dropColumn('competency_type');
            }
        });
    }
};
