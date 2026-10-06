## Decisions made — AFTER the snapshot (2026-08-06)

Rebuilt from the intact artefacts. The recovered table above ends at 2026-08-05.

| Date | Ref | Decision |
|---|---|---|
| 2026-08-06 | **Q-E1** | `task.skill_id` is **hand-picked at creation**, job-role-suggested. **Catalogue wins**; the instance is an override tagged `confidence`. 33% null = no signal, do not guess |
| 2026-08-06 | **Q-E2** | **Measure per KASBA item**, derive a weighted roll-up. One service, two numbers reported. **Unmeasured ≠ zero** |
| 2026-08-06 | **Q-F1** | Notifications: **fixed wording + tenant-substitutable terminology**. Both tables built now, so renaming *employee* → *clinician* is data entry, not a refactor |
| 2026-08-06 | **Q-L1** | Of 25 orphan Library fields: **BIND 10 / NOTE 13 / SUBSTITUTE 2**. BINDs become L-15…L-23, each carrying a typing change (R-a); two bind to **existing** fields rather than new systems (R-b); three are display-only (R-c) |
| 2026-08-06 | **Q-L2** | A retired skill **persists until the cycle closes**. Filter at **assignment** time, never read time — an in-flight assessment is a measurement being taken |
| 2026-08-06 | **Q-L3** | **One shared category table with per-taxonomy applicability.** Cross-taxonomy reporting without forcing irrelevant categories onto every tab |
| 2026-08-06 | **Corrections 1–5** | **This database is TEST DATA ONLY — no production tenant, no customer.** Every "for now" compromise re-examined; separate DBs now; `s_skill_matrix` semantics decoded; polymorphic integrity required |
| 2026-08-06 | **C11 ABANDONED** | The per-element coverage ledger is dropped, **eyes open**. Enumeration produced **zero findings on its own** across two full write-ups |
| 2026-08-06 | **C17** | **Gate D opened early**, in parallel with Gate C. The foundation items depend on none of the remaining audits |
| 2026-08-06 | **C19** | **Build the picker mechanism ONCE** — id-bearing meta bucket, `LibraryMeta` carrying ids, one closed-picker control with a permission-gated create, generic payload mapping. Then every entity binding is **configuration** |
| 2026-08-06 | **§10.0 BINDING RULE** | **Entity → closed picker + permission-gated inline create. Vocabulary → open choice.** Decided by **ownership**, not drift: a department must never be created as a side effect of typing into a Competency form. Pre-answers L-04, L-07, L-08, L-09 and every field L-11 touches |
| 2026-08-06 | **C20** | Verification protocol. **I cannot start the app or click a UI, so I can never mark my own work `Verified`.** Cap of 3 `Built-unverified`; `API-verified-UI-pending` does not count against it |
| 2026-08-06 | **C22** | Phase 1's auth sweep counted **`findToken()` as a guard**, so a controller that validates a token and discards its owner passed it. **Two of three scripts read only `api.php`.** Inheriting conclusions listed, not re-audited |
| 2026-08-06 | **C23 INVERTED** | **Write the guard FIRST, before any fix.** Its failure list *is* the worklist, with a completion criterion that is not "we think we got them all". Now gates four figures |
| 2026-08-06 | **C24** | **Company-level release precondition** — no customer tenant until a tenant-isolation suite passes end to end |
| 2026-08-06 | **C25** | **No security figure is quoted until reconciled.** 1,676 routes across six files, not 739. **The Blade assumption was FALSE** — `authMiddleware` accepts a session **or a bare token** — so scope went **35 → 66** controllers. G-SEC-01/04 and the 279 remain **unquotable** |
| 2026-08-06 | **C30** | The S-1 49 splits **36 references / 5 own-identity / 8 noise**. Only the **36** is quotable. **283,126 is independent of it** |
| 2026-08-06 | **C33** | Global libraries are **copy-at-seed**: no tenant column, no write path. A customer renaming a job role **cannot** affect another customer |
| 2026-08-06 | **C35** | **S-2 RETIRED** → a module-write-up checklist item (payload vs validator vs insert, three files named per form) |
| 2026-08-06 | **C36** | **S-5 RETIRED.** A failed sweep is not rebuilt by default — ask whether a tool is cheaper than a checklist item |
| 2026-08-06 | **C37** | Bounds C34's 114: hand-verify **ten** by data sensitivity, then either calibrate or **close as a proven negative** |
| 2026-08-06 | **G-DATA-08** | **Add the tenant column.** `skill_matrix_item` **and** `s_skill_matrix` get `sub_institute_id` in §10 step 12, set at write time **from the resolved identity, never request input**, plus a consistency check that each row's tenant equals its user's. Reason: **it is what makes the guards work at all** |
| 2026-08-06 | **G-MAP-01** | The 79,295 mapping rows came from `SchoolSetupController.php:392-408` at **tenant provisioning**. A bulk-create mechanism **exists**, reachable only from signup. M-03 re-costs to *"surface an existing path"* |
| 2026-08-06 | **Sweep conclusion** | **Structural questions get tools; behavioural questions get a careful reading by a person.** Both productive sweeps were structural; every behavioural one failed |

---

## Changes executed — AFTER the snapshot

**Gate D opened 2026-08-06.** Full detail in `09-implementation-log.md`.

| Ref | Change | Guard result | Status |
|---|---|---|---|
| **D-001** | L-03R — delete the unreachable library detail panel (366 lines) | n/a | `Built` |
| **D-002** | G-COMP-01 + G-SEC-08 — server owns `approve_status` / `verification_status`; rejected competencies made resubmittable | n/a | `Built`. ⚠️ visible: new competencies born `Pending` |
| **D-003** | G-SEC-09 — `skillLibraryController` onto `ResolvesApiIdentity` | **2 FAIL → 0** | `API-verified-UI-pending` |
| **D-004** | G-SEC-10 — `PayrollController`, **26 sites in four styles** | **9 FAIL → 0** | `API-verified-UI-pending` |

---

## Open questions

`10-open-questions.md` — **24 questions, ALL ANSWERED.**

**Reconciled against the snapshot:** the six the snapshot listed as outstanding —
**Q-C4, Q-A3, Q-A5, Q-C1, Q-C2, Q-C3** — were **all answered on 2026-08-05** and
appear in the recovered Decisions table above. **Nothing is awaiting Triz.**

---

## Still owed — carried explicitly so they do not drop again

| Item | State |
|---|---|
| **4 `user_id` sites in `PayrollController`** | The **actor** half, not the tenant half. Must be resolved or explicitly cleared as subject-not-identity |
| **G-MAP-01 mis-wired button** | `'Create Framework'` and `'Create Role Mapping'` both map to `kind:'framework'`. **One-line fix on an S1 golden-thread-1 break.** Scheduled |
