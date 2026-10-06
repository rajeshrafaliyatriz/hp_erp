# Phase 14 — finishing the list instead of stopping at the worst of it

**Raised and closed:** F-158 … F-163 — **6.**
**Verification:** **267 assertions across 14 probes, 0 failures.** `tsc` clean in HRIT; `next build` succeeds.

---

## Why this phase exists

Phase 13 found ~75 frontend findings and fixed **seven**. I took the worst — invented figures, dead
controls, the unreachable screen — reported the rest honestly, and stopped. Asked whether HRIT was
complete, the accurate answer was no, with a list.

This is that list, for the items a user actually meets.

---

## F-158 — fifteen reports, three endpoints

The largest remaining lie in the module.

```
catalogue offered   15 reports
leaveService has     3 endpoints   getReportSummary / getReportRegister / getReportBalance
```

Three failures stacked:

- **Nine had no backing at all** — Holiday Calendar, Monthly Trend, Absenteeism, Policy Exception,
  Encashment, Carry Forward, Leave Usage, Department Summary, Custom Report.
- **The preview ignored the selection.** `summary?.rows` was passed unconditionally, so every report
  rendered the same leave-type table. Choosing one changed the **title and nothing else**.
- **Export wrote the wrong file under an asserting name.** The filename comes from the report id, so
  "Holiday Calendar Report" downloaded `holiday-calendar-<dates>.csv` full of leave-type totals, and
  Carry Forward downloaded a balance CSV **with no carry-forward column in it**.

Three more — Employee Leave History, Long Leave, Pending Approvals — were duplicates rather than
unbacked: a `ReportDefinition` is title, description, category, icon and tone, with **no filter of any
kind**, so all three were labels over one unfiltered register.

**Fixed by trimming to the three that exist and making the preview render what each one names** — a
row-level register table, an employee × leave-type balance table, the summary. `register` and
`balance` were already fetched and already used by the export; only the preview ignored them. Three
now-empty categories went too, since the sidebar draws one filter per category with a count beside it.

The three duplicates return the day they carry the filter their name implies. The API already accepts
`status` and dates.

---

## F-159 to F-163 — the rest

**F-159.** Group By Department and Group By Employee rendered Punch In, Punch Out, Expected In,
Expected Out and Early By as hardcoded `'--'` — five of eleven columns constant. They are built from
a day-count summary that has no punch times, and no endpoint returns other employees' punch times
across a range. Removed: a permanent dash reads as "still loading", not as "this view lacks that".

**F-160.** The month lock lived only in `MonthLockCard`'s local state and reported to the page solely
after a lock/reopen action — so on load the page never learned the month was locked, and "Generate
Payroll" stayed enabled for a save the server was about to refuse. Worse, the card rendered **only in
the populated branch**, so on an empty month the Lock/Reopen controls vanished entirely — and an
empty month is exactly the one you might want to declare finished. Both fixed.

**F-161.** "Download PDF" depended on in-memory `lastQuery`, so a page refresh permanently orphaned a
certificate that was sitting in `hrms_salary_certificate`. The URL needs only employee and year, both
of which are in the form, so it now derives from the current selection. **Still true and said out
loud:** no endpoint lists previously issued certificates, so a history panel is not something this
can honestly offer.

**F-162.** Print called `window.print()` with no print stylesheet — F-99 fixed exactly that on the
sibling attendance screen and never reached here — so the sidebar, tabs and every button went on the
paper. Export carried a `ChevronDown` promising a format picker that never existed. "Rows per page:
10" was two plain spans where a select belongs.

**F-163.** A rejected `/my-hr/payslips` collapsed to `[]`, rendering *"Payslips appear here once your
organisation has run payroll."* A network failure told the employee a fact about their **pay**.

---

## Three new detectors, and the vacuous-assertion check again

`probe-frontend-wiring.sh` grew a section 5:

- **the catalogue matches the endpoint count** — the check that would have caught F-158 on day one
- **no column hardcoded to a dash**
- **every Print button has a print stylesheet**

Each was run against a known-bad sample before being trusted, for the same reason as last phase: a
detector that always returns zero passes forever and proves nothing.

---

## Where the module stands

| | Phase 13 | Now |
|---|---|---|
| Findings closed | 67 | **73** |
| Probe assertions | 264 | **267** |
| Gate lines passing | 13 of 16 | **13 of 16** |

**Still AMBER**, and the reasons are unchanged and unchangeable by me: scale cannot be measured where
the data does not exist, **Q8/Q9/Q10** each put real money either way, and **domain sign-off** has not
been sought. What has changed is that the remaining list is no longer defects I found and left.
