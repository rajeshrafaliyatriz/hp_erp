<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring a freshly migrated database in line with the live s_skill_matrix.
 *
 * The production table carries three columns no migration ever created:
 *
 *     type       enum('skill','knowledge','ability','attitude','behaviour')
 *     behaviour  text
 *     attitude   text
 *
 * They were added directly against the database, so `php artisan migrate` on a
 * new environment produces a table that is missing them. Everything that writes
 * KASBA ratings - SkillMatrixController, EmployeeCompetencyProfileController -
 * then fails there while working in production, which is the most expensive
 * kind of bug to chase: it only reproduces where nobody is looking.
 *
 * Written to be idempotent. Production already has all three, so this is a
 * no-op there; only a fresh or lagging database is changed. That also makes it
 * safe to run against an environment whose history is unknown - which, given
 * 99 of the 309 recorded migrations no longer exist as files, is every
 * environment.
 */
return new class extends Migration
{
    private const KASBA_TYPES = ['skill', 'knowledge', 'ability', 'attitude', 'behaviour'];

    public function up(): void
    {
        if (!Schema::hasTable('s_skill_matrix')) {
            return;
        }

        Schema::table('s_skill_matrix', function (Blueprint $table) {
            // Which KASBA dimension the row records. Nullable: 23 of the 169
            // live rows predate the column and carry NULL.
            if (!Schema::hasColumn('s_skill_matrix', 'type')) {
                $table->enum('type', self::KASBA_TYPES)->nullable()->after('user_id');
            }

            // The 2025_07_02 migration added knowledge and ability but stopped
            // there; the other two halves of KASBA were added by hand later.
            if (!Schema::hasColumn('s_skill_matrix', 'behaviour')) {
                $table->text('behaviour')->nullable()->after('ability');
            }

            if (!Schema::hasColumn('s_skill_matrix', 'attitude')) {
                $table->text('attitude')->nullable()->after('behaviour');
            }
        });
    }

    /**
     * Deliberately does not drop these columns.
     *
     * They hold real data in production - attitude in 19 rows, behaviour in 20 -
     * and this migration did not create them there. A down() that dropped them
     * would destroy data it never owned.
     */
    public function down(): void
    {
        // no-op, see above
    }
};
