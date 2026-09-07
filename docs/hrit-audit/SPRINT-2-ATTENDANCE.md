# Sprint 2 — Attendance Tracking

**Closed:** F-97, F-98, F-107, F-113, F-115, F-116, F-117 — **7**, taking the total to **17 of 36**.
**Partly closed:** F-112 — 6 of its 12 controls. *This write-up originally claimed F-112 in full;*
*that was wrong and is corrected here — the finding spans three screens and this sprint fixed one.*
**Partly fixed:** F-121 (89% of the queries removed; still too slow — Sprint 6).
**Live changes:** two migrations, both with reversal scripts. No data edits left standing.

---

## What this sprint was for

The audit's first named failure mode is *"screens that render invented data"*. Attendance Tracking
was the clearest case in the module: the leave balance, the next holiday, the alert panel, the
request counts, the shift length and the date on the calendar button were **all literals**, and the
five Quick Actions beneath them were `onClick: () => {}`.

It now runs on the tenant's own data, and every control goes somewhere.

---

## The fixtures, and where the real numbers came from

Two of them were set in the hook's `finally` block, so they rendered on **success** as well as on
failure — every employee in every tenant saw the same figures:

```ts
const mockLeaveBalance  = { casual: 12, earned: 7, sick: 0, pending: 1 }
const mockUpcomingEvents = [{ title: 'Independence Day', date: '2026-08-15' }]
```

Both numbers already had correct, tenant-scoped endpoints that nothing called — `/api/leave/balances`
and `/api/leave/holidays/upcoming`. They are called now. **Nothing here re-implements them**: a second
version of a number the product must agree with itself about is how the two drift apart.

The four hardcoded leave types went with them. Tenant 3's are "Annual Leave", "Scholar Clone" and
"Scholar Clone 2" — not Casual / Earned / Sick.

`GET /api/attendance/self-summary` is new and serves the rest: the shift, the alerts, the counts.
Leave balance and holidays are deliberately **not** in it, for the reason above.

---

## The shift is real, and Q2 is answered

`SHIFT_TOTAL_MINUTES = 510` drew the progress ring for everyone. The audit said there was no source
for a real one. **That was wrong**, and the correction is recorded against F-105:

- The per-employee roster is on `tbluser` as fourteen `time` columns — `monday_in_date` /
  `monday_out_date` through `sunday_*` — and it is **populated**: 102 of 122 active users in
  tenant 3, 100 of 181 in tenant 7.
- `tbluser_shift_master` is the *template* table that a bulk update copies **into** those columns.
  It is absent on both `hp_erp` hosts and present with 3 real rows on the third deployment
  (`10 to 7  10:00–19:00`). Its two admin screens still 500 — F-105 stays open for them.
- `hrms_in_out_times` is **not** a roster at all: its 18 rows on the third deployment are recorded
  punch times per user per day, a second generation of `hrms_attendances`.

Verified end to end: `hr_manager` (user 67) is rostered Saturday `09:00–14:00` in the database, and
on a Saturday `self-summary` returns `expected_in 09:00, expected_out 14:00, expected_minutes 300,
source roster`. An employee with no roster gets `source: none`, and **the UI says "No shift set"**
rather than inventing one.

Overnight shifts roll forward a day rather than returning a negative length — the third row of
`tbluser_shift_master` on the deployment that has it reads `09:30 → 07:00`.

---

## Regularisation: the capability that had no caller

`POST update_user_att` has corrected attendance rows all along, and nothing in `g2gv0` called it.
The control that should have was the dead `regularize` Quick Action — while the alert panel showed
a hardcoded *"Regularization Pending (1)"* for a feature that did not exist.

New: `hrms_attendance_regularisations`, `AttendanceRegularisationApiController`, and a
`RegularisationDrawer`. Proven against live data, then removed:

```
1. employee raises                      201  {"id":1}
2. re-raises the same day               200  "Your pending request for this day was updated."   (id 1, not 2)
3. appears in their own list            count=1  pending
4. employee opens the review queue      403  "You do not have permission to review attendance corrections."
5. HR sees it in the queue              count=1  Vivek Gajera / 2026-08-31 / pending
6. applicant approves their own         403
7. attendance row before approval       (none — the day was never punched)
8. HR approves                          200  "Approved. The attendance record has been corrected."
9. attendance row after approval        punchin 09:35, punchout 18:15, timestamp_diff 08:40:00
10. deciding twice                      422  "This request has already been approved."
```

Step 9 was hand-checked: 09:35 → 18:15 is 8h40m. Step 8 **creates** the row when the day has none —
a wholly missed punch is the commonest reason to regularise, and refusing it would leave the
employee with an approved request and an absent day.

**On authority, and a deliberate reuse.** Approving a correction needs reach over that employee, and
that reach is already configured per tenant in `hrms_leave_role_permissions` — the table Sprint 1
made load-bearing. This reuses it rather than adding an `approve_attendance` column, because a
column with no checkbox on the Roles & Access tab would be configuration that controls nothing:
exactly the NOT-WIRED defect this remediation exists to remove. It gets its own column when that tab
is reworked in Sprint 5, and the controller's docblock says so.

---

## Work mode — one migration, two findings

The Location column rendered `record.location || 'Office'` where `location` was itself
`ipaddress_in ? 'Office' : undefined` — a constant twice over (F-115) — next to a "Mark WFH" button
that did nothing (F-112), because the schema had nowhere to record it.

`hrms_attendances.work_mode` (`varchar(20) NOT NULL DEFAULT 'office'`, office / home / field).
varchar rather than enum on purpose: `hrms_emp_leaves.status` was an enum and needed a later
migration purely to widen it. The 994 existing rows take the default, which is what the UI showed
for them anyway.

---

## The other three

- **F-113** — the calendar button read `'Today, 22 Jun 2026'`. It reads the clock.
- **F-116** — an unrecognised status was coerced to `'present'`, the most favourable possible
  reading of a day nobody could classify. It is now `undefined` and renders "Unknown"; `half_day` is
  aliased to `half-day` rather than falling through.
- **F-117** — `retry()`, offered after a failed **load**, called `punch()`. It reloads.

Also: the page now renders the API's own `percentege` instead of computing a second, different
attendance percentage in the browser — half of F-108, which closes fully in Sprint 8.

---

## F-121: found by fixing F-93, and mostly fixed

Sprint 1 closed the session bug that made Monthly Payroll Report 500 for everyone. That revealed a
worse one: the screen then 500'd at PHP's execution limit instead.

```
                        3 consecutive runs
before   500   60.5s   61.0s   66.1s    "Maximum execution time of 60 seconds exceeded"
after    200   58.7s   39.5s   30.8s    output byte-identical (279,817 bytes)
```

Cause: `getTotalDays()` ran **one COUNT query per day, per employee**, and is called once per
employee — 26 × 122 = **~3,172 queries** for one month of tenant 3, against a database on another
host at a measured **39.7 ms** round trip. That is ~126 s of pure latency.

Collapsed to one grouped query. Kept deliberately the same shape — COUNT per day, not a presence
check, and no new soft-delete filter — because `$totalAtt` sums these; this is a performance fix,
not a change of answer, and the byte-identical output is the evidence. A `$userData` lookup that was
assigned and never read anywhere in the method was deleted: 122 more wasted round trips.

**Still open.** ~370 per-employee queries remain and 31–59 s is not usable at the 3,000-employee
scale the brief names. Sprint 6.

---

## Verification

`Docs/hrit-audit/_evidence/probe-sprint2.out` — 12 assertions, all PASS, including four that prove
validation is at the API rather than only in the browser:

```
422  no times given         "Give a corrected punch-in time, a punch-out time, or both."
422  no reason given        "A reason is required."
422  out before in          "Punch-out must be later than punch-in."
422  a future day           "You can only regularise a day that has already happened."
422  invalid work mode      "The selected work mode is invalid."
```

Sprint 1's 14 assertions re-run with no regressions. `tsc` back to **2** pre-existing errors (it was
regressed mid-sprint by the `LeaveBalance` type change), `next build` clean.

**Live state:** tenant 3 back to 29 leave requests, 42 attendance rows, 3 leave types, 0
regularisations; all audit tokens revoked.

| Migration | Reversal |
|---|---|
| `2026_09_05_140000` `hrms_attendance_regularisations` | `_local-backups/REVERSAL-2026-09-05-sprint2-regularisations.sql` |
| `2026_09_05_150000` `hrms_attendances.work_mode` | `_local-backups/REVERSAL-2026-09-05-sprint2-work-mode.sql` |

---

## What this sprint did not do

- **F-105** — the shift *template* screens (`hrms/user_shift_master`, `hrms/user_bulk_shift_update`)
  still 500, because `tbluser_shift_master` does not exist on either `hp_erp` host. The dashboard no
  longer needs them, which is why this is no longer blocking, but two registered admin screens are
  still broken.
- **F-120** — attendance *reports* are still readable by every role. Sprint 3, where those screens
  are being reworked and can be re-tested.
- **The approver's queue has no screen yet.** The API is built, gated and proven, and HR can see
  the queue through it; the manager-facing list lands with the other Leave/Attendance screens in
  Sprint 5. Employees can raise and withdraw today.
