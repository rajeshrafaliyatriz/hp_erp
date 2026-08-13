<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROUND AND CYCLE KEYS — both nullable, both additive, neither backfilled.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOTE 1 — WHY THE 108 EXISTING ROWS HAVE round = NULL, AND MUST KEEP IT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * No round marker exists in this data. Tested against `due_date` and REFUSED:
 * every assessor of the same participant holds a DIFFERENT due-date set, so
 * due_date is a personal deadline, not a round. These rows are historical
 * assessments with no recoverable round. NULL IS THE CORRECT VALUE.
 *
 *     DO NOT BACKFILL IT.
 *
 * The measurement, so the refusal can be re-checked rather than trusted:
 *
 *     participants whose 4 assessors share one due-date set   0 of 8
 *     cycle 37    93 rows, 61 distinct due_dates
 *     cycle 39    47 rows, 38 distinct due_dates
 *
 * Backfilling round from due_date would have manufactured 61 rounds out of 93
 * rows and called it structure. A round is something a panel works to TOGETHER;
 * one date per row is not a rhythm.
 *
 * This note exists because a nullable column with 108 NULLs reads like an
 * UNFINISHED BACKFILL, and that is exactly the state that invites someone to
 * finish it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOTE 2 — THERE IS NO NATURAL KEY ON s_competency_assessments
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Anyone reaching for one later needs to know there is not one to reach for:
 *
 *     rows                                             140
 *     distinct (cycle_id, user_id, assessor_id)         32   -> 108 rows over
 *     distinct (cycle_id, user_id, assessor_id, due_date) 138 -> STILL COLLIDES
 *
 * Even the four-column combination collides twice. NO UNIQUE CONSTRAINT IS
 * ADDED HERE, and the one previously proposed on (cycle, user, assessor) was
 * the WRONG FIX — it would have required destroying 108 real assessment
 * records. The 108 are ROUNDS, not duplicates: they diverge on score, status,
 * review_status, due_date and completed_at, and were created a median of 51
 * days apart. Not one group is a repeated write.
 *
 *     ANY FUTURE UNIQUENESS CONSTRAINT ON THIS TABLE MUST INCLUDE THE ROUND KEY.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOTE 3 — THE 140 EXISTING SCORES ARE HISTORICAL AND NOT DERIVED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `score` is computed from item ratings for anything written from here on.
 * The 140 already present are HELD AS-IS. Their provenance is unknown, and
 * recomputing them would manufacture agreement with a formula that did not
 * produce them.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY cycle_id GOES ON competency_kasba_rating AND NOT competency_id HERE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * s_competency_assessments stays the PARTICIPANT record; competency_kasba_rating
 * carries the PER-ITEM ratings. Adding competency_id to the participant record
 * would fork the rating story into two paths that look equivalent and are not —
 * which is how the name-join got into s_user_jobrole_task and cost 80,064 rows
 * to remove. One nullable cycle_id is what makes a rating belong to a campaign;
 * every rating already recorded stays valid as a role-based rating with NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('s_competency_assessments', 'round')) {
            Schema::table('s_competency_assessments', function (Blueprint $table) {
                // Nullable on purpose. See NOTE 1 - NULL means "historical, no
                // recoverable round", not "not yet backfilled".
                $table->unsignedInteger('round')->nullable()->after('cycle_id');
                $table->index(['cycle_id', 'round'], 's_comp_assessments_cycle_round_index');
            });
        }

        if (!Schema::hasColumn('competency_kasba_rating', 'cycle_id')) {
            Schema::table('competency_kasba_rating', function (Blueprint $table) {
                // NULL = a role-based rating, not part of any campaign. That is a
                // real and permanent state, not a gap: rating someone against
                // their job role's requirements is a valid path in its own right.
                $table->unsignedBigInteger('cycle_id')->nullable()->after('kasba_item_id');
                $table->unsignedInteger('round')->nullable()->after('cycle_id');
                $table->index(['cycle_id', 'round'], 'kasba_rating_cycle_round_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('s_competency_assessments', 'round')) {
            Schema::table('s_competency_assessments', function (Blueprint $table) {
                $table->dropIndex('s_comp_assessments_cycle_round_index');
                $table->dropColumn('round');
            });
        }

        if (Schema::hasColumn('competency_kasba_rating', 'cycle_id')) {
            Schema::table('competency_kasba_rating', function (Blueprint $table) {
                $table->dropIndex('kasba_rating_cycle_round_index');
                $table->dropColumn(['cycle_id', 'round']);
            });
        }
    }
};
