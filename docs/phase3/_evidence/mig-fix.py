import io

P = r"C:\Users\MILAN\Downloads\hp_erp\database\migrations\2026_08_07_100000_phase3_foundation_join_tables.php"
t = io.open(P, encoding="utf-8", newline="").read()

# --- (1) tenancy on the two tables that genuinely lacked it -------------------
t = t.replace("""                $t->bigIncrements('id');
                $t->unsignedBigInteger('competency_id');
                // §11 (v): the polymorphic key is (type, item_id) EVERYWHERE.""",
"""                $t->bigIncrements('id');
                // Tenancy on EVERY tenant-owned table, not only the parent. The
                // C23/C34 guards can only see scoping where the column exists, and
                // twelve new tables is the single best chance to get this right.
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('competency_id');
                // §11 (v): the polymorphic key is (type, item_id) EVERYWHERE.""")

t = t.replace("""                $t->bigIncrements('id');
                $t->unsignedBigInteger('library_map_id')->index();""",
"""                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->unsignedBigInteger('library_map_id')->index();""")

# --- (4) item_label on skill_matrix_item --------------------------------------
t = t.replace("""                $t->unsignedBigInteger('item_id');
                $t->decimal('proficiency', 5, 2)->nullable();""",
"""                $t->unsignedBigInteger('item_id')->nullable();
                // ALWAYS keep the label. A failed match must not destroy what was
                // meant - an unmatched row keeps its text and is REPORTED, never
                // inferred (§10.0). item_id is nullable for exactly that reason.
                $t->string('item_label', 191)->nullable();
                $t->decimal('proficiency', 5, 2)->nullable();""")

# item_id now nullable -> the unique key must tolerate it; key on label as well
t = t.replace("$t->unique(['user_id', 'kasba_type', 'item_id'], 'uq_smi_subject');",
              "$t->unique(['user_id', 'kasba_type', 'item_id'], 'uq_smi_subject');\n"
              "                $t->index(['user_id', 'kasba_type', 'item_label'], 'idx_smi_unmatched');")

# --- (6) competency_evidence: direction + dismissed_reason --------------------
t = t.replace("""                $t->string('outcome', 32)->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();""",
"""                $t->string('outcome', 32)->nullable();
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
                $t->unsignedBigInteger('recorded_by')->nullable();""")

# --- header: record the six confirmations -------------------------------------
t = t.replace(" * ─── NOT INCLUDED, deliberately ──────────────────────────────────────────────",
""" * ─── SIX CONFIRMATIONS, 2026-08-07 ───────────────────────────────────────────
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
 * ─── NOT INCLUDED, deliberately ──────────────────────────────────────────────""")

io.open(P, "w", encoding="utf-8", newline="").write(t)
print("migration updated")
for k in ("sub_institute_id')->index();", "item_label", "dismissed_reason", "direction"):
    print("  %-22s occurrences: %d" % (k, t.count(k)))
