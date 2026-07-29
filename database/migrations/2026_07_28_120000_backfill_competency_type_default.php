<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the newly-added s_users_skills.competency_type (KASA) column for
 * pre-existing rows. Every row in s_users_skills is, by definition, a Skill in
 * the KSAAB model, so 'Skill' is the correct default classification for legacy
 * competencies that never had a type. New competencies created/edited via the
 * Competency Library set their own type (defaulting to 'Skill' when omitted).
 *
 * Data-only migration: touches just the new column's NULLs, no schema change.
 * down() is intentionally a no-op — once set, backfilled and user-set 'Skill'
 * values are indistinguishable, so reversing would risk clobbering real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('s_users_skills')
            ->whereNull('competency_type')
            ->update(['competency_type' => 'Skill']);
    }

    public function down(): void
    {
        // Intentionally left blank (see class docblock).
    }
};
