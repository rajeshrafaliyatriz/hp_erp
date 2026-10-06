# Sprint 6 — The approval chain, and payroll that stops duplicating itself

**Closed:** F-109, F-124, F-125, F-126, F-127 — **5**, taking the total to **33 of 41**.
**Raised:** F-125 (found while proving F-109), and **F-126 + F-127, which this sprint itself
introduced** and then closed after reviewing its own work.
**Live changes:** three migrations (+ one reversal script). No pre-existing table altered.

---

## F-124 — the last configuration screen that controlled nothing

`hrms_leave_workflow_settings` has three live rows. Every one of them says
`escalation_enabled = 1, escalation_time = 24`. The Leave Configuration screen saves them,
reloads them, and validates them.

**Nothing in the product read that table.** One approval from anybody holding `approve_leave`
decided the request, whatever the tenant had configured. A two-stage chain and a one-stage
chain behaved identically, so the screen demonstrated a capability the product did not have.

### It could not be fixed with a column

"Approved" stops being one fact once a chain exists. A request can hold the reporting
manager's approval and still be waiting on the department head, and **both** of those
decisions — who, when, with what comment — are part of the record. So the unit of storage is
one row per required approval:

```
hrms_leave_approval_steps
  leave_id, sub_institute_id, step_order, approver_role
  status         waiting -> pending -> approved | rejected | skipped
  approver_id, approver_name, comment, decided_at
  pending_since  when it became this step's turn
  escalated_at, escalated_to
```

`status = 'waiting'` is what stops step 2 being approved before step 1. Only one step is ever
`pending`, and `currentStep()` is the single place that says which.

### How the screen's switches become a chain

`LeaveApprovalWorkflow::chainFor()` is the only reader of the settings table:

| Setting | Chain |
|---|---|
| Multi-level **off** | one step: the first enabled role |
| Multi-level **on**, count `n` | the first `n` enabled roles, in order RM → DH → HR |
| Nothing enabled | a single `hr` step |

The last row is a deliberate choice. A tenant that switches every approver off has
misconfigured itself; "HR decides" is the safe reading. Never "it approves itself", and never
"it can never be approved".

### The chain is frozen at submit time

`openFor()` runs in `store()`, when the request is raised. If HR changes the configuration
tomorrow, requests already in flight keep the chain they entered under. Re-reading the
settings at approval time would let a configuration change retroactively approve a request, or
strand one that was already half-approved.

For the same reason, **editing a pending request does not restart its chain**. The days may
have changed but the same approvers are still being asked, and discarding a manager's approval
because the employee fixed a typo would quietly undo work somebody had already done.

### Proven live, end to end

`_evidence/probe-sprint6.sh` — **20 of 20 PASS**. The sequence that matters:

```
1. PUT /api/leave/workflow    multi-level ON, 2 levels     (what the screen posts)
   chainFor(3) -> ["reporting_manager","department_head"]

2. employee applies           steps: 1:reporting_manager=pending, 2:department_head=waiting

3. HR MANAGER approves        403
   "This request is waiting for Reporting Manager approval (step 1).
    You are not the approver for this step."
   hrms_emp_leaves.status ... still pending

4. Reporting Manager approves 200
   "Approved at step 1 of 2. Now waiting for Department Head."
   hrms_emp_leaves.status ... STILL PENDING

5. Reporting Manager again    403   (their step is decided)

6. Department Head approves   200, final
   hrms_emp_leaves.status ... approved

   step 1  reporting_manager  approved  Farida Khan   "ok from RM"
   step 2  department_head    approved  Rajesh Iyer   "ok from DH"
```

Step 3 is the whole sprint in one line. The HR Manager holds **Organization scope** and
`approve_leave` — every check that existed before this sprint passes — and the chain refuses
them anyway, because it is not their turn.

### Escalation

`php artisan leave:escalate`, scheduled hourly in `routes/console.php`. Hourly and not more
often because one hour is the finest granularity the screen offers, so a shorter interval is
work that cannot change an outcome. `escalated_at` is one-shot, so a re-run escalates nothing
twice — proven: the second run in the probe reports `Nothing overdue`.

**Escalation widens; it does not reassign.** When a step is overdue, `escalate_to` may decide
it *as well as* the assigned role — the department head coming back from leave can still
approve their own step. Reassigning would silently take work away from the person it was
waiting on, and nothing on the screen says it does that.

`pending_since` restarts when a step becomes current, so the second approver's clock measures
their own wait and not the first approver's.

### One thing the screen was quietly getting wrong

The Escalate-To dropdown posts `department-head`, `hr` and `admin` — a **third spelling** of
the same roles. The switches above it use `department_head`; `role_key` uses `department_head`.
Left unmapped, escalating to "department-head" would stamp a step that **nobody could then
decide**: the escalation would look like it worked and quietly strand the request.

Normalised in `LeaveApprovalWorkflow::ESCALATE_ALIASES`, not in the screen, because the three
rows already in the table were written by the old screen and cannot be re-spelled
retroactively. A target that still resolves to nothing skips the tenant rather than stamping
steps no one can act on.

### The screen now shows what it does

The Approval Workflow tab renders the resulting chain live as the switches move —
`1. Reporting Manager → 2. Department Head`, and a plain sentence about what escalation will
do. It mirrors `chainFor()` so the preview tracks the switches; the server stays authoritative.

### Requests that predate the chain

The backfill migration gives every live request a chain. Decided requests get theirs closed,
with the single approval attributed to whoever `approved_by` names — **not** re-attributed to
three people, which would be a lie. Pending requests get a live chain from step one, because
nobody has in fact approved them yet.

`pending_since` starts at the migration, not at `created_at`: escalating a three-month-old
request the instant this deploys would be a false alarm about a rule that was not in force
while it was waiting.

Requests with no steps at all still fall through to a single decision. There is no correct way
to retro-fit a chain onto a request nobody was ever asked to approve.

**Live state after the sprint:** 21 live leave requests, 21 with a chain, 22 steps.
`pending requests with no open step: 0`. `decided requests with an open step: 0`.

---

## F-109 — a month saved twice made two payslips

`monthlyPayrollStore` INSERTed unconditionally. The frontend documented the hazard rather than
avoiding it (`services/hrms/payroll.ts:663-666`, *"will create duplicates if run twice"*).

This is not hypothetical. On live:

```
employee 1 · july 2026 · tenant 1 ......... 17 rows
```

Seventeen payslips for one employee-month, and every downstream report summed all of them.

`(employee_id, month, year, sub_institute_id)` identifies a payslip. Re-saving now **replaces**
that month's figures, which is what "save" has always looked like on the screen. Where
duplicates already exist the earliest row is kept — it holds the original `created_at` — and
the rest are removed, so a corrected month leaves **one** payslip behind rather than seventeen
with only the newest one right.

Proven through the real endpoint:

```
save 1  HTTP 200   rows: 1   40000.00
save 2  HTTP 200   rows: 1   45000.00     <- corrected, not duplicated
save 3  HTTP 200   rows: 1   45000.00
```

The message changed too: `"Inserted Successfully"` was inaccurate as well as duplicating.

---

## F-125 (new) — payroll fatally crashed on an employee with no salary structure

Found while proving F-109, not by the audit.

```php
$employeeSalaryStructure = EmployeeSalaryStructure::where(...)->first();   // can be null
...
json_decode($employeeSalaryStructure->employee_salary_data, true);         // no guard
```

`Attempt to read property "employee_salary_data" on null`. From `monthlyPayrollStore` that
fatal lands **after** the month's row has been written, so the caller sees a 500 for a save
that partly succeeded — the worst of both. Reproduced on live with employee 582, who has no
structure.

A payslip cannot be produced without a structure: the structure is where the per-head figures
come from, and inventing zeroes would print a payslip saying the employee earned nothing. So
the employee is skipped **and named**:

```json
{"status":"1",
 "message":"1 employee(s) saved for Nov 2026. 1 employee(s) have no salary structure,
            so no payslip was generated for them: Vikram Sethi.
            Add a salary structure and save the month again.",
 "no_payslip":[582]}
```

Named, not counted: "3 employees have no payslip" sends somebody hunting; naming them is the
difference between a warning and a task. A silently missing payslip is how somebody does not
get paid.

---

## A mistake this sprint made, and what changed because of it

The first version of `probe-sprint6.sh` proved escalation by taking **the oldest live pending
approval step**, ageing it past the deadline, escalating it and approving it as HR.

That is real work. Four runs of the probe silently approved **four genuine tenant 3 leave
requests** (ids 4, 5, 7, 8) as "Elakshi Seth". They were spotted in the post-sprint live-state
check — `escalated: 4` on requests nobody had touched — and restored by hand: status back to
`pending`, `approved_by` and `hr_remarks` cleared, their steps back to `pending` with
`escalated_at` cleared and `pending_since` returned to the backfill timestamp. Verified after:

```
id 4  pending  approved_by null
id 5  pending  approved_by null
id 7  pending  approved_by null
id 8  pending  approved_by null
escalated steps on live requests: 0
```

The probe now **raises its own request** to age, and soft-deletes it in its cleanup step along
with the chain request. The rule it broke is written into the file so the next person does not
rediscover it: *a probe must never consume production work to prove a point.*

Sprints 0–5 established that live writes get a reversal script. This adds the other half:
a probe must also be able to say which rows it touched, and only touch rows it created.

---

## The review that found what the probe did not

`probe-sprint6.sh` passed 14 of 14 and the sprint looked finished. Before writing it up, the
whole change set went through an adversarial pass: five reviewers on separate dimensions —
chain state machine, authorization, payroll, migrations and data, frontend — each finding
independently verified by a second agent whose default was **refuted**.

**36 candidates raised. 15 survived. One critical, in code this sprint had just written.**

### F-126, critical — a decided request could be decided again

`decision()` never read `hrms_emp_leaves.status`. Not once, in any version. It checked the
tenant, the row, `approve_leave`, scope and self-approval — and no state at all.

The chain made that worse in a specific way. Chain enforcement lives inside `if ($step)`; a
finished chain has no `pending` step, so `currentStep()` returns `null` and **the whole block
is skipped**. And the backfill wrote a closed chain onto every already-decided request, so the
branch commented *"this request predates the chain"* stopped catching legacy rows and started
catching **finished** ones.

On live: tenant 3 held 4 approved and 1 rejected request; HR Manager, Administrator and
Executive all hold `approve_leave` with Organization scope. Any of them could have flipped the
rejected request to approved on one signature — leaving the approval chain the screen renders
saying *rejected at step 1* beside a request marked approved. `bulkDecision()` did it fifty at
a time.

`cancel()` and `destroy()` have always guarded status. `decision()` was the outlier.

**The probe did not catch it because it walked one request forward through a chain and never
tried to walk a finished one backwards.** A passing probe proves the path it walks.

### F-127, high — "send back" destroyed the chain

`sent_back` is in `DECISION_STATUSES` and **no probe had ever exercised it**.
`recordDecision()` treated every non-approval as a rejection and skipped the remaining steps,
so a sent-back request's chain was closed and nothing reopened it. And `store()`'s re-apply
upsert matched only `pending`, so re-submitting the same dates created a **second** leave row
while the first sat `sent_back` for ever.

The step table also could not tell *rejected* from *sent back* from *cancelled* — so the chain
told an employee their request had been **rejected** when it had been sent back for a missing
handover note. A `decision` column now records what was actually chosen.

### Three more that were real

| | |
|---|---|
| **Race in `recordDecision()`** | No lock, no predicate, no transaction. Two approvers — or one double-click — could both write the same pending step; the second's "anything still waiting?" lookup missed, so it returned `final=true` and the request was **approved with a later step still pending**, in somebody's queue for ever and still being chased by the escalation sweep. Fixed by claiming the step with `where('status','pending')` inside a transaction and checking the affected count. The loser gets a 409 and is told to reload. |
| **`down()` would have destroyed real approvals** | The backfill's `down()` was `->delete()` on the whole table — every approval recorded through the API since it ran, wiped by a rollback meant only to undo a backfill. Now scoped to `whereNull('approver_id')`, which is exactly what the backfill wrote. Caught before anyone ran it. |
| **Escalation skipped tenants with no settings row** | `chainFor()` falls back to defaults, so those tenants get a real chain — and the sweep only iterated the three rows in `hrms_leave_workflow_settings`, so their requests could never escalate. A default that applies when *building* a chain and not when *enforcing* it is worse than no default: it looks configured and is not. |

Two smaller ones were fixed too: the chain's copy claimed *"nobody else can approve it"* when an
administrator always can, and its timestamps used the ambient locale on a screen where every
other date reads `05 Sep 2026`.

Eighteen of the 36 candidates could not be verified — the review hit a session limit partway
through the verify stage. They are **unverified, not refuted**, and the remaining list is in
`_evidence/` for Sprint 7 to work through.

---

## Verification

| Check | Result |
|---|---|
| `probe-sprint6.sh` | **20 / 20 PASS** (14 originally, +6 covering F-126 and F-127) |
| `probe-sprint1.sh` (regression) | PASS — no authorization regressions |
| `probe-sprint5.sh` (regression) | PASS — leave lifecycle intact |
| `npx tsc --noEmit` | 14 errors, **all in LMS files from another workstream**; zero in HRIT |
| `npm run build` | clean |
| Live integrity | 0 stranded pending, 0 decided-with-open-step |

| Live change | Reversal |
|---|---|
| `2026_09_05_190000` create `hrms_leave_approval_steps` | `migrate:rollback --step=2`, and `_local-backups/REVERSAL-2026-09-05-sprint6-approval-steps.sql` |
| `2026_09_05_190100` backfill chains for live requests | same |
| `2026_09_05_200000` `decision` column + backfill corrections | `migrate:rollback`; the status corrections are deliberately **not** reversed — see the migration |
| `2026_09_05_200000` `decision` column + backfill corrections | `migrate:rollback`; the status corrections are deliberately **not** reversed — see the migration |
| Payroll upsert + null guard | code only, no migration — `git revert` reinstates both defects |

The reversal script is short and the rollback genuinely complete, unlike Sprint 5's: this
change is **purely additive** at the schema level. Nothing in `hrms_emp_leaves`,
`hrms_leave_workflow_settings` or any other pre-existing table was altered.

---

## What this sprint did not do

- **Notifications.** Nothing tells an approver a request is waiting for them, or tells the
  employee it moved. The chain now knows exactly who to tell and when — `recordDecision()`
  returns the next role, and `escalateOverdue()` returns every breach — so the data is ready
  and the delivery mechanism is not. **Sprint 7.**
- **A month lock.** F-109's duplicate is fixed, but a finalised month can still be silently
  re-saved. Locking needs a state on the month and a reopen path with a reason, which is a
  screen as much as a column. **Sprint 7.**
- **Salary Certificate (F-110)** — still 0 rows on live — and **Form 16** under `type=API`.
  Both are payroll *output* rather than payroll *correctness*, and correctness came first.
- **F-121's remaining ~370 per-employee queries.** Monthly payroll returns in 31–59s. It no
  longer times out, and it is not fast.
