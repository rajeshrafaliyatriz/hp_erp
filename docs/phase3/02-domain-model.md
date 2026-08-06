# 02 — Domain model

**Gate B deliverable.** Read-only analysis; no application code changed.
Date: 2026-08-05

Covers the nine items required for Gate B as **one coherent schema change**. Every
decision it rests on is already approved (Q-A1, Q-A2, Q-B2–Q-B5, Q-C1–Q-C4, Q-D1–Q-D4,
A1–A7, M1–M5).

---

## 1. Ownership — one owner per concept

Per Q-A1 and Q-A2, and the ownership model in the customer's own spec:
**Competency defines and measures · LMS builds · Talent acquires, places, evaluates,
moves · Task executes · Organization is the source of identity.**

| Concept | Single source of truth | Owner | Readers |
|---|---|---|---|
| Employee identity | `tbluser` | Organization | all |
| Department | `hrms_departments` | Organization | all |
| **Job role — identity** | `s_user_jobrole` | **Organization** | all |
| **Job role — capability definition** | `jobrole_competency_map` **(new)** | **Competency** | LMS, Talent, Task |
| Reporting line | `tbluser.reporting_manager_id` **(new)** | Organization | all |
| **Competency (bundle)** | `competency` **(new)** | Competency | all |
| KASBA item — Skill | `s_users_skills` | Competency | all |
| KASBA item — K/A/A/B | `s_user_knowledge`, `_ability`, `_attitude`, `_behaviour` | Competency | all |
| **Competency ↔ KASBA** | `competency_kasba_item` **(new)** | Competency | all |
| Measured proficiency | `s_skill_matrix` | Competency | all |
| **Capability evidence** | `competency_evidence` **(restore)** | Competency | all |
| Course | `sub_std_map` | LMS | Competency, Talent |
| **Course ↔ competency** | `course_competency_map` **(new)** | LMS *(curated by HR)* | Competency |
| Enrolment / completion | `lms_course_enroll` | LMS | Competency |
| Certificate | `lms_certificates` | LMS | Competency, Talent |
| Certification requirement | `competency_certification_requirements` **(restore)** | Competency | LMS, Task |
| Job-role task (catalogue) | `s_user_jobrole_task` | Competency | Task |
| **Job-role task ↔ competency** | `jobrole_task_competency_map` **(new)** | Competency | Task |
| Task (instance) | `task` | Task | Competency |
| Performance review | `s_performance_reviews` | Talent | Competency (9-box) |
| Rights | `tblgroupwise_rights_g2g`, `tblindividual_rights` | Organization | all |
| **External identity** | `portal_identity` **(new)** | Talent | Recruitment only |

**Rule:** a module may read any table; it may write only the tables it owns. Q-A1's
"identity fields read-only in Competency" is an instance of this, and must be
enforced server-side.

---

## 2. The five join tables — one schema change

These land together or not at all. Four of the seven links in the capability chain
(`employee.md` §2) are missing, and each of these supplies one.

```
                        ┌──────────────────────────┐
                        │  s_user_jobrole          │  Organization owns identity
                        │  (4,610, tenant)         │
                        └──────────┬───────────────┘
                                   │ 1
                                   │
                     ┌─────────────┴──────────────┐
                     │ jobrole_competency_map ★   │  Competency owns capability
                     │ jobrole_id                 │
                     │ competency_id              │
                     │ required_proficiency 1..5  │
                     │ is_mandatory               │
                     └─────────────┬──────────────┘
                                   │ N
                                   │
      ┌────────────────────────────┴─────────────────────────────┐
      │ competency ★                                             │
      │ id, sub_institute_id, code, name, description            │
      │ competency_type, criticality, requires_assessment (Q-B2) │
      │ status, version                                          │
      └───┬──────────────────────────────────────────────────┬───┘
          │ 1                                                │ 1
          │                                                  │
┌─────────┴───────────────────┐                  ┌───────────┴──────────────┐
│ competency_kasba_item ★     │                  │ course_competency_map ★  │
│ competency_id               │                  │ course_id → sub_std_map  │
│ kasba_type ENUM(5)          │                  │ competency_id            │
│ item_id  → the 5 libraries  │                  │ proficiency_level        │
│ weight                      │                  │ is_primary               │
└─────────┬───────────────────┘                  └───────────┬──────────────┘
          │                                                  │
          │ measured against                                 │ builds
          ▼                                                  ▼
┌─────────────────────────────┐                  ┌──────────────────────────┐
│ s_skill_matrix              │                  │ sub_std_map (96)         │
│ user_id, skill_id           │◄─────────────────┤ courses                  │
│ type ENUM(5)  ← same 5      │   completion     │ jobrole (longtext → FK)  │
│ skill_level, knowledge,     │   raises level   └──────────────────────────┘
│ ability, behaviour,attitude │   (gated by Q-B2)
└─────────────────────────────┘

┌──────────────────────────────┐        ┌────────────────────────────────┐
│ s_user_jobrole_task (85,663) │───────►│ jobrole_task_competency_map ★  │
│ catalogue of role duties     │        │ jobrole_task_id, competency_id │
└──────────────┬───────────────┘        └────────────────────────────────┘
               │ instantiated as
               ▼
┌──────────────────────────────┐
│ task (2,271)                 │  skill_id populated on 1,514 (67%)
│ skill_id ──────────────────────────► resolves to a KASBA item (see §3)
│ approve_status, status       │
└──────────────────────────────┘
```

★ = new.

### 2.1 Table definitions

```sql
-- Competency as a named bundle of KASBA items (Q-C2)
CREATE TABLE competency (
  id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id    BIGINT UNSIGNED NOT NULL,
  code                VARCHAR(64)  NOT NULL,
  name                VARCHAR(191) NOT NULL,
  description         TEXT NULL,
  competency_type     VARCHAR(64) NULL,      -- core | functional | leadership (Q-B2 weighting)
  criticality         VARCHAR(32) NULL,      -- drives requires_assessment default
  requires_assessment TINYINT(1) NULL,       -- Q-B2 per-competency override; NULL = inherit tenant default
  status              VARCHAR(32) NOT NULL DEFAULT 'active',
  version             INT NOT NULL DEFAULT 1,
  created_by, updated_by, deleted_by, timestamps, softDeletes,
  UNIQUE KEY uq_competency_tenant_code (sub_institute_id, code),
  INDEX idx_competency_tenant (sub_institute_id)
);

-- Which KASBA items make up a competency (Q-C2)
CREATE TABLE competency_kasba_item (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  competency_id  BIGINT UNSIGNED NOT NULL,
  kasba_type     ENUM('skill','knowledge','ability','attitude','behaviour') NOT NULL,
  item_id        BIGINT UNSIGNED NOT NULL,   -- polymorphic into the 5 libraries
  weight         DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  timestamps,
  UNIQUE KEY uq_ck_item (competency_id, kasba_type, item_id),
  INDEX idx_ck_lookup (kasba_type, item_id)
);

-- What a job role requires (Q-C1) - the tenant's own definition
CREATE TABLE jobrole_competency_map (
  id                   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id     BIGINT UNSIGNED NOT NULL,
  jobrole_id           BIGINT UNSIGNED NOT NULL,   -- s_user_jobrole.id
  competency_id        BIGINT UNSIGNED NOT NULL,
  required_proficiency TINYINT NOT NULL,           -- 1..5, matches s_skill_matrix.skill_level
  is_mandatory         TINYINT(1) NOT NULL DEFAULT 1,
  source               VARCHAR(32) NULL,           -- 'import' | 'manual'  (provenance, see §9)
  source_ref           VARCHAR(191) NULL,          -- e.g. s_jobrole_skills row it came from
  created_by, updated_by, timestamps, softDeletes,
  UNIQUE KEY uq_jcm (sub_institute_id, jobrole_id, competency_id),
  INDEX idx_jcm_role (sub_institute_id, jobrole_id)
);

-- What a course builds (Q-B4) - THE highest-priority item
CREATE TABLE course_competency_map (
  id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id  BIGINT UNSIGNED NOT NULL,
  course_id         BIGINT UNSIGNED NOT NULL,      -- sub_std_map.id
  competency_id     BIGINT UNSIGNED NOT NULL,
  proficiency_level TINYINT NULL,                  -- level this course takes you TO
  is_primary        TINYINT(1) NOT NULL DEFAULT 0,
  created_by, updated_by, timestamps, softDeletes,
  UNIQUE KEY uq_ccm (sub_institute_id, course_id, competency_id),
  INDEX idx_ccm_comp (sub_institute_id, competency_id)
);

-- Which competency a catalogue duty exercises (Q-C3)
CREATE TABLE jobrole_task_competency_map (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  jobrole_task_id  BIGINT UNSIGNED NOT NULL,       -- s_user_jobrole_task.id
  competency_id    BIGINT UNSIGNED NOT NULL,
  created_by, updated_by, timestamps,
  UNIQUE KEY uq_jtcm (sub_institute_id, jobrole_task_id, competency_id)
);
```

**Why one change:** the gap calculation joins `jobrole_competency_map` →
`competency` → `competency_kasba_item` → `s_skill_matrix`. Landing any one alone
produces a table nothing can query. `course_competency_map` is the one with
independent value — it enables recommendation — but even that needs `competency`
to point at.

### 2.2 `is_primary` on course mapping

A course usually builds several competencies to different degrees. `is_primary`
marks the one it is *for*, so "which course closes this gap" has a single answer.
Without it, recommendation returns every course that touches a competency.

---

## 3. `task.skill_id` — instance vs catalogue (**answers Q-E1**)

### What populates it today

**Hand-picked at task creation.** `taskController.php:509`:

```php
$extraData['required_skills'] = $request->input("skills");
$extraData['skill_id']        = $request->input("skill_id");
```

It is **not** inherited from the job-role task catalogue. The create-task modal
loads the skills of the selected job role and offers them
(`create-task-modal.tsx:217`), so the value is **job-role-suggested,
human-selected** — a materially weaker guarantee than an inherited one. It is
populated on **1,514 of 2,271 tasks (67%)**; `required_skills` on 1,749 (77%).

### Which wins

| Level | Source | Strength | Use |
|---|---|---|---|
| **Catalogue** | `jobrole_task_competency_map` | **Strong** — curated once, reviewed | The default |
| **Instance** | `task.skill_id` | **Weak** — one person's pick at creation | An override, and only when explicit |

**Rule: catalogue wins.** Resolution order for "which competency does this task
exercise":

1. `jobrole_task_competency_map` for the task's catalogue entry → **use it**.
2. Else `task.skill_id` → resolve to its competency via `competency_kasba_item`
   → **use it, tagged `confidence = instance`**.
3. Else → **no competency signal**. The task still exists and is still tracked; it
   simply produces no capability evidence.

### The 33% null case

**No signal. Do not guess.** A task with no competency link produces no evidence,
contributes no weight, and never reaches a manager flag.

Inferring a competency from task text, job role, or department would manufacture
evidence about a person's capability from a guess — unacceptable when the output
can affect a rating (Q-B3). The honest behaviour is silence.

**Surface it instead as data quality:** "N% of your tasks carry no competency link"
is a readiness metric (§8), not a reason to invent one.

### Evidence weight follows provenance

Per §5, an evidence record carries `confidence`:

| Link source | Confidence | Effect |
|---|---|---|
| Catalogue map | `high` | Full weight toward the Q-B3 threshold |
| `task.skill_id` | `medium` | Counts, but flagged as instance-derived when the manager reviews it |
| None | — | No evidence record at all |

A manager opening a flag sees *why* the system thinks the task relates to that
competency. Hand-picked links will sometimes be wrong, and the manager needs to
see that to judge it.

---

## 4. The three restored tables (Q-B5)

### 4.1 Root cause

`competency_evidence`, `competency_certification_requirements` and `s_skill_jobrole`
have migrations **recorded as run** yet do not exist.

**Cause: two migration systems over one schema** (Q-C4). This database is shared
with HP Enterprise Brain, which maintains its own `hpbrain_schema_migrations` (38
entries). The evidence:

| Symptom | Reading |
|---|---|
| 3 tables absent, migrations recorded as run | Created, then dropped by something other than Laravel |
| 99 migrations recorded with **no file on disk** | Laravel's ledger describes a schema state the repo cannot rebuild |
| `s_skill_matrix` has `type`, `attitude`, `behaviour` that **no migration creates** | Columns added out of band |
| 211 migration files vs **357 live tables** | The schema is substantially larger than the migrations describe |

Drift runs in **both** directions — objects exist that migrations do not create, and
migrations claim objects that do not exist. That is the signature of more than one
writer.

### 4.2 Restore

Idempotent, matching the shape the controllers expect:

```sql
CREATE TABLE IF NOT EXISTS competency_evidence (...);              -- §5
CREATE TABLE IF NOT EXISTS competency_certification_requirements (...);
CREATE TABLE IF NOT EXISTS s_skill_jobrole (...);
```

Follow `_changes/G-NAV-01-*`: backup → guard → blast radius → rollback → G-SEC-05
pre-check.

### 4.3 Recurrence guard

| Guard | What it does |
|---|---|
| **Schema drift check in CI** | `migrate:fresh` on a scratch DB, diff against a checked-in schema snapshot, fail on difference |
| **Ownership prefix rule** | G2G owns everything **except** `hpbrain_*`; Enterprise Brain owns `hpbrain_*` and nothing else. Written down, because today it is only convention |
| **Separate DB users** | Each app connects with a user that cannot DDL the other's tables. The only structural fix; everything else is a convention that erodes |
| **Remove the cross-write** | `LmsGovernanceController` → `hpbrain_audit_logs` (Q-C4). G2G writes its own audit log |

**Only separate DB users actually prevents this.** The rest detect it after the
fact. Recommend it before the next schema change lands.

---

## 5. `competency_evidence` — the capability signal store (G-FLOW-19)

Harvested from `hpbrain_evidence` per Q-C4, with deviations noted.

```sql
CREATE TABLE competency_evidence (
  id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id  BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,      -- whose capability
  competency_id     BIGINT UNSIGNED NOT NULL,
  kasba_type        ENUM('skill','knowledge','ability','attitude','behaviour') NULL,

  evidence_type     VARCHAR(48) NOT NULL,          -- task_failure | assessment | course_completion
                                                   -- | certification | manager_note | peer_endorsement
  direction         ENUM('positive','negative','neutral') NOT NULL,
  weight            DECIMAL(5,2) NOT NULL DEFAULT 1.00,   -- manager.md §1.3 F1..F4
  confidence        ENUM('high','medium','low') NOT NULL DEFAULT 'medium',  -- §3

  source_type       VARCHAR(48) NOT NULL,          -- task | assessment | enrolment | certificate
  source_id         BIGINT UNSIGNED NULL,
  content           TEXT NULL,                     -- human-readable, e.g. approve_remarks
  observed_at       DATETIME NOT NULL,

  status            VARCHAR(32) NOT NULL DEFAULT 'active',  -- active | dismissed | superseded
  dismissed_by      BIGINT UNSIGNED NULL,
  dismissed_reason  TEXT NULL,                     -- manager.md §7(c)

  created_by, timestamps,
  INDEX idx_ce_user_comp (sub_institute_id, user_id, competency_id, observed_at),
  INDEX idx_ce_source (source_type, source_id)
);
```

**Deviations from `hpbrain_evidence`, and why:**

| hpbrain | Here | Reason |
|---|---|---|
| `signal_id` FK | `source_type` + `source_id` | Evidence exists before any signal fires — a single task rejection is evidence but not yet a flag |
| `hash`, `ledger_sequence` | omitted | An append-only ledger is real integrity engineering, but nothing in Phase 3 requires tamper-evidence. Revisit if a customer asks |
| `provenance` free text | `confidence` enum + `source_type` | Queryable; free text cannot drive a threshold |
| — | **`direction`** | hpbrain assumes evidence is neutral. Q-B3 needs positive and negative distinguished, because positive evidence should be able to *close* a flag |
| — | **`dismissed_reason`** | Q-B3's manager dismissal is itself evidence that the signal was wrong |

**Never deleted, only dismissed or superseded.** Evidence that disappears cannot
support a rating decision later.

---

## 6. Task status-transition history (M2)

Reopen (F2) is undetectable without it. Built as **part of the event mechanism**,
not separately — this is the task audit trail and the event store's first consumer.

```sql
CREATE TABLE task_status_history (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  task_id          BIGINT UNSIGNED NOT NULL,
  from_status      VARCHAR(48) NULL,       -- NULL on creation
  to_status        VARCHAR(48) NOT NULL,
  from_approve_status VARCHAR(48) NULL,
  to_approve_status   VARCHAR(48) NULL,
  actor_id         BIGINT UNSIGNED NULL,   -- NULL = system
  reason           TEXT NULL,
  event_id         BIGINT UNSIGNED NULL,   -- FK to the event store (05-data-flow-contracts)
  occurred_at      DATETIME NOT NULL,
  INDEX idx_tsh_task (task_id, occurred_at),
  INDEX idx_tsh_tenant_time (sub_institute_id, occurred_at)
);
```

**Reopen (F2)** = a transition into an active status *from* a terminal one
(`COMPLETED`, or `approve_status='approved'`). Detectable with one query once this
exists; undetectable without it.

Deliberately **not** backfilled: there is no historical transition data to recover,
and inventing it would fabricate evidence.

---

## 7. Identity, hierarchy and rights

### 7.1 Reporting line (G-STR-03)

```sql
ALTER TABLE tbluser
  ADD COLUMN reporting_manager_id BIGINT UNSIGNED NULL AFTER department_id,
  ADD INDEX idx_tbluser_reporting_manager (reporting_manager_id);

ALTER TABLE hrms_departments
  ADD COLUMN head_user_id BIGINT UNSIGNED NULL AFTER department,
  ADD INDEX idx_hrms_departments_head (head_user_id);
```

Cycle validation on write, depth bounding on read, per `03-rbac-matrix.md` §2.4 and
A5. **NULL for all 386 users today** — populated by the import flow (§9).

### 7.2 Tri-state rights (G-SEC-06)

Both tables take the same shape so one resolver serves both:

```sql
-- per action, on BOTH tblgroupwise_rights_g2g and tblindividual_rights
ADD COLUMN view_mode   ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit',
ADD COLUMN add_mode    ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit',
ADD COLUMN edit_mode   ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit',
ADD COLUMN delete_mode ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit',
ADD COLUMN approve_mode ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit',
ADD COLUMN export_mode  ENUM('allow','deny','inherit') NOT NULL DEFAULT 'inherit';
```

The legacy `can_*` booleans stay during migration and are dropped once the resolver
is live. Resolution order (G-SEC-06): **individual DENY > group DENY > individual
ALLOW > group ALLOW > role default > deny.**

Note `approve` and `export` are new actions — the §3.1–3.7 matrices use **A** and
**X**, which the current four columns cannot express at all.

### 7.3 External identity (Q-D4)

```sql
CREATE TABLE portal_identity (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  identity_type    ENUM('candidate','trainer','vendor') NOT NULL,  -- generalises (Q-D4)
  email            VARCHAR(191) NOT NULL,
  password         VARCHAR(255) NULL,
  full_name        VARCHAR(191) NULL,
  status           VARCHAR(32) NOT NULL DEFAULT 'active',
  converted_user_id BIGINT UNSIGNED NULL,   -- set at hire; the audit trail of conversion
  converted_at     DATETIME NULL,
  timestamps, softDeletes,
  UNIQUE KEY uq_portal_identity (sub_institute_id, identity_type, email)
);
```

**Separate table, separate guard, separate token.** Never joined to `tbluser` except
through `converted_user_id`, which records that a conversion happened rather than
blurring the two. Phase 3 defines this; the portal itself is deferred.

---

## 8. Tenant readiness gates (M1) — a first-class concept

A feature that needs data the tenant does not have should **say so**, not
misbehave. Generalised from the 80% reporting-coverage idea; the same mechanism as
`hpbrain_signal_rules`, which is further support for harvesting that design.

```sql
CREATE TABLE tenant_readiness_gate (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  gate_key         VARCHAR(64) NOT NULL,
  metric_value     DECIMAL(6,2) NULL,     -- measured
  threshold_value  DECIMAL(6,2) NOT NULL, -- tenant-configurable
  threshold_op     ENUM('gte','lte') NOT NULL,
  state            ENUM('blocked','warning','ready') NOT NULL DEFAULT 'blocked',
  measured_at      DATETIME NULL,
  UNIQUE KEY uq_trg (sub_institute_id, gate_key)
);
```

### The gates

| `gate_key` | Metric | Default threshold | Gates |
|---|---|---|---|
| `reporting_coverage` | % active employees with `reporting_manager_id` | **≥ 80%** | All manager-dependent flows |
| `task_hygiene` | % of open tasks **not** overdue | **≥ 60%** | **F4 severe-overdue weights** (M1) |
| `capability_coverage` | % active employees with ≥1 `s_skill_matrix` row | **≥ 50%** | Competency gap reporting |
| `jobrole_definition` | % job roles with ≥1 `jobrole_competency_map` row | **≥ 70%** | Gap analysis, auto-assignment |
| `course_mapping` | % courses with ≥1 `course_competency_map` row | **≥ 50%** | Auto-recommendation |
| `task_competency_link` | % tasks with a resolvable competency (§3) | **≥ 50%** | Golden thread 2 |

> **UNCALIBRATED DEFAULTS.** Every threshold above is a guess. This database is
> **test data only** (owner-stated), so none of them can be calibrated from it.
> They are starting points to be tuned against the **first real tenant**, and must
> be labelled as such in the admin UI rather than presented as product standards.

Thresholds are also **per-tenant, not constants** — a 30-person consultancy and a
5,000-person manufacturer differ.

### Behaviour

| State | Meaning | Effect |
|---|---|---|
| `ready` | metric passes | Feature on |
| `warning` | within 20% of threshold | Feature on, admin nudged |
| `blocked` | below | **Feature off, with a stated reason and the fix** |

Blocked must always name the remedy: *"Overdue-based capability signals are off:
99% of your tasks are overdue (needs ≤ 40%). Close or re-date stale tasks to
enable."*

### Why this is sellable

The readiness dashboard tells a new customer **what to fix before the clever
features switch on**. It converts "the product does not work for us" into a
checklist, and it is honest — the alternative is a competency engine producing
confident nonsense from empty tables. That is the difference between a demo and a
product.

**This test database would show:** `reporting_coverage` 0%, `task_hygiene` ~1%,
`capability_coverage` ~11%, `course_mapping` 0%, `task_competency_link` 67% —
useful as a worked example of the dashboard, not as evidence about any customer.

---

## 9. The seed-library import flow (Q-C1, elevated)

169 capability rows for 386 users means the chain will be **structurally correct and
visibly empty**. Import is what makes a new customer see a populated product on day
one, so it is a feature, not a utility.

**One import mechanism, not two** — job-role library *and* reporting line.

### 9.1 What exists to import from

| Source | Rows | Scope |
|---|---:|---|
| `master_skills` | 5,640 | global reference |
| `s_jobrole_skills` | **62,208** | global — jobrole→skill→proficiency |
| `s_skill_map_k_a` | **149,598** | global — KASBA proficiency descriptors |
| `s_user_jobrole_task` | 85,663 | tenant |

A substantial industry framework already ships with the product. It is unused
because nothing connects it to a tenant's own definitions.

### 9.2 The flow

```
1. CHOOSE     industry / sector  → filters s_jobrole_skills
2. MAP        the tenant's s_user_jobrole rows ↔ library job roles
                 exact name match → auto
                 fuzzy            → proposed, human confirms
                 no match         → skipped, listed
3. PREVIEW    "428 competencies across 37 roles will be created"  ← before writing
4. IMPORT     creates competency, competency_kasba_item,
              jobrole_competency_map with source='import', source_ref=<row>
5. REVIEW     tenant edits; edits set source='manual' so re-import never overwrites
6. RE-IMPORT  idempotent: adds new, leaves 'manual' rows untouched
```

Same mechanism, second use:

```
REPORTING LINE  CSV: employee_no, manager_employee_no
   → validate: both exist, no cycle (§7.1), depth ≤ 10
   → preview: "312 of 386 will get a manager. 74 unmatched:"
   → import → recomputes the reporting_coverage gate (§8)
```

### 9.3 Rules

- **Preview before write, always.** An import that silently creates 428 competencies
  is indistinguishable from a bug.
- **`source` tracks provenance** so re-import never clobbers human edits. This is
  what makes the library a *starting point* rather than a *cage*.
- **Import is a tenant-admin action**, gated `profile:admin,hr` and audited.
- **Failure is per row, not per file.** A 386-row upload must not fail entirely for
  one bad manager reference; it reports the 74 it could not match.

---

## 10. Migration sequence

| # | Step | Reversible | Behaviour change |
|---:|---|---|---|
| 1 | Restore the 3 missing tables (§4) | yes | none |
| 2 | Add `reporting_manager_id`, `head_user_id` (§7.1) | yes | none |
| 3 | Create the 5 join tables (§2) | yes | none — empty |
| 4 | Create `competency_evidence` (§5), `task_status_history` (§6) | yes | none |
| 5 | Create `tenant_readiness_gate` (§8), seed gates as `blocked` | yes | none |
| 6 | Add tri-state rights columns (§7.2) alongside `can_*` | yes | none |
| 7 | Create `portal_identity` (§7.3) | yes | none |
| 8 | Build the import flow (§9) | n/a | none until run |
| 9 | **Run the import** per tenant | data | **visible** — populated libraries |
| 10 | Populate the rights matrix (`03-rbac-matrix.md` §4.5) | data | **visible** — navigation changes |
| 11 | Drop legacy `can_*` once the resolver is live | yes | none |
| 12 | **Normalise `s_skill_matrix` blobs → `skill_matrix_item`** (§11.2); rename `skill_id` → `item_id` | yes | none |
| 13 | **`course_jobrole_map`**, drop `sub_std_map.jobrole` longtext (§11.1a) | yes | none |
| 14 | **`s_user_jobrole_task.jobrole_id` FK**, backfill, drop text key (§11.1b) | yes | none |
| 15 | **`g2g_audit_log`**; remove the `hpbrain_audit_logs` cross-write (§11.3) | yes | none |
| 16 | **Separate the hpbrain schema + DB users** (§11.3) | yes until step 8 of that plan | none |
| **3b** | **`certification_type` + `certification_competency_map`** (§10.1) — inserted at step 3, alongside the other join tables | yes | none — empty |
| **9b** | **Backfill `certification_type_id`** on requirements and held credentials from their free-text `name` + `issuing_body` (§10.1) | data | none until reviewed |

Steps 12–16 are the Correction 2/3 items. All are additive-then-drop, all are
reversible until the final drop, and **all are near-free only while the data is
test data**.

**Steps 1–8 are additive and change nothing a user sees.** The two user-visible
steps, 9 and 10, each carry a preview/diff review. Nothing here requires a
big-bang release.

---

## 10.-1 WHY THIS SEQUENCE EXISTS — the measured precondition

**Added 2026-08-06, from sweep S-1 against the live schema.**

> **283,126 rows across four tables resolve their relationships by matching a name
> string. Not one of them can be joined by key.**

| Table | Rows | String keys |
|---|---:|---|
| `s_user_jobrole_task` | 85,662 | `jobrole` |
| `s_user_skill_jobrole` | 79,295 | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_skills` | 62,208 | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_task` | 55,961 | `jobrole` |

These four carry **which job role needs which skill** and **which tasks belong to
which job role**. **Every golden thread crosses at least one of them.**

**Consequence for this document:** `L-11` (join on ids, not titles) is **not one
connection among twenty-three.** It is **the precondition for the others existing**.
A job role cannot be told what capability it requires, an employee's measured skill
cannot be tied to the role needing it, and a task cannot report the competency it
exercised — until these four tables have keys.

The step ordering in §10 already reflects this (steps 12 and 14). What changes is
the *justification*: those steps are not schema hygiene, they are the point.

Full framing, including exactly what the figure counts and excludes:
`07-gap-register.md` → **G-DATA-06**.

---

## 10.0 BINDING RULE — how a field that names another entity is bound

**Decided once, by ownership, 2026-08-06. Applies everywhere L-11 reaches.**

| The field names… | Control | Rule |
|---|---|---|
| **An ENTITY owned by another module** — department, job role, course, certification, skill | **Closed picker** sourced from the owning table, **plus a permission-gated "create new" action** inside the picker (HR/Admin only) that creates a proper row with its required fields | Never typeable into a free string |
| **A VOCABULARY** — categories, tags | **Open choice is fine**, optionally promoted into the shared category table (Q-L3) | Drift here is cheap and recoverable |

### Why ownership, not drift, is the reason

Organization owns department identity (**Q-A1**). A department has a head, a parent
and employees. **It must never be created as a side effect of typing a name into a
Competency form** — that would be wrong even if drift were solved.

`library-config.ts` carries a comment asserting *"a genuinely new department must
still be typeable."* That comment is **defending a workflow that should not
exist**, and it is superseded by this rule.

The gated inline create means there is **no dead end**: an HR or Admin user can
still create the department without leaving the form, but the entity is **born in
the module that owns it**, with its required fields.

### The hybrid is explicitly rejected

*Type-and-resolve-if-it-matches* produces rows with a NULL id **and** a text
value — the current bug with extra steps — and forces every reader to handle both
mechanisms indefinitely.

### What this pre-answers

**L-07** (job titles), **L-08** (learning resources → courses), **L-09**
(certifications), **L-04** (job level), and every field **L-11** touches. None of
them needs its own decision: entity → closed picker + gated create.

### Migration path, wherever an entity field already holds legacy free text

1. Add the `*_id` column; **backfill by name match within the tenant**.
2. **REPORT unmatched rows. Never guess a match.**
3. **Keep the free-text column during coexistence**, and make every filter read **BOTH** — `AT-L02` step 4 already flags that the department filter reads DISTINCT free text, so a filter reading only ids would make legacy rows vanish.
4. Drop the text column **only** after the backfill is verified and the filter reads ids.

---

## 10.1 Certifications — L-09, and the larger finding underneath it

**Added 2026-08-06.** L-09 was raised by the Libraries & Taxonomy write-up as a
suspected Gate B omission and **confirmed as one**. Two checks were run before
designing anything, precisely so a third overlapping concept was not created.

### Check 1 — what does the restored requirements table actually express?

`s_competency_certification_requirements`
(`database/migrations/2026_07_29_110000_*.php`):

| Column | Note |
|---|---|
| `name`, `certification_type`, `issuing_body` | **all free text.** `certification_type` is a *category* string — Industry / Internal / Regulatory / License / Vendor |
| `department_id`, `jobrole`, `competency_id` | scope. All nullable; scoped to none = organisation-wide |
| `is_mandatory`, `validity_months`, `renewal_reminder_days`, `grace_period_days` | the policy itself |

**It expresses "certification Y is REQUIRED for role / department / competency X".**
The requirement direction only.

It does **not** express the reverse — *"holding certification Y EVIDENCES competency
X at proficiency level P"*. The distinction is exactly the one anticipated:
`competency_id` here is a **scoping filter** (*this requirement applies to people
who need competency X*), not an evidence claim. And it is **one** nullable
competency per row, with no proficiency level and no `is_primary`.

### Check 2 — TYPE versus HELD INSTANCE · **the larger finding**

| Concept | Table | Verdict |
|---|---|---|
| Requirement (policy) | `s_competency_certification_requirements` | exists |
| **Held instance** | `s_competency_certifications` — `user_id`, `issued_date`, `expiry_date`, `credential_id`, `status`, `requirement_id` | **exists, and is properly instance-shaped** |
| **Certification TYPE** | — | **DOES NOT EXIST** |

The instance/type distinction the question asks about is **half present**. Issue
date and expiry live correctly on the instance. But there is **no catalogue of
certifications as reusable things.** Both the requirement and the held credential
carry their own free-text `name` and `issuing_body`
(`2026_07_28_100100_create_competency_module_tables.php:104-122`), and the instance
has **no `certification_type_id`**.

Consequences, all verified against the columns above:

1. **Two employees holding the same real-world certification have two unrelated free-text rows.** *"AWS Solutions Architect"* and *"AWS Certified Solutions Architect – Professional"* are different certifications as far as this schema is concerned. No count, no coverage report and no expiry roll-up can be trusted.
2. **The competency mapping has nowhere correct to live.** It belongs on the type. With no type, it can only be attached to the requirement (wrong — that is policy) or repeated on every held instance (wrong — that is per-person data, and it would let two employees disagree about what the same certification means).
3. **`requirement_id` is not a substitute.** An externally uploaded certificate that matches no requirement links to nothing — which is precisely the golden-thread 3 and 8 dead end.

**Raised as its own gap: `G-CERT-01`, severity S2.** It is larger than L-09 and it
is L-09's prerequisite — the map cannot be built until there is a type to map from.

### The design

```sql
-- 10.1a  The missing catalogue. NULL sub_institute_id = global seed row,
--        same two-layer pattern as the skill libraries (§9).
CREATE TABLE certification_type (
  id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id  BIGINT UNSIGNED NULL,
  name              VARCHAR(191) NOT NULL,
  issuing_body      VARCHAR(191) NULL,
  category          ENUM('industry','internal','regulatory','license','vendor') NOT NULL,
  default_validity_months SMALLINT UNSIGNED NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  timestamps, soft_deletes,
  UNIQUE KEY uq_cert_type (sub_institute_id, name, issuing_body)
);

-- 10.1b  Symmetric with course_competency_map (§2.1), by instruction.
CREATE TABLE certification_competency_map (
  id                    BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id      BIGINT UNSIGNED NOT NULL,
  certification_type_id BIGINT UNSIGNED NOT NULL,
  competency_id         BIGINT UNSIGNED NOT NULL,
  proficiency_level     TINYINT UNSIGNED NULL,
  is_primary            TINYINT(1) NOT NULL DEFAULT 0,
  timestamps,
  UNIQUE KEY uq_cert_comp (certification_type_id, competency_id),
  FOREIGN KEY (certification_type_id) REFERENCES certification_type(id),
  FOREIGN KEY (competency_id)         REFERENCES competency(id)
);

-- 10.1c  Both existing tables gain the FK. Free-text name/issuing_body are
--        RETAINED, not dropped: an externally uploaded certificate that matches
--        no catalogue row must still be storable. NULL type = unmatched.
ALTER TABLE s_competency_certifications              ADD certification_type_id BIGINT UNSIGNED NULL;
ALTER TABLE s_competency_certification_requirements  ADD certification_type_id BIGINT UNSIGNED NULL;
```

`is_primary` carries the same meaning as on `course_competency_map` (§2.2): the
competency this certification chiefly evidences, versus ones it touches
incidentally.

### Why this closes the two dead ends

| Thread | Dead end today | With 10.1 |
|---|---|---|
| **3** — capability is proven | An externally uploaded certificate has **no course to derive its competency from**, so it proves nothing | The certificate resolves to a type; the type maps to competencies at a proficiency level |
| **8** — compliance is demonstrable | Coverage cannot be counted because the same certification is many free-text strings | Coverage counts on `certification_type_id` |

### Deliberately NOT done

- **Not merging requirement and type.** They are different relations — check 1 exists to keep them apart. A requirement *references* a type; it is not one.
- **Not dropping the free-text columns.** Step 9b's backfill will not match everything, and an unmatched external certificate must remain storable. NULL `certification_type_id` is a valid, reportable state — *"credentials we hold but cannot classify"* is a real screen.
- **Not auto-raising competency on certificate upload.** That is Q-B2's decision (tenant setting, assessment required by default), and it applies here unchanged.

## 11. Amendments — Corrections 1–5 and Q-E2 (2026-08-05)

**Governing fact, owner-stated:** this database is **test data only; there is no
production tenant and no customer**. Breaking changes are nearly free today and
expensive forever after. Every "for now" compromise below is re-examined on that
basis, and **migration cost is no longer an acceptable justification for keeping
one.**

### 11.1 Correction 2 — the four compromises, re-examined

#### (a) `sub_std_map.jobrole` longtext → real FK · **DO IT NOW**

73 of 96 courses hold a job-role **name** in a longtext column. It is unjoinable,
un-indexable, and breaks silently when a role is renamed.

**Recommendation: normalise now**, and not to a column — to a join table, because a
course legitimately serves several roles:

```sql
CREATE TABLE course_jobrole_map (
  id, sub_institute_id,
  course_id   BIGINT UNSIGNED NOT NULL,   -- sub_std_map.id
  jobrole_id  BIGINT UNSIGNED NOT NULL,   -- s_user_jobrole.id
  UNIQUE KEY uq_cjm (sub_institute_id, course_id, jobrole_id)
);
-- then drop sub_std_map.jobrole
```

Migration: 73 rows matched by name against `s_user_jobrole`; unmatched names are
reported, not guessed. Trivial at this size.

#### (b) `s_user_jobrole_task` text keys → real FKs · **DO IT NOW**

85,663 rows keyed on `jobrole` and `task` as text, plus `sector`/`track`. This is
the catalogue golden thread 2 resolves against, so its keys must be reliable.

**Recommendation: add `jobrole_id` FK now**, backfill by name within tenant, report
unmatched. Keep the text columns during migration, drop them once backfill is
verified. The `task` text stays as the duty's description — that is legitimately
text; it is `jobrole` that must become a key.

#### (c) Is `sub_std_map` still the right Course table? · **KEEP IT — with one change**

Straight recommendation, as asked: **keep it.** Not because migration is expensive,
but because:

1. **It is not merely a K-12 artefact any more.** It already carries
   `jobrole`, `proficiency`, `subject_category`, `certificate_validity_months` and
   `content_quantity` — HR concepts added deliberately over time.
2. **The dependent graph is large and working.** `content_master`, `chapter_master`,
   `lms_course_enroll` (1,426), `lms_content_progress` and `lms_certificates` all
   key on it. Renaming the table changes nothing about the data model while
   invalidating every one of those relationships.
3. **The real problem was never the table — it was the missing links**, which
   `course_competency_map` and `course_jobrole_map` now supply.

**The one change worth making: stop sharing it between products.** Add a
discriminator (`product_context ENUM('hrms','k12')`) or, if K-12 does not run on
this database, confirm that and leave it. Two products sharing a courses table is
the same class of problem as two products sharing a database (Correction 3).

*Counter-argument, stated fairly:* the name is confusing and `subject_id` /
`standard_id` carry K-12 meaning. That is a **naming** debt, addressable with a
view or an Eloquent model alias, and not worth a migration that would break five
working relationships.

#### (d) `s_skill_matrix` → see §11.2.

### 11.2 Correction 4 — `s_skill_matrix`: what those columns actually hold

**Investigated. They are NOT two encodings of one fact** — but the finding is worse
than duplication.

| Column | What it holds |
|---|---|
| `type` | ENUM naming **which KASBA dimension this row measures**. In practice `'skill'` on all 146 populated rows, NULL on 23 |
| `skill_level` | The row's overall measured level, 1–5 |
| `knowledge`, `ability`, `behaviour`, `attitude` | **JSON maps of component item → rating**, e.g. `{"Industry-accepted hardware and software products":"2", "Emerging trends…":"2"}` |

So a row means: *"user X, skill Y, overall level 3, and here are the knowledge
components with their individual ratings, and the ability components with theirs."*

`type` says what kind of thing the row is. The four columns hold its **children**.
Different facts — not duplicates.

**But the four columns are unusable as data:**

| Column | Populated | JSON | CSV | Other format | Sub-items encoded |
|---|---:|---:|---:|---:|---:|
| `knowledge` | 50 | 38 | 1 | **11** | **5,026** |
| `ability` | 49 | 38 | 1 | **10** | 4,259 |
| `behaviour` | 20 | 13 | 0 | **7** | 67 |
| `attitude` | 19 | 10 | 0 | **9** | 46 |

Three incompatible formats in one column (JSON object, `1,1,1,1,1`, and free text),
and **the JSON keys are free-text descriptions, not IDs** — so ~9,400 sub-item
ratings cannot be joined to the knowledge/ability/attitude/behaviour libraries they
came from.

**Recommendation: normalise now.** This is exactly what `competency_kasba_item`
describes structurally; it needs a *measurement* sibling:

```sql
CREATE TABLE skill_matrix_item (
  id, sub_institute_id,
  skill_matrix_id  BIGINT UNSIGNED NOT NULL,   -- the parent s_skill_matrix row
  kasba_type       ENUM('skill','knowledge','ability','attitude','behaviour') NOT NULL,
  item_id          BIGINT UNSIGNED NULL,       -- resolved library item, NULL if unmatched
  item_label       VARCHAR(255) NOT NULL,      -- the original text, always kept
  rating           TINYINT NULL,
  UNIQUE KEY uq_smi (skill_matrix_id, kasba_type, item_label(191)),
  INDEX idx_smi_item (kasba_type, item_id)
);
```

Migration: parse the three formats, one row per sub-item, match `item_label` against
the libraries, leave `item_id` NULL where no match. **Keep `item_label` always** —
it is the only record of what was originally meant, and a failed match must not
destroy it.

Then drop the four blob columns. ~9,400 rows produced from 169 — trivial, and it
turns the product's most-read table into something joinable.

**`type` stays.** It is meaningful and already correct.

### 11.3 Correction 3 — separate the databases now

Confirmed: **G2G and HP Enterprise Brain are separate products.** No runtime
dependency, no shared tables, no cross-writes. Future integration is **API only**.

To be explicit, since the two are easy to confuse:

| | Status |
|---|---|
| Reusing a schema **design** already worked out in hpbrain | **Approved** — §5 does exactly this, with deviations justified |
| Reading or writing `hpbrain_*` tables at runtime | **Forbidden** |

#### Step 1 — remove the cross-write

`LmsGovernanceController::audit()` writes to `hpbrain_audit_logs` — the only
runtime coupling in the codebase. Replace with a G2G-owned table:

```sql
CREATE TABLE g2g_audit_log (
  id CHAR(36) PRIMARY KEY,          -- UUID, matching the existing shape
  event_id CHAR(36) NOT NULL,
  sub_institute_id BIGINT UNSIGNED NOT NULL,   -- proper tenant column, not the 't{id}' string
  entity_type VARCHAR(64), entity_id VARCHAR(64), action VARCHAR(64),
  actor_id BIGINT UNSIGNED NULL, actor_name VARCHAR(191) NULL,
  changes JSON NULL, ip_address VARCHAR(45), user_agent VARCHAR(500),
  source VARCHAR(64), status VARCHAR(32),
  created_at DATETIME NOT NULL,
  INDEX idx_gal_tenant_time (sub_institute_id, created_at),
  INDEX idx_gal_entity (entity_type, entity_id)
);
```

Note the improvement: `hpbrain_audit_logs.tenant_id` stores `'t3'` as a **string**,
which `scopeAuditToTenant` has to work around. The G2G table uses a real
`sub_institute_id`. Existing rows are hpbrain's; G2G starts clean.

#### Step 2 — separate the schemas

Concrete steps, in order:

| # | Step | Reversible |
|---:|---|---|
| 1 | Create database `hpbrain` (or `g2g` — whichever moves is the smaller set; **hpbrain has ~120 mostly-empty tables**, so move hpbrain) | yes |
| 2 | `CREATE TABLE hpbrain.x LIKE hp_erp.hpbrain_x` + copy, for each of the ~120 tables. Total data is small — the largest is 342 audit rows | yes — source untouched |
| 3 | Point HP Enterprise Brain's connection at the new database | yes |
| 4 | Run hpbrain's own migrations against it; confirm its 38-entry ledger matches | yes |
| 5 | Verify G2G has zero references to `hpbrain_*` (after step 1 above) | — |
| 6 | **Rename** the originals to `zz_moved_hpbrain_*` — do not drop | **yes — the safety net** |
| 7 | Run both products for one release with the renames in place | — |
| 8 | Drop `zz_moved_hpbrain_*` | no — final step only |

#### Step 3 — separate DB users

The structural fix. Conventions erode; grants do not.

```sql
CREATE USER 'g2g_app'@'%';      GRANT ALL ON hp_erp.*  TO 'g2g_app'@'%';
CREATE USER 'hpbrain_app'@'%';  GRANT ALL ON hpbrain.* TO 'hpbrain_app'@'%';
-- neither can DDL the other's schema
```

**This is what actually prevents Q-B5 recurring.** The CI drift check detects
drift; separate grants make it impossible. With test data only, the whole exercise
is a copy and two grants — it will never be this cheap again.

### 11.4 Correction 5 — polymorphic integrity

`competency_kasba_item.item_id` is polymorphic on `kasba_type`, so the database
cannot enforce it. A broken row silently corrupts every gap calculation downstream,
which makes this a data-integrity control, not a nicety.

#### The five targets

| `kasba_type` | Table |
|---|---|
| `skill` | `s_users_skills` |
| `knowledge` | `s_user_knowledge` |
| `ability` | `s_user_ability` |
| `attitude` | `s_user_attitude` |
| `behaviour` | `s_user_behaviour` |

#### (i) Write-time validator

One place, used by every writer:

```php
// KasbaItemResolver::assertExists(string $kasbaType, int $itemId, int $tenantId): void
// - rejects an unknown kasba_type
// - rejects an item_id absent from the mapped table
// - rejects an item belonging to a different tenant
// - rejects a soft-deleted item
```

Enforced in the model's `saving` hook, not only in controllers, so an import or a
console command cannot bypass it.

#### (ii) Periodic orphan check

A scheduled command reporting, per tenant: rows whose target is missing, rows whose
target is soft-deleted, and rows whose target belongs to another tenant. Output
feeds the readiness gates (§8) as a data-quality metric rather than a silent log.

#### (iii) Soft-delete behaviour — **block, do not cascade**

When a library item is soft-deleted while referenced:

| Option | Verdict |
|---|---|
| Cascade-delete the mapping | **No.** Silently changes what a competency means, and every historical measurement against it |
| Leave dangling | **No.** That is the corruption this section exists to prevent |
| **Block the delete, listing the competencies that use it** | **Yes** |

The item can be marked `inactive` — excluded from new mappings, retained for
existing ones. Measurement history must not be rewritten by a housekeeping action.

#### (iv) The join to `s_skill_matrix` — make the key `(type, item_id)` everywhere

**Yes, `s_skill_matrix.skill_id` is itself polymorphic**, discriminated by
`s_skill_matrix.type` — the same enum. Today every join treats `skill_id` as if it
pointed only at `s_users_skills`, which happens to be safe **only because `type` is
`'skill'` on all 146 rows**. The moment a knowledge row is written, those joins
silently return the wrong record.

**Binding rule: the join key is `(type, item_id)`, never `item_id` alone.**

```sql
-- correct
JOIN competency_kasba_item cki
  ON cki.kasba_type = sm.type
 AND cki.item_id    = sm.skill_id
```

Rename `s_skill_matrix.skill_id` → `item_id` in the same migration. `skill_id`
actively misleads: it names one of five possible targets, and that name is why
every existing join assumes the wrong thing.

### 11.5 Q-E2 — ANSWERED: option (a), with three binding rules

**Measure per KASBA item; derive competency proficiency as a weighted roll-up.**

#### Rule 1 — one implementation

**`CompetencyProficiencyService`** is the only code that computes it. No screen,
report, export or query does its own arithmetic.

```php
CompetencyProficiencyService::for(int $userId, int $competencyId): CompetencyProficiency
// ->weightedLevel(): ?float          null when nothing is measured
// ->blockingItems(): Collection      mandatory items below required level
// ->coverage(): float                measured items / total items
// ->isMeasured(): bool
```

Anything that needs the number calls this. A second implementation is a defect.

#### Rule 2 — always report two numbers

Never the average alone:

| Output | Meaning |
|---|---|
| **Weighted roll-up** | `Σ(item_rating × weight) / Σ(weight)` over **measured** items |
| **Blocking list** | Every **mandatory** item below its required level |

An average hides a critical hole: a fire-safety competency at 4.2 overall with one
mandatory item at 1 is **not** a pass. For regulated or safety competencies the
blocking list *is* the answer and the average is decoration.

UI rule: wherever the roll-up appears, the blocking count appears beside it.

#### Rule 3 — unmeasured is not zero and not pass

| State | Representation |
|---|---|
| Measured, meets required | value + pass |
| Measured, below required | value + gap |
| **Unmeasured** | **`null` + "unmeasured"** — never 0, never a pass |

`weightedLevel()` returns **null**, not 0, when nothing is measured. Coverage feeds
the `capability_coverage` readiness gate (§8).

**With 169 ratings for 386 people this is the common case, not the edge case** — so
every consumer must render "unmeasured" as a first-class state, not fall through to
a zero.

#### Recompute timing

| Trigger | Scope |
|---|---|
| Assessment completed | the assessed user × competency |
| Evidence written (`competency_evidence`) | that user × competency |
| Course completed *(where Q-B2 permits auto-raise)* | that user × competency |
| Required level changed (`jobrole_competency_map`) | **every user in that job role** |
| `competency_kasba_item` changed (weight/membership) | **every user measured on that competency** |
| Job role reassigned | that user, all competencies |

The last three are fan-outs and must be queued, not synchronous.

**Caching:** compute on read initially — the joins are small and correctness is
worth more than latency at this stage. If a cache is added later, these six
triggers are its invalidation set, and `02-domain-model.md` is where that list
lives. A cache with an invalidation list held anywhere else will drift.

---

## 11.6 S1 — dry-run match report (**run before migrating; nothing written**)

`scripts/` reproduction: the read-only script is `_evidence/dryrun-match.php`.

### The headline: we are **not** recovering 9,400 measurements

| Encoding | Rows | "Items" |
|---|---:|---:|
| **`ok_labelled`** — text key → rating. **The only usable form** | **87** | **293** |
| `mixed_keys` — **corrupt character-map** | 11 | **9,068** |
| `CORRUPT_charmap` | 1 | 37 |
| `csv_ratings_no_labels` — `1,1,1,1` with no labels | 2 | 16 |
| `unparsed` — free text | 37 | 37 |

**9,105 of the 9,451 "items" (96%) are corruption artefacts, not measurements.**

Eleven rows contain a JSON *string* that was then re-encoded **character by
character**:

```json
{"0":"{","1":"\"","2":"C","3":"o","4":"n","5":"t","6":"i","7":"n",...}
```

That is a double `json_encode` round-trip. Each such row explodes into thousands of
single-character pseudo-items. **My earlier figure of "~9,400 sub-item ratings" was
itself produced by counting those characters** — a fourth instance of the same
lesson: a self-consistent count of the wrong thing.

### Match rate, on the items that are real

| Dimension | Labelled items | Matched to library | Rate |
|---|---:|---:|---:|
| knowledge | 151 | 90 | 60% |
| ability | 156 | 69 | 44% |
| attitude | 9 | 9 | 100% |
| behaviour | 13 | 13 | 100% |
| **Total** | **329** | **181** | **55%** |

Libraries available to match against are large — `s_user_knowledge` 6,950,
`s_user_ability` 6,175 — so the 45% failure is a **vocabulary mismatch**, not a
missing library. The unmatched labels are recognisable competency statements
("Industry-accepted hardware and software products", "Provide recommendations with
strong rationale…") that simply are not in this tenant's library.

### Decision this enables

**We are recovering ~181 resolvable measurements, not 9,400.** So:

- The migration is **small and cheap** — hundreds of rows, not thousands.
- It creates **~112 labelled orphans** (unmatched but real). Keep them:
  `item_label` populated, `item_id` NULL. They are evidence of what someone meant
  to measure, and they feed the library-gap report.
- **The 9,105 corrupt items are dropped, not migrated.** Preserving a character map
  as rows would manufacture 9,105 fake measurements. The 11 source rows are
  quarantined intact (§11.7) so nothing is destroyed.

**New gap `G-DATA-04` (S2):** eleven `s_skill_matrix` rows contain
double-encoded character maps. Whatever wrote them is a live defect if it still
exists — the write path must be found before the migration, or it will corrupt the
new table too.

## 11.7 S2 — the 23 NULL-`type` rows

Investigated. **Backfill to `'skill'` — the evidence supports it.**

| Check | Result |
|---|---|
| Rows with NULL `type` | 23 |
| — with `skill_level` set | **23 / 23** |
| — with `skill_id` set | **23 / 23** |
| — whose `skill_id` **resolves in `s_users_skills`** | **23 / 23** |
| — carrying KASBA blobs | knowledge 12, ability 13, attitude 9, behaviour 12 |

Every one resolves cleanly against the skill library, exactly like the 146 rows
that already say `'skill'`. They are skill measurements written before the column
existed.

```sql
UPDATE s_skill_matrix SET type = 'skill'
WHERE type IS NULL AND skill_id IN (SELECT id FROM s_users_skills);
-- expect 23; then make `type` NOT NULL
```

**Then `type` becomes NOT NULL.** With `(type, item_id)` as the join key
(§11.4 iv), a NULL type is unjoinable by construction — leaving them would mean a
gap calculation silently skipping 14% of the measurement table.

Quarantine table for the 11 corrupt rows and anything that fails to resolve:

```sql
CREATE TABLE s_skill_matrix_quarantine (
  id, original_id, sub_institute_id, user_id, skill_id, type,
  raw_payload  JSON,        -- the blob exactly as found
  reason       VARCHAR(191),
  quarantined_at DATETIME
);
```

Nothing is deleted. Everything unmigratable is preserved verbatim with a reason.

## 11.8 S3 — capability coverage recomputed

The honest metric is **resolvable measurements per active user**, not raw rows.

| Metric | Value |
|---|---:|
| Active users | 264 |
| Users with **any** `s_skill_matrix` row | **8 (3.0%)** |
| Users with a **resolvable** measurement | **8 (3.0%)** |

**The `capability_coverage` gate reads 3.0%, not the ~11% previously stated.** My
earlier figure divided 169 *rows* by 386 *total* users; the right calculation is
distinct users with a resolvable measurement over **active** users.

Encouragingly, resolvable equals any-row: all 8 users' measurements resolve
cleanly. The problem is reach — 8 people — not quality.

Gate definition, restated precisely:

```
capability_coverage =
  DISTINCT users having ≥1 s_skill_matrix row with a resolvable (type,item_id)
  ÷ active users in tenant
```

## 11.9 S4 — two paths to "which courses for this job role?"

Correct catch. Both `course_jobrole_map` and the derived path
(job role → competency → course) can answer it. **They are assigned different
jobs and must never be silently merged.**

| | **Competency-derived** | **`course_jobrole_map`** |
|---|---|---|
| Question | "What closes this person's *gap*?" | "What does *everyone in this role* do?" |
| Path | `jobrole_competency_map` → gap → `course_competency_map` | `course_jobrole_map` directly |
| Per person? | **Yes** — depends on measured proficiency | **No** — same for every holder of the role |
| Use | **The default. The only input to the recommendation engine** | Induction, statutory compliance, role-mandatory training |
| Triggered by | A gap, an assessment result, a Q-B3 signal | Joining the role |
| Q-B2 applies? | Yes — completion may raise proficiency | No — completion proves attendance, not capability |

**Binding rules:**

1. **The recommendation engine reads the competency-derived path only.** Mandatory
   induction is not a recommendation.
2. Every screen and report states which it is using. Where both appear, rows are
   **labelled by reason** — *"Required for your role"* vs *"Closes a gap in X"* —
   never concatenated into one unexplained list.
3. Consumers, stated:

| Surface | Path |
|---|---|
| My Learning — "Assigned to me" | Both, **labelled** |
| Learning Catalog — "Recommended for you" | Competency-derived only |
| Development plan → course | Competency-derived only |
| Onboarding checklist | `course_jobrole_map` only |
| Compliance/mandatory report | `course_jobrole_map` only |
| Gap report → remediation | Competency-derived only |
| Q-B3 signal → remediation | Competency-derived only |

## 11.10 S5 — is a third product on this database? **No.**

Investigated. **The K-12 traces are leftovers from shared ancestry, not a running
product.**

| Signal | Result |
|---|---|
| `student` | **ABSENT** |
| `tblstudent` | **ABSENT** |
| `admission` | **ABSENT** |
| `front_desk_visitor` | **ABSENT** |
| `standard` | 71 rows (vestigial) |
| `subject` | **5 rows** |
| `sub_std_map` distinct `subject_id` | **81** — does not resolve against a 5-row `subject` table |

No students, no admissions, no front-desk visitors. A K-12 product cannot be
running here — and `sub_std_map.subject_id` does not even resolve against
`subject`, so those columns are inert identifiers, not live foreign keys.

**Consequence: do NOT add `product_context`.** Per your instruction, a column
implying an ongoing arrangement that does not exist is worse than nothing. The
§11.1(c) recommendation stands **without** the discriminator: keep `sub_std_map`,
add the join tables, and treat `subject_id` / `standard_id` as legacy identifiers
to be documented rather than re-pointed.

This also means there are **two** products on this database, not three — G2G and
HP Enterprise Brain — so the Correction 3 separation plan is complete as written.

---

## 12. Open question raised here

### Q-E2 — Does `competency` replace `s_users_skills` as the unit of measurement? · `ANSWERED`

**Option (a)** — measure per item, derive the roll-up. Three binding rules and the
recompute triggers are specified in §11.5.

`s_skill_matrix` measures per **KASBA item** (`skill_id` + `type`). Q-A2 makes
**competency** the meaningful unit — so a required proficiency is stated per
competency while measurement is per item.

Two options:

| | Approach | Trade-off |
|---|---|---|
| **(a)** | Keep measuring per item; **derive** competency proficiency as the weighted roll-up of `competency_kasba_item.weight` | No migration of 169 existing rows; roll-up is computed. **Recommended** |
| (b) | Measure per competency directly; add `competency_id` to `s_skill_matrix` | Simpler queries, but discards item-level detail and needs a migration |

**Recommendation (a)** — it preserves the existing data and the item-level grain
that 360 feedback and evidence both need. But it means "current proficiency for
competency X" is always a computed value, and that must be stated once and
consistently or two screens will disagree.

> **Answer:**

---

## 12. Verification status

| Claim | Method | Status |
|---|---|---|
| `task.skill_id` written from request input, not inherited | `taskController.php:509,549,652` | **Verified** |
| `task.skill_id` populated 1,514/2,271; `required_skills` 1,749 | Live count | **Verified** |
| Create-task modal offers job-role skills for selection | `create-task-modal.tsx:217` | **Verified** |
| 3 tables missing despite migrations recorded | `information_schema` + `migrations` | **Verified** |
| 99 recorded migrations have no file; 211 files vs 357 tables | Set difference + count | **Verified** |
| `hpbrain_schema_migrations` = 38 entries (second migration system) | Live count | **Verified** |
| `s_jobrole_skills` 62,208 / `s_skill_map_k_a` 149,598 / `master_skills` 5,640, all global | Live count + column check | **Verified** |
| Current rights columns cannot express approve/export | Column list | **Verified** |
| All 386 users have no reporting manager | Column absent entirely | **Verified** |
