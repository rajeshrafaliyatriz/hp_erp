# RECOVERED SECTIONS — 00-progress.md

**Source:** an intact snapshot of `docs/phase3/00-progress.md`, captured at the
point where Gate C had just opened (both calibrations complete and clean, 2 of 32
sub-modules written up).

**Why this exists:** the reconstruction written after the file was truncated
contains **zero dated decision rows** (the original had 43) and **no Deferred
scope table at all**. Those are the sections whose detail lived only here.
Everything else in the reconstruction is current and correct.

## HOW TO USE THIS — MERGE, DO NOT REPLACE

The reconstruction is NEWER and correct on: gate state, the C17/C18 plan,
G-DATA-06, standing rules R1-R17, the sweep conclusion, the module tally (17 of
32) and the Gate D build thread. **Keep all of that.**

This file is OLDER and covers exactly the window the reconstruction lost: the
Gate A/B decision log and the deferred-scope register.

Merge procedure:
1. Paste the three sections below into the reconstruction as new sections.
2. Append decisions taken AFTER this snapshot — recover them from
   `10-open-questions.md`, `07-gap-register.md` and `09-implementation-log.md`,
   which are intact. Known post-snapshot decisions: the Q-L1/L2/L3 answers, C19
   (picker mechanism), C23-C38, R5-R17, the G-SEC-09 and G-SEC-10 fixes, the S-2
   and S-5 retirements, and the G-DATA-08 tenant-column decision.
3. Reconcile the "Open questions" table against `10-open-questions.md` — the
   snapshot lists items since answered.
4. **Commit the file to git before anything else.** It was untracked through a
   40-turn engagement; that is the actual root cause of this loss.

---

## Decisions made

| Date | Ref | Decision |
|---|---|---|
| 2026-08-05 | — | Inventory is generated from `tblmenumaster_g2g` (the live nav tree) cross-referenced against the frontend content maps — not from the brief's scope list. The brief is treated as a hint per §2.2. |
| 2026-08-05 | — | The 3,378-element raw agent inventory is kept in `_raw-inventory/` as **unverified input**, not a deliverable. Every row is re-checked by hand before entering `06-feature-audit/`. |
| 2026-08-05 | **Q-A1** | **Organization owns JobRole identity** (code, title, department, grade, reporting line). Competency owns the **capability definition** only. Identity fields become read-only in Competency → Library & Taxonomy → Job Role tab, enforced **server-side**. One table, no duplicate. |
| 2026-08-05 | **Q-A2** | **Competency = a named bundle of KASBA items. Skill is one of the five KASBA dimensions, not a synonym.** Library & Taxonomy, Competency Library and Command Center forms all restructure around this. |
| 2026-08-05 | **Q-A3** | Non-live nav rows triaged in one pass → `01b-scope-triage.md`: **12 SHIP, 27 DEFER, 65 DELETE, 1 HOLD**. Flows are designed only for SHIP. Awaiting single-pass approval. |
| 2026-08-05 | **Q-A4** | **Agentic AI = IN** (live and reachable). **Reports = IN as a consolidation decision only** — one reporting home reading all modules, not per-module report screens. **CRM = DEFERRED, not deleted** — code and data intact, hidden from nav, no Phase 3 work. |
| 2026-08-05 | **Q-A5 (partial)** | **"Pal" (id 187) — remove from navigation.** **"Skills" (id 24) — HOLD**, pending the form's field list. Investigation complete: data flow is one-way out, no identifiers passed, `noreferrer` set, referenced nowhere but the menu row. |
| 2026-08-05 | **Q-B1** | **Add a Manager / Department Head role AND a reporting-manager field**, as a prerequisite to any approval or team-scope flow. Role model + scope rules to be reviewed before implementation. |
| 2026-08-05 | **Q-B2** | Course completion raising proficiency is a **tenant setting, overridable per competency**. Critical/regulated competencies require a **passed assessment**; others may auto-raise on completion. Default = assessment required. |
| 2026-08-05 | **Q-B3** | **Never auto-lower a rating.** Evidence recorded immediately on every overdue/rejected/reopened/failed task. Manager flag at **3 failures in 90 days on the same job-role task**. Remediation shown immediately regardless. Proficiency changes only on **explicit manager confirmation**. Threshold and window **tenant-configurable**. |
| 2026-08-05 | **Q-B4** | Keep the existing course record; add **`course_competency_map (course_id, skill_id, proficiency_level, is_primary)`**. **Highest-priority item in the connection plan.** Also plan migration of `sub_std_map.jobrole` from longtext to a real FK. |
| 2026-08-05 | **Q-B5** | **Restore all three missing tables** (`competency_evidence`, `competency_certification_requirements`, `s_skill_jobrole`), with a root-cause explanation and a recurrence guard. |
| 2026-08-05 | **Q-C4** | `hpbrain_*` is **HP Enterprise Brain** — Triz's other product (repo `hpbrain_backend`, Laravel 11), sharing this database. **Option B:** build G2G's connection layer **natively in G2G**; no runtime dependency on hpbrain tables. **Harvest its schema design deliberately** — event store ← `hpbrain_event_store`, threshold engine ← `hpbrain_signal_rules`, evidence ledger ← `hpbrain_evidence`, industry configurability ← `hpbrain_industries`/`_industry_templates`. Justify every deviation. Shared DB is a **risk to document**, and the likely root cause of Q-B5. The single cross-write (`LmsGovernanceController` → `hpbrain_audit_logs`) is **to be removed** — G2G writes its own audit log. Future Enterprise-Brain-as-intelligence-layer is **API contract only, never shared tables** — explicitly not Phase 3 work. |
| 2026-08-05 | **Q-A3 amendments** | (1) A **notification/alert service IS in Phase 3 scope** even though the 5 manual send-message screens are deferred — design it in `05-data-flow-contracts.md`. (2) LMS rows 77/86/78/87/88 → **HOLD-FOR-GATE-C**, deletion approved in principle but not executed until the competency audit confirms "Self skill rating" has nothing worth harvesting. (3) Document Management (97/112) → resolve in the Gate C onboarding audit; becomes SHIP if onboarding has nowhere to store documents. (4) **No nav row is removed without a reversible SQL script + a `tblmenumaster_g2g` backup**, applied as one reviewed change. Rows 184/185 backends harvested into the consolidated reporting home. |
| 2026-08-05 | **Q-A5 final** | **"Skills" (id 24) — REMOVE.** Obsolete; Employee Profiles already covers per-employee skill capture and Q-A2 makes Competency the owner. No rebuild. |
| 2026-08-05 | **Q-C1** | `s_jobrole_skills` becomes a **seed library tenants import from**. Add tenant-owned `jobrole_competency_map (sub_institute_id, jobrole_id, competency_id, required_proficiency, is_mandatory)`. **Design the import flow as a real feature** — "a new customer starts with a populated library, not a blank screen" is a selling point. |
| 2026-08-05 | **Q-C2** | Add `competency` (tenant-scoped, with `requires_assessment` per Q-B2) and `competency_kasba_item (competency_id, kasba_type, item_id, weight)`. `s_users_skills` becomes the **Skill-dimension library**, not the competency store. |
| 2026-08-05 | **Q-C3** | Add `jobrole_task_competency_map (jobrole_task_id, competency_id)`. Also plan migration of `s_user_jobrole_task` from text keys to real FKs — golden thread 2 depends on it resolving reliably. |
| 2026-08-05 | **Connection layer** | The five join tables (`course_competency_map`, `jobrole_competency_map`, `competency`, `competency_kasba_item`, `jobrole_task_competency_map`) are **one coherent schema change**, not five. **Full ER diagram required before implementation.** |
| 2026-08-05 | **Reporting** | The consolidated home **must** include a **competency gap report**, a **development plan report** and a **certification expiry report** — none of the 45 legacy reports covered these. |
| 2026-08-05 | **RBAC approved** | Role model, 4 scopes, 3 enforcement layers, `tbluser.reporting_manager_id` + `hrms_departments.head_user_id`, `role_key`/`data_scope` columns, and the 9-step sequencing — all **APPROVED**. Reuse of the `hrms_leave_role_permissions` vocabulary confirmed as the right call. Instructor/Assessor stay **assignments, not roles**. **Payroll stays hidden from Reporting Manager and Department Head.** |
| 2026-08-05 | **A1** | Employee Directory: Employee scope corrected from *self only* to **org-wide BASIC directory** (name, job title, department, work contact, manager). Sensitive fields remain restricted. |
| 2026-08-05 | **A2** | **Field-level permissions required** — screen-level is insufficient for employee record (salary, personal contact, documents), performance review (manager-only comments), competency ratings (who sees whose), payslips. → `03-rbac-matrix.md` §3.8, via named field groups + an API resource layer so restricted fields are **absent from the payload**. |
| 2026-08-05 | **A3** | **Route-to-menu map is an explicit deliverable before step 7.** Delivered: `_evidence/route-to-menu-map.csv` + `authorization-coverage.json`, via `scripts/audit-authorization.py`. **158 routes map to no menu** → `G-SEC-02`. Mapping to be made **explicit** in `routes/api.php`, not inferred. |
| 2026-08-05 | **A4** | **Delegation / acting manager** recorded in the model and in deferred scope. Not Phase 3 build work. Two rules designed in now: audit records **both** parties ("B acting for A"), and delegation **never widens scope**. |
| 2026-08-05 | **A5** | **Skip-level is a tenant setting** — `team_scope_depth`, default **direct reports only**. Cycle validation + depth bounding confirmed as **step 4** work, incl. on bulk import. |
| 2026-08-05 | **A6** | **Individual rights precedence:** individual DENY → individual GRANT → group GRANT → default deny. **Explicit DENY always wins.** Requires a tri-state on `tblindividual_rights` (grant/deny/inherit) — today a `0` is indistinguishable from "no row". **Scope is never individually overridable.** |
| 2026-08-05 | **A7** | **Leave module convergence scheduled**, not assumed. Steps 1–2 inside Phase 3 (add `role_key`, resolve approver via shared model); steps 3–4 post-Phase-3 (fold flags into the rights matrix, drop the local table). Until then `hrms_leave_role_permissions` is **read-only config — no new writers**. |
| 2026-08-05 | **Q-D1** | **Recruiter IS role 9.** Full CRUD on Recruitment; **no** access to Performance, Payroll, Competency ratings; basic-fields-only on Employee Directory. Retains read of job-role competency requirements so requisitions/scorecards generate from the framework (golden thread 7). |
| 2026-08-05 | **Q-D2** | Single role for now, **with the condition** that all authorization resolves through **one accessor returning a collection**. Direct `user_profile_id` comparisons in controllers are **prohibited**. Moving to `user_roles` later must be a data migration, not a rewrite. |
| 2026-08-05 | **Q-D3** | **Executive and Auditor stay separate.** Auditor: read + export **including audit logs**. Executive: dashboards + exception approval, **no audit log access**. |
| 2026-08-05 | **Security** | The unguarded-route finding is carried as **G-SEC-01, severity S1** in `07-gap-register.md`, with the full controller breakdown, and will be **sequenced highest-risk-first** in `08-connection-plan.md`. |
| 2026-08-05 | **Change template** | `_changes/G-NAV-01-*` is the **standard template for every data or code change at Gate D**: backup first → guard query → stated blast radius → exact rollback → G-SEC-05 pre-check. **Do not deviate.** |
| 2026-08-05 | **Verification rule** | **No number from an audit script is quoted in a document until cross-checked by a SECOND independent method** — a different parser, a manual count of a sample, or a query from the other side. Internal-consistency checks confirm consistency, **not completeness**, and cannot catch a tool's own blind spot (the 52-route miss, G-QUAL-02). |
| 2026-08-05 | **Q-D4** | **Candidate = separate portal identity.** Own table, guard and token; never resolvable in any internal module; explicit auditable candidate→employee conversion at hire; offer e-signature binds to that identity. **Phase 3 defines the model, isolation boundary and conversion step — it does NOT build the portal** (deferred scope). External trainers/vendors: same pattern, deferred; the model must generalise (hence `portal_identity` with a type discriminator). |
| 2026-08-05 | **G-SEC-06** | Approved as **S2**. Rights value becomes **tri-state (ALLOW / DENY / INHERIT)**, INHERIT the default, absence of a row also = inherit. **Same shape applied to `tblgroupwise_rights_g2g`** so both tables are consistent. Resolution order: **individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny.** |
| 2026-08-05 | **G-SEC-07 actions** | (1) `03-rbac-matrix.md` §1.2 and §4.2 corrected — the matrix **must be populated before enforcement**, it is not "a reader away". (2) **Seed source is §3.1–3.7** — those tables become a seeder; permissions are **not** re-derived. (3) §4.4 corrected — the claim that nothing before step 7 changes behaviour was **false**; the sidebar filters live on `can_view`. (4) New **§4.5 rollout plan**: seed a non-production tenant → per-role before/after menu diff → **Triz reviews every lost screen** → amend → roll out per tenant with the backup/rollback template. |
| 2026-08-05 | **G-FLOW-05** | Investigated. **Not a defect.** Issuance is fully wired (button → hook → service → route → insert) and is a manual claim gated on full content completion. Zero certificates is correct: of 1,426 enrolments, **1,425 `enrolled`, 1 `completed`**, and `lms_content_progress` has **1 row for 1 user**. **No customer can have been promised certificates.** Real finding is a funnel collapse — enrolment happens, consumption does not. Recommend auto-issue on completion via `course.completed`. |
| 2026-08-05 | **Import flow elevated** | `G-FLOW-03` + Q-C1: 169 capability rows for 386 users means the chain will be structurally correct and **visibly empty**. The seed-library import is a **first-class Phase 3 feature**, specified in `02-domain-model.md` alongside the join tables. |
| 2026-08-05 | **Custom-task split** | The existing `catalogue` / `custom` split in `create-task-modal.tsx:505` — including promotion into the Job Role Task library — is **carried forward as the foundation for golden thread 2**. No new mechanism designed. |
| 2026-08-05 | **§12 pattern** | "What this role must never see" is a **required section in every subsequent role flow**. |
| 2026-08-05 | **G-SEC-04 method** | ~537 accepted as the honest task size. **Not attempted in one pass** — sequenced by the same eight risk groups as G-SEC-01, starting Performance then Competency master data. **Regression guard required**: a check that fails when a new route is added without a menu declaration, so the gap cannot regrow. Recorded as a Gate D item. |

## Deferred scope — recorded so it is not silently lost

| Item | Decision | Ref |
|---|---|---|
| **CRM** (Marketing, Leads, Master Fields) | Deferred to a later version. Code and data intact, hidden from navigation, no Phase 3 design or connection work | Q-A4 |
| 27 further nav rows | Deferred — see `01b-scope-triage.md` for the itemised list and reasons | Q-A3 |
| **Applicant-facing candidate portal** | Phase 3 defines the identity model, isolation boundary and conversion step. **Building the portal is a separate deliverable** | Q-D4 |
| **External trainer / vendor identities** | Same pattern as Candidate, deferred. The identity model must generalise to them (`portal_identity` + type discriminator) but they are not designed now | Q-D4 |
| **Delegation / acting manager** | Approval delegation for a date range. Not Phase 3 build work; two rules designed in now (audit records both parties; delegation never widens scope) | A4 |
| **Leave module convergence steps 3–4** | Fold local leave flags into the shared rights matrix, then drop `hrms_leave_role_permissions`. First post-Phase-3 items | A7 |

---

## Open questions awaiting Triz's confirmation

Full detail in `10-open-questions.md`. Outstanding:

| Ref | Question | Blocks |
|---|---|---|
| **Q-C4** | **What is the parallel `hpbrain_*` system?** ~120 tables, own migration system, containing an event store, an industry/tenant-configurable signal-rule engine, KASBA-per-capability columns and an evidence ledger — i.e. the exact architecture Phase 3 needs. Adopt it, copy its design, or treat it as a separate product? | **`02-domain-model.md`** |
| **Q-A3** | Single-pass approval of the 104-row triage (12 SHIP / 27 DEFER / 65 DELETE / 1 HOLD) | Scope of flows in Gate B |
| **Q-A5** | "Skills" (id 24) — need the external form's field list, or confirmation it is obsolete | Nothing; recommendation is *remove* |
| Q-C1 | Where do **tenant-specific job-role capability definitions** live? `s_jobrole_skills` is global, string-keyed, not tenant-scoped | Domain model |
| Q-C2 | What table holds a **Competency as a bundle of KASBA**? None exists | Domain model |
| Q-C3 | How does a **task link to KASBA**? `s_user_jobrole_task` is text-keyed with no skill FK | Golden thread 2 |

Q-C1–Q-C3 each have a concrete recommendation in `10-open-questions.md`; they are
design proposals for your review, not blockers.

---

## Changes executed

**Application code: none.** Phase 3 remains read-only on code until Gate D (§2.1).

| Date | Change | Type | Reversible | Artefacts |
|---|---|---|---|---|
| 2026-08-05 | **G-NAV-01** — menu row 219 `access_link` corrected so Task → Permission reaches the Permission screen instead of Priority Management | **Data only** (1 row, 1 column) | Yes — rollback SQL provided; row restores byte-identical | `_changes/backup-tblmenumaster_g2g-2026-08-05.sql`, `_changes/G-NAV-01-fix-permission-menu-link.sql`, `_changes/G-NAV-01-apply.php` |

Verified after: nav cross-reference reports **0 duplicate access_links** (was 1),
broken-nav still 0.

Documentation created:
- `docs/phase3/00-progress.md` (this file)
- `docs/phase3/01-inventory.md`
- `docs/phase3/10-open-questions.md`
- `docs/phase3/_evidence/*` — reproducible extraction scripts + their output
- `docs/phase3/_raw-inventory/*` — 3,378-element raw feature catalogue (input, not deliverable)
