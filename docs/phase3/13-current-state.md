# 13 — Current state · one page

**Written 2026-08-07.** Replaces nothing. Reconciled against
`PHASE3-CANONICAL-RECAP.md` (the independent review-side record).

---

## Recap reconciliation

| # | Item | Verdict |
|---|---|---|
| 1 | **§3 — `s_user_jobrole_task` 85,662** | ⚠️ **Recap carries the corrected TOTAL with the uncorrected COMPONENT.** Its four components sum to **283,126**, but it states **283,127**. **Settled 2026-08-07: the headline is 283,126 — rows with a populated key.** The tables hold 283,127 rows; one `s_user_jobrole_task` row has an empty `jobrole`. The recap's total was mine and paired a corrected total with an uncorrected component — **R19's failure mode inside the document written to prevent drift** |
| 2 | §3 — 79,295 / 62,208 / 55,961 | ✅ all three re-derived exactly (V4) |
| 3 | §5 — 2.7% coverage, 7 of 264 | ✅ matches |
| 4 | §5 — 46 / 29 | ✅ matches the corrected figures |
| 5 | §5 — 912 GET routes | ✅ matches |
| 6 | §4 — G-SEC-12 "~33 places" | ✅ matches. **Still candidates, not findings (R6)** — the hand-classification has not run |
| 7 | §8 — what has been built | ✅ **CORRECT IN BOTH DIRECTIONS.** Nothing claimed that was not built; nothing built that is missing |
| 8 | §9 — build order | ✅ matches exactly |
| 9 | §6 — deferred scope | ✅ matches all eight items |
| 10 | §2 — Gate C "verified at 3.3%" | ✅ matches |

### What the recap misses entirely

| Missing | Where it lives |
|---|---|
| **G-FLOW-24 correction** — `delay_category` is empty because **1 task of 2,271** ever reached `ON HOLD`, not because nothing writes it. The **only** finding to move in the reassuring direction | `07-gap-register.md`, `task.md` |
| **F-2 downgraded to a candidate** by V4 — the Command Center navigation claim had a wrong file citation and an incomplete key list | `competency.md`, `12-gate-c-verification.md` |
| **The S-5 defect class has no replacement mechanism** — divergent vocabularies are unchecked outside one documented instance | `12-gate-c-verification.md` V3 |
| **V8 coverage: 12 of 30 sub-modules hand-read**, 18 by sweeps/guard/DB | `12-gate-c-verification.md` V8 |
| **`c23-result-FULL-912.json` recovered** from a Recycle Bin snapshot — the per-route records the 37 candidates get worked from | `_evidence/sweeps/` |
| **1,683 registered routes** (router) vs 1,676 (regex); **772** write routes, and the "430 never audited" claim **withdrawn** | `12-gate-c-verification.md` V6 |

### Out of date in the recap

| Stale | Current |
|---|---|
| §2 — *"plan partly written"* | §1–§3 written; §4–§11 next |
| §5 — G-MAP-01 *"button now removed"* | ✅ correct, and committed (`cb2f6a5`) |

**No contradiction was found in either direction on what has been built.**

---

## Gates

| Gate | State |
|---|---|
| **A** | ✅ Signed off |
| **B** | ✅ Signed off |
| **C** | ✅ **Closed and verified.** 30 audited · 1 duplicate · 1 out of scope. **V4 sample error 3.3%** (1 of 30); **V7 reconciliation clean** — no work silently dropped |
| **D** | **In progress.** Plan §1–§3 written. 5 code items shipped |

---

## Gate D — every item

| # | Item | State |
|---:|---|---|
| — | G-NAV-01 menu row | **Verified** (data change, nav cross-ref confirmed) |
| 1 | **L-03R** dead panel deleted | **Built** — UI steps unrun |
| 2 | **D-002** server owns `approve_status` / `verification_status` + rejected-competency dead end | **Built** — UI steps unrun |
| 3 | **D-003** `skillLibraryController` tenant resolution | **API-verified-UI-pending** — guard 2 FAIL → 0 |
| 4 | **D-004** `PayrollController` tenant **and** actor resolution | **API-verified-UI-pending** — guard 9 FAIL → 0 |
| 5 | **G-MAP-01** button removed | **Built** — R8 checklist clean |
| 6 | **S-01** `talent_interviewpanelController` | ✅ **API-verified-UI-pending** — guard 1 FAIL → 0 (`15791bca`) |
| 7 | **S-02** G-SEC-12 actor fixes | ✅ **API-verified-UI-pending** — 76 sites / 16 files, 0 remain (`d70a204c`). **No longer blocks 11** |
| 8 | **Join-table migration (one change)** | ✅ **APPLIED** (`7df8c1c7`) — 12 tables, 3 columns, all tenant-scoped |
| 9a | **Tri-state rights columns** | ✅ **APPLIED** (`5e302651`) — additive, no behaviour change |
| 9b | **Populate the matrix** | ⛔ **REVIEW GATE — NOT APPLIED.** Blocked: 8 roles specified, 3 exist; no screen→menu mapping. Backup taken; admin lockout asserted safe |
| 10 | **`reporting_manager_id` + `head_user_id` + role_key + cycle validation** | ✅ **APPLIED** (`f293edb0`) — 6 columns, `tenant_setting`, validator tested |
| 11 | Event store + projector/reactor split | **Not started. UNBLOCKED** — S-02 closed |
| 12 | C19 picker mechanism | **Not started** |
| 13 | `certification_type` + map | **Not started** |
| 14 | Three restored tables | **Not started** |
| 15 | `skill_matrix_item` + tenant column | **Not started** |
| 16 | Text→FK migrations | **Not started** |

**Built: 11.**

**FOUNDATIONS BUILT — 3 of 6, with item 4 at 4a.**

---

## Security queue — data-class order

| Tier | Class | Items |
|---|---|---|
| **1** | **Candidate / personal** | `talent_interviewpanelController` ✅ **done** · then the other three C27 `talent_*` controllers |
| **2** | Payroll-adjacent | `PayrollController` ✅ done · `HrmsLeaveController`, `ApplyLeaveController`, `LeaveTypeController`, `LeaveSummaryReportController` |
| **3** | Credentials / integrations | `ExcelAutomationAgentController@credentialStatus` |
| **4** | Competency / learning | `skillLibraryController` ✅ done · `skillcontroller`, `assignmentController`, `courseController`, rest |

**Also open:** 37 unverified guard candidates (work from `c23-result-FULL-912.json`,
do **not** re-run), C37's nine remaining checks, the C23 write-half phase, the C23
regression guard in CI.

> ⛔ **C24** — no customer tenant until the tenant-isolation suite passes end to end.

---

## Blocked, and by what

| Blocked | By |
|---|---|
| ~~Event store~~ | ~~G-SEC-12~~ — **UNBLOCKED 2026-08-07** |
| Threads 2 and 9 | the event store |
| L-01, L-02, L-04 and every later entity binding | **C19** picker mechanism |
| Rights matrix population (4b) — **targets `_g2g`, INVISIBLE to users** | ~~tri-state columns~~ ✅ · ~~role model~~ ✅ · ~~nine roles~~ ✅ **(dd25e450)** — **now blocked only on the screen→menu mapping (4b-prep c) and approval of the Recruiter expansion** |
| Everything in Tier 3 | the join tables |
| The three mandatory reports | the joins — building them first yields three empty reports |
| Write-route audit coverage figure | re-derivation from the router |

---

## Still owed

| Item | State |
|---|---|
| PayrollController's 4 `user_id` sites | ✅ **CLOSED** — 3 were identity and are fixed (`2223b2e3`); 1 is a legitimate subject, left alone |
| G-MAP-01 mis-wired button | ✅ **CLOSED** — removed under R8 (`cb2f6a5`). **M-03 is its reinstatement** |

**Both closed with commit references, per the rule.**
