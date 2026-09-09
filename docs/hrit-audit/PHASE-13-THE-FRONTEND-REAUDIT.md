# Phase 13 — the re-audit the customer asked for, and why they were right

**Raised and closed:** F-151 … F-157 — **7, all fixed.**
**Verification:** **264 assertions across 14 probes, 0 failures.** `tsc` clean in HRIT.
**Trigger:** *"pleage re audit ones their are many commeonets buttons are still static and not wires"*

---

## The customer was right, and the first check I ran was wrong

Twelve phases had closed 60 findings and left 253 probe assertions passing. **Every one of those
probes talks to the API.** Not one of them opens a screen.

So the first thing I did was scan for buttons with no `onClick`. It found **2 in 161 interactive
tags**, and I reported that — with the honest observation that it "doesn't match *many*". Then I ran
an orphan-component scan and it found **0**.

**Both checks were wrong, in the same way: they looked for the defect in the wrong place.**

- The dead handlers are **passed down as props** — `onColumnVisibilityChange={() => {}}` sits in the
  *parent*, so the `<Button>` tag itself is clean. A grep over buttons cannot see it.
- A **barrel re-export counts as a reference**, so eight components rendered nowhere all looked used.

A proper re-audit of all three sub-module groups found **~75 findings**. The customer's instinct beat
my tooling, and that is worth recording plainly: this is the third time in this audit that a check
which *passed* was the problem (F-109's probe printed the surviving duplicate and passed; F-149's
Form 16 assertions passed vacuously on an empty list).

---

## F-151 — invented figures, printed as measurements

The worst class, because a dead button announces itself and a fake number does not.

```
attendance-kpi-cards.tsx:60-87      trendValue: '+2.5%' / '-1.2%' / '+0.8%' / '0.0%'
                                    four constants, under every KPI, on every tenant,
                                    every filter and every date range
LeaveCalendarDrawer.tsx:29,38,51    getDaysInMonth(2026, 6) - "June 2026" - `2026-06-${day}`
attendance-reports/page.tsx:770-778 head-counts rendered with unit '%': "3 late employees" -> "3%"
attendance-reports/page.tsx:724     earlyGoing: 0 on the branch that runs, while the real count
                                    was already fetched and the OTHER branch computed it correctly
leave-mappers.ts:88-129              percentageChange: 0 on all six stats, and DashboardStats read
                                    `0 >= 0` as positive - a green up-arrow "0%" on every card
```

The leave calendar is the one to sit with: **the button that opens it shows today's real date**, and
the grid it opened was pinned to a month that had passed, with every leave dot positioned against
that fixed month.

**The rule applied:** where no endpoint supplies the figure, the control or the column is **removed**
— not filled with a placeholder, and not left as a permanent `--` that reads as "still loading".
`trend`/`trendValue` stay on the interface so a real comparison can arrive later.

---

## F-152 — the controls that could not work

```
AttendanceReportTable.tsx:52-53  onColumnVisibilityChange={() => {}}   onFilterClick={() => {}}
RecentLeaveRequests.tsx:39       "View All" - no onClick, no href, no asChild
attendance-history-drawer.tsx:92 <Button disabled title="Export is not available yet">
LeaveReportsSections.tsx:291     "Refresh" -> onApplyFilters -> same filter values -> same cache
                                 key -> no refetch. The one button whose entire purpose is to
                                 refetch when nothing has changed.
```

**"Columns" was dead twice over.** Beneath the no-op, its own handler looked for a column *not* in
`visibleColumns` — and the caller passed **every** column as visible, so `columnId` was always
`undefined`. Even a working callback would never have been called.

**Every one had its target already in the file.** "View All" → the `navigate()` helper three sibling
cards on the same dashboard already use. Export → `downloadCsv`, which the same screen's "Download
Timesheet" already calls. Refresh → `retry`, which the hook returned and the page dropped on the
floor.

**"Filter" was deleted rather than wired**, because the same screen already renders a full filter
panel above the table. That is the F-99 precedent: a duplicate of a working control gets removed, not
fixed.

---

## F-153 — My HR had no front door

`tblmenumaster_g2g` held **zero rows** for `/module/hrit-solutions/my-hr`. The only screen where an
employee can see their own payslip could not be reached by navigating.

F-130 was closed in Sprint 8 as "My HR shipped", and the content map said it was "reachable by URL
and from the leave screens until HR adds the menu row". **Neither half held.** No component anywhere
links to it, and a typed URL only resolves when the user's first module happens to be HRIT.

**And a menu row alone would not have fixed it.** `canView()` reads
`($rights->can_view ?? 0) == 1` — a menu with no rights row is **invisible**, because absence is how
revocation is expressed in this system. Menu 102 carries 72 such rows. So the migration inserts a
menu row *and* one `can_view` row per profile, on both hosts.

**Verified in the sidebar** for administrator, employee, hr_manager, team_employee and auditor. It
does **not** appear for recruiter — and that is correct: that profile has no rights row for module 5
itself, so the whole HRIT module is dropped before My HR is reached. Granting it would be a silent
permission change, and not mine to make.

---

## F-154 to F-156 — the rest

**F-154.** Eight components exported from the barrel and rendered nowhere, plus three dead factories,
three dead date helpers, and 56 commented-out lines above a live component. They were a **drifted
duplicate** of panels the live page implements inline, and they disagreed with it:
`today-status-card.tsx:116` rendered an unknown status as **"Absent"**, contradicting the live page's
`undefined → "Unknown"` rule, with the badge hardcoded green.

**F-155.** Three controls that accepted input and discarded it: a leave attachment, an emergency
contact, and an "Include Subordinate Data" filter that ticks and changes nothing. Each disclosed the
truth in hint text — more honest than most, and still a file picker that takes a file, and a filter
that teaches the user to distrust the ones that work.

**F-156.** A button reading **"Select All"** wired to a toggle, so on a fully-permitted role it
silently revoked every permission — on the matrix that decides who may approve leave. And an
Entitlements Save that returned mute when every changed cell was invalid: enabled button, click,
nothing, indistinguishable from success.

---

## F-157 — this audit's own probe suite was lying

The phase's most uncomfortable finding. `probe-sprint6` created three leave requests per run and tore
them down **by id**.

Its dates were `+45/+70/+95 days`, so whether one landed on a weekly off depended on **which day of
the week the probe was run**. When one did, the apply was correctly refused — *"Every day in that
range is a weekly off"* — the id came back empty, and `id in (424,425,)` is a **SQL syntax error**,
so the teardown deleted **nothing**, including the two requests that had succeeded. Those leftovers
then collided with the next run, which failed differently, leaving more.

Leave **414** sat `approved` on user 582 and made the file permanently red.

**So the suite reported 253/253 in Phase 12 and 250/253 today, from identical code.** A probe whose
result depends on the calendar is not evidence. Fixed three ways: a `workday()` helper that steps
past a weekly off, a `0,` prefix so one empty variable cannot invalidate the teardown, and a
**start-of-run clear by the probe's own marker** so a crashed run cannot poison the next. Proven
re-runnable — 20/20 twice, zero rows left behind.

---

## The new probe, and what it exists to catch

`probe-frontend-wiring.sh` (11/11) asserts the layer 253 API assertions could not see:

- **the menu/route contract** — every routed screen has a menu row, every reachable menu item has a
  screen. The check whose absence let My HR ship unreachable.
- **dead-control patterns** — `() => {}` handler props, `href="#"`.
- **invented figures** — hardcoded trend deltas, calendars pinned to a fixed month, placeholder
  letters where an icon belongs.
- **orphaned components** — by file existence, not by reference, because a barrel defeats references.

Its engine strips comments before scanning, because the **first version failed its own no-op check on
two comments I had just written describing the code I had removed**. And each detector was run
against a known-bad sample to prove it fires: a detector that always returns zero is the
vacuous-assertion trap in a new costume.

---

## One correction to my own finding, and one scope reversal

**I told the customer five menu items were live dead links.** They are not. I checked the five leaf
rows' status (all `1`) and **not their parents'** — menus 96–99 and 115 are all `status = 0` on
**both** hosts, and the sidebar builder does `where('status',1)` then groups by `parent_id`, so a
disabled parent means its children never enter the tree. They are switched off, not broken.

On the strength of that wrong claim, "build all five sub-modules" was approved. Told the truth, the
customer chose to surface only the two that already exist. **Then that turned out not to be worth
doing either:** Compliance Library and Disciplinary Management are *already reachable today* under
Organizational Management, for 80 and 71 profiles. The HRIT rows carry **2 rights rows each**, so
surfacing them would mean enabling two disabled parents and backfilling ~212 rights rows to add a
second door to screens users already reach. Not done, and the reasoning recorded rather than the work.

---

## Where the module stands

| | Phase 12 | Now |
|---|---|---|
| Findings closed | 60 | **67** |
| Probe assertions | 253 across 13 | **264 across 14** |
| Gate lines passing | 13 of 16 | **13 of 16** |

**Still AMBER**, and the three reasons are unchanged — scale cannot be measured where the data does
not exist, **Q10** puts real money either way, and domain sign-off cannot come from me. Nothing in
this phase touched the backend behaviour proven in phases 1–12; this was the layer above it, which
nobody had checked.
