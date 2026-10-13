# Phase 18 — an attendance desk for HR, and the roster 88% of people do not have

> *"in the hrit managment the atendence edit and aprove screen is not seprate
> for the admin — the attendance tracking is only one page for both but we want
> seprate screen for it for the emplooyee means seprate sub module where admin
> can change the attendence of emplooyes show all emplooyes attendace set the
> office time edit it days wise"*

Attendance Tracking (menu 100) is an employee's own screen: punch in, punch out,
see my month. There was nothing for the person who has to *correct* it. This
phase adds that screen, the endpoints behind it, and department office hours —
and in the process closed two authorization holes and surfaced a data problem
larger than either.

Choices the user made before implementation: **per department, then per
employee**; **keep the original and record who changed it**; **all four
extras** (bulk actions, inline approvals, add a missing day, export and print).

---

## What existed, and why none of it was usable

There was no clean way for HR to edit an employee's attendance. Three things
came close:

| | |
|---|---|
| `updateUserAttendance` (`HrmsController:2677`) | role-gated, does update + insert — and has **zero callers in any surface**. Omits `sub_institute_id` on the update, so it writes by `(day, user_id)` alone and reaches across tenants; takes its actor id from the request body; its delete branch calls a `destroy()` that is an empty stub which still replies "Deleted Successfully" |
| `hrms-attendance-in-time/store` + `out-time/store` | wrote an **arbitrary** `employee` id, gated only on "is logged in". The out-time lookup had no tenant filter at all |
| Regularisation approval | correct — tenant-scoped, transactional, recomputes `timestamp_diff`, writes a before-image — but only ever runs for a request **the employee themselves** raised. `store()` has no parameter for anybody else |

So the writer was right and the way in was missing. `applyCorrection()` was the
seam; it moved to `App\Services\Attendance\AttendanceCorrector` so the new
HR-initiated route and the employee-initiated one share one writer and cannot
drift.

---

## What was built

### Backend

| File | What |
|---|---|
| `app/Services/Attendance/AttendanceCorrector.php` | the shared writer: update-or-insert, recomputed duration, before-image |
| `app/Http/Controllers/Api/Attendance/AttendanceAdminController.php` | `POST /api/attendance/admin/corrections`, `GET /admin/edits`, `GET /admin/grid` |
| `app/Http/Controllers/Api/Attendance/DepartmentScheduleController.php` | `GET`/`POST /admin/schedules`, `POST /admin/schedules/preview`, `POST /admin/schedules/apply` |

Routes sit under `Route::middleware('profile:admin,hr')` — deliberately
narrower than the reporting group above them, which also admits `executive` and
`auditor`. Those two may *read* the organisation's attendance; changing somebody's
recorded hours changes their pay, and that is an HR act.

**The role gate says who may ask. It does not say whom they may ask about.** An
HR manager is HR for one organisation, not for all twelve, and the route cannot
know which employee id belongs to whose — so every endpoint carries its own
tenant check, answering **404 rather than 403** so a refusal does not confirm
that an id exists in somebody else's organisation.

### Migrations — five, both hosts, each with a reversal

| Migration | Reversal |
|---|---|
| `2026_10_07_100000_create_hrms_attendance_edits_table` | `REVERSAL-2026-10-07-attendance-edits.sql` |
| `2026_10_07_110000_add_manage_employee_attendance_menu` | `REVERSAL-2026-10-07-attendance-admin-menu.sql` |
| `2026_10_07_110100_grant_manage_employee_attendance_rights` | *(same file)* |
| `2026_10_07_110200_grant_manage_employee_attendance_legacy_profiles` | *(same file)* — the repair, see below |
| `2026_10_07_120000_create_hrms_department_schedules_table` | `REVERSAL-2026-10-07-department-schedules.sql` |

Run with `--path` on purpose: both hosts carry pending migrations belonging to
other work (AI tables, document master, role_key backfills) that a bare
`php artisan migrate` would have swept up.

**Menu id 433, not 312.** The obvious next id is free on `202.47.117.220` and
**taken by "Platform Services" on `128.199.17.97`**. These migrations guard on
the id, so 312 would have inserted on one host and silently skipped on the
other, leaving the menu missing on live with nothing in the output to say so.

### Frontend

| File | What |
|---|---|
| `manage-employee-attendance/page.tsx` | the screen: three tabs, the month grid, the correction dialog, the bulk bar, the change history, the print rules |
| `manage-employee-attendance/office-hours.tsx` | department hours, the copy-forward, the apply confirmation |
| `hooks/use-attendance-admin.ts` | grid, corrections, bulk, edit log |
| `hooks/use-department-schedules.ts` | hours, preview, apply, `copyAcrossWeek` |

The approver queue is the **existing** `RegularisationQueue` component, dropped
in with `onDecided` wired to the grid refresh — so HR approves and the corrected
time appears in one step, and approving here cannot drift from approving in
Attendance Tracking.

---

## The finding that matters more than the feature

**2,008 of 2,283 active employees — 88% — have no working days set at all.** All
seven `tbluser` weekday flags are `0`.

Two existing screens then disagree about the same person, because each invented
its own fallback:

- **Monthly Attendance Report** treats a `0` flag as a weekend, so every day of
  the month reads "weekend"
- **Attendance Tracking** falls back to "everything except Sunday is a working
  day", so the same month reads Mon–Sat working

Neither states it as an assumption. This is very likely behind "the attendance
is not correct" as much as any missing screen.

The new grid refuses to guess. A day with no attendance row resolves to:

| | |
|---|---|
| `weekend` | the roster exists and says this weekday is not worked |
| `absent` | the roster exists and says it **is** worked |
| `unset` | the employee has **no roster**, so neither word is true |
| `upcoming` | the day is in the future — which is the other way a month-to-date view manufactures absences |

`unset` renders in its own colour with a banner counting it. It is the
difference between telling HR that somebody has nine absences and telling them
nobody ever recorded which days that person works.

**The hours themselves remain a data decision, not a code one.** The screen can
set them; what they should *be* is the organisation's call.

---

## Six defects found outside the plan — four of them mine

### 1. My own grant migration under-granted on the host the app uses

`110100` selected profiles with `whereIn('role_key', ['administrator',
'hr_manager', 'hr_executive'])` — the way all three precedents in this module do
it. `tbluserprofilemaster.role_key` is not populated everywhere:

| Host | Result |
|---|---|
| `128.199.17.97` | every Admin/HR profile has a `role_key` → 26 grants, correct |
| `202.47.117.220` | Admin, HR and Employee have `role_key` **NULL** → 10 grants, **all of them "HR Executive"** |

So on the host the application actually uses, the new menu was granted to a
profile almost nobody holds and withheld from the Admin and HR profiles
everybody does — **32 profiles across 16 tenants**. The API worked for them (it
resolves through `RoleKey`, which handles `LEGACY_NAMES`), so the symptom would
have been *"the screen exists and I have no menu item for it"*, with nothing in
either migration's output to suggest why.

`110200` repairs it by asking `RoleKey::fromProfile()` the same question
`RequireProfile` asks, rather than filtering in SQL. Both hosts now grant
exactly the set the route admits.

### 2. And my probe assertion was green while that was broken

The assertion counted profiles granted the menu whose `role_key` falls outside
admin/hr and expected `0`. A subset check cannot see **under**-granting, and
under-granting is the failure that actually happened. It is now set equality in
both directions.

### 3. A 500 on the most common correction

`$data['out_time']` threw on every **one-sided** correction.
`Validator::validated()` returns only the keys present in the request, so a
`nullable` field the caller omitted is *missing from the array* rather than null
in it — and the common case is filling in a missing punch-out without restating
the punch-in.

### 4. The audit trail did not match the row it described

The attendance row held `1999-01-11 09:15:00` (MySQL widens a `DATETIME`) while
`hrms_attendance_edits.after_in_time` — a plain string column — kept the raw
`1999-01-11 09:15`. A before/after record whose "after" disagrees with the row
is a second discrepancy to chase, not evidence. `AttendanceCorrector::stamp()`
normalises once, for both tables and both correction paths.

### 5. A lost before-image, hidden inside a try/catch

`g2g_event.idempotency_key` carries a **unique index (`uq_event_idem`) on both
hosts**, so deduplication is done by the database: a repeated key makes the
insert throw, and the `try/catch` around it swallows the throw. That makes the
key's precision load-bearing.

- The correction event was first keyed `admin:{employee}:{day}` — so correcting
  the same day twice (fix the punch-in, then the punch-out) recorded only the
  first. Now keyed on the edit id.
- The schedule event was keyed `{department}:{second}:{actor}` — so applying
  Monday and then Saturday within one second recorded only the first. Now
  includes the weekday selection.

### 6. Two React defects the linter caught

- `setPage(1)` in an effect fired **two requests per filter change**: `loadGrid`
  depends on both the filter and the page, so the filter change fetched with the
  stale page and the follow-up `setPage` fetched again.
- `correct()` and `correctMany()` called `getLaravelContext(user)` without
  listing `user` as a dependency, closing over whoever was signed in when the
  callback was first built.

The grid selection is now **derived** rather than reset by an effect: ticking
three people, changing the department filter and pressing the bulk action must
not write to three employees you are no longer looking at, and clearing state in
an effect leaves exactly one render where it still could.

---

## Office hours: why save and apply are two buttons

There is no office-hours table in this product. Hours live as fourteen `time`
columns on `tbluser`, per employee per day, plus seven flags. Three things look
like an office-hours store and are not: `hrms_in_out_times` exists, is **empty**
and is referenced by nothing; `tenant_setting['org.working_days']` is written by
a settings screen and **read by no attendance code**; `hrms_weekdays` describes
the same week from the tenant's side and is not reconciled with the `tbluser`
flags.

So `hrms_department_schedules` is a **template**. Applying it writes the
`tbluser` columns, because those are what the calculations read — nothing
downstream has to change.

The previous version of this feature was **removed from the product** because a
bulk shift write silently flattened Saturday across a department; 100 employees
in one tenant have a Saturday that finishes at 14:00. Three things that version
did not have:

1. **`preview()` computes exactly what `apply()` computes** and writes nothing.
   It reports, per weekday: how many employees already match, how many have
   nothing set, and **how many hold something different** — the last being the
   number that matters. "40 match, 0 differ" is a safe apply; "40 match, 12
   differ" means twelve people have hours somebody chose for a reason.
2. **`apply()` takes an explicit weekday list.** There is no "all" on the
   server, and `copyAcrossWeek` excludes Saturday and Sunday unless they are
   ticked.
3. **A `department.schedule.applied` event carrying the before-image** of every
   employee touched — so "what did this used to be" is answerable in March.

A working day with **no** hours set writes the flag only. Writing null into the
time columns would read downstream as `00:00` and turn a day off into a day
whose shift starts at midnight.

`weekday` is stored as a **name** (`'monday'`…`'sunday'`), not an index, because
it is also the `tbluser` column prefix. Two live endpoints select only
`monday_in_date` and compare every day of the week against it, and the Early
Going Report reads Saturday's column for Thursday — both are index arithmetic
gone wrong. A name has no arithmetic to get wrong.

---

## Verification

| | |
|---|---|
| `probe-attendance-admin.sh` | **112 assertions, 0 failures** |
| `probe-department-schedules.sh` | **62 assertions, 0 failures** |
| `knownbad-attendance-admin.py` | 20 of 20 detectors shown to go red |
| `knownbad-department-schedules.py` | 12 of 12 detectors shown to go red |
| `probe-phase17.sh` | 27 / 0 (no regression) |
| `probe-frontend-wiring.sh` | 24 / 3 — all three pre-existing, see below |
| `tsc --noEmit`, `eslint` | clean on every file written here |

The schedules probe seeds **its own** department and **three** employees,
deliberately with different Saturdays (18:00, 14:00, and nothing set), applies,
and asserts the difference survived. Running a bulk-write probe against real
employees would be the same mistake the deleted feature made.

### Two mutations that taught something

- **The Saturday guard is doubled.** `whereIn('weekday', $weekdays)` narrows
  what is fetched and `foreach ($weekdays …)` narrows what is iterated. Breaking
  either alone moved no assertion, because the other held the line — real
  defence in depth, and also why the central assertion had never been shown to
  fail. It took a *surgical* mutation, corrupting `$updates` after the preview
  was already built, to put "SATURDAY WAS NOT FLATTENED" on the line.
- **The Sunday assertions were vacuous.** The seed left every employee's Sunday
  at the column default, which already matched the template's "not worked, no
  hours" — so the apply had nothing to do and both following assertions were
  true *before it ran*. Fixed by seeding a working Sunday with hours.

### Recorded as untested rather than quietly omitted

- That the apply is **transactional**. A transaction differs from a loop of
  updates only when something fails partway, which this probe does not induce.
- That a **too-coarse idempotency key** loses an event. The loss needs two
  applies in the same second; the probe's are seconds apart, so the bug is
  latent and reproducing it would be a timing test.
- That the corrector's own tenant predicate matters. `tbluser.id` is the primary
  key, so it is redundant for a subject the controller already verified — but
  **not** redundant in general: an attendance row can carry a
  `sub_institute_id` the employee no longer has, if they were moved between
  organisations. Constructing that needs a moved employee.

---

## Open, and not mine to close here

### Three older screens never reached tenant 1000098

`probe-frontend-wiring.sh`'s three remaining failures are one defect: tenant
1000098 was created **2026-10-05**, after the one-off grant backfills ran.

| Menu | Migration | Profiles missing |
|---|---|---|
| My HR (305) | `2026_09_08_100000` | 6 — all of tenant 1000098 |
| Bank-wise Payment Advice (307) | `2026_09_19_100000` | 2 — that tenant's Admin and HR |
| Monthly Attendance Report | `2026_09_19_120000` | 6 — all of tenant 1000098 |

This is the same class of problem flagged after Phase 17 (*"new organisations
don't get the Leave Requests grant"*), now with a measured instance. The remedy
is one migration iterating profiles through `RoleKey::fromProfile()` the way
`110200` does — but it changes access control on three screens outside this
phase, so it is flagged rather than done.

Phase 18's own menu **did** reach that tenant, because `110200` iterates
profiles rather than filtering SQL.

### `next build` cannot complete in this working copy

Two declared dependencies are not installed, and `npm install` fails outright
rather than partially:

- `fflate` — declared `^0.8.3` in `package.json`, absent from `node_modules`
- `darshana-ai-core` — a private git dependency (`git+https://github.com/Dz-02/…`)
  this machine has no access to

Both are reached only from `app/documents/_lib/expand-upload.ts` and
`lib/ai-core/`, which arrived in other contributors' commits
(`zeeltank/ai-core-integration`, "Document v1 - Rajesh"). `tsc --noEmit` and
`eslint` are clean on everything written here; the bundler was not run.

### Out of scope, still true

- **Lateness is measured against Monday for every day of the week.** Two
  endpoints select only `tbluser.monday_in_date` and compare every punch to it.
  100 employees in one tenant have a Saturday that ends at 14:00; those screens
  cannot see it.
- **The Early Going Report computes lateness only on NON-working days** — its
  seven branches all test `== 0`. It also reads Saturday's column for Thursday.
- **`timestamp_diff` format.** The plan listed this as a defect feeding payroll.
  Measured: the column is `TIME`, which coerces both `HH:MM` and `HH:MM:SS`, and
  all 693 stored non-null durations are whole-minute. But **857 of 3,300
  punch-ins carry non-zero seconds**, so the two writers do differ on those rows
  — one truncates to the minute, one keeps seconds. Real, bounded at 59 seconds,
  not corrupting.

Fixing the first two changes numbers on reports people already read, which
deserves its own change rather than riding along with a new screen.

---

## Said plainly

**An attendance edit is a pay edit.** `timestamp_diff` is read by
`PayrollController`, and a corrected 2nd-Saturday punch-in changes the late
count that is *subtracted* from payable days. The correction dialog says so
where it is true rather than leaving it to be discovered at payroll, and every
change is kept with the actor, the original times and a required reason.

**"Mark absent" and "mark holiday" are deliberately absent from the bulk bar.**
An absence is the *absence* of an attendance row, so marking somebody absent
means deleting theirs — and the only method that claimed to do that is an empty
stub that replies "Deleted Successfully" having done nothing. A holiday comes
from the holiday calendar; writing it into attendance would put one fact in two
places and let them disagree. A button labelled "mark absent" that quietly did
something else would be worse than its absence.
