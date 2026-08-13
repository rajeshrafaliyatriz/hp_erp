# 05 — Data flow contracts

**Gate B deliverable, final.** Read-only analysis; no application code changed.
Date: 2026-08-05

This is the mechanism every golden thread depends on. `app/Events`,
`app/Listeners` and `app/Observers` **do not exist** in this codebase (`G-STR-04`),
so none of the cross-module behaviour in the brief §6 has anywhere to run today.

Design harvested from `hpbrain_event_store` per Q-C4, built **natively in G2G**.
No runtime dependency on `hpbrain_*`.

---

## 1. One history, two projections (**S6 — settled first**)

Three overlapping records of "something happened" were about to exist:
`g2g_audit_log`, `task_status_history`, and the event store. **Three logs that
disagree is worse than no log.**

### The model

```
                    ┌───────────────────────────────┐
   every meaningful │        EVENT STORE            │  the single source of record
   state change ───►│  g2g_event                    │  append-only, never updated
                    └───────────────┬───────────────┘
                                    │ projected into
                 ┌──────────────────┼──────────────────┐
                 ▼                  ▼                  ▼
     ┌────────────────────┐ ┌──────────────────┐ ┌──────────────────┐
     │ g2g_audit_log      │ │task_status_history│ │competency_evidence│
     │ "who did what"     │ │ "task lifecycle" │ │ "capability proof"│
     │ compliance view    │ │ operational view │ │ measurement view  │
     └────────────────────┘ └──────────────────┘ └──────────────────┘
              PROJECTIONS — rebuildable, never written independently
```

**Binding rules:**

1. **`g2g_event` is the only thing written first.** Every state change appends an
   event, in the same database transaction as the change itself.
2. **Projections are derived.** `g2g_audit_log`, `task_status_history` and
   `competency_evidence` are built by projectors reading the event stream.
3. **A projection can be dropped and rebuilt** from the store. If it cannot, it is
   not a projection — it is a second source of truth, and that is the defect this
   section exists to prevent.
4. **No projector writes to another projection.** Each reads only the store.

### AMENDMENTS — 2026-08-10

Two changes to the model above, made explicitly because §1 is the contract for six
other items and a silent change would be a change to all of them.

#### A1 — `g2g_audit_log` supersedes `task_management_audit_logs`, and THE WORK IS THE WRITER

`g2g_audit_log` is built as a projection. **The six rows in
`task_management_audit_logs` are not the work.** `TaskAuditService` **writes
directly** today, so the deliverable is converting it to **EMIT EVENTS**.

> **A projection and a direct writer existing at once is exactly what §1 prevents,
> dressed as compliance with it.**

#### A2 — navigation telemetry is OUT OF SCOPE OF THE MODEL, not an exception to it

`tbl_user_journey_logs` (5,234 rows) is **not** declared a third independent
writer.

The two existing exceptions are **facts that BELONG in the store but originate
outside it** — a manager's observation, imported history. **Navigation telemetry
does not belong in the store at all:** nobody acts on a page view, so every such
event **fails the named-consumer test by construction**, and 5,234 rows of
navigation would flood the store with consumerless events.

> **THE MODEL COVERS BUSINESS FACTS WITH NAMED CONSUMERS.** Telemetry is a
> separate concern with its own table — **not an exception to the model.**
>
> An exception list that grows invites more exceptions. *"Not in scope"* closes
> the question; *"a third exception"* opens it.

#### A3 — engine note, not a contract change

MariaDB stores `JSON` as `LONGTEXT` with a check constraint, so `payload` and
`metadata` report as `longtext` in `DESCRIBE`. **The contract's column type is
honoured; the engine's storage differs.** Recorded so a future reader diffing DDL
against this document does not raise it as a violation.

**Schema claims are verified against the CREATED SCHEMA, never the migration
source** — the migration is what was meant, the database is what happened.

---

### Justified independent writers — the exceptions

Per your instruction to justify explicitly, there are exactly **two**:

| Writer | Why it is not a projection |
|---|---|
| **Manual `competency_evidence`** — a manager's written observation | Nothing happened in the system. There is no state change to emit an event for; the observation *is* the source fact. Marked `source_type='manual'` |
| **Imported history** — measurements or task records loaded from a customer's previous system | The events genuinely occurred, but outside this product. Fabricating events to describe another system's past would put fiction in the source of record. Marked `source_type='import'` |

Both are flagged in the projection so a reader can tell derived rows from asserted
ones. **Everything else is derived, without exception.**

### Why `competency_evidence` is a projection and not a store

It was specified in `02-domain-model.md` §5 as a table. It stays a table — but its
rows are **written by a projector** reacting to `task.rejected`,
`assessment.completed`, `course.completed`, `certification.issued`. That is what
makes the Q-B3 threshold recomputable: if the weighting changes, evidence is
rebuilt from the events rather than migrated.

### The event store

```sql
CREATE TABLE g2g_event (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  event_uuid       CHAR(36) NOT NULL,
  type             VARCHAR(96) NOT NULL,        -- 'task.rejected'
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  entity_type      VARCHAR(64) NOT NULL,        -- task | user | enrolment
  entity_id        BIGINT UNSIGNED NULL,
  actor_id         BIGINT UNSIGNED NULL,        -- NULL = system
  acting_for_id    BIGINT UNSIGNED NULL,        -- delegation (A4): "B acting for A"
  payload          JSON NOT NULL,
  metadata         JSON NULL,                   -- ip, user agent, request id
  correlation_id   CHAR(36) NULL,               -- one user action, many events
  causation_id     CHAR(36) NULL,               -- which event caused this one
  idempotency_key  VARCHAR(191) NULL,
  occurred_at      DATETIME(3) NOT NULL,
  recorded_at      DATETIME(3) NOT NULL,
  UNIQUE KEY uq_event_uuid (event_uuid),
  UNIQUE KEY uq_event_idem (sub_institute_id, idempotency_key),
  INDEX idx_event_tenant_time (sub_institute_id, occurred_at),
  INDEX idx_event_type (type, occurred_at),
  INDEX idx_event_entity (entity_type, entity_id)
);
```

**Append-only. No UPDATE, no DELETE.** A mistake is corrected by a compensating
event, never by editing history.

Deviations from `hpbrain_event_store`, per Q-C4:

| hpbrain | Here | Why |
|---|---|---|
| `status`, `retry_count`, `last_retry_at`, `failure_reason` on the event | moved to `g2g_event_delivery` | Delivery state is per **consumer**. One event succeeding for the notifier and failing for the projector cannot be expressed in a single status column |
| — | **`acting_for_id`** | A4 requires the audit to record "B acting for A" |
| `created_at` only | **`occurred_at` + `recorded_at`** | Imports and offline actions occur before they are recorded; conflating them silently reorders history |
| — | `DATETIME(3)` | Two events in one request need a deterministic order |

```sql
CREATE TABLE g2g_event_delivery (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  consumer VARCHAR(96) NOT NULL,          -- projector or subscriber name
  status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_delivery (event_id, consumer),
  INDEX idx_delivery_pending (status, consumer)
);
```

This is what makes "did the notification actually go out?" answerable.

---

## 1.9 ⛔ PREREQUISITE — G-SEC-12 blocks this document

**Everything below assumes `actor_id` on an event is trustworthy.** It is not, yet.

`created_by` / `updated_by` are taken from the **request body** in **33 places**
(S-3). A caller can attribute a write to another user and the record states it as
fact. **An event store built on top of that inherits a corrupted audit trail on day
one — the exact thing it exists to provide.**

**G-SEC-12 is sequenced before the event store in the Gate D order.** Until it is
closed, no event's `actor_id` can be relied on for audit, approval or evidence.

---

## 2. Event catalogue

**The test applied to every event: does it have a NAMED CONSUMER that DOES
something?** An event nobody listens to is a slower log. Events failing the test
are dropped or deferred below, however tidy they looked.

### 2.0 Two kinds of consumer (**B1**)

The §1 model promises projections are droppable and rebuildable, and §6 makes
replay the point. But several consumers **change the world outside the database**.
A rebuild that replayed through them would re-issue certificates, re-revoke access,
re-assign courses and email everyone.

There is also a contradiction to resolve: idempotency keyed on
`(event_id, consumer)` means a rebuild either skips everything and rebuilds
nothing, or clears the ledger and re-fires the side effects. Both behaviours are
needed — so there are two categories, not one.

| | **PROJECTOR** | **REACTOR** |
|---|---|---|
| Effect | Writes **only its own projection** | Touches the outside world |
| Purity | Pure — same events in, same rows out | Impure — sends, issues, revokes, assigns |
| Replay | **Replayable.** Runs on rebuild | **Never runs on replay.** Live processing only |
| Ledger on rebuild | **Cleared**, then re-derived | **Permanent — survives every rebuild** |
| Failure | Retry freely; idempotent by construction | Retry with care; each attempt is externally visible |

**Rule: a reactor may never be triggered by a projector.** Reactors subscribe to
the event store directly, exactly as projectors do. A projector that could invoke a
reactor would make rebuild unsafe again through the back door, and it is the one
mistake that would silently undo this whole section.

Where a projector's output *should* cause a reaction, the projector **emits a new
event** and the reactor subscribes to that. The chain stays in the store, visible
via `causation_id`, and replay stops at the projector boundary because reactors do
not run on replay.

### 2.1 Events that ship

`kind` per B1: **P** = projector (replayable) · **R** = reactor (live only).

| Event | Emitted when | Consumer | Kind | What the consumer DOES |
|---|---|---|:-:|---|
| **`task.rejected`** | `approve_status` → `rejected` | `CapabilityEvidenceProjector` | **P** | Writes `competency_evidence` weight 3 (F1). **The only Q-B3 signal that ships first** (M2) |
| | | `TaskStatusProjector` | **P** | Appends `task_status_history` |
| | | `NotificationDispatcher` | **R** | Notifies assignee + manager |
| **`task.status_changed`** | any status transition | `TaskStatusProjector` | **P** | Appends `task_status_history` — **makes F2 reopen detectable** |
| **`task.reopened`** | transition into active **from** terminal | `CapabilityEvidenceProjector` | **P** | Evidence weight 3 (F2). *Ships only once history exists* |
| **`capability.flag_raised`** | evidence weight ≥ 3 in 90d, one job-role task | `NotificationDispatcher` | **R** | Notifies the manager |
| | | `RemediationRecommender` | **R** | Finds the course via the **competency-derived** path (S4) and shows it **immediately**, per Q-B3 |
| **`capability.flag_resolved`** | manager confirms / assigns / dismisses | `ProficiencyService` | **P** | Applies the change **only** on explicit confirm (Q-B3) |
| | | `CapabilityEvidenceProjector` | **P** | Records the dismissal reason as evidence |
| **`assessment.completed`** | all raters submitted | `ProficiencyService` | **P** | Recomputes the roll-up (Q-E2 trigger 1) |
| | | `GapRecalculator` | **P** | Recomputes gaps for that user |
| | | `NotificationDispatcher` | **R** | Notifies employee + manager |
| **`course.completed`** | all content complete | `CertificateIssuer` | **R** | **Auto-issues the certificate** — fixes the manual-claim gap in `G-FLOW-05` |
| | | `ProficiencyService` | **P** | Raises proficiency **only if** Q-B2 permits for that competency |
| | | `GapRecalculator` | **P** | Recomputes |
| **`certification.issued`** | certificate created | `CapabilityEvidenceProjector` | **P** | Positive evidence |
| | | `NotificationDispatcher` | **R** | Notifies the employee |
| **`certification.expiring`** | scheduled, at T-30/T-7 | `NotificationDispatcher` | **R** | Notifies holder + manager |
| | | `RemediationRecommender` | **R** | Assigns the renewal course (golden thread 8) |
| **`employee.role_assigned`** | `allocated_standards` set/changed | `GapRecalculator` | **P** | Resolves required capability, computes gaps (golden thread 1) |
| | | `ProficiencyService` | **P** | Recomputes (Q-E2 trigger 6) |
| | | `MandatoryLearningAssigner` | **R** | Assigns `course_jobrole_map` courses — role-mandatory, **not** gap-driven (S4) |
| **`employee.hired`** | employee record created | `OnboardingLauncher` | **R** | Creates the onboarding journey |
| | | *(then emits `employee.role_assigned`)* | | |
| **`employee.offboarded`** | `terminated_date` set | `AccessRevoker` | **R** | Revokes access per RBAC (`G-FLOW-12`) |
| | | `TaskReassigner` | **R** | Surfaces open tasks for reassignment |
| | | `NotificationDispatcher` | **R** | Notifies manager + HR |
| **`development_plan.approved`** | manager approves | `LearningAssigner` | **R** | Assigns the plan's courses |
| | | `NotificationDispatcher` | **R** | Notifies the employee |
| **`readiness_gate.changed`** | recompute crosses a threshold | `NotificationDispatcher` | **R** | Notifies tenant admin |
| | | `FeatureGateApplier` *(renamed, B2)* | **R** | Applies the gate — **ON automatic, OFF never** (§4) |
| **`rights.changed`** | a rights row is written | `AuditProjector` | **P** | `g2g_audit_log` |
| | | `NotificationDispatcher` | **R** | Notifies the affected user if their access narrowed |

**Consumer register:**

| Projectors (replayable) | Reactors (live only) |
|---|---|
| `AuditProjector` | `NotificationDispatcher` |
| `TaskStatusProjector` | `CertificateIssuer` |
| `CapabilityEvidenceProjector` | `AccessRevoker` |
| `GapRecalculator` | `MandatoryLearningAssigner` |
| `ProficiencyService` | `LearningAssigner` |
| | `RemediationRecommender` |
| | `FeatureGateApplier` |
| | `OnboardingLauncher` |
| | `TaskReassigner` |

`OnboardingLauncher` and `TaskReassigner` are reactors: both create work items
people will see and act on. Re-running either on a rebuild would duplicate a new
hire's onboarding journey or re-open settled reassignments.

**Verified against B1's list:** every consumer named there —
`CertificateIssuer`, `AccessRevoker`, `NotificationDispatcher`,
`MandatoryLearningAssigner`, `LearningAssigner`, `RemediationRecommender`,
`FeatureGateApplier` — is classified **R**. No projector in the left column has an
effect outside its own table, and **no reactor appears as the downstream of a
projector** in the catalogue above.

### 2.2 Events deliberately NOT shipped

Applying the test honestly:

| Candidate | Verdict | Reason |
|---|---|---|
| `task.assigned` | **Deferred** | The only plausible consumer is a notification, and 2,271 tasks with 99% overdue means it would be noise before it was useful. Revisit when `task_hygiene` passes |
| `task.overdue` | **Deferred** | Gated on the same readiness gate as F4 (M1). Emitting it now would fire 2,245 times |
| `task.completed` | **Dropped for now** | No consumer *does* anything. Completion without approval is not a capability signal; approval already emits `task.approved` |
| `competency.gap_detected` | **Dropped as an event** | Gaps are **derived**, not a state change. Emitting one every time a recompute finds the same standing gap would flood the store. The gap is a *query*, not an event |
| `assessment.launched` | **Ships only with the notifier** | Its sole consumer is a notification. Kept because a launched assessment nobody is told about is useless |
| `course.assigned` | **Ships only with the notifier** | Same reasoning; also carries the *reason* (`G-FLOW-04`) |
| `succession.candidate_identified` | **Deferred** | Succession has no consumer that acts on it yet. Add when the Talent flows are designed |
| `employee.role_changed` | **Merged** into `employee.role_assigned` | Two events, one handler set. A separate type would only duplicate consumers |

`competency.gap_detected` is the one worth arguing about: it appears in the brief's
§6 list. But a gap is a standing condition, not an occurrence — the occurrence is
*the assessment* or *the required-level change* that caused it, and both already
emit. Modelling a derived condition as an event produces an ever-growing stream of
"still true" records.

---

## 3. Notification service

Every golden thread ends in "notify someone". The five manual send-message screens
are deferred (Q-A3 amendment 1); **the service is in scope.**

```sql
CREATE TABLE notification (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id BIGINT UNSIGNED NULL,
  recipient_portal_identity_id BIGINT UNSIGNED NULL,   -- Q-D4 external, never mixed
  event_id BIGINT UNSIGNED NULL,                        -- provenance back to the store
  template_key VARCHAR(96) NOT NULL,
  channel ENUM('in_app','email','sms','whatsapp') NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','sent','failed','suppressed','read') NOT NULL DEFAULT 'queued',
  suppressed_reason VARCHAR(96) NULL,
  sent_at DATETIME NULL, read_at DATETIME NULL,
  INDEX idx_notif_recipient (sub_institute_id, recipient_user_id, status),
  INDEX idx_notif_event (event_id)
);

CREATE TABLE notification_preference (
  id, sub_institute_id, user_id, template_key,
  channel ENUM('in_app','email','sms','whatsapp'),
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  digest ENUM('immediate','daily','weekly') NOT NULL DEFAULT 'immediate',
  UNIQUE KEY uq_pref (sub_institute_id, user_id, template_key, channel)
);
```

### Rules

1. **Every notification names its event.** `event_id` is how "why did I get this?"
   is answerable, and how a duplicate is detected.
2. **Recipient resolution goes through the RBAC scope resolver**, not ad-hoc
   queries. "Notify the manager" means the no-manager ladder in `manager.md` §2.1 —
   including the visible-block terminal state.
3. **Internal and external recipients never share a column.** Q-D4's isolation
   boundary holds here too.
4. **Digest before volume.** Any template that can fire more than ~5×/day per
   recipient defaults to `daily`. This is what prevents the capability signal
   becoming spam the day `task_hygiene` passes.
5. **Suppression is recorded, not silent.** A notification not sent because of a
   preference, a digest, or a missing recipient is stored with
   `status='suppressed'` and a reason.

### Templates that ship

| `template_key` | Recipient | Trigger |
|---|---|---|
| `capability.flag_raised` | manager | Q-B3 threshold |
| `capability.remediation_available` | employee | same event — help, not a verdict |
| `assessment.due` / `assessment.completed` | employee, manager | assessment events |
| `course.assigned` | employee | includes **why** (`G-FLOW-04`) |
| `certificate.issued` | employee | `course.completed` |
| `certification.expiring` | holder, manager | T-30, T-7 |
| `development_plan.awaiting_approval` | manager | plan submitted |
| `leave.awaiting_approval` | approver via ladder | leave request |
| `onboarding.task_due` | new hire, buddy | onboarding |
| `offboarding.reassign_tasks` | manager | `employee.offboarded` |
| `readiness_gate.blocked` | tenant admin | gate crosses down |
| `access.narrowed` | affected user | `rights.changed` |

---

## 4. Readiness gates, observable through the same mechanism

Per your requirement, gates are not a side system.

```
nightly ReadinessGateRecomputer
   → measures each of the 6 metrics per tenant
   → writes tenant_readiness_gate
   → emits `readiness_gate.changed` ONLY on a state transition
        → FeatureGateApplier    [REACTOR]  applies it — asymmetrically, see 4.1
        → NotificationDispatcher [REACTOR] tells the tenant admin
```

**Emitting only on transition** matters: a nightly "still blocked" event for every
gate in every tenant is exactly the noise §2.2 rejects for
`competency.gap_detected`.

`FeatureGateApplier` is **renamed from `FeatureToggleProjector` (B2)** — it changes
what users can do, so it is a **reactor**, not a projector. The old name would have
put it in the replay path.

### 4.1 Asymmetric switching (**B2**)

**Turning a feature ON may be automatic. Turning one OFF must never be.**

The failure this prevents: a bulk employee import briefly drops
`reporting_coverage` below 80%, manager approvals disable mid-cycle, and nobody
knows why approvals stopped working.

| Direction | Behaviour |
|---|---|
| **blocked → ready** | **Automatic.** Feature enables immediately. Admin notified. Gaining a capability needs no ceremony |
| **ready → below threshold** | **Never automatic.** Enters `at_risk`, feature **stays on**, warning period begins |
| **Disable** | Only after the warning period **and** explicit admin acknowledgement |

Extended state machine:

```
blocked ──(metric passes + sustained)──► ready
ready ──(metric falls)──► at_risk ──(warning period elapses
                            │          AND admin acknowledges)──► blocked
                            └──(metric recovers)──► ready   [no admin action needed]
```

```sql
ALTER TABLE tenant_readiness_gate
  ADD COLUMN state ENUM('blocked','at_risk','ready') NOT NULL DEFAULT 'blocked',
  ADD COLUMN enable_threshold  DECIMAL(6,2) NOT NULL,   -- hysteresis: enable point
  ADD COLUMN disable_threshold DECIMAL(6,2) NOT NULL,   -- always looser than enable
  ADD COLUMN sustained_periods TINYINT NOT NULL DEFAULT 3,
  ADD COLUMN at_risk_since     DATETIME NULL,
  ADD COLUMN warning_days      TINYINT NOT NULL DEFAULT 14,
  ADD COLUMN acknowledged_by   BIGINT UNSIGNED NULL,
  ADD COLUMN acknowledged_at   DATETIME NULL;
```

**Hysteresis** — the enable and disable points differ, so a metric sitting on the
boundary cannot flap:

| Gate | Enable at | Disable at |
|---|---:|---:|
| `reporting_coverage` | ≥ 80% | < 65% |
| `task_hygiene` | ≥ 60% | < 45% |
| `capability_coverage` | ≥ 50% | < 35% |
| `jobrole_definition` | ≥ 70% | < 55% |
| `course_mapping` | ≥ 50% | < 35% |
| `task_competency_link` | ≥ 50% | < 35% |

Plus `sustained_periods` — a gate enables only after passing on 3 consecutive
recomputes, so a mid-import spike does not switch a feature on either.

All values remain **UNCALIBRATED DEFAULTS** (test data only, owner-stated).

**A feature disabled this way always states why**, names the metric and its value,
and records who acknowledged it. A capability that vanishes without explanation is
worse than one that was never offered.

Each gate exposes: current value, threshold, state, **and the specific remedy**.

| Gate | Blocks | Remedy shown |
|---|---|---|
| `reporting_coverage` | all manager-dependent flows | "Import your reporting line — 0 of 264 employees have a manager" |
| `task_hygiene` | F4 overdue weights, `task.overdue` | "99% of tasks are overdue — close or re-date stale tasks" |
| `capability_coverage` | gap reporting | "3.0% of employees have a capability measurement" |
| `jobrole_definition` | gap analysis, auto-assignment | "Import the job-role library" |
| `course_mapping` | recommendation engine | "0% of courses are mapped to a competency" |
| `task_competency_link` | golden thread 2 | "67% of tasks carry a competency link" |

All thresholds remain **UNCALIBRATED DEFAULTS** (test data only, owner-stated).

---

## 5. `task_status_history` — the store's first consumer

Deliberately the first projector built, per M2. It proves the pattern on something
small and immediately useful.

```
task status/approve_status changes
   → emit task.status_changed  (+ task.rejected when approve_status→rejected)
   → TaskStatusProjector appends task_status_history
   → F2 reopen becomes detectable:
        a transition INTO an active status FROM a terminal one
        (COMPLETED, or approve_status='approved')
```

**Not backfilled.** There is no historical transition data to recover, and
inventing it would fabricate evidence — the same principle as refusing to infer a
competency for the 33% null `task.skill_id`.

Sequencing:

| # | Step | Unlocks |
|---:|---|---|
| 1 | `g2g_event` + `g2g_event_delivery` | the mechanism |
| 2 | Emit `task.status_changed`, `task.rejected` | — |
| 3 | `TaskStatusProjector` → `task_status_history` | **F2 detectable** |
| 4 | `CapabilityEvidenceProjector` → `competency_evidence` | **F1 ships** |
| 5 | `NotificationDispatcher` | golden threads terminate visibly |
| 6 | `ProficiencyService` + `GapRecalculator` | threads 3 and 4 |
| 7 | Remaining projectors | the rest |

---

## 6. Transactional guarantees

| Concern | Decision |
|---|---|
| Event vs state change | **Same transaction.** A state change without its event is a hole in the source of record |
| Projections | **After commit, asynchronously.** A failing projector must not roll back the user's action |
| Ordering | Per `(entity_type, entity_id)`, by `occurred_at` then `id` |
| Idempotency | `uq_event_idem` on `(tenant, idempotency_key)`; consumers idempotent per `(event_id, consumer)` |
| Failure | Recorded in `g2g_event_delivery`, retried with backoff, surfaced on a health screen — **never silently dropped** |

### 6.1 The replay rule (**B1**)

```
REBUILD A PROJECTION
  1. Truncate the projection table
  2. DELETE FROM g2g_event_delivery WHERE consumer = <projector>   -- projector ledger only
  3. Replay the event stream through that projector alone
  4. Reactor ledger rows are NEVER touched

REACTORS DO NOT RUN ON REPLAY.
  The replay runner is invoked with an explicit projector list.
  It has no code path that can dispatch to a reactor.
```

This resolves the contradiction B1 identified. The two behaviours needed —
*clear and re-derive* versus *never re-fire* — are exactly the two consumer kinds:

| | Ledger on rebuild | Effect of replay |
|---|---|---|
| Projector | **Cleared**, rows re-derived | Table is rebuilt, identical |
| Reactor | **Permanent** | Nothing happens. No email, no certificate, no revocation |

Three safeguards, because getting this wrong is silent and expensive:

1. **Separate interfaces.** `Projector` and `Reactor` are distinct contracts. The
   replay runner accepts only `Projector`; passing a `Reactor` is a type error, not
   a runtime check.
2. **A reactor may never be invoked by a projector** (§2.0). Where a projector's
   output should cause a reaction, it **emits an event** and the reactor subscribes
   to that. The chain is visible via `causation_id`, and replay stops at the
   projector boundary.
3. **Replay mode is explicit and asserted.** In replay, reactor dispatch throws
   rather than silently no-ops — a no-op would hide a wiring mistake until the day
   it mattered.

**Deliberately not built:** a message broker. The store plus a delivery table plus
Laravel's queue is enough at this scale, and introducing infrastructure before it
is needed is how a schema change becomes a platform migration.

---

### 6.2 Replay operating procedure (**C7**)

§6.1 defines what replay *is*. This defines how it is *run*. Replay is the property
that makes the event store worth having, and it is also the single most destructive
command in the system: step 1 truncates a table that users are reading. Written
down, it is routine. Undocumented, it is the outage.

#### When replay is the right tool — and when it is not

| Situation | Replay? |
|---|---|
| Projector logic was wrong; the store is correct | **Yes.** This is the case replay exists for |
| A new projection is added and needs back-filling | **Yes.** Same mechanism, empty starting table |
| A projection drifted from the store for unknown reasons | **Yes — but diagnose first.** Drift means a writer bypassed the store (§1). Replay hides the symptom and the bypass keeps writing |
| The **events themselves** are wrong | **No.** Replay faithfully reproduces bad events. Emit corrective events instead; the store is append-only |
| A reactor's side effect needs re-running (resend an email, reissue a certificate) | **No.** Replay cannot do this by construction (§6.1). Use the reactor's own re-dispatch path, deliberately, per subject |
| Something looks broken and nobody knows why | **No.** Replay is not a diagnostic |

#### Preconditions — all four, every time

1. **The store is intact.** `g2g_event` row count and max `id` recorded before starting.
   *(**Corrected 2026-08-10** — this read `g2g_event_store`, a name §1's DDL never
   used. A typo, not a design change: the built table matches §1. **A contract
   carrying two names for one table is a defect generator** — the same family as
   the mark and the qualifier sharing a cell, which produced three separate
   defects.)* If the store is damaged, replay is not recovery — restore is.
2. **The projector is idempotent under a full rebuild**, proven by the dry run below, not by inspection.
3. **The projection has no independent writer.** If anything writes it outside the projector, truncation destroys data the store cannot regenerate. Check §1's exceptions table — the *justified independent writers* listed there are **not replayable** and must never be targets.
4. **A dated backup of the target table exists** and its row count is written into the change record.

#### The procedure

```
0. RECORD      Open a change record (standing change template). Note:
               target projection, projector class, store max(id), target row
               count, backup filename, operator, reason.
1. DRY RUN     Rebuild into a SHADOW table, store untouched, live table untouched.
               Run against the SAME store max(id) recorded in step 0.
2. DIFF        Compare shadow against live, row by row.
                 - identical                -> the projector is sound; proceed
                 - shadow differs as INTENDED (this is the bug fix) -> proceed,
                   and paste the diff into the change record
                 - shadow differs UNEXPECTEDLY -> STOP. Do not proceed.
                   An unexplained diff means an unknown writer or a
                   non-deterministic projector. Both are worse bugs than the
                   one being fixed.
                 - shadow is EMPTY or short  -> STOP. Usually a filter or a
                   tenant scope silently excluding events.
3. WINDOW      Announce. Put the affected screens into read-only if the tenant
               is live. A user reading a half-rebuilt projection sees data
               that never existed.
4. EXECUTE     §6.1 steps 1-4, in that order, inside a transaction where the
               engine allows it. Explicit projector list. Replay mode ON, so
               any reactor dispatch throws (§6.1 safeguard 3).
5. VERIFY      Three checks, before reopening:
                 a. row count == shadow row count from step 1
                 b. reactor ledger rows for this consumer: UNCHANGED (count
                    them before and after; this is the safeguard that catches
                    a Reactor mistyped as a Projector)
                 c. spot-check FIVE ACTUAL ROWS by eye  (R3)
6. REOPEN      Lift read-only. Close the change record with final counts.
```

#### Rollback

Rollback is **restore the backup from precondition 4**, not "replay again" — if the
projector is what went wrong, running it a second time reproduces the same result.
Restore first, reopen, then fix the projector offline.

Events arriving *during* the window are not lost: they are in the store, and the
projector's delivery ledger picks them up on the next ordinary pass. This is the
one hazard the append-only store removes for free.

#### Standing constraints

- **Replay is never automatic.** No schedule, no deploy hook, no self-healing job. Every run is a human decision with a change record.
- **Replay never runs against more than one projection at a time.** Failures must be attributable.
- **Replay is never the first response to an incident.** Diagnose, then decide.
- **The runner refuses to start** without an explicit projector list, a recorded store `max(id)`, and replay mode ON — three arguments, no defaults, because every default here is a way to lose data quietly.

---

## 7. What this closes

| Gap | Closed by |
|---|---|
| `G-STR-04` no event mechanism | §1 |
| `G-FLOW-19` no evidence store | `CapabilityEvidenceProjector` |
| `G-FLOW-25` reopen undetectable | §5 |
| `G-FLOW-05` manual certificate claim | `CertificateIssuer` on `course.completed` |
| `G-FLOW-11` role assignment triggers nothing | `employee.role_assigned` |
| `G-FLOW-12` no access revocation | `AccessRevoker` |
| `G-FLOW-10` no expiry notification | `certification.expiring` |
| `G-FLOW-04` course assignment has no reason | `course.assigned` payload |
| `G-FLOW-14` no unified approvals queue | notifications + projections |

---

## 8. Open question

### Q-F1 — Notification content and language · `ANSWERED`

**Decision:** fixed wording with **tenant-substitutable terminology**. Build both
tables **now**, even though only system-owned rows exist at launch — adding tenant
overrides later must be **data entry, not a refactor**.

**Terminology matters more commercially than translation:** "employee" is wrong for
a hospital (clinician), a shipping line (crew) and a retailer (associate), and this
product is sold across all of them.

```sql
CREATE TABLE notification_template (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NULL,   -- NULL = system-owned default
  template_key VARCHAR(96) NOT NULL,
  channel ENUM('in_app','email','sms','whatsapp') NOT NULL,
  locale VARCHAR(12) NOT NULL DEFAULT 'en',   -- one locale at launch, column present
  subject VARCHAR(255) NULL,
  body TEXT NOT NULL,                          -- {{term.employee}}, {{user.first_name}}
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  timestamps,
  UNIQUE KEY uq_tpl (sub_institute_id, template_key, channel, locale)
);

CREATE TABLE terminology (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sub_institute_id BIGINT UNSIGNED NULL,   -- NULL = system default
  term_key VARCHAR(64) NOT NULL,           -- 'employee', 'manager', 'competency'
  locale VARCHAR(12) NOT NULL DEFAULT 'en',
  singular VARCHAR(96) NOT NULL,           -- 'clinician' | 'crew member' | 'associate'
  plural VARCHAR(96) NOT NULL,
  timestamps,
  UNIQUE KEY uq_term (sub_institute_id, term_key, locale)
);
```

**Resolution — same shape as the rights resolver:** tenant row for
`(key, locale)` → system row for `(key, locale)` → system row for `(key, 'en')`.
A tenant that overrides nothing gets the defaults; one that renames *employee*
gets it everywhere, including notifications, screens and reports.

Rules:

1. **No hardcoded user-facing strings in code.** Every one resolves through
   `terminology` or `notification_template`, even while only system rows exist.
   This is the whole point of building the tables now.
2. **`locale` is populated with one value at launch.** Multi-language is **not** a
   launch feature, but the column exists so adding a locale is data entry.
3. **Terminology applies beyond notifications.** Screen labels and report headings
   resolve through the same table — otherwise the hospital that renamed *employee*
   to *clinician* sees it only in emails.
4. `hpbrain_terminology` (42 rows) shows this pattern was already worked out. Per
   Q-C4 the **design** is harvested; the table is G2G's own.

---

## 9. Verification status

| Claim | Method | Status |
|---|---|---|
| `app/Events`, `app/Listeners`, `app/Observers` absent | Filesystem | **Verified** |
| `app/Jobs` holds one unrelated job | Filesystem | **Verified** |
| `hpbrain_event_store` columns as cited | Live schema | **Verified** |
| Certificate issuance is a manual claim, chain complete | Code trace, 5 layers | **Verified** |
| No status-transition history exists | Schema search | **Verified** |
| 2,245 / 2,271 tasks overdue *(test data)* | Live query | **Verified** |
| Readiness metrics: coverage 3.0%, course mapping 0% | Live query | **Verified** |
