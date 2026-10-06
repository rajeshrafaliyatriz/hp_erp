<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * framework_id ON competency — the link that did not exist.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Measured before writing this: `competency` HAS NO FRAMEWORK COLUMN AT ALL.
 *
 *     tenant 1    s_competency_frameworks   24
 *                 competency               199
 *                 anything joining them      0 — no column, no pivot
 *
 * A framework appeared only on s_competency_assessments, so "add a framework"
 * organised nothing: it produced a second list beside the competency library
 * rather than a structure over it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A COLUMN AND NOT A PIVOT TABLE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A competency belongs to ONE framework. "Risk Assessment" sits inside the
 * Enterprise Risk Management framework; it is not simultaneously a member of
 * three. A pivot would permit a shape the domain does not have, and every read
 * downstream would then have to decide which of several frameworks to report -
 * a question with no correct answer.
 *
 * If a competency ever genuinely needs to appear under two frameworks, that is a
 * REUSE relationship and deserves its own explicit table then. It is not
 * something to leave a door open for now: an unused many-to-many is read as
 * permission to create the ambiguity.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NULLABLE, ADDITIVE, AND NOTHING IS BACKFILLED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * All 199 existing competencies keep framework_id = NULL. That is a real state -
 * "not filed under a framework" - and not an unfinished migration.
 *
 * NAME MATCHING IS NOT ATTEMPTED, AND THE REASON IS SPECIFIC: the frameworks
 * themselves are duplicated. 24 framework ids carry only 13 DISTINCT NAMES,
 * imported twice six months apart (2026-01-10 and 2026-07-15):
 *
 *     Assurance Competency Framework          ids 316 and 329
 *     Internal Audit Competency Framework     ids 322 and 335
 *     ...11 names doubled in total
 *
 * Matching a competency to a framework BY NAME would therefore have to choose
 * between two equally valid ids, and would choose silently. That is the same
 * refusal made for F-10's ambiguous pairs and for `round` from due_date: WHERE
 * THE DATA CANNOT DECIDE, THE MIGRATION MUST NOT DECIDE FOR IT.
 *
 *     DO NOT BACKFILL THIS FROM NAMES UNTIL THE DUPLICATE FRAMEWORKS ARE
 *     RESOLVED. Filed separately: 24 ids, 13 names, all in tenant 1.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NO FOREIGN KEY CONSTRAINT, DELIBERATELY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * An index, not an FK. `s_competency_frameworks` is tenant-scoped and this
 * codebase scopes by sub_institute_id in every query rather than by database
 * constraint; an FK here would be the only one of its kind and would behave
 * differently from every neighbouring relationship. Consistency with how the
 * system actually enforces tenancy is worth more than a constraint that catches
 * a case the application layer already refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('competency', 'framework_id')) {
            Schema::table('competency', function (Blueprint $table) {
                // NULL = not filed under a framework. A real state, permanently
                // valid, not a gap awaiting a backfill.
                $table->unsignedBigInteger('framework_id')->nullable()->after('id');
                $table->index(['sub_institute_id', 'framework_id'], 'competency_tenant_framework_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('competency', 'framework_id')) {
            Schema::table('competency', function (Blueprint $table) {
                $table->dropIndex('competency_tenant_framework_index');
                $table->dropColumn('framework_id');
            });
        }
    }
};
