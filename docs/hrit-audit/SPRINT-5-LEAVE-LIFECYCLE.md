# Sprint 5 — Leave lifecycle and data integrity

**Closed:** F-94, F-112 (finally, 12 of 12), F-114, F-123 — **4**, taking the total to **28 of 37**.
**Live changes:** one migration (+ reversal) and 17 rows soft-deleted, reversibly.

---

## F-94 and F-123 — the table stopped accepting things that are not leave requests

Two defects in one table, and the second was found by Sprint 4's backfill rather than by the audit.

**The foreign key named the wrong parent.** `leave_type_id` referenced `tbluser`, copied from the
`user_id` line directly above it — so the database constrained a leave's *type* to be a valid *user*
id. That is how 15 rows in tenant 3 came to reference leave type 11: no such leave type has ever
existed, but `tbluser` 11 does, so the constraint was satisfied.

**Everything was nullable.** Two rows had no dates at all, pending since January and March, invisible
to every date filter — so nobody reviewing a date range would ever have found them to clear them.

### What the 17 rows turned out to be

Worth stating, because it changed the decision. All 15 were created in the **same second**
(2026-06-19 10:25:55) by four employees, all still pending, with the comments **"Monday pattern"**
and **"Friday/post-payday"**. That is an absenteeism-pattern demo seed, not fifteen people's leave.

Soft-deleted, reversibly, with the reasoning in the reversal script. Nothing was destroyed.

### Then the constraints went on

```sql
FOREIGN KEY (leave_type_id) REFERENCES hrms_leave_types (id)   -- was tbluser
from_date      DATE                NOT NULL                     -- was NULL-able
user_id        BIGINT(20) UNSIGNED NOT NULL
leave_type_id  BIGINT(20) UNSIGNED NOT NULL
```

Proven at the database, not in the application:

```
REJECTED  leave_type_id = 11 (a user id)   1452 Cannot add or update a child row
REJECTED  from_date NULL                   1364 Field 'from_date' doesn't have a default value
ACCEPTED  a valid row                      the table still works
```

And the visible consequence — the leave summary report, which used to lead with
`{"leave_type":"Unassigned","total":15,"days":15}`:

```
Annual Leave       total=2    days=11
Scholar Clone      total=1    days=1.5
```

**The Unassigned bucket is gone.**

### The migration got it wrong twice before it got it right

Recorded because the lesson generalises: **`ALTER` and `FOREIGN KEY` apply to soft-deleted rows too.**

My first pre-flight check counted only live rows, passed, and then failed on
`1265 Data truncated for column 'from_date' at row 11` — the two dateless rows were soft-deleted, not
gone. Fixed, re-run, and my own guard then caught the second form of the same mistake: the 15
soft-deleted rows still pointed at leave type 11, which the new foreign key would not accept.

Both dead-row groups were repaired in the migration so the constraints could apply, and **the one
thing the reversal cannot put back exactly is written into the reversal script**: restoring
`leave_type_id = 11` would now be rejected by the very constraint that fixes F-94. The script gives
the statement to drop the constraint first, and says plainly that doing so reinstates the defect.

---

## Cancel after approval — a golden transaction that was FAIL

`hrms_emp_leaves.status` has had `cancelled` in its enum since the table was created, and no code
path has ever reached it. The audit listed "cancel after approval" as a failing golden transaction.

`POST /api/leave/requests/{id}/cancel`. Withdrawal and cancellation are kept apart deliberately:

| | |
|---|---|
| **Withdraw** (`DELETE`) | a **pending** request, before anyone decided. Soft-deleted; it never happened. |
| **Cancel** (`POST .../cancel`) | an **approved** request, before it starts. Status becomes `cancelled` and the row survives, because somebody approved it and that decision is part of the record. |

**The balance returns on its own.** `CONSUMING_STATUSES` is `['approved', 'pending']`, so a cancelled
request stops being counted the moment its status changes — no compensating write, nothing to get out
of step. Proven: `used=2 remaining=8` → cancel → `used=0 remaining=10`.

**Only before it starts.** Cancelling leave already under way is not self-service — the days were
taken and attendance for them is recorded. Refused with a reason rather than silently allowed.

---

## F-112, finally closed — 12 of 12

Sprint 2 marked this closed having fixed six of its twelve controls, and Sprint 3 corrected that and
closed four more. The last two:

- **"View" on the Leave Dashboard** was `console.log('View', request.id)`. The detail drawer *and* its
  open handler already existed on that page — the component had simply never been passed them. One
  prop.
- **"Customize Columns" on Leave Requests** was a menu holding a single inert item. It now lists the
  eight optional columns and toggles them, remembered per browser.

---

## F-114 — the Saved tab now saves

It was component state seeded from a static `report.saved` flag on the catalogue, so it reset on
every refresh and showed every user the same three reports.

A starred report is a **per-person display preference**, not tenant configuration, so it lives in the
browser rather than in a new table — the same reasoning that removed the fake "Saved Reports"
dropdown in Sprint 3 rather than building a table for it. Every read and write is wrapped, because
storage throws outright in a private window rather than returning null.

---

## The approver's queue, promised in Sprint 2

Sprint 2 built the regularisation lifecycle and left the approver working through the API, with the
screen scheduled here. It exists now, on the attendance dashboard above the employee's own history.

**Who sees it is decided by the server.** It asks for `scope=team`; the API answers 403 unless the
caller holds `approve_leave`, and narrows the rows to their configured scope. On a 403 the component
renders nothing at all — so the card is hidden *because* the endpoint refused, and the two cannot
disagree. Payroll was reachable by everyone precisely because a React component was the only thing
saying no.

---

## Verification

`_evidence/probe-sprint5.out` — 5 assertions all PASS, plus the structural evidence above.
`next build` clean; zero TypeScript errors in HRIT.

**Live state:** tenant 3 at 13 live leave requests (29 minus the 17 soft-deleted, plus/minus probe
rows removed), `hrms_leave_allocation` back to its 1 pre-existing row.

| Change | Reversal |
|---|---|
| 17 unusable rows soft-deleted | `_local-backups/REVERSAL-2026-09-05-sprint5-bad-leave-rows.sql` |
| `2026_09_05_180000` FK repointed + three columns `NOT NULL` | `migrate:rollback`, and the same script |

---

## What this sprint did not do

- **Multi-level approval and escalation.** `hrms_leave_workflow_settings` is still written by its
  screen and read by nothing — the last NOT-WIRED configuration table in the module. It needs an
  approval-steps table so a request can hold two of three approvals, plus a scheduled job for the
  24-hour escalation the settings describe. Doing it badly would be worse than deferring it, so it
  moves to **Sprint 6** as a piece of work in its own right rather than being squeezed in here.
- **Notifications.** Nothing in the module sends anything on submit or decision. Same sprint, same
  reason — it needs the delivery mechanism decided first.
- **LWP conversion** when a balance is exhausted. The `approved_lwp` status is reachable through
  `decision()` today; converting *automatically* on over-application needs the policy decision about
  whether that is even wanted.
