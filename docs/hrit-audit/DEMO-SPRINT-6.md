# Demo — Sprint 6

Seven minutes. You will need **four** browser sessions on tenant 3, or one browser and three
private windows: an **employee**, a **Reporting Manager**, a **Department Head** and an
**HR Manager**.

The point of this demo is one sentence: **a settings screen that has never controlled anything
now controls something, and you can watch it happen.**

---

## 1. Turn the chain on, and watch the screen tell you what it will do (1 min)

As **Administrator** or **HR**: **HRIT Solutions → Leave Management → Leave Configuration →
Approval Workflow.**

Switch **Multi-Level Approval** on and set **2 Levels**. Below the switches a box now reads:

```
A REQUEST SUBMITTED WITH THESE SETTINGS WILL NEED

  1. Reporting Manager   →   2. Department Head

Every stage must approve. Nobody — including HR — can approve on behalf of a stage
that has not been reached. If a stage waits longer than 24 hours, HR can decide it as well.
```

Toggle **Department Head** off and on and watch the line change. **Save.**

**Say this:** this tab has existed since July. It has always saved these settings and always
reloaded them — and until today **nothing in the product read them**. A two-stage chain and a
one-stage chain behaved exactly the same. That box is the first time the screen has been able
to tell you what your settings do.

---

## 2. Apply for leave and look at the chain (1 min)

As the **employee**: **Leave Requests → Apply Leave**, pick a date next month, submit.

Open the request → **Timeline** tab. Above the history there is now an **Approval chain**:

```
0 of 2 approved

 ●  Reporting Manager        Step 1
    Waiting for a decision · 05 Sep 14:20

 ○  Department Head          Step 2
    Not started — an earlier approval is outstanding

    Waiting on Reporting Manager. Only they or an administrator
    can act on it until it is escalated.
```

---

## 3. The one to show your senior: HR cannot skip the queue (2 min)

As the **HR Manager** — the most senior leave role in the product, with **Organization
scope** — open that request and press **Approve**.

```
This request is waiting for Reporting Manager approval (step 1).
You are not the approver for this step.
```

**Say this:** every check that existed before today passes for this person. They hold
`approve_leave`. Their scope covers the whole organisation. The request is in their tenant.
Before this sprint their single click would have approved it outright. What refuses them is
the tenant's own configuration, finally being enforced.

Check the list: the request is **still pending**. The refused attempt changed nothing.

---

## 4. One approval is not approval any more (2 min)

As the **Reporting Manager**: approve it.

```
Approved at step 1 of 2. Now waiting for Department Head.
```

Now go back to the leave list. **The request is still pending.** That is the demo. One
approval used to be the end of the story.

Open it again — the chain has moved:

```
1 of 2 approved

 ✓  Reporting Manager     Approved by Farida Khan · 05 Sep 14:25
    “ok from RM”

 ●  Department Head       Waiting for a decision
```

Try approving again as the **Reporting Manager**: refused. Their step is decided.

As the **Department Head**: approve. **Now** the request reads *Approved*, and the chain shows
both names, both timestamps, both comments.

---

## 5. Escalation, from the command line (1 min)

```bash
php artisan leave:escalate --tenant=3
```

```
+------+-------+--------+-------------------+----+---------------------+
| step | leave | tenant | from              | to | waiting since       |
+------+-------+--------+-------------------+----+---------------------+
| 32   | 244   | 3      | Reporting Manager | HR | 2026-09-04 08:40:45 |
+------+-------+--------+-------------------+----+---------------------+
1 approval step(s) escalated.
```

Run it again: `Nothing overdue.` It does not escalate the same step twice.

This is scheduled **hourly** — `php artisan schedule:list` shows it. The escalated request now
shows an amber **Escalated to HR** chip in its chain, and HR can decide it.

**Say this, because it matters:** escalation **widens** who can act, it does not reassign. The
department head coming back from leave can still approve their own step. Taking work away from
the person it was waiting on is not what "escalate" says on the screen.

---

## 6. Payroll stops making duplicate payslips (1 min)

**Payroll Management → Monthly Payroll.** Pick a month, save it, then save it again.

```
save 1   1 payslip   40,000
save 2   1 payslip   45,000     <- corrected, not a second payslip
save 3   1 payslip   45,000
```

**Say this:** this used to INSERT every time. On the live database there is an employee with
**seventeen payslips for July 2026** — one employee-month, seventeen rows, and every report
that adds up payroll was adding up all seventeen. The frontend even had a comment warning that
saving twice would do this, rather than stopping it.

And a second thing found while proving the first: saving payroll for an employee who has no
salary structure used to **crash after writing the row** — a 500 for a save that half
succeeded. It now saves the month and tells you plainly:

```
1 employee(s) saved for Nov 2026. 1 employee(s) have no salary structure, so no
payslip was generated for them: Vikram Sethi. Add a salary structure and save again.
```

Named, not counted. A silently missing payslip is how somebody does not get paid.

---

## 7. The bug this sprint found in itself (1 min — worth the minute)

Before writing Sprint 6 up, the whole change set went through an adversarial review: five
reviewers on separate angles, every finding checked by a second reviewer told to **refute it**.
36 candidates, 15 real, **one critical — in code written three hours earlier.**

`decision()` never checked whether a request had already been decided. The chain made that
worse: the chain check only runs while a step is still open, so a **finished** request skipped
it entirely. An HR Manager could take a rejected request and approve it, on one click, while
the approval chain on screen still said *rejected at step 1*.

It is fixed, and there is a test for it now:

```
POST /api/leave/requests/{approved-id}/decision  {"status":"rejected"}

-> This request is already approved and cannot be decided again.
   Ask HR to correct it if that is wrong.
```

**Say this:** the probe passed 14 of 14 before that review. A passing test proves the path it
walks and nothing else — this one walked a request forward through the chain and never tried to
walk a finished one backwards. The review also found that "Send Back" destroyed the chain so a
sent-back request could never be approved again, and that re-submitting it created a **second**
leave request. Neither had ever been tested; both are fixed and covered now.

The probe is 20 of 20.

---

## Where we are

**33 of 41 findings closed (80%). 7 of 9 sprints.**

`hrms_leave_workflow_settings` was the **last configuration table in this module that
controlled nothing**. Every settings screen in HRIT now changes what the product does.

**Next, Sprint 7.** Notifications — the chain now knows exactly who to tell and when, and
nothing tells them. A month lock so a finalised payroll cannot be silently re-saved. "My HR"
for the employee: their own payslip, balance and salary certificate, none of which they can
reach today.

---

## If you want to re-run the evidence yourself

```bash
bash Docs/hrit-audit/_evidence/probe-sprint6.sh     # 20 / 20 PASS
```

The full review, with every candidate and its verdict, is in
`Docs/hrit-audit/_evidence/sprint6-review.md`.

It creates its own leave requests and removes them, and puts tenant 3's workflow settings back
the way it found them. It did **not** in its first version — it aged and approved four real
pending requests before that was caught, and those were restored by hand. That is written into
the top of the file, and into the sprint write-up, rather than quietly fixed.
