# User flow — Administrator

**Written 2026-08-11, against what exists.** Every capability below was checked
against the running system, not against the plan. Where something does not work,
the item it waits on is named.

`role_key = administrator`. Guarded by the `profile:admin,hr` middleware, which
matches exactly on `role_key` and carries the alias map for the 13 legacy profiles
that predate it (D-010). **The Administrator shares this guard with `hr_manager`** —
where the two differ it is by data, not by route, and that is noted in-line.

---

## 0. What an Administrator can reach today

Sixteen endpoints, all live and all tenant-scoped from identity:

| area | endpoints |
|---|---|
| **Competency** | `/competency/definitions`, `/competency/role-map`, `/competency/nine-box` |
| **Framework import** | `/competency/seed-library/preview`, `/competency/framework-import/dry-run`, `/competency/framework-import/commit` |
| **Readiness gates** | `/readiness/gates`, `/readiness/gates/acknowledge` |
| **Organization** | `/reporting-line/assign`, `/reporting-line/bulk`, `/reporting-line/department-head` |
| **Performance** | `/performance/cycles`, `/performance/reviews/bulk` |
| **Talent** | `/talent/mobility/internal-jobs`, `/talent/succession/plans` |
| **Settings** | `/terminology` |

**The tenant is never read from the request on any of them.** It comes from the
authenticated identity, and a request that supplies a different one is refused,
not silently ignored. That is G-SEC-12's rule and it holds across all sixteen.

---

## 1. Readiness gates — **the one place a human deliberately switches a capability off**

Screen: `/organization/readiness`. Browser-verified 2026-08-11 (X-21: admin renders
5 gates, employee refused, three identical runs each).

### What the Administrator sees

Five gates, each with its measured value, its threshold, its state, and — when it
is at risk — **what the customer loses by turning it off**.

    reporting_coverage    turns on at 80%   at risk below 65%
    task_hygiene          turns on at 60%   at risk below 45%
    capability_coverage   turns on at 50%   at risk below 35%
    jobrole_definition    turns on at 10 roles  (a COUNT, not a percentage)
    course_mapping        turns on at 50%   at risk below 35%

**A never-computed gate shows "not yet computed", never 0.** A gate nobody has run
has made no claim, and it does not block anything.

### The acknowledgement — the flow that matters

**ON is automatic. OFF is never automatic.** A gate that falls below its threshold
enters `at_risk` and **the capability stays on**; a warning period begins. Only the
Administrator (or HR) can end it, and only after that period elapses.

The confirm dialog carries three things, and a generic "are you sure?" would be
asking for consent to something unstated:

| the dialog states | example, live |
|---|---|
| **What you lose** | *"All manager-dependent flows stop: approvals, reporting-line views, and anything that needs to know who someone reports to."* |
| **Why it is at risk** | *"Import your reporting line — 8 of 122 employees have a manager"* |
| **The warning period** | *"ended (14 day period)"* or *"N days remaining of 14"* |

The warning period is **a column, not a constant** — per gate and per tenant,
because a hospital group and a twelve-person company do not get the same grace
period. Proven: 20 days elapsed with `warning_days=30` refuses; with `warning_days=7`
acknowledges.

**The acknowledgement record is the only thing that disables a gate.** Not the
clock, not a low measurement. `acknowledged_by` and `acknowledged_at` are written
in the same statement that sets `blocked` — there is no path that writes one
without the other, and an invariant checks it from the data.

Cancel writes nothing. Verified.

### What is enforced today

**One gate enforces: `capability_coverage` → gap reporting.** Blocked returns 409
with the reason and the remedy, never an empty list — a customer must be able to
tell "no gaps" from "gap reporting is off".

The other four **measure but do not yet refuse anything**. `reporting_coverage` in
particular cannot: the manager-dependent features it would gate were deferred
pending the very coverage it measures. **A gate cannot enforce against features
that were deferred waiting for it.**

---

## 2. Framework import — **the moment "held, not guessed" becomes visible to a person**

Three endpoints, in this order, and the order is the design.

### 2.1 Preview the seed library — **it previews, it does not import**

`/competency/seed-library/preview` shows what a global seed library contains
against this tenant. **Nothing is written.** An Administrator who wants the rows
must run the import; looking is not taking.

### 2.2 Dry run — `/competency/framework-import/dry-run`

Parses the customer's framework and reports **exactly what would be written**,
including what would NOT be:

- rows that resolve cleanly → will be written
- rows whose department, job role or competency **cannot be matched** → **HELD**
- rows that match **more than one** candidate → **HELD**, because ambiguity is not
  a tie to be broken

**Unmatched and ambiguous are both HELD, and neither is guessed.** A framework
import that quietly picks the closest department is the system manufacturing a
claim nobody made, and it is invisible afterwards — the row looks identical to one
a person authored.

### 2.3 Commit — `/competency/framework-import/commit`

**One transaction.** A partial import is not resumable and would leave a customer's
framework half-mapped with no way to tell which half. Either the whole run lands or
none of it does.

**The write REUSES the dry run's decisions rather than re-deriving them.** If it
re-parsed, the commit could reach a different answer than the one the Administrator
approved — and the thing they approved was a specific list.

### What the Administrator must do about HELD rows

Nothing automatic exists. They are reported, and resolving them is manual: correct
the source or author the missing library entry. **That is the intended behaviour,
not a gap** — the alternative is the import choosing on the customer's behalf.

---

## 3. Competency definitions and role mapping

`/competency/definitions` creates a competency as a **bundle of KASBA items**
(knowledge, attitude, skill, behaviour, ability) with weights. A skill is one of
five dimensions, not a synonym for a competency.

An item is either a **TARGET** (`item_id` — points at a catalogue row) or a
**HOLDING** (`item_label` — a name with nothing behind it yet). Holdings are legal
and visible; they are how a framework gets authored before its catalogue is
complete.

`/competency/role-map` attaches competencies to a job role with a required
proficiency. This is the link that makes a gap computable, and **it is the chain
Slice 1 demonstrates**: role → required competencies → measured level → gap.
Asserted in the regression suite, running in tenant 3.

---

## 4. The 9-box

`/competency/nine-box`. Two axes: **capability** (weighted roll-up over the job
role's required competencies) and **performance** (review ratings).

**An employee with no measurement on an axis is NOT placed in a box.** They appear
in an "unplaced" list with the reason. Placing them would put someone in a box on
the strength of a number nobody produced — the same principle as everywhere else
in this product.

Tenant 3 today: 5 placed, 1 unplaced.

---

## 5. Reporting lines

`/reporting-line/assign`, `/reporting-line/bulk`, `/reporting-line/department-head`
(X-16). Writes go through a validator that refuses a cycle and refuses a
cross-tenant manager.

**Coverage today: 8 of 401 platform-wide.** This is the single largest gap in the
product's data, and it is why `reporting_coverage` reads `blocked` everywhere.

---

## 6. Notifications

In-app only. **Email is OFF (`G2G_NOTIFY_EMAIL=false`) and must stay off** until a
test tenant with fake addresses exists and the decision is taken explicitly — there
are 386 real addresses at real companies behind this switch.

The Administrator receives notifications for rights changes and for events whose
recipient resolves to an admin. `g2g_notification` currently holds 0 rows in this
environment.

---

## ⛔ What an Administrator must NEVER see

- **Another tenant's anything.** Every one of the sixteen endpoints resolves the
  tenant from identity. A request carrying a different `sub_institute_id` is
  refused — G-SEC-27 was a real cross-tenant write and is fixed and probed.
- **A gate switching a capability off without their acknowledgement.** The system
  cannot reach `blocked` from `at_risk` on its own; verified across 64 state-machine
  inputs, zero of which reach a disable.
- **A `0` where nothing was measured.** Never-computed is rendered as words, and
  the API keeps `null` distinct from `0` all the way to the screen.
- **An import that resolved an ambiguity for them.** Held rows are reported, never
  silently assigned.
- **`hpbrain_*` data.** Untouched by this phase and not surfaced anywhere.

---

## 7. Where this role stands

### ✅ Works today, end to end

- Readiness gates: measured, displayed, acknowledged with attribution, browser-verified.
- `capability_coverage` → gap reporting enforcement, with reason and remedy.
- Competency definitions, KASBA bundles, role mapping, and the Slice 1 chain.
- The 9-box with unplaced handled honestly.
- Framework import: preview → dry run → one-transaction commit, with held rows.
- Reporting-line writes with cycle and cross-tenant refusal.
- Rights matrix and the nine role logins (tenant 3).

### ⛔ Dead-ended, and on what

| dead end | waits on |
|---|---|
| Four of five gates measure but refuse nothing | **enforcement points** — one exists; `reporting_coverage` cannot have one until its features return |
| `department_head` / `reporting_manager` scope | **reporting-line coverage** — 8 of 401. The const arrays hardcode the absence and nothing re-evaluates them |
| A gate transition notifies nobody | **X-15 `FeatureGateApplier`** — trigger reads "more features enforce gates", currently one |
| Certification requirements | **G-STR-02** — `competency_certification_requirements` verified absent 2026-08-11 |
| Held import rows have no resolution screen | **X-08(b)** enrichment loop |

### 🔗 Remaining plan items this role depends on

`X-15` (gate reactor) · `X-08(b)` (held-row resolution) · `G-STR-02`
(certification requirements) · the **role-lists-defer-to-the-gate** convergence
item · `G-MIG-01` (retire anonymous `table_data` callers, an admin-surface
migration).

**None of these block the Administrator's current flows.** Every one is an
extension of something already working — which is a different statement from the
Employee and Manager flows, where the dead ends sit on the main path.
