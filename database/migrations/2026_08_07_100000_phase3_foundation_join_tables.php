<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 3 — F-01..F-04, F-07, F-09. THE FOUNDATION MIGRATION, AS ONE CHANGE.
 *
 * Specified in docs/phase3/02-domain-model.md §2.1, §4.2, §10, §10.1 and §11.2.
 * The five join tables land together or not at all: four of the seven links in the
 * capability chain are missing, and each of these supplies one. Splitting them
 * creates half-connected states nothing can use.
 *
 * ─── STRICTLY ADDITIVE ───────────────────────────────────────────────────────
 * Creates tables and adds nullable columns. It does NOT:
 *   - drop any column or table            (R8 — every drop is its own reviewed change)
 *   - backfill by inference               (§10.0 — unmatched rows are REPORTED, never guessed)
 *   - change any existing column's type   (no consumer's shape changes, so R9 has nothing to re-read)
 *
 * The text→FK work of §10 steps 12–14 is therefore split in two, deliberately:
 *   THIS migration  — adds the *_id columns, nullable, indexed. Nothing reads them yet.
 *   A LATER change  — runs the backfill, publishes the unmatched report, and only
 *                     then drops the text keys. Reversible until that final drop.
 *
 * ─── ROLLBACK ────────────────────────────────────────────────────────────────
 * down() drops exactly what up() created, in reverse dependency order, and drops
 * the added columns. Because nothing is backfilled and no existing column is
 * altered, rollback restores the schema byte-for-byte. No data can be lost by
 * reverting: every table created here starts empty, and every column added here
 * starts NULL.
 *
 * ─── SIX CONFIRMATIONS, 2026-08-07 ───────────────────────────────────────────
 *  1. TENANCY — sub_institute_id is on every tenant-owned table. Ten already had
 *     it; competency_kasba_item and library_map_skill did NOT and now do. The
 *     diagram was brevity for ten of twelve, and wrong for two.
 *  2. certification_type — sub_institute_id is NULLABLE: NULL = global seed,
 *     non-null = tenant-authored. §10.0's gated inline create writes tenant rows.
 *  3. s_competency_certification_requirements EXISTS (15 rows) and the controller
 *     references it correctly. The audit recorded it WITHOUT the s_ prefix — a
 *     naming error in the record, not a missing table. Two of three restored.
 *  4. skill_matrix_item keeps item_label, and item_id is NULLABLE, so a failed
 *     match is reported rather than destroying what was meant.
 *  5. UNIQUE on every map table's natural key — verified present on all five.
 *  6. competency_evidence carries direction + dismissed_reason/_by/_at.
 *
 * ─── CARRIED FORWARD (recorded, not re-analysed) ─────────────────────────────
 *  - competency_kasba_item.item_id is POLYMORPHIC: application-level validator on
 *    write, a periodic orphan check, and (kasba_type, item_id) as the join key
 *    everywhere. No database FK can express it.
 *  - course_jobrole_map is for ROLE-MANDATORY learning that is not gap-driven.
 *    The competency-derived path is the DEFAULT and the only input to
 *    recommendations.
 *
 * ─── NOT INCLUDED, deliberately ──────────────────────────────────────────────
 *   portal_identity (F-08)  — Q-D4, not in the agreed scope for this change
 *   reporting_manager_id    — F-05, build item 5
 *   tri-state rights        — F-06, build item 4
 *   s_competency_certification_requirements — ALREADY EXISTS with 15 rows. Q-B5
 *       listed it among three "missing" tables; it is present. Only two of the
 *       three needed restoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. competency — a named bundle of KASBA items (Q-C2) ──────────────
        if (!Schema::hasTable('competency')) {
            Schema::create('competency', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->string('code', 64);
                $t->string('name', 191);
                $t->text('description')->nullable();
                $t->string('competency_type', 64)->nullable();
                // Fed by L-16 (Risk Implications → severity). Bound here so no
                // parallel severity system is created later.
                $t->string('criticality', 32)->nullable();
                // Q-B2: NULL = inherit the tenant default.
                $t->boolean('requires_assessment')->nullable();
                $t->string('status', 32)->default('active');
                $t->integer('version')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['sub_institute_id', 'code'], 'uq_competency_tenant_code');
            });
        }

        // ── 2. competency_kasba_item — which KASBA items make up a competency ──
        if (!Schema::hasTable('competency_kasba_item')) {
            Schema::create('competency_kasba_item', function (Blueprint $t) {
                $t->bigIncrements('id');
                // Tenancy on EVERY tenant-owned table, not only the parent. The
                // C23/C34 guards can only see scoping where the column exists, and
                // twelve new tables is the single best chance to get this right.
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('competency_id');
                // §11 (v): the polymorphic key is (type, item_id) EVERYWHERE.
                $t->enum('kasba_type', ['skill', 'knowledge', 'ability', 'attitude', 'behaviour']);
                $t->unsignedBigInteger('item_id');
                // Fed by L-18 (Importance). Bound here so no parallel weighting exists.
                $t->decimal('weight', 5, 2)->default(1.00);
                $t->timestamps();
                $t->unique(['competency_id', 'kasba_type', 'item_id'], 'uq_ck_item');
                $t->index(['kasba_type', 'item_id'], 'idx_ck_lookup');
            });
        }

        // ── 3. jobrole_competency_map — what a job role requires (Q-C1) ───────
        if (!Schema::hasTable('jobrole_competency_map')) {
            Schema::create('jobrole_competency_map', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('jobrole_id');          // s_user_jobrole.id
                $t->unsignedBigInteger('competency_id');
                $t->unsignedTinyInteger('required_proficiency')->nullable();
                $t->boolean('is_mandatory')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['sub_institute_id', 'jobrole_id', 'competency_id'], 'uq_jcm');
                $t->index('competency_id', 'idx_jcm_competency');
            });
        }

        // ── 4. course_competency_map — what a course builds (Q-B4) ────────────
        if (!Schema::hasTable('course_competency_map')) {
            Schema::create('course_competency_map', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('course_id');           // sub_std_map.id
                $t->unsignedBigInteger('competency_id');
                $t->unsignedTinyInteger('proficiency_level')->nullable();
                // §2.2: the competency this course CHIEFLY builds, vs incidental.
                $t->boolean('is_primary')->default(false);
                $t->timestamps();
                $t->unique(['course_id', 'competency_id'], 'uq_ccm');
                $t->index('competency_id', 'idx_ccm_competency');
            });
        }

        // ── 5. jobrole_task_competency_map — the CATALOGUE link (Q-C3) ────────
        // The instance link (task.skill_id) exists at 66.7%; the catalogue has none.
        if (!Schema::hasTable('jobrole_task_competency_map')) {
            Schema::create('jobrole_task_competency_map', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                // s_jobrole_task.id - the SHARED CATALOGUE, not the tenant's own
                // s_user_jobrole_task. This comment said the latter for months
                // while every code path enforced the former; corrected here to
                // match the code, which holds the coherent reading: a global task
                // catalogue with a tenant-owned mapping laid over it.
                $t->unsignedBigInteger('jobrole_task_id');
                $t->unsignedBigInteger('competency_id');
                $t->timestamps();
                // NOTE: widened to include sub_institute_id by
                // 2026_08_22_120000. Two organisations must be able to map the
                // same catalogue task to different competencies, and this
                // two-column key made that impossible.
                $t->unique(['jobrole_task_id', 'competency_id'], 'uq_jtcm');
                $t->index('competency_id', 'idx_jtcm_competency');
            });
        }

        // ── 6. certification_type — the catalogue that did not exist (§10.1) ──
        // NULL sub_institute_id = a global seed row, same two-layer pattern as
        // the skill libraries.
        if (!Schema::hasTable('certification_type')) {
            Schema::create('certification_type', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->nullable()->index();
                $t->string('name', 191);
                $t->string('issuing_body', 191)->nullable();
                $t->enum('category', ['industry', 'internal', 'regulatory', 'license', 'vendor']);
                $t->unsignedSmallInteger('default_validity_months')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['sub_institute_id', 'name', 'issuing_body'], 'uq_cert_type');
            });
        }

        // ── 7. certification_competency_map — symmetric with course map ───────
        if (!Schema::hasTable('certification_competency_map')) {
            Schema::create('certification_competency_map', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('certification_type_id');
                $t->unsignedBigInteger('competency_id');
                $t->unsignedTinyInteger('proficiency_level')->nullable();
                $t->boolean('is_primary')->default(false);
                $t->timestamps();
                $t->unique(['certification_type_id', 'competency_id'], 'uq_cert_comp');
                $t->index('competency_id', 'idx_certcomp_competency');
            });
        }

        // ── 8. competency_evidence — RESTORED (Q-B5, §5) ──────────────────────
        // A projection, written by a projector reacting to task/assessment/course
        // /certification events — except source_type 'manual' and 'import' (§1).
        if (!Schema::hasTable('competency_evidence')) {
            Schema::create('competency_evidence', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->enum('kasba_type', ['skill', 'knowledge', 'ability', 'attitude', 'behaviour'])->nullable();
                $t->unsignedBigInteger('item_id')->nullable();
                $t->unsignedBigInteger('competency_id')->nullable();
                $t->string('source_type', 48);      // task | assessment | enrolment | certificate | manual | import
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('outcome', 32)->nullable();
                // Approved deviations from the hpbrain design:
                //   direction  - evidence can count for or against, and Q-B3 says a
                //                negative NEVER auto-lowers a rating; it is recorded
                //                and surfaced, and only a manager confirms a change.
                //   dismissed_reason - a manager who rejects a signal must say why,
                //                or the same signal returns forever with no memory.
                $t->enum('direction', ['positive', 'negative', 'neutral'])->default('neutral');
                $t->text('dismissed_reason')->nullable();
                $t->unsignedBigInteger('dismissed_by')->nullable();
                $t->timestamp('dismissed_at')->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'kasba_type', 'item_id'], 'idx_ce_subject');
                $t->index(['source_type', 'source_id'], 'idx_ce_source');
            });
        }

        // ── 9. s_skill_jobrole — RESTORED (Q-B5) ──────────────────────────────
        if (!Schema::hasTable('s_skill_jobrole')) {
            Schema::create('s_skill_jobrole', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('skill_id')->nullable()->index();
                $t->unsignedBigInteger('jobrole_id')->nullable()->index();
                $t->string('proficiency_level', 64)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        // ── 10. skill_matrix_item — the normalised matrix, WITH a tenant column ─
        // G-DATA-08: s_skill_matrix has no sub_institute_id, so tenancy is only
        // expressible two joins away — invisible to the C23/C34 guards. The new
        // table carries it, set at WRITE TIME from the resolved identity, never
        // from request input (that is G-SEC-09's exact defect).
        if (!Schema::hasTable('skill_matrix_item')) {
            Schema::create('skill_matrix_item', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->enum('kasba_type', ['skill', 'knowledge', 'ability', 'attitude', 'behaviour']);
                $t->unsignedBigInteger('item_id')->nullable();
                // ALWAYS keep the label. A failed match must not destroy what was
                // meant - an unmatched row keeps its text and is REPORTED, never
                // inferred (§10.0). item_id is nullable for exactly that reason.
                $t->string('item_label', 191)->nullable();
                $t->decimal('proficiency', 5, 2)->nullable();
                $t->unsignedBigInteger('source_matrix_id')->nullable()->index();  // provenance to s_skill_matrix
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['user_id', 'kasba_type', 'item_id'], 'uq_smi_subject');
                $t->index(['user_id', 'kasba_type', 'item_label'], 'idx_smi_unmatched');
                $t->index(['sub_institute_id', 'kasba_type', 'item_id'], 'idx_smi_lookup');
            });
        }

        // ── 11. library_map_skill — G-DATA-07 ─────────────────────────────────
        // s_library_map.skill_ids packs ids into TEXT; LIKE '%,3,%' matches 13, 23
        // and 130 — a wrong-results bug. The text column is NOT dropped here.
        if (!Schema::hasTable('library_map_skill')) {
            Schema::create('library_map_skill', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('library_map_id')->index();
                $t->unsignedBigInteger('skill_id')->index();
                $t->timestamps();
                $t->unique(['library_map_id', 'skill_id'], 'uq_lms');
            });
        }

        // ── 12. course_jobrole_map — §10 step 13 ──────────────────────────────
        // sub_std_map.jobrole is a longtext of role names. The column stays until
        // the backfill is verified.
        if (!Schema::hasTable('course_jobrole_map')) {
            Schema::create('course_jobrole_map', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('jobrole_id');
                $t->timestamps();
                $t->unique(['course_id', 'jobrole_id'], 'uq_cjm');
            });
        }

        // ── 13. ADDITIVE FK COLUMNS — nullable, indexed, unread until backfill ─
        if (Schema::hasTable('s_user_jobrole_task') && !Schema::hasColumn('s_user_jobrole_task', 'jobrole_id')) {
            Schema::table('s_user_jobrole_task', function (Blueprint $t) {
                $t->unsignedBigInteger('jobrole_id')->nullable()->after('id')->index();
            });
        }
        if (Schema::hasTable('s_competency_certifications') && !Schema::hasColumn('s_competency_certifications', 'certification_type_id')) {
            Schema::table('s_competency_certifications', function (Blueprint $t) {
                $t->unsignedBigInteger('certification_type_id')->nullable()->after('id')->index();
            });
        }
        if (Schema::hasTable('s_competency_certification_requirements') && !Schema::hasColumn('s_competency_certification_requirements', 'certification_type_id')) {
            Schema::table('s_competency_certification_requirements', function (Blueprint $t) {
                // Explicit short index name: MySQL caps identifiers at 64 chars and
                // the auto-generated one on this table name is 66.
                $t->unsignedBigInteger('certification_type_id')->nullable()->after('id');
                $t->index('certification_type_id', 'idx_ccr_cert_type');
            });
        }
    }

    public function down(): void
    {
        foreach (['s_user_jobrole_task' => 'jobrole_id',
                  's_competency_certifications' => 'certification_type_id',
                  's_competency_certification_requirements' => 'certification_type_id'] as $tbl => $col) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, $col)) {
                Schema::table($tbl, function (Blueprint $t) use ($col) { $t->dropColumn($col); });
            }
        }

        // Reverse dependency order.
        foreach (['course_jobrole_map', 'library_map_skill', 'skill_matrix_item',
                  's_skill_jobrole', 'competency_evidence', 'certification_competency_map',
                  'certification_type', 'jobrole_task_competency_map', 'course_competency_map',
                  'jobrole_competency_map', 'competency_kasba_item', 'competency'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
