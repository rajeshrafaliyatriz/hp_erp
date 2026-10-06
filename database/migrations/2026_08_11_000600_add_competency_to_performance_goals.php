<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TL-02(a) — GIVE A PERFORMANCE GOAL A COMPETENCY.
 *
 * G-FLOW-26's second broken promise: "performance reviews informed by competency"
 * existed as the WORD competency, as a dropdown label and a validator value.
 * `s_performance_goals` had no column naming a capability at all - measured, 17
 * rows, zero capability columns.
 *
 * THIS IS TL-02(a) ONLY - THE COLUMN AND THE WRITE PATH.
 *   TL-02(b) is populating it, and that is AUTHORING: someone must choose which
 *   competency a goal develops. No code produces that choice, which is why it is
 *   counted in G-PLAN-01's seven rather than scheduled as a build item.
 *
 * NULLABLE, DELIBERATELY. A goal that develops no particular competency is a
 * legitimate goal - "improve stakeholder communication" may map to one, and
 * "deliver the Q3 migration" may not. A NOT NULL column would force every author
 * to pick something, and a forced choice is worse data than an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('s_performance_goals')) {
            return;
        }
        if (Schema::hasColumn('s_performance_goals', 'competency_id')) {
            return;
        }

        Schema::table('s_performance_goals', function (Blueprint $table) {
            $table->unsignedBigInteger('competency_id')->nullable()->after('id')
                ->comment('FK -> competency.id (a KASBA BUNDLE). NEVER a skill id: a skill is one of five KASBA dimensions inside a bundle (Q-A2, G-DATA-11).');
            $table->index('competency_id', 's_performance_goals_competency_idx');
        });
    }

    /** DELIBERATELY EMPTY - dropping it would discard whatever has been authored. */
    public function down(): void
    {
    }
};
