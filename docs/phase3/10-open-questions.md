# 10 — Question register

**The single register of every question raised in Phase 3.** Per working rule §2.6:
flag, do not guess. Status here must always match `00-progress.md`.

Status: `OPEN` | `ANSWERED` | `SUPERSEDED`

---

## Index — every Q-* ever raised

| Ref | Question | Status | Decision (one line) | Detail |
|---|---|---|---|---|
| **Q-A1** | Where is JobRole mastered? | `ANSWERED` | Organization owns **identity**; Competency owns the **capability definition**. One table, identity fields read-only in Competency, enforced server-side | §Q-A1 below |
| **Q-A2** | Skill vs Competency — one entity or two? | `ANSWERED` | **Competency = a named bundle of KASBA items; Skill is one of the five dimensions**, not a synonym | §Q-A2 below |
| **Q-A3** | What happens to the non-live nav rows? | `ANSWERED` | Triaged in one pass: **12 SHIP · 27 DEFER · 65 DELETE · 1 HOLD**, +4 amendments | `01b-scope-triage.md` |
| **Q-A4** | Agentic AI / Reports / CRM in or out? | `ANSWERED` | Agentic **IN**; Reports **IN as a consolidation decision only**; CRM **DEFERRED, not deleted** | §Q-A4 below |
| **Q-A5** | Two external hosts in navigation | `ANSWERED` | **Both removed.** "Pal" removed; "Skills" removed as obsolete — Employee Profiles already covers per-employee skill capture | §Q-A5 below |
| **Q-B1** | Add a Manager / Department Head role? | `ANSWERED` | **Approved as a prerequisite**, with `tbluser.reporting_manager_id` + `hrms_departments.head_user_id` | `03-rbac-matrix.md` §2 |
| **Q-B2** | Does course completion auto-raise competency? | `ANSWERED` | **Tenant setting, overridable per competency.** Critical/regulated require a passed assessment; default = assessment required | §Q-B2 below |
| **Q-B3** | Task → competency signal thresholds | `ANSWERED` | **Never auto-lower.** Evidence immediately; manager flag at 3 failures / 90 days; proficiency changes only on explicit manager confirmation; thresholds tenant-configurable | §Q-B3 below |
| **Q-B4** | Which table is the canonical Course? | `ANSWERED` | Keep `sub_std_map`; add **`course_competency_map`**. Highest-priority connection item. Migrate `jobrole` longtext → FK | §Q-B4 below |
| **Q-B5** | Restore the three missing tables? | `ANSWERED` | **Restore all three**, with root cause (shared DB, two migration systems) and a recurrence guard | §Q-B5 below |
| **Q-C1** | Where do tenant job-role capability definitions live? | `ANSWERED` | `s_jobrole_skills` becomes a **seed library tenants import from**; add tenant-owned **`jobrole_competency_map`**. Import flow is a real feature | §Q-C1 below |
| **Q-C2** | What table holds a Competency as a bundle? | `ANSWERED` | Add **`competency`** (with `requires_assessment`) + **`competency_kasba_item`**. `s_users_skills` becomes the Skill-dimension library | §Q-C2 below |
| **Q-C3** | How does a task link to KASBA? | `ANSWERED` | Add **`jobrole_task_competency_map`**; migrate `s_user_jobrole_task` from text keys to FKs | §Q-C3 below |
| **Q-C4** | What is the parallel `hpbrain_*` system? | `ANSWERED` | **HP Enterprise Brain — Triz's other product, shares this DB. Option B:** build G2G's layer natively, **harvest** the schema design, treat the shared DB as a documented risk, remove the single cross-write, future integration is API-only | §Q-C4 below |
| **Q-D1** | Is Recruiter a distinct role? | `ANSWERED` | **Yes — role 9.** Full CRUD on Recruitment; no Performance, Payroll or Competency ratings; basic-fields-only on the directory | `03-rbac-matrix.md` §5B |
| **Q-D2** | Can one person hold two roles? | `ANSWERED` | **Single role for now**, but all authorization resolves through **one accessor returning a collection**. Direct `user_profile_id` checks prohibited | `03-rbac-matrix.md` §5B |
| **Q-D3** | Executive and Auditor — one role or two? | `ANSWERED` | **Two.** Auditor reads audit logs + exports; Executive gets dashboards and exception approval, **no audit log access** | `03-rbac-matrix.md` §5B |
| **Q-D4** | Is Candidate a role or a separate identity? | `ANSWERED` | **Separate portal identity** — own table, guard, token; never resolvable in any internal module. **Phase 3 defines the model, isolation boundary and conversion step; the portal itself is deferred** | §Q-D4 below |
| **Q-E1** | What populates `task.skill_id`? | `ANSWERED` | **Hand-picked at creation** (`taskController.php:509`), job-role-suggested. **Catalogue wins**, instance is an override tagged `confidence`. **33% null → no signal, do not guess** | `02-domain-model.md` §3 |
| **Q-E2** | Is competency the unit of measurement? | `ANSWERED` | **Option (a)** — measure per KASBA item, derive a weighted roll-up. One service, two numbers reported, unmeasured ≠ zero | `02-domain-model.md` §11.5 |
| **Q-F1** | Notification content and language | `ANSWERED` | **Fixed wording + tenant-substitutable terminology.** Both tables built now, so a tenant renaming *employee* → *clinician* is data entry, not a refactor. Resolution ladder mirrors the rights resolver | `05-data-flow-contracts.md` §8 |
| **Q-L1** | Of the **25** Library free-text fields with no consumer, are any *meant* to drive behaviour? | `ANSWERED` | **BIND 10 · NOTE 13 · SUBSTITUTE 2.** BINDs become L-15…L-23, each carrying a typing change (R-a); two bind to existing fields rather than new systems (R-b); three are display-only (R-c). NOTEs are **not gaps** | `06-feature-audit/competency-library-taxonomy.md` §6 |
| **Q-L2** | When a skill is retired, does it vanish from open assessments or persist until the cycle closes? | `ANSWERED` | **Persist. Filter at ASSIGNMENT time, not read time.** Retiring blocks new assignments, leaves open ones untouched — an in-flight assessment is a measurement being taken. Same principle as block-don't-cascade soft delete: housekeeping must not rewrite measurement history | same |
| **Q-L3** | Should the 7 taxonomies share one category vocabulary, or is per-entity categorisation intentional? | `ANSWERED` | **One shared category table with per-taxonomy applicability** — each category declares which of the 7 it applies to. Enables cross-taxonomy reporting without forcing irrelevant categories onto every tab. L-12/L-13 stay in scope, built to that shape | same |

**24 questions · 24 answered · 0 open.** New questions are appended here as they arise.

---

## Q-A1 — Where is JobRole mastered? · `ANSWERED`

**Decision:** Organization owns job role **identity** (code, title, department,
grade, reporting line). Competency owns the **capability definition** only.
Identity fields become read-only in Competency → Library & Taxonomy → Job Role tab.
Both editors write to one table — no duplicate.

**Consequences to carry into the design:**
- `s_user_jobrole` stays the single table. The Competency tab needs field-level
  read-only enforcement, not a separate store.
- Read-only must be enforced **server-side**, not by hiding inputs — the Phase 1
  audit found that pattern repeatedly.
- The capability definition needs somewhere to live. `s_jobrole_skills` (62,208
  rows, global reference) is jobrole→skill→proficiency but is **string-keyed and
  not tenant-scoped**, so it cannot hold a tenant's own definitions. A
  tenant-scoped, FK-keyed equivalent is required. → **new Q-C1**

---

## Q-A2 — Skill vs Competency · `ANSWERED`

**Decision:** Option (a). **Competency = a named bundle of KASBA items. Skill is one
of the five KASBA dimensions, not a synonym for competency.** Restructure Library &
Taxonomy around this; make Competency Library and Command Center forms consistent
with it.

**Consequences:**
- Resolves D2 and D3: the Skill tab and Competency Library are no longer rivals —
  Skill becomes one KASBA dimension among five, and "Competency" becomes a new
  first-class entity that groups them.
- **There is currently no table for a competency-as-bundle.** `s_users_skills`
  holds flat skill definitions; `s_skill_matrix.type` enumerates the five
  dimensions per employee rating. Nothing models "competency X consists of these
  K/A/S/B/A items". → **new Q-C2**
- The existing `type ENUM('skill','knowledge','ability','attitude','behaviour')`
  on `s_skill_matrix` already matches this model, which is good evidence the
  decision fits the grain of the data.

---

## Q-A3 — The 125 non-live nav rows · `ANSWERED`

**Decision:** produce one grouped list with a recommendation per row; approve in a
single pass; design flows only for **SHIP**.

**Delivered:** `01b-scope-triage.md` — 104 non-live rows, grouped by module.
**12 SHIP · 27 DEFER · 65 DELETE · 1 HOLD.** **Approved 2026-08-05** with four
amendments (notification service in scope; LMS rows 77/86/78/87/88 HOLD-FOR-GATE-C;
Document Management resolved in the Gate C onboarding audit; no nav row deleted
without reversible SQL + backup). The 1 HOLD is now resolved — see Q-A5.

*(The 125 figure in the original question double-counted rows that are both
disabled and hidden behind a disabled ancestor. The true count of distinct
non-live rows is 104.)*

---

## Q-A4 — Agentic AI / Reports / CRM · `ANSWERED`

**Decision:**
- **Agentic AI — IN.** Live and reachable.
- **Reports — IN, as a consolidation decision only.** One reporting home reading
  from all modules; not separate report screens per module.
- **CRM — DEFERRED, not deleted.** Keep code and data intact, hide from
  navigation, no Phase 3 design work, recorded as deferred scope.

**Consequences:** the 45 legacy Reports rows are superseded. Their titles are
harvested as requirements in `01b-scope-triage.md` §8. That harvest surfaced
something notable: **there is no competency gap report, no development-plan report
and no certification-expiry report anywhere in the existing 45** — the three things
the product is sold on are the three nobody built reporting for.

---

## Q-A5 — Two external hosts in navigation · `ANSWERED`

**Decision:** **both removed.** "Pal" (id 187) — remove from navigation.
"Skills" (id 24) — **remove as obsolete**: Employee Profiles already covers
per-employee skill capture, and Q-A2 makes Competency the owner of skill data. No
rebuild needed. Both applied with the other nav changes, through the
`_changes/G-NAV-01-*` template.

The investigation that led to this is kept below.

### Investigation findings

| # | Your question | Finding | Confidence |
|---|---|---|---|
| 1 | What does the form do / what data does it collect? | **Could not determine.** `form-scholar-clone.vercel.app` is a client-rendered SPA titled "Form Builder". A static fetch returns only the app shell — the form definition loads via JavaScript. The URL `/submit/9b13d496-362e-45b4-b9f8-de0867b94521` identifies one specific form instance | **Unresolved** |
| 2 | Where does submitted data go — anything back into hp_erp? | **Nothing comes back.** No controller, route, model, service or config in either repo references this host, and there is no inbound webhook or import path. Data flow is **one-way out** | **Verified** |
| 3 | Is any employee or tenant identifier passed? | **No.** The stored `access_link` is a static URL with no placeholders. The frontend does not append anything: `gtg-app-shell.tsx:154-156` opens external links with `window.open(path, '_blank', 'noopener,noreferrer')` — `noreferrer` also suppresses the `Referer` header, so the host does not even learn which page linked to it. **Whatever the form collects, the user types in by hand** | **Verified** |
| 4 | Which feature was it meant to serve? Does an internal screen cover it? | It sits at Organizational Management → User Management, `sort_order` 4, between Role & Permissions and Certifications — i.e. **per-employee skills capture**. **Yes, covered internally:** Competency → Employee Profiles already writes per-employee ratings to `s_skill_matrix`, and Competency Library owns skill definitions | **Verified** |
| 5 | Referenced anywhere besides the menu row? | **No.** Greps across both repos for the host, `form-scholar`, and the form UUID return nothing outside `tblmenumaster_g2g` | **Verified** |

Both rows were created `2025-06-18 08:06:41` — the same seed batch as the whole
menu — and both have `page_type = 'link'`, a distinct type the app treats as
"open externally". So they were deliberate placeholders in the original menu
design, not accidents.

### Options

| Option | What it means | Assessment |
|---|---|---|
| **A — Remove** | Delete the nav row | **Recommended.** The need is already met internally by Competency → Employee Profiles, and Q-A2 makes Competency the owner of skill data. An external form cannot write to `s_skill_matrix`, so anything captured there is stranded outside the product you are selling |
| **B — Rebuild internally** | Build the same capture as a native screen | Only if the form collects something Employee Profiles does not. Cannot be judged until question 1 is answered |
| **C — Keep as external link with consent** | Leave it, add a disclosure interstitial | Weakest option. It stays one-way, the data never enters the product, and a corporate security review will still ask about it |

**To close this I need one of:** the form's field list (a screenshot is enough), or
confirmation that it is obsolete. Given finding 4, my recommendation is **A**
unless the form captures something Employee Profiles does not.

**Not a Gate A or Gate B blocker.** Work continues.

---

## Q-B1 — Manager / Department Head role · `ANSWERED`

**Decision:** APPROVED as a prerequisite. Add a Manager / Department Head role
**and** a reporting-manager field on the employee record, before designing any flow
involving approval or team-level scope. **Show the proposed role model and scope
rules (self / team / department / org) before implementing.**

→ Delivered in `03-rbac-matrix.md`.

---

## Q-B2 — Does course completion auto-raise competency? · `ANSWERED`

**Decision:** Option (c), defaulting to (b). Tenant-level setting, overridable per
competency: critical and regulated competencies require a **passed assessment**
before proficiency rises; others may auto-raise on course completion.

**Consequences:** needs a per-competency flag (e.g. `requires_assessment`) plus a
tenant default. `s_competency_settings` already exists as a key/value store with a
`scope`/`scope_id` shape, so the tenant default has a home; the per-competency
override needs a column on the competency record.

---

## Q-B3 — Task → competency signal thresholds · `ANSWERED`

**Decision:** never auto-lower a rating.

| Rule | Value |
|---|---|
| Evidence record created | Immediately, on every overdue / rejected / reopened / failed task, against the linked KASBA |
| Manager flag raised | 3 failures in 90 days on the same job-role task |
| Recommended course / remediation shown | Immediately, regardless of threshold |
| Proficiency change | Only after **explicit manager confirmation** |
| Threshold + window | **Tenant-configurable**, not hardcoded |

**Consequences:** requires (a) the `competency_evidence` table restored (Q-B5),
(b) a manager role to confirm against (Q-B1), (c) a task→KASBA link, which today
exists only as strings in `s_user_jobrole_task`. → **new Q-C3**

---

## Q-B4 — Canonical Course · `ANSWERED`

**Decision:** APPROVED. Keep the existing course record for now; add
`course_competency_map (course_id, skill_id, proficiency_level, is_primary)`.
**Highest-priority item in the connection plan** — golden threads 2 and 3 cannot be
built before it exists. Also plan migration away from the `jobrole` longtext to a
real foreign key.

---

## Q-B5 — Restore three missing tables · `ANSWERED`

**Decision:** restore `competency_evidence`,
`competency_certification_requirements`, `s_skill_jobrole`. Explain the cause and
prevent recurrence. → Answered in `02-domain-model.md` §7.

---

# New questions raised during Gate B

### Q-C1 — Where do tenant-specific job-role capability definitions live? · `ANSWERED`

**Decision:** `s_jobrole_skills` becomes a **seed library tenants import from**; add
tenant-owned `jobrole_competency_map`. **The import flow is a first-class Phase 3
feature** (elevated 2026-08-05, see G-FLOW-03) — it is what lets a new customer
see a populated product on day one. Specified in `02-domain-model.md`.
Q-A1 gives Competency ownership of the capability definition, but there is no
tenant-scoped table for it. `s_jobrole_skills` (62,208 rows) is **global reference
data, string-keyed, no `sub_institute_id`** — a shared industry taxonomy, not a
per-customer definition.

**Recommendation:** treat `s_jobrole_skills` as a seed *library* customers import
from, and add `jobrole_competency_map (sub_institute_id, jobrole_id, competency_id,
required_proficiency, is_mandatory)` as the tenant-owned definition. This is the
table golden thread 1 resolves "required KASBA at required proficiency" against.

### Q-C2 — What table holds a Competency as a bundle? · `ANSWERED`

**Decision:** add `competency` (tenant-scoped, with `requires_assessment` per Q-B2)
and `competency_kasba_item`. `s_users_skills` becomes the Skill-dimension library.
Q-A2 makes Competency a named bundle of KASBA items. No such table exists.

**Recommendation:** `competency` (tenant-scoped: id, code, name, description, type,
`requires_assessment` for Q-B2) plus `competency_kasba_item (competency_id,
kasba_type, item_id, weight)`. `s_users_skills` then becomes the Skill-dimension
library rather than the competency store.

### Q-C3 — How does a task link to KASBA? · `ANSWERED`

**Decision:** add `jobrole_task_competency_map`; migrate `s_user_jobrole_task`
from text keys to real FKs.
Q-B3 needs "the linked KASBA" for a job-role task. Today `s_user_jobrole_task`
(85,663 rows) holds `jobrole` and `task` as **text**, with no skill FK.

**Recommendation:** `jobrole_task_competency_map (jobrole_task_id, competency_id)`.
Without it golden thread 2 has nothing to attach evidence to.

*(These three plus Q-B4's `course_competency_map` are the four join tables the whole
connection plan rests on. All four are absent today.)*

---

### Q-C4 — There is a second, parallel system in your database · `ANSWERED`

**This is the largest architectural discovery of Gate B and it changes what Phase 3
should build.**

The database contains **~120 `hpbrain_*` tables** managed by their own migration
system (`hpbrain_schema_migrations`, 38 entries) — i.e. a **separate application
writes to the same database**. Only one line of the Laravel app touches any of it
(`LmsGovernanceController` writes audit rows to `hpbrain_audit_logs`).

It is almost entirely unpopulated — 1 organization, 4 departments, 2 people — so it
is not running your business. But its **design is precisely what Phase 3 needs**:

| hpbrain table | Rows | What it is | Phase 3 relevance |
|---|---:|---|---|
| `hpbrain_event_store` | 82 | `type, tenant_id, entity_type, entity_id, actor_id, payload, correlation_id, causation_id, idempotency_key, status, retry_count, processed_at` | A **properly designed event store**. `05-data-flow-contracts.md` needs exactly this, and the Laravel side has no event mechanism at all |
| `hpbrain_signal_rules` | 5 | `tenant_id, industry_code, rule_key, universal_entity, predicate, threshold_op, threshold_value, recommended_action, owner_role, is_active` | A **tenant- and industry-configurable rule engine with thresholds**. This is Q-B3's "threshold and window must be tenant-configurable" and your domain-agnostic requirement, already modelled |
| `hpbrain_capabilities` | 49 | `capability_code, name, category, capability_type, difficulty, criticality, version, status` + columns `knowledge, ability, skill, behaviour, attitude` | **KASBA as first-class columns on a capability** — this is Q-A2's "competency = a bundle of KASBA items", already designed |
| `hpbrain_evidence` | 63 | `signal_id, evidence_type, content, provenance, confidence, hash, ledger_sequence` | An **evidence ledger**. Q-B3 requires evidence records; `competency_evidence` is one of the three missing tables |
| `hpbrain_industries` / `hpbrain_industry_templates` | 11 / 11 | Industry-keyed templates | Your "domain-agnostic, sell across all industries" requirement |

**What it is actually doing today:** the 5 signal rules are *data-quality* checks
(`people_without_profile`, `people_without_department`, `people_without_email`,
`inactive_users_in_active_departments`, `departments_without_manager`), and the 82
events are AI-conversation logs (`SessionStarted`, `ObservationMade`,
`SubjectSelected`). It is not operating on competency, learning or task data.

*(Worth noting: `departments_without_manager` exists as a rule — whoever built this
had already identified the missing-manager problem that Q-B1 now addresses.)*

**Why this blocks the domain model:** I am about to specify an event catalogue, a
signal/threshold mechanism, an evidence store and industry-configurable rules. All
four already exist here, designed better than I would specify them from scratch.
Writing `02-domain-model.md` without knowing this system's status risks
specifying a **third** parallel model.

**The question:** what is `hpbrain_*`?

| Option | Implication for Phase 3 |
|---|---|
| **A — It is the intended future architecture** | Phase 3's connection layer should be built **on** it: emit to `hpbrain_event_store`, express golden thread 2 as an `hpbrain_signal_rule`, write evidence to `hpbrain_evidence`. Biggest payoff, needs the other team's cooperation and a shared contract |
| **B — Abandoned or paused prototype** | Build the connection layer natively in Laravel, but **harvest the schema design** — it is good, and copying it costs nothing |
| **C — A separate product that merely shares the database** | Treat as out of scope, but flag the shared-database coupling as a risk; two systems migrating one schema is how tables go missing (see Q-B5) |

**My recommendation:** B if you own it and it is paused; A if it is active and you
control the roadmap. Either way, **do not invent a third event/signal model** — I
would rather adopt or copy this one.

**I need to know before writing `02-domain-model.md`.** Everything else in Gate B
(RBAC, user flows) can proceed regardless, and will.

**ANSWERED 2026-08-05 — Option B.** hpbrain is HP Enterprise Brain, Triz's other
product sharing this database. Build G2G's connection layer natively; harvest the
schema design deliberately; treat the shared database as a documented risk and the
likely root cause of Q-B5; remove the single cross-write; any future integration is
API-contract only, never shared tables. Recorded in `00-progress.md`.

---

### Q-D4 — Is **Candidate** a role, or a separate portal identity? · `ANSWERED`

`spec-TalentManagementFeatures.txt` §5 defines Candidate as a first-class actor:
job portal browse/search, profile creation, resume upload and parsing, quick apply,
application status tracking, self-service interview slot booking, document upload,
assessment links, **offer view and e-signature**, and pre-boarding portal access
before transitioning to employee LMS/Competency access.

`03-rbac-matrix.md` does not cover this, and it should not be bolted on: **a
candidate is not "an Employee with fewer rights."** They are an external party who
must never be resolvable inside any internal module.

| | Employee-shaped role | **Separate portal identity** |
|---|---|---|
| Authentication | Same `tbluser` + Sanctum | **Own table, own guard, own login** |
| Tenant data exposure | Every internal query must remember to exclude them | Structurally impossible — different identity space |
| Risk if a check is missed | An external applicant appears in the employee directory, or reaches an internal endpoint | Cannot happen |
| Conversion on hire | Flip a flag | Explicit create-employee step, which is the auditable behaviour you want anyway |
| Cost | Lower now | Higher now, much lower later |

**My strong recommendation: a separate portal identity.** Given G-SEC-01 — 279
write routes currently have no role check — introducing an external identity into
the *same* identity space would mean every one of those routes is reachable by an
unvetted external party the moment they authenticate. The blast radius of a single
missed check changes from "an employee sees too much" to "a stranger does."

Consequences either way:
- A `candidate` table (or `portal_identity`) with its own guard and its own token.
- A deliberate **candidate → employee conversion** step at hire, which golden
  thread 7 needs regardless.
- Offer e-signature has legal weight; it needs a durable, auditable identity, not a
  role flag on a shared record.

**Same question, lower risk, for external trainers and vendors** in the LMS spec.
Recommendation: same mechanism, so there is one external-identity pattern rather
than three.

**ANSWERED 2026-08-05 — separate portal identity.**

- Own table, own guard, own token. **A candidate is never resolvable inside any
  internal module.**
- **Explicit candidate → employee conversion at hire**, auditable — which golden
  thread 7 needs regardless.
- **Offer e-signature binds to that durable identity**, not a role flag.

**Scope condition:** Phase 3 **defines** the identity model, the isolation boundary
and the conversion step. Phase 3 does **not** build the applicant-facing portal —
that is a separate deliverable, recorded as deferred scope.

**External trainers and vendors:** same mechanism, same pattern, but **deferred** —
not designed now. The identity model must simply generalise to them, so it is
specified as `portal_identity` with a type discriminator rather than as
`candidate` alone.
