# Sprint 7 — Nothing ever told anybody anything

**Closed:** F-128, F-129 — **2**, taking the total to **35 of 43**.
**Raised:** F-128 and F-129 (both raised and closed here — the module had no
notifications and no month state, and neither had a finding of its own).
**Live changes:** two migrations (+ reversal). Additive: no pre-existing table altered.

---

## F-128 — the module had never sent a notification

Not "the notifications were wrong". There were none. An employee applied for leave and their
approver found out by opening the screen. An approver decided and the employee found out the
same way. A request sat for a week and nobody was told at all — which is exactly how tenant 3
came to have requests pending since January.

### Why this shipped now and not in Sprint 4

`RecipientResolver`, built for another module, says it plainly in its own docblock:

> There is no org-chart fallback because there is no org chart. Any notification whose only
> plausible recipient is "the employee's manager" cannot be delivered to anyone and is
> **deferred, not shipped**.

It measured the thing and was right: `tbluser.reporting_manager_id` was 0 of 387 on the host it
checked. So "tell the approver" was undeliverable, and a leave notification would have had
nowhere to go.

**Sprint 6 changed the facts.** `hrms_leave_approval_steps` names the exact *role* that must
decide *this* request, frozen when it was submitted, and `role_key` resolves that role to real
users in a real tenant. That is not an org chart — it is something better for this purpose: the
recipient is a **stored fact about this request**, not an inference about the organisation.

The chain built to *enforce* approvals turned out to be the thing that makes approval
notifications deliverable. Sprint 7 ships what Sprint 6 made possible.

### Reuse, not rebuild — the whole point

There was already a complete notification stack:

```
EventRecorder → g2g_event → ReactEvents → NotificationDispatcher
              → RecipientResolver → NotificationComposer → NotificationSender
              → g2g_notification → /api/notifications → the bell
```

HRIT added **three event types** to it. `LeaveNotifier` does not write `g2g_notification`, does
not send, and does not know what a channel is.

**The frontend needed no work at all.** The bell already existed, was already mounted in both
headers, and was already wired to `/api/notifications`. HRIT notifications appeared in it the
moment the first event was delivered — which is what reuse is supposed to look like.

### Proven live

```
Vikram applies for leave
  → Farida Khan (Reporting Manager)  "Vikram Sethi has applied for leave"
                                      reason: leave_approver

Farida approves — step 1 of 2
  → Vikram Sethi                     "Your leave request was approved"
                                      reason: leave_applicant
  → Rajesh Iyer (Department Head)    "Vikram Sethi has applied for leave"
                                      reason: leave_approver   ← now his turn

Rajesh approves — final
  → Vikram Sethi                     "Your leave request was approved"
```

**The employee is told when it merely advances**, not only when it finishes. *"Your manager
approved it, it is now with the department head"* is the thing they actually want to know, and
no screen has ever said it.

Every recipient carries a **reason**, stored on the row. Six months from now "why did I get
this?" is a support question, and the answer is in the data.

### Escalation tells somebody

Escalating without telling anyone is a row in a table. `leave:escalate` now notifies whoever
`escalate_to` resolves to — capped at 5 holders of the role, deliberately: an escalation to
"HR" in an institute with forty HR users must not become forty notifications about one leave
request.

The body says what escalation actually means, because the word is ambiguous:

> It has been escalated to HR, which means you can now decide it **as well as** they can. They
> keep their right to decide it; escalation widens who may act, it does not take the work away
> from them.

### Email is off, and this sprint did not touch that

`NotificationSender` keeps email behind `G2G_NOTIFY_EMAIL` with three written conditions, one
of which is Triz's explicit decision in the turn it happens — 386 real addresses at real
companies. HRIT's three event types go through that same sender and inherit the in-app-only
default. Nothing here reads, sets or tests that flag.

### A bug this sprint found in itself

The first version keyed idempotency on the leave id alone: `leave.submitted:{id}`. A two-stage
chain has to tell **two** people it is their turn, so the second emit collided with the first,
`EventRecorder` deduplicated it, and **the department head was never told**. The probe caught
it — one `leave.submitted` notification for a request that had passed through two approvers.
The key now carries the step.

---

## F-129 — a payroll month you can declare finished

Sprint 6 stopped a re-save **duplicating** a month. It did not stop a re-save happening — and
once salaries are paid, silently rewriting the figures behind them is its own defect.
`employee_monthly_salary_data` had no state at all: a month that has been paid and a month
still being edited were the same rows.

"Locked" is a fact about a **month**, not about a payslip, so it is one row per
`(tenant, month, year)` rather than a column on 122 rows that would then have to agree with
each other.

**A lock you cannot undo is a trap**, so reopening is recorded too — by name, with a time, and
**with a reason the server requires**. "Why were March's figures changed after we paid them?"
is now a question the data answers.

```
save Dec ............... 50,000
LOCK Dec ............... locked
save again ............. REFUSED
                         "Dec 2026 is locked by kalpesh sheth on 2026-09-05 12:45.
                          Reopen the month with a reason before changing it."
figures ................ still 50,000
reopen (no reason) ..... REFUSED  "The reason field is required when action is reopen."
reopen ("PF correction from finance")  → open
save again ............. 99,999
employee tries to lock . 403
```

**The server enforces it, at the write.** The card on the screen reads the *same endpoint* that
refuses the save, so the two cannot disagree, and deleting the component would not lift the
lock. That ordering is the lesson from F-91, where payroll's only gate was a React component.

And a comment came out of the frontend that had been describing a hazard instead of preventing
it — `payroll.ts` warned that saving twice "gets duplicate payslips". It now says what the
server does about it.

---

## A probe that was not evidence

`probe-sprint7.sh` passed 12 of 12 on its own and **9 of 12 immediately after
`probe-sprint6.sh`**. Nothing was wrong with the code. Sprint 6's probe creates leave requests,
which create `leave.submitted` events, and Sprint 7's `events:react` then delivered those too —
so a check reading "the Reporting Manager was told: count = 1" saw 2.

The probe now clears leave events and notifications **at the start** as well as the end. A probe
whose result depends on what ran before it is not evidence, and this one would have failed for a
reason that had nothing to do with the thing it tests.

---

## Verification

| Check | Result |
|---|---|
| `probe-sprint7.sh` | **12 / 12 PASS**, including run back-to-back after Sprint 6's |
| `probe-sprint6.sh` (regression) | **20 / 20 PASS** |
| `probe-sprint1.sh` (regression) | 16 PASS — no authorization regressions |
| `npx tsc --noEmit` | 2 errors, **both in another workstream's files**; zero in HRIT |
| `npm run build` | clean |
| Live integrity | 20 live requests, 20 with a chain, 0 stranded, 0 decided-with-open-step |
| Live state restored | 0 leave notifications, 0 events, 0 locks, 22 payslips (unchanged) |

| Live change | Reversal |
|---|---|
| `2026_09_05_210000` 3 notification templates | `_local-backups/REVERSAL-2026-09-05-sprint7-notifications-and-lock.sql` |
| `2026_09_05_220000` `payroll_month_locks` | same — **and the code must be reverted with it**, or every payroll save fails on a missing table |

---

## What this sprint did not do

- **"My HR" self-service.** An employee still cannot see their own payslip:
  `monthlyPayslipPdfUrl` is reachable only from the HR-gated screen. This was the third item in
  Sprint 7's plan and is the one that was cut — notifications and the month lock are both
  *correctness* work, and the self-service screen is a new surface. **Sprint 8.**
- **Salary Certificate (F-110)** — still 0 rows on live.
- **The 18 unverified review candidates** from Sprint 6 (`_evidence/sprint6-review.md`). The
  review hit a session limit partway through its verify stage; those are unverified, not
  refuted.
- **F-121's remaining ~370 per-employee queries.** Monthly payroll returns in 31–59s.
