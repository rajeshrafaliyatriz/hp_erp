# Phase 10 — closing the release gate, and what closing it found

**Raised:** F-142, F-143, F-144, F-145 — **4 new, all open by choice.**
**Closed:** Q3, the §E.3 payroll reconciliation, the attendance audit trail, §13, §14, §15, §12.
**Verdict:** **RED → AMBER.** Not GREEN, and the reason is F-142.
**Verification:** **171 assertions across 11 probes, 0 failures.**

---

## What this phase was for

Nine sprints closed 53 findings. What was left was not a defect list — it was the audit's own
unfinished homework: a release gate untouched since Sprint 0, a golden-transaction table whose every
FAIL cited a closed finding, an integrity checklist with **no payroll figure in it**, and an open
question (Q3) sitting on the gate's only PASS.

Four of those are now closed. One of them closed by failing.

---

## F-142 — the payroll reconciliation, and why it is the headline

§E.3 asked for calculations to be *independently reconciled*. It had five reconciled figures and
**not one was a payroll figure** — no PF, no PT, no net, no Form 16 total had ever been checked
against a hand-computed value.

Doing it meant reading the arithmetic out of the code first. It is real and it is careful:

```
allowance   day_count = 0  ->  round((amount / daysInMonth) * total_day)
PF          when basic+grade+DA < 15000  ->  round(totalSal / 100 * 12), capped 1800
PT          never pro-rated                (correct - it is a slab, not a daily rate)
total_payment = sum(allowances) - sum(deductions)      <- NET, despite the name
```

Then I recomputed all six live payslips from their own stored inputs. **None of them agrees.**

```
id  tenant emp month days | stored data   | stored pay  stored ded | derived pay derived ded
2   1      1   Aug  25    | {"6":"1000"}  |    3306.00        0.00 |     1000.00       0.00
3   1      2   Aug  28    | {"6":"1000"}  |    3403.00        0.00 |     1000.00       0.00
4   1      3   Aug  31    | {"6":"1000"}  |    3500.00        0.00 |     1000.00       0.00

  payslips reconciled : 6      agree with own data : 0      DISAGREE : 6
```

A payslip carrying **one allowance head worth 1000** records a total of 3306. Row 1 records a
deduction of 100.00 with no deduction head in its own JSON at all.

**The cause is one line.** `monthlyPayrollStore` stores what it is posted:

```php
'total_payment'   => $value['total_payment']   ?? 0,      // :2850
```

The arithmetic above runs only to **draw the screen** (`:2455-2520`). The figures go to the browser
and come back, and whatever comes back is filed. I traced the frontend too —
`use-monthly-payroll.ts:54,183` echoes `salaryData.total_payment` and posts it unchanged. **Nothing
recomputes on either side of the wire.**

This is why §E.3 had no payroll figure: there was no server-side computation to reconcile *against*.

**It is not fixed, deliberately.** Recomputing on write is the obvious answer and is not a safe
unilateral change — every payslip already filed would then disagree with what the system would
produce for it, and people have been paid against those numbers. That is **Q8**, and it belongs to
the customer.

*One caveat stated plainly:* the live-write demonstration of this — posting a deliberately
inconsistent payslip and reading it back — was blocked by the environment's permission classifier
and I did not route around it. The finding rests on the code path, the six live rows, and
`probe-sprint9`'s existing assertion that a posted total is read back unchanged.

---

## Q3 — the qualifier on the gate's only PASS

The gate's one PASS read *"Tenant isolation proven with two tenants ✓ (list endpoints; see Q3)"*.
Q3 itself explained why it had never been closed: *"the tenant 6 ids available fall outside tenant
3's leave-year window, so a 404 would be ambiguous."*

That ambiguity dissolves by asserting the **positive** case in the same run. If tenant 3 fetches its
own record successfully and tenant 6's with a 404, the 404 is isolation and not breakage.

Every m5 by-id endpoint was read statically first — `LeaveRequestApiController`,
`HolidayApiController`, `LeaveTypeApiController`, `AttendanceRegularisationApiController`,
`EmployeeDirectoryController`, `MyHrController` — and every lookup is tenant-scoped.
`MyHrController::payslipPdf` is the nicest of them: it takes **no id at all** and overwrites the
request with the resolved identity before calling the generator.

`probe-q3.sh`, **9/9**, both directions, and the 404 body names nothing.

---

## The attendance audit trail — and the path nobody had driven

Leave got a trail in Sprint 7, payroll in Sprint 9. Attendance emitted nothing, and it was the write
that most needed one: an approved regularisation **overwrites `punchin_time` and `punchout_time` on
an existing row**, and payroll reads `timestamp_diff` off that same row. A corrected day could move
somebody's pay with no record of what the day used to say.

Two events, both **PROJECTOR-only**: `attendance.regularisation.decided` and `attendance.corrected`,
the latter carrying the before-image.

**What was deliberately not done.** No `NotificationDispatcher`. Telling the applicant their
correction landed is reasonable and is a separate decision with its own recipient question; it is not
smuggled in behind an audit-trail change. That follows Sprint 9's precedent exactly.

**And a discovery while testing it:** `probe-sprint2` had only ever proven the endpoint *refuses*
bad input — four 422s. **Approve → correct → attendance-rewritten had never been executed at all.**
It works, and now it is asserted: the trail names the old punch-in `10:30:00` and the new
`09:00:00`, tells amending a row from creating one, and a rejection records the decision while
touching no attendance row.

---

## The gap in the audit's own evidence

The verdict in §1 opens with five requests an ordinary employee made successfully in Sprint 0 —
apply, self-approve, withdraw a colleague's, create a leave type, rewrite the permission matrix.

They were re-verified once, by hand, when F-87…F-91 closed. **No standing probe re-asserted them.**
The audit's headline claim had rested for ten phases on a check nobody re-ran — and searching for
one turned up a single self-decision assertion, for *attendance*, written earlier in this same
phase.

`probe-sprint10` section 7 now replays all five, and asserts the refusals **changed nothing**: the
permission matrix still reads `approve_leave = 0` for the Employee role, the colleague's request has
`deleted_at` still null, the leave-type count is unmoved.

---

## Scale — measured, and honestly partial

| what | measured | result |
|---|---|---|
| employee directory | **1001 employees** | 28.9 ms, linear from 23 rows, **no N+1** |
| attendance + user join | **939 rows** | 11.5 ms |
| leave register | 13 rows | *no tenant has volume* |
| payroll | 6 payslips | *no tenant has volume* |
| approval queue | 8 steps | *no tenant has volume* |

Endpoint timings sit on a **~600 ms floor** that is framework and network overhead rather than
query cost, so the query-level figures are the ones that discriminate by data size — and they are
labelled as such in `probe-scale-query.php`, which exists so tenant 1000000 can be measured without
minting a token against a live organisation.

**One endpoint stands clear of that floor:** `monthly-payroll/create` at **1.24 s** for tenant 6 —
roughly double everything else on the list — with `dashboard/hr/workforce`, a six-month attendance
scan, next at 0.84 s. Payroll is the slowest screen in the module, on the organisation with the most
attendance rows.

**One real finding fell out (F-145):** the directory has no `LIMIT` and no pagination. At 1001
employees that is 29 ms; the point is that the ceiling is set by the customer's headcount rather
than by anything in the code.

**The line is marked `~`, not closed.** Leave, payroll and approvals have no volume anywhere on this
deployment to measure against, and marking scale closed on a 13-row sample would repeat F-132
exactly — Monthly Payroll passed every check for eight sprints while returning two of 122 rows.

---

## Two more payroll findings, both referred rather than fixed

**F-143 — eleven of twelve payroll adjustments can never be applied.** `hrms_emp_payroll_deduction`
matches `month` exactly against the screen's spelling (`Aug`); eleven rows are spelled `"8"`, `"2"`,
`"3"`, worth up to 50,000 each. This is **F-137's defect in a second table** that F-137's migration
did not touch.

It is classified as **data debt, not a live code defect** — the current frontend takes its month
options from the server and posts `Aug`, so today's writes and reads agree. The eleven are legacy.
Repairing them needs the tenant, because `"3"` could be March or a March-*year* convention (**Q9**).

**F-144 — a hardcoded February rule.** `if($request->month=="Feb" && $payrollType->id==2) $payrollAmount=300;`
sits in arithmetic every tenant shares. It is **dead code today** — head 2 is soft-deleted and the
calculation filters `status = 1` — and it is dead by luck, because a tenant deleted a pay head. Left
in place: it changes nobody's pay, and removing a payroll rule whose origin is unknown deserves the
same confirmation F-111 got.

---

## Where the gate stands

Four lines moved. Ten of sixteen are now ✓, three are `~`, one is ✗, one is `—`.

- **`Tenant isolation`** — now unqualified (Q3).
- **`Error handling + audit trail`** — closed; attendance was the last piece.
- **`Golden transactions`** — re-run for the first time since Sprint 0: **9 of 12 pass**, 1 partial,
  2 unproven, against 2 of 12 at Sprint 0.
- **`Calculations independently reconciled`** — done, and **failed**. The one line that got worse,
  and only because somebody finally looked.

**AMBER, not GREEN.** What stands between them is four things and only one is code: **Q8** (F-142)
needs the customer before anyone changes what a payslip says; leave, payroll and approvals have no
volume to measure; two golden transactions cannot be honestly proven while F-142 stands; and
**domain sign-off has not been sought and cannot be given by me.**

---

## Verification

| Probe | Result |
|---|---|
| `probe-sprint1` … `probe-sprint9` | 125 assertions, **0 failures** |
| `probe-sprint10` — attendance trail + the five authorization requests | **28 / 28** |
| `probe-q3` — cross-tenant fetch by id | **9 / 9** |
| `probe-f132` | **9 / 9** |
| **Total** | **171 assertions, 0 failures** |

`reconcile-payroll.php` is read-only and re-runnable. `probe-sprint10` creates its own data and
removes it — asserted, after the earlier lesson about consuming four real leave requests.

**No live schema was changed in this phase.** The only code changes are the attendance events and
their catalogue registration.
