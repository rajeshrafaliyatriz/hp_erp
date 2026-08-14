<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE CITATION — what each question was written against, as it stood at the time.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT THE DUPLICATION I REFUSED EARLIER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * I argued against putting `competency_id` on the question because it is
 * DERIVABLE from kasba_item_id, and two paths to the same CURRENT fact can
 * disagree. That argument stands and these columns do not contradict it.
 *
 *     kasba_item_id       the LIVE LINK. Follow it for what is true now.
 *     cited_*             the RECORD. What it was when the question was written.
 *
 * They are allowed to diverge, and the divergence is the point. If a KASBA item
 * is renamed, re-scoped or removed, a question generated against it must still be
 * able to say what it assessed — otherwise a completed assessment becomes
 * unreadable evidence, and an employee's score refers to something nobody can
 * name any more.
 *
 * A DERIVED VALUE ANSWERS "WHAT IS THIS?"; A CITATION ANSWERS "WHAT WAS ASKED?"
 * Those are different questions and only one of them can be reconstructed later.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY IT MATTERS MORE HERE THAN ANYWHERE ELSE IN THIS SYSTEM
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * These questions are MACHINE-GENERATED. When someone later asks "why was this
 * person asked this?", the answer must be in the row rather than reconstructed
 * from tables that have moved on. Provenance for generated content is not a
 * nicety; it is the difference between an assessment record and an assertion.
 *
 * The model that produced the test is already stored on
 * competency_assessment_test.model for the same reason.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ALL NULLABLE, NOTHING BACKFILLED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The tables are empty (0 questions), so there is nothing to backfill and no
 * ambiguity to refuse. Every question written from here on carries its citation
 * because generate() populates it in the same insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('competency_assessment_question', 'cited_item_label')) {
            Schema::table('competency_assessment_question', function (Blueprint $table) {
                // The item, as it read when the question was written.
                $table->string('cited_item_label', 255)->nullable()->after('kasba_item_id');
                $table->string('cited_kasba_type', 50)->nullable()->after('cited_item_label');

                // The competency it sat under. Both id AND name: the id survives a
                // rename, the name survives a deletion.
                $table->unsignedBigInteger('cited_competency_id')->nullable()->after('cited_kasba_type');
                $table->string('cited_competency_name', 191)->nullable()->after('cited_competency_id');

                // The job role the test was generated for, by name. The test row
                // already holds jobrole_id; this is the label at the time, because
                // job roles get renamed and a paper record should not silently
                // change what it says it assessed.
                $table->string('cited_jobrole', 191)->nullable()->after('cited_competency_name');

                // What the role required of this item when the question was set.
                // A score means nothing without the standard it was measured
                // against, and that standard is editable in Role Requirements.
                $table->string('cited_required_proficiency', 50)->nullable()->after('cited_jobrole');

                $table->index('cited_competency_id', 'caq_cited_competency_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('competency_assessment_question', 'cited_item_label')) {
            Schema::table('competency_assessment_question', function (Blueprint $table) {
                $table->dropIndex('caq_cited_competency_index');
                $table->dropColumn([
                    'cited_item_label',
                    'cited_kasba_type',
                    'cited_competency_id',
                    'cited_competency_name',
                    'cited_jobrole',
                    'cited_required_proficiency',
                ]);
            });
        }
    }
};
