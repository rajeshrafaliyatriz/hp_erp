# Sprint 4 — Leave rules and the missing front door

**Closed:** F-95, F-96, F-101, F-102 — **4**, taking the total to **24 of 36**.
**Live changes:** two migrations (+ reversals) and a backfill of 37 rows.

---

## What this sprint was for

The two findings that made Leave Management unusable as an HR system, and the two that let bad
requests in.

Every leave balance in the product read **zero**, because entitlement is stored in a table with one
row for the entire platform and no screen anywhere wrote to it. And leave was charged in **calendar
days**, so a Saturday-to-Sunday request cost an employee two days in a tenant whose own configuration
says one of those is a half day and the other is not worked at all.

---

## F-96 — entitlement had no front door

`hrms_leave_allocation` is what every balance is computed from. On live it held:

```json
{"id":1,"employee_id":null,"department_id":572,"leave_type_id":10,"year":"2024","value":12,"sub_institute_id":1}
```

One row. Tenants 3, 6 and 7 had none. The only writer in the entire codebase was a private helper,
`LeaveTypeApiController::syncAllocation()`, firing as a side effect of saving a leave type.

So `GET /api/leave/balances` answered, for a real administrator on tenant 3:
`{"overall":{"total":0,"used":7,"remaining":0}}` — seven days taken against an entitlement of zero,
still reporting zero remaining.

**Now:** an **Entitlements** tab on Leave Configuration, placed second because a leave type with no
entitlement grants nobody anything. Days per department per leave type for the April–March year;
clearing a cell removes the grant. `GET/PUT /api/leave/allocations`, gated on `configure_settings`.

The shape is the one `entitlementByType()` already reads — this gives the existing shape a way in
rather than inventing a second one. The screen shows headcount per department and totals the grants
as **person-days**, so a number on it has visible weight.

Proven live, end to end:

```
before   Annual Leave total=0 remaining=0
         HR grants 3 days to department 35        -> "1 entitlement(s) saved."
after    Annual Leave total=3 remaining=3
```

---

## F-95 — leave was charged in calendar days

Three implementations of one sum: `countDays()` called from `requestDays()` and `consumedByType()`,
and a raw SQL copy in `LeaveReportApiController::DAYS_EXPR`. All three counted calendar days, so
they agreed with each other only because they were wrong in the same way.

**Now:** one `LeaveDayCounter` service reading the tenant's own `hrms_weekdays` (21 rows) and
`hrms_holidays` (18 rows) — two tables HR maintains through working screens that until today changed
no number anywhere in the product.

**The count is computed once at write time and stored** on `hrms_emp_leaves.chargeable_days`. That
fixes the arithmetic and the duplication together: the reports sum a column rather than re-deriving
it, so they cannot drift from what the employee was told when they applied. It also **freezes the
charge** — if HR adds a public holiday next year, an already-approved request keeps the cost it was
approved at, which is what an HR system has to do and what a live recalculation could not.

The backfill used the same service the application uses, so historical rows got the corrected figure.
**It reproduced the audit's hand-computed values exactly** — and those were written down before any
of this code existed:

| Leave | Dates | Audit's hand calculation | Backfill wrote | Old calendar-day figure |
|---|---|---|---|---|
| #19 | 2026-03-05 → 03-18 | 11.0 | **11.00** | 14.00 |
| #20 | 2026-04-04 (Sat) → 04-05 (Sun) | 0.5 | **0.50** | 2.00 |
| #21 | 2026-06-12 (Fri) → 06-13 (Sat) | 1.5 | **1.50** | 2.00 |

A half day taken on a half-Saturday costs 0.25, which falls out of the weighting rather than being a
special case. A tenant with no weekday configuration keeps 1.0 per day — the old behaviour, so their
numbers do not move — but holidays are excluded for them too, because a holiday needs no weekly
pattern to interpret.

---

## F-101 and F-102 — the rules

`store()` validated shapes and wrote. The audit proved the gaps by calling the endpoint directly, and
those exact probes now return 422:

| Probe | Before | After |
|---|---|---|
| Another tenant's leave type (id 9 belongs to tenant 6) | **201** | **422** "That leave type is not available to your organisation." |
| 365-day leave, 2026-12-01 → 2027-11-30 | **201** | **422** "Leave must fall inside the 2026-04-01 to 2027-03-31 leave year." |
| Leave dated 1990-01-01 | **201** | **422** same |
| A Sunday only | 201, charged 1 day | **422** "Every day in that range is a weekly off or a holiday." |
| Overlapping dates | accepted | **422** "You already have pending leave from 2026-11-09 to 2026-11-10." |
| 5 days against a 3-day balance | accepted | **422** "That is 5 day(s) of Annual Leave and you have 3 remaining." |

Overlap is checked **on date ranges**, not on a start-date key — the old upsert keyed on
`(user_id, from_date, status='pending')` is what let two overlapping requests through.

> **One looseness, stated rather than hidden.** An entitlement of **0** is not read as "no balance,
> refuse". Every entitlement is currently zero, so enforcing strictly would have refused every leave
> request in the product the moment this shipped. The rule is: enforce the balance where the tenant
> has configured one, allow it through where they have not. It resolves itself as entitlements are
> set through the screen F-96 adds, and it is written into the code comment and the finding, not left
> as a silent gap.

---

## Verification

`_evidence/probe-sprint4.out` — **11 assertions, all PASS**, and the script is self-contained: it
creates its own entitlement grant, proves the rules against it, then removes everything it made.
Run it twice and it behaves the same way.

**Live state:** tenant 3 back to 29 leave requests; `hrms_leave_allocation` back to its 1 pre-existing
row.

| Migration | Reversal |
|---|---|
| `2026_09_05_160000` `hrms_emp_leaves.chargeable_days` + backfill | `_local-backups/REVERSAL-2026-09-05-sprint4-chargeable-days.sql` |
| `2026_09_05_170000` `hrms_leave_allocation.value` int to decimal(6,2) | `_local-backups/REVERSAL-2026-09-05-sprint4-allocation-decimal.sql` |

**Typecheck:** zero errors in HRIT, the leave/attendance hooks, the HRMS services or the role model.

> Two other sessions are editing this repository concurrently, and `npm run typecheck` showed
> transient errors in `components/domain/lms/delivery/learning-delivery-workspace.tsx` that changed
> between consecutive runs (`onTakeQuiz` → `goToQuiz` → `quizPanelRef`) — an LMS quiz feature being
> written as I worked. Not mine, and not fixed by me. The production build was not run for the same
> reason: a failure there would have told me nothing about this sprint's change.

---

### One thing I nearly deferred, and should not have

While writing this up I noticed `hrms_leave_allocation.value` was `int(11)` while the new
Entitlements screen offers a `0.5` step - so a user typing **12.5 would have been silently stored as
12**, with no message. A control that quietly changes what you typed is the same class of defect this
whole remediation exists to remove, and "note it for Sprint 8" would have meant shipping it.

Widened to `decimal(6,2)`, matching `chargeable_days` from the same sprint so a grant and a deduction
are the same kind of number. Widening an integer column is lossless - the one existing row went
`12` to `12.00`. Verified end to end afterwards: a 12.5-day grant round-trips and the employee's
balance reads `total=12.5 remaining=12.5`.

---

## What this sprint did not do

- **Multi-level approval and escalation.** `hrms_leave_workflow_settings` (3 rows) is still written by
  its screen and read by nothing. The plan listed it here; it needs the approval chain reworked, and
  that belongs with the approver-facing screens in **Sprint 5**.
- **Cancel-after-approval and LWP conversion.** The status enum has `cancelled` and `approved_lwp`
  and no path reaches either. Sprint 5.
- **Notifications** on submit and decision. Nothing in the module sends anything today. Sprint 5.
- **Per-employee entitlement overrides.** The API reads and returns them — `entitlementByType()` has
  always honoured them for Casual and Earned leave during probation — but the screen edits the
  department grid only. The override path is unchanged, not newly broken.
