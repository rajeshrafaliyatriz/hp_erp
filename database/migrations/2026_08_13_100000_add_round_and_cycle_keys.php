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
 * NOTE 2 — CORRECTED. A NATURAL KEY DOES EXIST, AND IT CONTAINS NO ROUND.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THIS NOTE ORIGINALLY SAID THERE WAS NO NATURAL KEY. THAT WAS WRONG, and it is
 * corrected here rather than deleted because a wrong note gets trusted:
 *
 *     rows                                                        140
 *     distinct (cycle, user, assessor)                             32   108 collide
 *     distinct (cycle, user, assessor, framework_id)              130    10 collide
 *     distinct (cycle, user, assessor, framework_id, due_date)    140     0 collide
 *
 *     -> (cycle_id, user_id, assessor_id, framework_id, due_date) IS UNIQUE
 *        across all 140 rows. framework_id accounts for 98 of the 108
 *        collisions; due_date closes the remaining 10.
 *
 * THE KEY CONTAINS NO ROUND. A cycle spans many frameworks - 24 in cycle 37, 20
 * in cycle 39, and 6 to 19 for a single person inside a single cycle - so the
 * rows that looked like repeated assessments are mostly PER-FRAMEWORK
 * assessments, discriminated by a column that was already in the table.
 *
 * The 108 are still NOT duplicates: they diverge on score, status,
 * review_status, due_date and completed_at, created a median of 51 days apart,
 * and not one group is a repeated write. The constraint once proposed on
 * (cycle, user, assessor) was still the WRONG FIX and would still have
 * destroyed 98 per-framework and 10 further real records.
 *
 * NO UNIQUE CONSTRAINT IS ADDED HERE. If one is wanted later it is the
 * five-column key above - NOT one including `round`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NOTE 2b — WHY `round` EXISTS ANYWAY, AND WHY IT IS UNUSED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *     `round` WAS JUSTIFIED BY 108 UNEXPLAINED ROWS.
 *     98 OF THEM WERE EXPLAINED BY framework_id, ALREADY PRESENT IN THE TABLE.
 *
 * The justification did not survive the next measurement. The column is RETAINED
 * NULLABLE AND UNUSED rather than reversed the same day it was added, because a
 * same-day schema reversal costs more than an unused nullable column.
 *
 *     IT IS UNUSED. NOTHING WRITES IT AND NOTHING READS IT.
 *     DO NOT INFER A MEANING FROM ITS EXISTENCE.
 *
 * HOW THE ERROR HAPPENED, recorded because the shape recurs: DIVERGENCE WAS READ
 * AS EVIDENCE FOR A SPECIFIC CAUSE. The rows differed, the difference was real,
 * and the conclusion named a mechanism the data never pointed to. Divergence
 * proved they were not duplicates; IT NEVER PROVED THEY WERE ROUNDS.
 *
 *     GUARD: what else varies between these rows that I have not checked?
 *     Ask it BEFORE naming a cause, not after.
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
 *
 *     cycle_id ON THE RATINGS TABLE STANDS. The reasoning above is unaffected.
 *
 * BUT THE JUSTIFICATION FOR `round` ON THAT TABLE IS VOID.
 *
 * It was approved on the reasoning that a rating belonging to a campaign belongs
 * to a ROUND of that campaign. Rounds have since stopped existing (NOTE 2). The
 * discriminator between assessments of one person in one cycle is FRAMEWORK, not
 * round.
 *
 *     competency_kasba_rating.round is UNUSED and its stated reason is WITHDRAWN.
 *     It is NOT dropped this turn, for the same same-day-reversal reason.
 *
 * OPEN, AND FLAGGED RATHER THAN DECIDED: whether that column should carry
 * framework_id instead. A rating needs to say which assessment it came from, and
 * on this evidence framework is what identifies one. It is not changed here
 * because swapping a column's meaning is exactly the move that put a wrong note
 * in this file in the first place - the second measurement is what should decide
 * it, not the first correction.
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
