import io, os

D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

# ---- 09-implementation-log
p = os.path.join(D, "09-implementation-log.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("\n---\n\n## R7 applied retroactively", """
---

## D-005 · S-01 — `talent_interviewpanelController` tenant resolution

**2026-08-07** · `API-verified-UI-pending` · commit **`15791bca`**

| | |
|---|---|
| **Changed** | Five `if ($type == "API")` branches validated that a token existed, then took `sub_institute_id` from the request body — the G-SEC-09 defect. Added `panelTenantId()` (token first, session fallback, mirroring `payrollTenantId`) and substituted all five |
| **Files** | `app/Http/Controllers/talent/interview_panel/talent_interviewpanelController.php` |
| **Guard** | **1 FAIL → 0.** `LEAK-NOSCOPE 0 · FAIL 0 · PASS 2 · UNTESTABLE 4` |
| **Acceptance** | API-level green. **Not `Verified`** — 4 UNTESTABLE routes need path parameters; the write half is untested |

**First by data class**, ahead of controllers with four times the route count:
interview panel records cover **candidates** — people outside the company who never
agreed to be in the system.

---

## D-006 · S-02 — G-SEC-12, the acting user resolved from identity

**2026-08-07** · `API-verified-UI-pending` · commit **`d70a204c`**

| | |
|---|---|
| **Changed** | **76 provenance sites across 16 files** took `created_by` / `updated_by` / `verified_by` / `reviewer_id` from request input. Added `g2gActorId()` per file (token first, session fallback, mirroring `payrollActorId`) and substituted every site |
| **Files** | 16 — `skillLibraryController` (29 sites), `jobroleLibrary1Controller` (16), `jobroleLibraryController` (11), `jobroletexonomycontroller` (4), `HolidayController` (2), `jobroletaskcontroller` (2), `LmsCourseEnrollController` (2), `talent_jobpostingcontroller` (2), plus 8 with one each |
| **Guard** | Re-scan: **0 provenance-from-request sites remain.** C23 on previously fixed controllers **unchanged at 0 FAIL** — no regression |
| **Acceptance** | `php -l` clean on all 16. **Not `Verified`** — no UI path exercised |

### Classification — the rule held completely

**76 IDENTITY, 0 AMBIGUOUS.** The proven rule — *provenance columns fed from input
are always IDENTITY; a field naming who the operation is ABOUT is SUBJECT* —
cleared **every one mechanically. None needed a hand read.**

⚠️ **Scope was larger than estimated: 76 sites, not 33.** S-3's figure counted only
`created_by`; this covers all provenance columns. **The estimate was low by 2.3×**,
and it was marked ESTIMATE PENDING for exactly this reason.

**75 substituted by script; 1 by hand** — a CRLF line ending defeated the anchor
regex in `SuggestedCourseController`. Same class of defect as earlier in the phase.

### ⛔ This unblocks the event store

`05-data-flow-contracts.md` §1.9 recorded that everything downstream assumes
`actor_id` is trustworthy. **It now is.** X-04 is no longer blocked by S-02.

---

## R7 applied retroactively""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- 08-connection-plan §5 statuses
p = os.path.join(D, "08-connection-plan.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("| S-01 | `talent_interviewpanelController` tenant fix | G-SEC-11 | 7 | **XS-S** · `talent_interviewpanelController.php` | nothing | — | AT-S01 | API | **NEXT** |",
              "| S-01 | `talent_interviewpanelController` tenant fix | G-SEC-11 | 7 | **XS-S** · `talent_interviewpanelController.php` | nothing | — | AT-S01 | API | ✅ **API-verified** (`15791bca`) |")
t = t.replace("| S-02 | G-SEC-12 actor identity, 33 candidates | G-SEC-12 | 2, 9 | **ESTIMATE PENDING** — 33 sites unclassified | **event store** | own classification | AT-S02 | API | Not started |",
              "| S-02 | G-SEC-12 actor identity | G-SEC-12 | 2, 9 | **M** — **76 sites / 16 files** (est. was 33; low by 2.3×) | ~~event store~~ **UNBLOCKED** | — | AT-S02 | API | ✅ **API-verified** (`d70a204c`) |")
t = t.replace("| X-04 | **Event store + projector/reactor split** | G-STR-04 | 2, 9 | **L** · `05-data-flow-contracts.md` §1 DDL | X-05, threads 2/9 | **S-02** | AT-X04 | DB | Not started |",
              "| X-04 | **Event store + projector/reactor split** | G-STR-04 | 2, 9 | **L** · `05-data-flow-contracts.md` §1 DDL | X-05, threads 2/9 | ~~S-02~~ **now unblocked** | AT-X04 | DB | Not started |")
t = t.replace("| TL-01 | *(= S-01)* interview panel leak | G-SEC-11 | 7 | **XS-S** | — | — | AT-S01 | API | **NEXT** |",
              "| TL-01 | *(= S-01)* interview panel leak | G-SEC-11 | 7 | **XS-S** | — | — | AT-S01 | API | ✅ **API-verified** (`15791bca`) |")
t = t.replace("| **ESTIMATE PENDING** | **6** — S-02, S-03, S-06, S-08, X-02, R-01 |",
              "| **ESTIMATE PENDING** | **5** — S-03, S-06, S-08, X-02, R-01 *(S-02 derived: 76 sites / 16 files)* |")
io.open(p, "w", encoding="utf-8").write(t)

# ---- 13-current-state
p = os.path.join(D, "13-current-state.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("| 6 | `talent_interviewpanelController` | **NOT STARTED — next** |",
              "| 6 | **S-01** `talent_interviewpanelController` | ✅ **API-verified-UI-pending** — guard 1 FAIL → 0 (`15791bca`) |")
t = t.replace("| 7 | G-SEC-12 actor fixes (33 candidates) | **Not started.** Blocks 11 |",
              "| 7 | **S-02** G-SEC-12 actor fixes | ✅ **API-verified-UI-pending** — 76 sites / 16 files, 0 remain (`d70a204c`). **No longer blocks 11** |")
t = t.replace("| 11 | Event store + projector/reactor split | **Not started.** Blocked by 7 |",
              "| 11 | Event store + projector/reactor split | **Not started. UNBLOCKED** — S-02 closed |")
t = t.replace("**Built: 5. Structural foundations built: 0.**",
              "**Built: 7. Structural foundations built: 0 of 6.**\n\n"
              "**FOUNDATIONS BUILT — 0 of 6.** The counter starts at the join-table migration.")
t = t.replace("| Event store, `task_status_history`, `competency_evidence` projector | **G-SEC-12** — actor identity must be trustworthy first |",
              "| ~~Event store~~ | ~~G-SEC-12~~ — **UNBLOCKED 2026-08-07** |")
t = t.replace("| **1** | **Candidate / personal** | **`talent_interviewpanelController`** ← next · then the other three C27 `talent_*` controllers |",
              "| **1** | **Candidate / personal** | `talent_interviewpanelController` ✅ **done** · then the other three C27 `talent_*` controllers |")
io.open(p, "w", encoding="utf-8").write(t)

# ---- 00-progress
p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()
old_q = t[t.index("## Queue"):]
new_q = """## Queue

**CURRENTLY WORKING ON — the build. Analysis is closed.**

**FOUNDATIONS BUILT — 0 of 6.**

| # | Build item | State |
|---:|---|---|
| 1 | `talent_interviewpanelController` | ✅ done (`15791bca`) |
| 2 | G-SEC-12 actor identity | ✅ done (`d70a204c`) — **unblocks the event store** |
| 3 | **The join-table migration, as ONE change** | **NEXT — the counter starts here** |
| 4 | Rights matrix populated + before/after menu diff for review | after 3 |
| 5 | `reporting_manager_id` + `head_user_id` + cycle validation | after 4 |
| 6 | Event store + projector/reactor split + `task_status_history` | after 5 |

### Next 3 steps

1. **F-01** — the five join tables, one migration (`02-domain-model.md` §2.1 DDL)
2. **X-01** — rights matrix populated, with the before/after menu diff
3. **F-05** — reporting line with cycle validation

### Still queued, not blocking

- **F-6** ontology iframe deletion (approved; needs the R8 checklist)
- **C37** — nine more hand-checks of C34's 114
- The **37** guard candidates, from `c23-result-FULL-912.json` — **do not re-run the guard**
- ~~C32~~ — subsumed by Slice 1's demo step 6
"""
t = t.replace(old_q, new_q)
io.open(p, "w", encoding="utf-8").write(t)
print("written: 09, 08 §5, 13, 00")
