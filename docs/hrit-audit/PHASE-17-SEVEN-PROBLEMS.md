# Phase 17 — seven reported problems, and the platform faults under two of them

**Raised and closed:** F-214 … F-224 — **11 findings**, five of them things that
had never worked for anybody.
**Verification:** **522 assertions across 29 probes, 0 failures**, re-runnable.
**Frontend:** `next build` green, `tsc` clean in HRIT.
**Trigger:** a walk of the whole module — *"what problems is find"* — filing
seven numbered issues.

Six were real as described. One was real but its cause was not what it looked
like. And **two turned out to be symptoms of faults that reach well outside
HRIT.**

---

## The caveat that shapes everything below

The screenshots came from **`192.168.0.2`**; this repo's `.env` points at
**`202.47.117.220`**. So every *structural* claim here is verified and every
*data-level* symptom on that screen is not.

That distinction bought something, though. `128.199.17.97` runs **MariaDB
10.1.48** — an engine old enough to reproduce the one bug that depends on the
database version. The fix for #6 is proven on the engine that actually has the
fault, not reasoned about.

---

## #6 — not two tabs. 47 models, six modules.

The error is not in the leave code. It is Eloquent's mass-assignment guard: a
model with `$guarded` and no `$fillable` asks the database for its column list
on first `fill()`, and that query selects `generation_expression` — a column
that **arrived in MariaDB 10.2**.

```
202.47.117.220  MariaDB 10.11.9   has it    -> everything works
128.199.17.97   MariaDB 10.1.48   has NOT   -> everything throws
```

Reproduced verbatim on the 10.1 host. `LeaveWorkflowApiController:218` calls
`firstOrCreate` and `:235` calls `create`, so the two tabs broke **only for a
tenant with no row yet** — the seed-on-first-read is what tripped it.

**This repo already fixed this once.** `SkipsGuardableColumnCheck` names the
bug, cites the same error and the same host, and was applied to **18** models.
A scan found **34 more** without it — Competency 13, talent 11, HRMS 10. On an
old engine every `create()` on any of them 500s.

Thirteen further models looked vulnerable and are not: `$guarded = []` short-
circuits `isGuarded()` before the introspection. Worth stating because the naive
grep counts them, and a probe that flags them cannot be asserted at zero.

**The other half** — roughly thirty direct `Schema::hasColumn` /
`getColumnListing` / `getColumns` calls across AI, Competency, custom_module and
the user importer — is covered by a schema-grammar override registered on every
mysql/mariadb connection. It is inert on a modern server.

Two corrections carried into the code:

- `Schema::hasTable()` is **safe**; the three column-level calls are not. The
  probe demonstrates this — with the override disabled, `hasTable` still passes
  while the other three fail.
- `tbluserController.php` carried a comment asserting the **opposite**, sitting
  above the wrong method, and `userColumns()` was itself one of the broken
  calls.

---

## #7 — one line, three calendars

`react-day-picker` is 9.14.0 and the props are correct for v9. The cause is CSS:

```
month_caption: "flex h-9 items-center justify-center px-9"   (static flow)
nav:           "absolute inset-x-0 top-0 z-10 flex h-9 ... justify-between"
```

`nav` is a full-width transparent `z-10` bar lying exactly over the caption row.
Its chevrons sit at the edges; its entire middle is empty and still
hit-testable — and the month and year `<select>`s live in that middle. They were
rendered, styled and correctly wired, and never received a pointer event. The
line's own comment says *"z-10 keeps the buttons clickable above the label"*,
written before dropdowns existed.

**No call site overrides the year range**, so all 22 DatePickers offer current
year −100 to +10. With the dropdown dead, reaching a 1970s date of birth meant
roughly 600 chevron clicks — which is why this read as blocking rather than
cosmetic.

**And the shared component was only one of three calendars.** A background
search caught this after the first fix was already reported done:

| Surface | Mechanism | State |
|---|---|---|
| 22 `DatePicker` call sites | `ui/calendar` | broken by the nav overlay — fixed |
| Leave Dashboard drawer | hand-rolled month grid | **no month or year control at all** — fixed |
| Attendance Tracking drawer | native `<input type="month">` | always worked — left alone |

The Leave Dashboard one makes the report literally accurate: zero state, zero
handlers, hard-locked to the current month. Its own docblock describes an
earlier fix replacing a hardcoded `June 2026` with `new Date()` — the hardcoding
went, navigation never arrived. The third was checked rather than assumed, and
is pinned by an assertion so a later consistency pass does not replace a working
control with the shared one.

---

## #1 and #4 — the mechanism was not the one reported

*"The card grows because many holidays are set."* Holidays are **already capped
at 5 server-side**. The count is not what grows.

Two separate faults:

**Height.** `attendance-tracking/page.tsx:335` is a `grid … lg:grid-cols-5` and
all five widgets set `h-full`. Grid stretches by default, so **the tallest card
sets the height of all five** — and `upcoming-events-widget.tsx:80` renders the
holiday title with no `truncate` and no `min-w-0` inside a 3-line row, so one
verbose name wraps without limit and drags the row with it. The sibling
`my-requests-widget` already truncates.

**Crowding.** The shell compensates for the sidebar with `padding-left`, but
**Tailwind breakpoints key off viewport width**. Expanding the sidebar removes
188px while the grid keeps its column count: Attendance Tracking goes from
~168px per card to **~130px**, and headers that put a title and a button in one
no-wrap flex row collide. `LeaveQuickActionsCard` already did it correctly with
`min-w-0` + `truncate` + `shrink-0`; four siblings did not.

The content wrapper is now `@container/content` — a pattern the careers and
assessment pages already use — and the two widest grids follow it.

---

## #2 — the table was never empty, and two KPI bugs nobody reported

**The endpoint is already per-employee.** `departmentwiseAttendanceReportCreate`
returns one row per `tbluser.id`. The frontend folded them into department
totals because `groupBy` defaulted to `'organization'` — the `'employee'` branch
was written, correct, and one dropdown away with nothing saying so. It is the
default now, and carries the columns the API has always returned: Present,
Absent, Half Day, Late, Holidays, Week Off, Working Days, Attendance % — where
before, two of those were squashed into `"12/22 days"` and the rest discarded.
The CSV follows the table; it was exporting eight columns for a twelve-column
view.

**The drawer had no data source at all.** Its rows were a slice of the
*early-going* dataset — a single date, and only employees who had already
punched out. With the default range of "today" it was empty essentially always,
and two of its columns (`workingHours`, `earlyGoing`) were populated by no
branch, so they read `--` and `0` permanently. It now fetches
`/api/employee-attendance-monthly-report`, which is gated HR-or-self.

**Two bugs behind "Attendance 5%" beside "Absent 100%"** — figures that cannot
both be true:

- `/api/attendance/kpi` **ignored the date range entirely**, hardcoding
  `Carbon::today()`. Every preset the screen offers returned today's number
  under the selected period's label. It also counted every `tbluser` row —
  `status` is 1/0, so disabled and deleted accounts inflated the denominator.
- `/api/attendance/weekly-summary` emitted **six labels whatever the range** and
  walked six days forward. For a one-day selection, five lay outside the queried
  window, and `absent = totalUsers − 0` made each of them 100% absent. It now
  plots the days actually asked about, buckets by week beyond 31 days, and
  counts distinct people per bucket rather than rows.

**And two arithmetic faults in the same report.** `HrmsController:1834` used
`'=>'` as a SQL operator. Laravel does not reject it — `Builder::where()`
rewrites the clause to `from_date = '=>'`, binding the operator as the value:

```
where `from_date` = ?    bindings: ["=>"]
```

No error, a condition that can never be true, so the holiday join has never
matched and working days have always been overstated. Then `:1915` subtracted
`holidays` — a `GROUP_CONCAT` **string of ids** — from a day count:
`(int) "12,45,7"` is **12**.

---

## #3 — including one 500 nobody had reported

*"######"* is Excel: a bare `2025-09-01` is converted to a date serial with a
date format attached, and renders as hashes the moment the column is narrower
than the result. There was **no date formatting at all** — the raw value went
straight through a writer that quotes only on `"`, `,` or newline. `payroll-shell`
now exports `csvText()`, which wraps as `="…"` — the one form Excel, LibreOffice
and Sheets all read as text. The bank report had already hand-rolled exactly
this for account numbers; every export in the module now uses the shared one.

**The Leave column had always exported blank.** The CSV read `day.leave?.type`;
the server sends `leave_type`. Nothing errored.

**And the endpoint 500'd for anyone who had leave that month.** It read
`$leave->reason`; `hrms_emp_leaves` has no such column — the employee's words
are in `comment`. So the Monthly Attendance Report worked for people with no
leave and broke for exactly the people whose row it exists to explain.

Filters: department was added with **no new API** — `getAttendanceEmployees`
has always accepted a `department_id` the hook never passed. The 18-entry month
dropdown became a month picker, because a year that fell off the end was
unreachable from the screen.

Print: a print-only report head (title, employee, staff code, period, date
printed), `@page` sizing, repeating column headers, and `overflow: visible` on
the table wrapper — which was clipping every column past the page width.

---

## #5 — and the access question was already settled

**An employee cannot reach this screen**: Phase 16 revoked menu 104 from every
`employee` profile. Verified — 0 profiles hold it, along with Leave Dashboard
and Leave Configuration.

The filters were stacked one per row inside a **330px rail fixed by the page
grid**, so the panel was taller than the report it filtered and Apply sat below
the fold. They are a full-width wrapping bar now.

The category counts were computed from a module-level constant inside a
`useMemo` with an **empty dependency array** — frozen at 3/2/1 forever,
ignoring both the search box and the active tab, while the "Showing X of Y" line
on the same card used the real number. Two figures on one card, disagreeing.
Both now come from the server, computed from one list.

`GET /api/leave/reports/catalog` serves the definitions and their counts.
Deliberately from code rather than a table: each entry names the endpoint that
produces it, and a database row could name a report this application cannot
build — which is the "label over nothing" the catalogue was trimmed from fifteen
entries to three to remove.

Report Insights was the second card in that rail, so below `xl` it rendered
**last**, a screen away from the metrics it comments on. It sits under them now.

---

## The probes, and the two that caught me

Two new probes, 25 and 51 assertions; three new detectors in the wiring scan.
Every one was known-bad'd.

**They caught two of my own bugs mid-build:**

- The grammar override **silently did nothing**. It decided whether the server
  was old by looking for "mariadb" in `Connection::getServerVersion()` — and PDO
  returns a bare `10.1.48` with no vendor tag. Installed, inert, and the query
  still threw. It asks information_schema about itself now, which answers the
  real question rather than a proxy.
- A detector **reported clean because it could not look**. With F-210 reinstated
  the endpoint 500s, so there was no payload, and an `array_intersect` over
  nothing returned nothing — the credential check passed while the endpoint was
  completely broken. It reports `no-payload` now.

A third trap was avoided by construction: `GuardsAttributes::$guardableColumns`
is a **static per-class cache**, so touching the healthy host first answers for
the broken one. The first version of that check passed on a fully-present bug.
The probe now queries the old host first and clears the cache by reflection.

Two detectors also had to be **narrowed to be assertable**. A repo-wide count of
viewport-keyed grid columns runs to 38 and not one is a defect; a card capped by
`.slice(0, 4)` is bounded as surely as one with a `max-height`. Asserting on
those numbers would be asserting on noise, so both were scoped to what actually
broke.

---

## Where the module stands

| | Phase 16 | Now |
|---|---|---|
| Findings closed | 129 | **140** |
| Probe assertions | 443 across 27 | **522 across 29** |
| Models that can save on old MariaDB | 18 of 52 | **52 of 52** |

Still **AMBER**, and the reason is unchanged: domain sign-off on the payroll
calculation. What changed is that five things which had never worked for anybody
— Form 16, the salary certificate for a full year, the monthly report for anyone
on leave, the month and year pickers, and every `create()` on an old engine —
now do.

**Not verified, and worth saying plainly:** nothing here was run against
`192.168.0.2`. The structural fixes are proven and the MariaDB fix is proven on
an engine with the same fault, but the data on those screenshots was never in
reach.
