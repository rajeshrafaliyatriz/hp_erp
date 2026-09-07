# Demo — Sprint 4

Seven minutes. This is the sprint that fixes the two things making Leave Management unusable as an
HR system, so it is worth taking slowly.

Sign in as **HR Manager** on tenant 3.

---

## 1. The screen that never existed (2 min)

**HRIT Solutions → Leave Management → Leave Configuration.** There is a new second tab:
**Entitlements**.

It opens with a banner: *"No entitlements are set for 2026. Until they are, every leave balance in
the product reads zero and the system cannot tell anyone they are out of leave."*

That was literally true this morning.

The grid is departments down the side, leave types across the top, and the number of people in each
department beside its name. Type **12** into Nursing / Annual Leave and press **Save**. The footer
totals it as **person-days**, so the number has weight — 12 days across 34 people is 408 person-days
of liability.

**Say this:** the table this writes to had **one row in it for the entire platform**. Not one per
organisation — one, total. And nothing in the product could add another; the only code that ever
wrote to it was a side effect of saving a leave type. So every leave balance we have ever shown
anybody was zero.

---

## 2. Watch a balance become real (1 min)

Sign in as an **employee in that department** → **Attendance Tracking**. The Employee Snapshot now
shows their real remaining leave.

Or from the terminal, which is starker:

```bash
bash Docs/hrit-audit/_evidence/probe-sprint4.sh
```

```
before: Annual Leave total=0 remaining=0
        hr grants 3 days to department 35   ->  "1 entitlement(s) saved."
after:  Annual Leave total=3 remaining=3
```

---

## 3. A weekend no longer costs two days (2 min)

Still as the employee → **Leave Requests → Apply Leave**. Pick **Saturday to Sunday**.

The request is refused: *"Every day in that range is a weekly off or a holiday, so there is no leave
to take."*

Now pick **Friday to Saturday**. It costs **1.5 days**, not 2 — because this organisation's own
settings say Saturday is a half day.

**Say this:** leave was being counted in calendar days. Three different parts of the system were each
doing that sum, all the same wrong way, and the working-week and holiday screens HR maintains changed
none of them.

Here are three real leave records in our own database, before and after. The audit worked these out
by hand *before* any of this code was written:

| Leave | Dates | Was charged | Should be | Now |
|---|---|---|---|---|
| #19 | 5–18 Mar | 14 days | 11 | **11.00** |
| #20 | Sat 4 – Sun 5 Apr | 2 days | 0.5 | **0.50** |
| #21 | Fri 12 – Sat 13 Jun | 2 days | 1.5 | **1.50** |

---

## 4. The system says no now (2 min)

The probe script runs these. Every one used to be **accepted**:

```
422  a year of leave                "Leave must fall inside the 2026-04-01 to 2027-03-31 leave year."
422  leave dated 1990               same
422  another org's leave type        "That leave type is not available to your organisation."
422  overlapping dates               "You already have pending leave from 2026-11-09 to 2026-11-10."
422  5 days on a 3-day balance       "That is 5 day(s) of Annual Leave and you have 3 remaining."
201  2 days within balance           accepted, charged 2.00
```

Each message says what is wrong and what the person can do about it.

---

## If you are asked "is anything still loose?"

Yes, one thing, and it is deliberate and written down.

**An entitlement of zero does not block a request.** Every entitlement is currently zero, so
enforcing the balance rule strictly would have refused *every leave request in the product* the
moment it shipped. So: where an organisation has set an entitlement, the balance is enforced; where
they have not, the request goes through. It fixes itself as entitlements get set on the new tab.

That is in the code comment and in the audit finding, not hidden.

**Also still open:** approvals are single-step (the Approval Workflow tab still controls nothing),
there is no cancel-after-approval, and nothing sends a notification. All Sprint 5.

---

## One thing I caught while writing this up

The entitlement column in the database was a whole number, while the new screen offers half-day
steps. Someone typing **12.5** would have been silently stored as **12** — no error, no message.

That is exactly the kind of thing this whole exercise exists to find, so it was fixed before shipping
rather than noted for later: the column is now decimal, and a 12.5-day grant round-trips correctly.

---

## Where we are

**24 of 36 findings closed (67%). 5 of 9 sprints.** Still no sub-module green — Leave Requests is
closest, and it stays amber until approvals are multi-step and Sprint 8 tests it at scale.

**Next, Sprint 5:** make the Approval Workflow tab actually drive approvals, add cancel-after-approval,
notifications, the approver's queue for attendance corrections, and repair the 15 leave rows whose
leave type points at an employee instead of a leave type (F-94).
