# Demo — Sprint 5

Six minutes. Sign in as **HR Manager** on tenant 3.

---

## 1. A line that used to be in every leave report has gone (1 min)

**HRIT Solutions → Leave Management → Leave Reports.** Look at the summary table.

It used to open with **"Unassigned — 15 requests, 15 days"**. It now reads:

```
Annual Leave       2 requests    11 days
Scholar Clone      1 request     1.5 days
```

**Say this:** those fifteen requests pointed at a leave type that has never existed. The database
allowed it because the rule policing that column was pointing at the **wrong table entirely** — it
was checking the number against the list of employees, and employee 11 exists, so the check passed.
They could not be counted against anyone's allowance and could not be filtered or reported on.

Two more had **no dates at all** and had been sitting in the pending queue since January and March —
invisible to anybody filtering by date, so nobody would ever have found them to clear them.

---

## 2. The database itself now refuses them (2 min)

This is the part worth showing, because it is not a screen check that someone can bypass:

```bash
bash Docs/hrit-audit/_evidence/probe-sprint5.sh
```

```
FK  hrms_emp_leaves_leave_type_id_foreign  ->  hrms_leave_types     (was: tbluser)
    from_date       NOT NULL
    user_id         NOT NULL
    leave_type_id   NOT NULL
    unusable_rows_still_live: 0
```

And tried directly against the database:

```
REJECTED   leave_type_id = 11 (a user id)   1452 Cannot add or update a child row
REJECTED   from_date NULL                   1364 Field 'from_date' doesn't have a default value
ACCEPTED   a valid row                      the table still works
```

**Say this:** the seventeen bad rows are out of the way — reversibly, nothing destroyed — and the
database is now built so they cannot come back. And be straight about what they were: all fifteen
were created in the same second by four people, with comments "Monday pattern" and
"Friday/post-payday". That is demo data somebody generated, not fifteen people's leave.

---

## 3. Leave can be cancelled after approval (2 min) — in the browser

As an **employee**: **Leave Requests → Apply Leave**, book a date next month, submit.

As **HR**: approve it. Look at the employee's balance — the days are gone.

Back as the **employee**: cancel it. **The days come straight back.**

```
after approval    total=10  used=2  remaining=8
after cancelling  total=10  used=0  remaining=10
```

Try cancelling one that is still *pending* and it tells you to withdraw it instead. Try cancelling
leave that has already started and it refuses — those days were taken, and unpicking that is an HR
correction, not something an employee does to themselves.

**Say this:** the system has always had a "cancelled" state and nothing in it could ever reach that
state. The audit listed cancel-after-approval as a failing scenario. It works now, and the balance
returns by itself rather than through a second correcting write that could get out of step.

---

## 4. The last two dead buttons (1 min)

- **Leave Dashboard → any request → ⋮ → View.** It opens the detail panel. That panel was already on
  the page, fully built, and the component had simply never been handed the handler for it.
- **Leave Requests → Columns.** It used to be a menu with one item that did nothing. It now lists the
  eight optional columns and turns them on and off, remembered for next time.

That finding covered **twelve buttons across three screens**. It is finally, properly closed — and it
is the one I wrongly marked closed in Sprint 2 with half of them still dead.

---

## 5. And the approver's queue I promised in Sprint 2

As **HR**, on **Attendance Tracking**: if anyone has asked for an attendance correction, a review card
appears above your own attendance history, with the before-and-after times and Approve / Reject.

As an **employee**, that card does not exist — because the server refused the request, not because
the page decided to hide it. That ordering is the whole point: hiding a card is not access control.

---

## Where we are

**28 of 37 findings closed (76%). 6 of 9 sprints.** Still no sub-module green — Leave Requests now has
its entire employee-side lifecycle and stays amber until approvals are multi-step and Sprint 8 has
tested it at scale.

**Next, Sprint 6.** The Approval Workflow tab lets HR configure a two-stage approval chain and
escalation after 24 hours — and **nothing reads any of it**. That is the last piece of configuration
in this module that controls nothing, and it needs an approval-steps table and a scheduled job rather
than being squeezed into the end of a sprint. Alongside it: Monthly Payroll's remaining slowness, the
duplicate-payslip risk, and the salary certificate that has never written a row.
