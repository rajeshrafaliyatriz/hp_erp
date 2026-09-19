# Phase 15 — finishing HRIT, and what the plan got wrong

**Raised and closed:** F-158 … F-208 — **51 findings.**
**Verification:** **411 assertions across 25 probes, 0 failures**, re-runnable.
**Frontend:** `next build` green.
**Migrations:** 0 pending on both hosts. `tsc` clean in HRIT.
**Trigger:** *"complete the hrit full module … some fetures have only backend but
not the perfect frontend some where frontend have the design but it not working
funcnoally"*

---

## The plan was wrong about the thing it was most confident about

Q8 said all six filed payslips would move, and gave figures. Running the actual
calculation showed:

| Payslip | Plan said | What `getEmpMonthlyData` returns |
|---|---|---|
| 1 | → 15,000 | **2,823** |
| 2, 3, 4 | → 1,000 each | **no change — already correct** |
| 5 | → 7,000 | **refused — "Salary Structure Not Found"** |
| 22 | → 47,405 | **refused — same** |

The plan's "new pay" column was **the sum of each payslip's stored components**,
not the output of a recompute. Two payslips had no salary structure for their
year at all, so the calculation could not produce a figure for them.

That was worth stopping for, and the customer was asked before anything was
written. They chose to create the two missing structures and recompute — so the
structures were built **from each payslip's own stored components, verbatim**,
because that is what the payslip was actually paid against and the only
defensible source.

**One payslip was still refused.** Number 22 recomputes to **−3,482**: tenant 3
has `HRA` and `daycount1` configured as *deductions*, so the structure's 10,000
becomes a 9,677 deduction against 6,200 of earnings. A negative number is not a
payslip. Writing it would have replaced one wrong figure with a more obviously
wrong one and called it a correction.

Three things were checked rather than assumed, and each was wrong first time:

- The hand-written reversal had **every JSON payload wrong**. It is now
  *generated* from the captured before-image.
- The claim that these rows existed only on `mysql`. **Both hosts are
  byte-identical**, so the change had to go to both — and did.
- `EventRecorder` hardcodes the default connection, so the events describing
  `live`'s changes landed on `mysql`. Mirrored across.

---

## What was actually broken

### Silent deletion, not a stale reference — F-174

The Salary Structure grid renders only **active** pay heads, and
`employeeSalaryStructureStore` **overwrites** `employee_salary_data` with
exactly what is posted. So an ordinary **Save, on a screen nobody had edited,
deleted every amount booked against a deactivated head.**

All **eight of eight** live structures were exposed. Proven by seeding a scratch
structure, saving it the way the old frontend did, and watching the amount
vanish; then proven fixed by carrying the unrendered heads through the post.

### Money that leaves the building — F-189

Leave Reports rendered a **failed API call as a zeroed report** — "Total
Requests 0 / Approved 0 (0%)", "No leave data for this period", and the insight
*"No leave was taken in the selected period."* — then let you **export it** as
`leave-summary-<from>-to-<to>.csv`. A file whose own name asserts a period and
whose contents assert nothing happened in it.

### 343,001 the calculation never applied — F-173

Eleven of twelve rows in `hrms_emp_payroll_deduction` have a month spelled
`"8"`, `"2"` or `"3"`. They were entered on a screen that reported success and
skipped by every payroll run since. Now surfaced with the stored month shown
**verbatim** — rendering `"8"` as "August" would be the guess this finding
exists to avoid — and one explicit decision per row. There is no "fix all".

### The gate that only ran when the caller asked for it — F-159

`/employee-attendance-monthly-report` had no route gate, and its own token check
ran only when the caller passed `type=API`. Even with a token it never asked
whether `user_id` was the caller, so any employee could read a colleague's punch
times, lateness and **leave reasons**.

*An agent reported this as "no token gate → unauthenticated cross-employee
read". That overstates it: `apiTenantId()` resolves from the token, so an
unauthenticated caller gets `null` and no rows. The real gap was horizontal and
bounded to the caller's own tenant — still worth closing, and closed.*

### A route that 500'd on every call — F-158

`api.php:420` registered `designation_leave → HrmsController@store`, a method
that does not exist, **shadowing** the correct token-gated route. The earlier
dedup pass missed it precisely because it is not an *exact* duplicate — same URI
and verb, different controller. That difference is what made it harmful rather
than inert.

---

## Five screens that existed only as endpoints

| Screen | Menu | Rights |
|---|---|---|
| Bank-wise Payment Advice | 307 | admin/hr |
| Employee Payroll History | 308 | admin/hr |
| Monthly Attendance Report | 309 | **every profile** |
| Payroll Register | 310 | admin/hr |
| Salary Structure Report | 311 | admin/hr |

The rights split is deliberate and is now asserted three ways in the probe.
Menu 309 and My HR (305) go to every profile because the **server** enforces
HR-or-self, so a rights row grants an employee their own data and nothing more.
The other four show every employee's pay.

Two could not have worked before this phase: the Salary Structure Report
resolved to tenant `null` under `type=API` (**F-160**), and the payroll history
reported the wrong employee id because `tbluser` has its own `employee_id`
column that shadowed the payslip's (**F-168**, same class as **F-172**).

Also: menu 140 was named "Monthly Payroll **Report**" and mounts the
data-**entry** grid, with Generate Payroll and per-row Delete on it. Renamed.

---

## What the scale exercise found — F-207

`payrollBankWiseReport` called `employeeDetails()` **once per payslip**.

| | Queries | Time |
|---|---|---|
| Before | **1,001** | **6,323 ms** |
| After | **3** | **138 ms** |

**Invisible until a cohort existed** — the largest tenant here had one payslip,
so the loop ran once. Everything else measured under 17 ms at 500 employees and
3,000 leave requests. See `SCALE-MEASUREMENTS.md`, including what it does *not*
establish.

The guard counts **queries, not milliseconds**: a timing varies with the
network, a query count does not.

---

## The suite was lying again, twice

**F-163.** `probe-writes.sh` created a leave request whose comment literally
reads *"HRIT audit probe - delete me"* and nothing ever deleted it;
`probe-validation.sh` did the same with a Gujarati comment. Every full-suite run
left two more pending requests against user 7, draining the balance
`probe-sprint4` grants itself — until it went red, and took `probe-sprint7` with
it, from code that had not changed.

**Two assertions broke on my own Q8 change**, and the more interesting one did
not go red for the right reason: `limit 1` with no `ORDER BY` meant it would
have **silently started asserting about a different event**. Both are now pinned
by idempotency key.

And my first F-161 probe **passed with the bug deliberately re-inserted** —
vacuous, because F-162 meant the code path was never reached. Finding that is
what found F-162.

---

## Two things finished after the plan closed

### F-208 — "My Pending Approvals" means it now

The preset applied `status=pending` and nothing else, so it returned every
pending request in the caller's scope, including ones sitting with somebody
else. Phase 15 renamed it to "Pending Approvals" because the API could not
express the scope its label claimed. That was a workaround, not a fix.

"Awaiting me" is a property of the approval CHAIN, not of the request:
`hrms_leave_approval_steps` holds one row per step, and the one still `pending`
is the one whose turn it is. `awaiting_me=1` resolves against that, honouring
escalation, narrowing within the existing scope and granting nothing.

**The trap it had to avoid:** the filter returns 0 for every token on this
deployment, because no token's role matches a pending step inside its own scope.
A filter that only ever returns zero cannot be told from one that returns
nothing at all. So `probe-awaiting-me.sh` CONSTRUCTS the positive case — a
request that lands on a specific approver's desk — and asserts it appears for
them (0 → 1) while HR can see 2 pending and **0 awaiting them**. That contrast
is the whole label.

### The resolution paths, proven in tenant 6

Phase 15 built the tooling to resolve the three surfaced problems and proved
only the REFUSAL paths — that re-dating to `"8"` is rejected, that a Save which
drops a head deletes money. **What a successful resolution does was never
tested.** A repair path exercised only by watching it refuse is not a tested
repair path.

`probe-resolution-flows.sh` closes that, in **tenant 6** — Scholar Clone, which
has zero payroll data of its own — and never touches the real orphans in tenant
3. Nineteen assertions covering: an orphan appearing with its month verbatim and
its entry date beside it; **re-dating actually filing it** where payroll will
match; **removal actually removing it**, soft-deleted; the clash guard refusing
an occupied slot and leaving the row unchanged; both paths refused for an
employee; and F-174's carry-through surviving a Save.

Tenant 6 is left byte-for-byte at baseline. Everything was marked by a dedicated
probe pay head, so teardown is by marker and cannot be malformed.

## Where the module stands

| | Phase 14 | Now |
|---|---|---|
| Findings closed | 73 | **124** |
| Probe assertions | 267 across 14 | **411 across 25** |
| Screens routed | 13 | **18** |

**Still AMBER, and the reason has changed.** Scale is measured. What remains is
**domain sign-off** — whether the calculation matches the customer's policy —
and the three data problems this phase surfaced rather than decided:

1. **Payslip 22** recomputes to a negative. Tenant 3's pay heads need fixing
   first; the script re-runs cleanly once they are.
2. **Eight of eight** salary structures reference a head that is not active —
   three of them another **organisation's**. The warning now names them.
3. **343,001** in adjustments awaiting a human decision, one row at a time.

**None of those is mine to decide, and the reason is specific rather than
cautious.** Tenant 3 is "healthcare" — 122 active users, a real employer.
Reclassifying their HRA from deduction to earning, or deleting 343,001 of their
adjustments, changes what their staff are paid. The platform operator cannot
make that call on their behalf either.

What CAN be done for them was done: the panel now shows **when each row was
entered** beside the month it claims. Four rows stored as `"8"` were entered on
2025-12-01; four stored as `"2"` with year **2020** were entered the same day;
three stored as `"3"` on 2025-12-09 — with amounts of 1, 20 and 50000 repeated.
Healthcare's HR will recognise that in seconds, because they will remember doing
it. No query can.

All three are now visible on the screens where someone can act on them, and the
buttons that carry the decision out have been exercised end to end. That is the
difference between this phase and the fourteen before it.
