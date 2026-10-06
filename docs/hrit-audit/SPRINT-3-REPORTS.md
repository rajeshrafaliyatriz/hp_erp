# Sprint 3 — Attendance Reports

**Closed:** F-99, F-119, F-120 — **3**, taking the total to **20 of 36**.
**Also closed:** 4 more of F-112's twelve controls (10 of 12 now).
**Live changes:** none. No migration, no data.

---

## First: a correction to Sprint 2

Sprint 2 marked **F-112 as CLOSED**. It was not. F-112 lists **twelve controls across three screens**
and Sprint 2 only touched Attendance Tracking — six were still `console.log` or had no handler when
the sprint was declared done.

That is the exact defect this audit exists to catch, committed by the person writing it. It is
corrected in the register with the full table rather than quietly edited, and Sprint 2's write-up and
the progress tracker were both revised down (18 → 17 closed).

Sprint 3 closes four more of them. Two remain, on Leave screens, for Sprint 5.

---

## F-99 was worse than filed

The finding said Export, Print and Saved-report selection were `console.log` handlers — which implies
there were buttons wired to them.

**There were no Export or Print controls on the screen at all.** `handleExport` and `handlePrint`
were defined and bound to nothing: dead code, sitting beside a Saved Reports dropdown that *was*
rendered and did nothing. Visible only by grepping for the handler names rather than trusting the
finding's wording.

Both controls now exist and work:

- **Export CSV** writes the view the user is actually looking at — the grouped table or the daily
  detail rows, whichever tab is open. Exporting a different shape from the one on screen is how an
  export stops being trusted. It reuses `downloadCsv()` from `payroll-management/shared/payroll-shell`
  rather than adding a second CSV writer to the module.
- **Print** opens the browser's own dialog, with a print stylesheet that drops the filter bar, the
  tabs and the buttons, so what comes out is a report rather than a screenshot of an application.

## Saved Reports was removed, not built

Its three entries were **"Last Month Report"**, **"Q1 2026 Report"** and **"This Week Report"** —
date ranges. The Quick Filter sitting next to it is already a date-range control.

So it was a broken duplicate of a working control, and the brief's rule is *do not make duplicate
functionality*. It is gone, and the ranges it promised — Last Month, This Quarter, This Year — were
added to the Quick Filter, where they now apply.

Building a `saved_reports` table with CRUD would have been inventing an entity to justify a control
that duplicated its neighbour. That is a bigger version of the same mistake, not a fix for it.

## Two other duplicates removed

- `services/report-data.ts` **deleted** (F-119). Four fixture datasets, three of which had no
  importer at all. The one type worth keeping, `EarlyGoingRecord`, moved to
  `attendance-reports/types.ts` — where nothing can accidentally import a fixture beside it.
- `attendance-filters.tsx` **deleted**. It was the earlier generation of
  `EnhancedAttendanceFilters` — same control, same props, plus the Saved Reports dropdown — exported
  from the components barrel and imported by nothing. §E.0 of the audit calls out two generations of
  one idea living side by side as the drift risk; the unused one goes.

## The row action

The "eye" button on every daily-detail row had **no `onClick`**. It now opens
`AttendanceDrillDownDrawer` — which already existed, fully built, in the tracking components folder
and was being rendered by nothing.

---

## F-120: gated on both surfaces

Sprint 1 deliberately skipped this block because employees punch in and out through parts of it, so
the gate had to be drawn per route rather than per group. That is what this is.

| | Roles |
|---|---|
| **Gated** — reporting and shift configuration | `admin, hr, executive, auditor` |
| **Open** — the employee's own attendance and punches | everyone |

Gated on **both** surfaces, because closing one would have moved the hole rather than shut it:

- the legacy routes in `routes/hrms.php` (`hrit.role:`, which falls back to the session for the Blade
  screens), and
- the four `/api/attendance/*` endpoints the React screen actually reads (`profile:`, token-only —
  there is no Blade surface for those).

The role list matches the `REPORTING` group in the frontend's `gtg-nav-visibility.ts` exactly, so the
menu and the API agree about who may read a report. **`auditor` and `executive` are admitted**:
hiding reports from the two read-only oversight roles would remove the one thing they exist to look at.

---

## Verification

`_evidence/probe-sprint3.out` — **16 assertions, all PASS**.

```
403  employee / recruiter / department_head  ->  the three legacy report routes
200  hr_manager / administrator / auditor / executive  ->  the same routes
403  employee  ->  /api/attendance/kpi, /employees, /report-filters
200  hr_manager, auditor  ->  the same
200  employee  ->  my-attendance, self-summary, regularisations, legacy punch screen
```

The last block is the one that mattered most: a gate that also blocks the people who need the screen
is not a fix, and the punch path is how everyone records a working day.

**Filters proven to reach the query**, not assumed from the UI:

| Filter as the service sends it | Employees returned |
|---|---|
| `department_id=0` (all) | **118** |
| `department_id[]=81` (Nursing, 35 attendance rows) | **14** |
| `department_id[]=35` (Administrative Support) | **9** |
| `emp_id=6` | **1** |

`tsc` at the 2 pre-existing errors, `next build` clean. No live database change this sprint.

---

## What this sprint did not do

- **Server-side pagination and sorting.** The plan named it; it is not done, and here is why rather
  than a silent omission. The daily-detail rows come from
  `/show-early-going-hrms-attendance-report`, a legacy Blade endpoint that returns **one day** and
  has no pagination contract. Adding one means changing that controller, which also serves the Blade
  screens. The current page size is bounded by a single day's rows, so this is a scale concern rather
  than a live defect — it belongs with the other N+1 work in **Sprint 6**.
- **F-112's last two controls** — "Customize Columns" on Leave Requests and "View" on the Leave
  Dashboard. Sprint 5, with the rest of the Leave screens.
- **F-105** — the shift *template* screens still 500. Unchanged.
