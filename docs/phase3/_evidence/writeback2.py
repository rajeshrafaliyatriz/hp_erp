import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

# ---- 09-implementation-log
p = os.path.join(D, "09-implementation-log.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("\n---\n\n## R7 applied retroactively", """
---

## D-007 · F-01..F-04, F-07, F-09 — the foundation migration, as ONE change

**2026-08-07** · **APPLIED** · commit **`7df8c1c7`**

| | |
|---|---|
| **Changed** | **12 tables created, 3 nullable columns added.** Strictly additive — no drops, no inferred backfill, no existing column altered |
| **Files** | `database/migrations/2026_08_07_100000_phase3_foundation_join_tables.php` |
| **Verification** | **12 of 12 tables created · 3 of 3 columns added · all twelve carry `sub_institute_id` · all new tables empty** |
| **Acceptance** | AT-F01..F04, F07, F09 structural half **PASSED** (schema verified by query). **Not `Verified`** — no application code reads these yet |

### The six confirmations, answered before running

| # | Question | Answer |
|---:|---|---|
| 1 | Tenancy on every tenant-owned table | **Two were genuinely missing.** `competency_kasba_item` and `library_map_skill` had no `sub_institute_id`; both now do. **The diagram was brevity for ten of twelve and wrong for two** — the check was worth making |
| 2 | Can a tenant own a `certification_type`? | **Yes.** `sub_institute_id` NULLABLE — NULL = global seed, non-null = tenant-authored, which is where §10.0's gated inline create writes |
| 3 | Why did the controller break if the table exists? | **It did not.** `CertificationRequirementController` references `s_competency_certification_requirements` — **with** the `s_` prefix — and that table exists with 15 rows. **The audit recorded it without the prefix.** A naming error in the record, not a missing table |
| 4 | Does `skill_matrix_item` keep `item_label`? | **Yes**, and `item_id` is **nullable** — so an unmatched row keeps what was meant and is reported, never inferred |
| 5 | UNIQUE on every map table's natural key | **All five verified present** before running |
| 6 | Does `competency_evidence` carry direction and dismissal? | **Yes** — `direction` (positive/negative/neutral, default neutral), plus `dismissed_reason`, `dismissed_by`, `dismissed_at` |

### One failure during application, and its fix

The first run failed on MySQL's **64-character identifier limit**: the
auto-generated index name on `s_competency_certification_requirements` came to 66.
Replaced with an explicit `idx_ccr_cert_type`. **The migration is idempotent
(`if (!Schema::hasTable)`), so re-running after the fix was safe** and no partial
state persisted.

### Rollback

`down()` drops exactly what `up()` created, in reverse dependency order, plus the
three columns. **Every new table starts empty and every new column starts NULL, so
reverting cannot lose data.**

### What this does NOT do

No backfill. `s_user_jobrole_task.jobrole_id` and the two `certification_type_id`
columns are **nullable, indexed and unread**. The backfill, its unmatched report,
and the eventual text-key drops are a separate reviewed change (R8).

---

## R7 applied retroactively""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- plan §5 statuses
p = os.path.join(D, "08-connection-plan.md"); t = io.open(p, encoding="utf-8").read()
for iid, note in [("F-01", "the five join tables, ONE migration"), ("F-02", "`certification_type` + `certification_competency_map`"),
                  ("F-03", "Three restored tables"), ("F-04", "`skill_matrix_item` + `sub_institute_id`"),
                  ("F-07", "Text→FK migrations (steps 12-14)"), ("F-09", "`library_map_skill` join table")]:
    for line in t.split("\n"):
        if line.startswith("| " + iid + " |") and "Not started" in line:
            t = t.replace(line, line.replace("| Not started |", "| ✅ **APPLIED** (`7df8c1c7`) |"))
t = t.replace("| F-03 | Three restored tables | Q-B5 | 2, 3, 8 | **S** · §4.2 idempotent DDL | evidence projector | — | AT-F03 | DB | ✅ **APPLIED** (`7df8c1c7`) |",
              "| F-03 | **Two** restored tables *(not three — see D-007)* | Q-B5 | 2, 3, 8 | **S** · §4.2 idempotent DDL | evidence projector | — | AT-F03 | DB | ✅ **APPLIED** (`7df8c1c7`) |")
t = t.replace("| F-07 | Text→FK migrations (steps 12-14) | G-DATA-06 | all | **L** · backfill + report unmatched | joins by key | F-01 | AT-F07 | DB | ✅ **APPLIED** (`7df8c1c7`) |",
              "| F-07a | Text→FK **columns added**, nullable, unread | G-DATA-06 | all | **M** | F-07b | F-01 | AT-F07 | DB | ✅ **APPLIED** (`7df8c1c7`) |\n| F-07b | Text→FK **backfill + unmatched report + drops** | G-DATA-06 | all | **L** · R8 on the drops | joins by key | F-07a | AT-F07b | DB | Not started |")
io.open(p, "w", encoding="utf-8").write(t)

# ---- 13-current-state
p = os.path.join(D, "13-current-state.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| 8 | Join-table migration (one change) | **Not started** |",
              "| 8 | **Join-table migration (one change)** | ✅ **APPLIED** (`7df8c1c7`) — 12 tables, 3 columns, all tenant-scoped |")
t = t.replace("**Built: 7. Structural foundations built: 0 of 6.**\n\n**FOUNDATIONS BUILT — 0 of 6.** The counter starts at the join-table migration.",
              "**Built: 8.**\n\n**FOUNDATIONS BUILT — 1 of 6.**")
io.open(p, "w", encoding="utf-8").write(t)

# ---- 00-progress
p = os.path.join(D, "00-progress.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("**FOUNDATIONS BUILT — 0 of 6.**", "**FOUNDATIONS BUILT — 1 of 6.**")
t = t.replace("| 3 | **The join-table migration, as ONE change** | **NEXT — the counter starts here** |",
              "| 3 | **The join-table migration, as ONE change** | ✅ done (`7df8c1c7`) — 12 tables, 3 columns |")
t = t.replace("| 4 | Rights matrix populated + before/after menu diff for review | after 3 |",
              "| 4 | **Rights matrix populated + before/after menu diff for review** | **NEXT** |")
t = t.replace("""1. **F-01** — the five join tables, one migration (`02-domain-model.md` §2.1 DDL)
2. **X-01** — rights matrix populated, with the before/after menu diff
3. **F-05** — reporting line with cycle validation""",
"""1. **F-06 + X-01** — tri-state rights columns, then populate the matrix with the
   before/after menu diff **for Triz's review before rollout**
2. **F-05** — reporting line with cycle validation
3. **X-04** — event store + projector/reactor split *(unblocked by S-02)*

**Also now unblocked by D-007:** F-07b (backfill + unmatched report + drops, R8),
and every Tier 3 connection that was waiting on the join tables.""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- register: Q-B5 correction
p = os.path.join(D, "07-gap-register.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("`competency_evidence`, `competency_certification_requirements`, `s_skill_jobrole`.",
"""`competency_evidence`, ~~`competency_certification_requirements`~~, `s_skill_jobrole`.

> ⚠️ **CORRECTED 2026-08-07 (D-007): TWO of three, not three.**
> `s_competency_certification_requirements` — **with** the `s_` prefix — **exists
> and holds 15 rows**, and `CertificationRequirementController` references it
> correctly. **This record listed it without the prefix**, which is why it read as
> missing. **A naming error in the audit, not a missing table.** The other two were
> genuinely absent and are now created.""")
io.open(p, "w", encoding="utf-8").write(t)
print("written: 09, 08 §5, 13, 00, 07")
