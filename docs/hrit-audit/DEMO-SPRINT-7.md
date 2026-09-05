# Demo — Sprint 7

Six minutes. Three browser sessions on tenant 3: an **employee**, a **Reporting Manager**, a
**Department Head**. Watch the **bell** in the header.

The line to open with: **until today, nothing in this module had ever told anybody anything.**

---

## 1. Apply for leave, and watch someone else's bell (2 min)

As **HR**, make sure the chain is two stages: **Leave Configuration → Approval Workflow**,
multi-level on, 2 levels.

As the **employee**: **Leave Requests → Apply Leave**, pick a weekday next month, submit.

Now look at the **Reporting Manager's** window. Their bell has a badge:

```
Vikram Sethi has applied for leave

Vikram Sethi has applied for leave on 2027-02-02.

You are step 1 of 2 in the approval chain, so it is waiting on you
before anyone else can act on it.

Open the request to approve, reject or send it back for amendment.
```

Click it — it opens the request.

**Say this:** before today an approver found out a request existed by opening the screen and
looking. That is why this organisation had leave requests sitting pending since January.

---

## 2. Approving tells two people, not one (2 min)

As the **Reporting Manager**: approve it.

**The employee's bell**: *"Your leave request was approved — at step 1 of 2."*

**The Department Head's bell**: *"Vikram Sethi has applied for leave — you are step 2 of 2."*

**Say this, because it is the part people miss:** the employee is told when the request merely
*moves on*, not only when it finishes. "Your manager approved it, it is now with the department
head" is what they actually want to know, and no screen has ever said it.

Approve as the **Department Head**. The employee is told again — this time it is final.

---

## 3. Where the notifications came from (1 min — for the technical half of the room)

Nothing new was built on the frontend. **The bell already existed**, mounted in both headers,
wired to `/api/notifications`, built for another module. HRIT added three event types to the
platform's existing notification stack and the messages simply appeared in it.

And there is a reason this could not have been done two sprints ago. The resolver that decides
who to notify says in its own comments:

> There is no org-chart fallback because there is no org chart. Any notification whose only
> plausible recipient is "the employee's manager" cannot be delivered to anyone and is deferred.

That was true. **Sprint 6's approval chain changed it** — the chain records the exact role that
must decide *this* request, so "who is waiting on this" became a stored fact instead of a guess.
The thing built to enforce approvals is what made approval notifications possible.

Every notification also carries a **reason** in the data — `leave_approver`, `leave_applicant`,
`leave_escalation_target` — so "why did I get this?" is answerable six months from now.

---

## 4. An overdue request finds someone (30 sec)

```bash
php artisan leave:escalate --tenant=3
```

Five HR users get:

```
Overdue leave approval escalated to you

A leave request has been waiting on Reporting Manager since 2026-09-03 23:45
— longer than your organisation allows.

It has been escalated to HR, which means you can now decide it as well as they
can. They keep their right to decide it; escalation widens who may act, it does
not take the work away from them.
```

**Say this:** capped at five deliberately — an escalation to "HR" in an institute with forty HR
users must not become forty notifications about one leave request. And escalating without
telling anybody, which is what last sprint did, is a row in a table rather than an escalation.

---

## 5. A payroll month you can close (2 min)

**Payroll Management → Monthly Payroll.** Run a month. Above the register there is now a bar:

```
🔓  Dec 2026 is open
    Saving it again replaces this month's figures. Lock it once the salaries are paid.
                                                          [ Lock Dec 2026 ]
```

Press **Lock**. Then try to save the month again:

```
Dec 2026 is locked by kalpesh sheth on 2026-09-05 12:45.
Reopen the month with a reason before changing it.
```

Check the figures — **unchanged**.

Press **Reopen this month**. It asks *why*, and will not proceed without an answer. Type
"PF correction from finance" and reopen. The bar now shows the reason, with the time. Save
again — it works.

**Say this:** last sprint stopped a re-save creating a *duplicate* payslip. This one stops a
finished, paid month being quietly rewritten at all — and when it legitimately has to be, the
reason is on the record with a name and a timestamp. "Why were March's figures changed after we
paid them?" is now a question the data answers.

**And the important part:** the server refuses the save. This bar reads the same endpoint that
enforces it, so the two cannot disagree, and deleting the component would not unlock anything.
An employee's token calling the lock endpoint gets **403**.

---

## Where we are

**35 of 43 findings closed (81%). 8 of 9 sprints.**

Every configuration screen in this module now changes what the product does, every approval has
a chain, and every stage of that chain tells the person waiting on it.

**Next, Sprint 8 — the last one.** "My HR" for the employee (they still cannot see their own
payslip), the salary certificate that has never written a row, the negative and scale suite, and
then the audit is re-run and the verdicts move.

---

## Re-run the evidence yourself

```bash
bash Docs/hrit-audit/_evidence/probe-sprint7.sh     # 12 / 12 PASS
bash Docs/hrit-audit/_evidence/probe-sprint6.sh     # 20 / 20 PASS, no regressions
```

It creates its own leave requests, its own notifications and its own payroll month, and removes
all of them. It also **clears leave events before it starts** — it passed 12/12 alone and 9/12
straight after Sprint 6's probe, because that one leaves events behind that this one then
delivered. A probe whose result depends on what ran before it is not evidence.
