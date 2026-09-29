<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * s_skill_matrix (CRA-005) — the one competency table with no tenant column at
 * all. Every sibling table in this area (hrms_departments, s_users_skills, ...)
 * carries sub_institute_id; this one never has, so any query against it has to
 * join out to tbluser to recover which organisation a row belongs to, or it
 * silently reads across tenants.
 *
 * Nullable and indexed, same shape as hrms_departments' own sub_institute_id -
 * no foreign key to school_setup added here, on purpose: a hard FK could fail
 * this migration outright on a row whose user_id points at a tbluser with a
 * tenant id that no longer exists in school_setup, and a migration that adds a
 * missing column should not be the thing that breaks on unrelated bad data.
 *
 * Written to be idempotent (Schema::hasColumn guard), matching
 * 2026_08_04_120000_align_s_skill_matrix_with_live_schema.php in this same
 * folder. Backfills every existing row from tbluser.sub_institute_id via
 * user_id - the same join every controller reading this table already has to
 * do by hand today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('s_skill_matrix')) {
            return;
        }

        if (!Schema::hasColumn('s_skill_matrix', 'sub_institute_id')) {
            Schema::table('s_skill_matrix', function (Blueprint $table) {
                $table->unsignedBigInteger('sub_institute_id')->nullable()->index()->after('user_id');
            });
        }

        // Backfill: every existing row's tenant, recovered from its own user.
        // Safe to re-run - only touches rows still NULL.
        DB::statement(
            'UPDATE s_skill_matrix AS m '
            . 'INNER JOIN tbluser AS u ON u.id = m.user_id '
            . 'SET m.sub_institute_id = u.sub_institute_id '
            . 'WHERE m.sub_institute_id IS NULL AND u.sub_institute_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('s_skill_matrix') && Schema::hasColumn('s_skill_matrix', 'sub_institute_id')) {
            Schema::table('s_skill_matrix', function (Blueprint $table) {
                $table->dropColumn('sub_institute_id');
            });
        }
    }
};
