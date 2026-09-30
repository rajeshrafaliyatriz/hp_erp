# Filter integrity audit — Talent Management & Competency

**Scope** 89 files / 52,437 lines — 39 under `components/domain/talent/`, 50 under
`components/domain/competency/`. Every filter control cross-checked against its backend query and
the real stored value domain (Laravel controllers, `types/recruitment.ts`, type unions).
**Date** 2026-09-30 · **Changes made** one, recorded at the end.

## Why this was measured

The review opened with *"see the all filters all are static in this screen"*, about the
Certification & Compliance Center. My first answer was that all 12 of its filters are wired, its
options are server-derived, and the screen looked dead because **tenant 6 has 0 certification
rows**. That was correct about 11 of them and **wrong about one** — see the fix at the bottom.

The claim also said nothing about the other 27 screens, so all of them were measured.

## The numbers

| | |
|---|---|
| Screens with filter controls | 28 |
| Total filter controls | **156** |
| — `<Select>` filters | 93 |
| — search inputs | 26 |
| — tab strips used as filters | 6 |
| — KPI cards used as filter toggles | 10 |
| — filter chip groups | 2 |
| — date / numeric range inputs | 8 |
| **Decorative controls (options but no `value`/`onChange`)** | **0** |
| Filters whose options are derived from data | **71 of 93 (76%)** |
| Hardcoded literal option arrays feeding a filter | 22 |
| — of those, **provably broken** | **5** |
| — honestly disabled with a stated reason (correct) | 3 |
| — verified exhaustive against the real domain (correct) | 14 |

**There are no dead filters.** Every `<Select>` in both modules carries both `value` and
`onChange`. The only three empty handlers (`onChange={() => {}}`) sit on controls that are also
`disabled` with an `aria-label` saying why — the honest pattern, not a defect.

So "all the filters are static" is **false as a generalisation** and **true in five specific
places**, listed next. The earlier headline figure of "74 inline literals" was an upper bound
across all option sets including forms and page-size pickers; 22 of those actually feed a filter,
and that is the number that can be wrong.

---

## The five that are broken

### F-1 · Workflow module filter offers two modules that can never have rows
`talent/administration/admin-center.tsx:357-369` — offers `Onboarding` and `Performance`. The
backend registry has 4 workflow points across 3 modules (`AdminWorkflowController.php:54-59`):
`Recruitment` ×2, `Offboarding`, `Mobility`. The controller's docblock says so outright:

> *"No `Onboarding`/`Performance` point exists in the registry yet, so those filter options
> legitimately return nothing rather than something invented."*

**2 of 5 real options are dead.** Selecting either gives an empty table reading as "no workflows
found" rather than "this module has no workflow points".

The sting: **the same file**, 180 lines later (`:539-547`), derives its audit-event filter from
data with the comment *"A fixed list would offer filters that can never match."* The lesson was
written down in this file and not applied to the control above it.

### F-2 · Competency Library cannot filter to its most common status
`competency/cm-competency-library.tsx:139-143`, used at `:947`. Offers 8 options. The table
renders a 9th state it does not offer: `statusLabel()` (`:169`) returns **`'Not submitted'`**
whenever `approve_status` is null — and null is the default:

- `CompetencyLibraryCrudController.php:1118, 1185` — every newly created competency is written
  with `approve_status => null`
- `:1249` — every restored competency is reset to `null`
- the file's own comment (`:118-121`) records this state covering **all 231 competencies**

The backend cannot rescue it: `:173` guards on `$request->filled('status')`, so even sending
`status=''` is skipped. **The single most common status is the one that cannot be filtered to.**

### F-3 · Skill Library status filter is missing `Rejected`
`competency/libraries/library-tab.tsx:380-385` → `:877-886`. Offers Approved / Pending /
Cancelled. `'Rejected'` is a real stored value — `SkillMatchingController.php:34, 218` queries
`where('approve_status', 'Rejected')` — and the sibling screen documents it
(`cm-competency-library.tsx:101-103`): *"'Rejected' is a real stored state… can be revised and
resubmitted."* Rejected skills render in the table and cannot be filtered to.

This is the **only** non-derived option list on that screen; its seven neighbours are all
`useMemo` over fetched data.

### F-4 · Exit cases: two statuses unreachable, and the filter is keyed on display text
`talent/offboarding/offboarding-center.tsx:704-724`. `statusFilter` is wired to the API (`:204`)
but **has no dropdown at all** — its only input is five KPI cards matched on `kpi.title`:

```js
if (kpi.title === 'Notice Period') …
else if (kpi.title === 'Clearance Pending') setStatusFilter('Clearance')
```

Backend validation gives the true domain (`OffboardingController.php:583`): `Resignation
Submitted, Notice Period, Clearance, Exit Interview, Awaiting F&F, Closed`. Reachable: 4.
**Unreachable: `Resignation Submitted` and `Awaiting F&F`** — both render in the status column.

Two further hazards on the same screen:
- The backend sends stable ids (`notice-period`, `clearance-pending`, …) and the frontend
  **ignores them in favour of the labels**. Rename a KPI label server-side and the filter
  silently stops working — the same "a display string is not a key" failure that
  `cm-assessment-workspace.tsx:307-312` documents having already fixed elsewhere.
- `activeFiltersCount` (`:169`) omits `searchQuery` and `statusFilter`, so the Filters badge
  under-reports while "Clear Filters" (`:815`) correctly clears them.

### F-5 · Interview feedback: a row says "Submitted" and then hides when you filter for Submitted
`talent/recruitment/interview-tools-drawer.tsx:349-353`. `status` is optional
(`types/recruitment.ts:291`) and the two halves disagree about `undefined`:

- `:363` renders the badge as `{row.status ?? 'submitted'}` → a null-status row **displays
  "Submitted"**
- `:142` filters `row.status === feedbackStatus` → selecting **"Submitted" excludes that row**

---

## Filters that look richer than they are

| Screen | Looks like | Actually distinct |
|---|---|---|
| `admin-center.tsx:352-394` | 3 controls | 2 — Module has 2 dead options; the table is 4 rows total |
| `cm-development-career.tsx:2454-2533` | 5 controls | **3** — the "More Filters" popover (`:2512`, `:2524`) re-renders the *same* Department and Plan Owner selects already inline at `:2477`/`:2486`. Only `pendingOnly` is new |
| `cm-certifications.tsx:1041-1160` | 7 selects + 4 tabs + 5 KPIs | 6 — and `compliance`/`expiry_windows`/`issuing_bodies`/`verification` fall back to `?? []`, so when `filterOptions` is null their only option is "All". **On tenant 6, with no rows, that is what the reviewer saw.** |
| `mobility-center.tsx:1082-1176` | 5 selects | 4 — Business Unit honestly disabled; but **`filterStatus` (`:131`) has no control at all**, `setFilterStatus` is never called, and it ships `status: 'All'` forever (`:332`) |
| `mobility-center.tsx` — Applications / Transfers / Promotions / Succession / Talent Pools | — | **0** — 5 of 7 tabs in a 3,097-line screen have no filter or search at all, while their create dialogs set filterable statuses (`:2842`, `:2947`, `:3008`) |
| `recruitment-center.tsx` Requisitions | search only | 1 — `RequisitionStatus` includes `'Pending'` (`recruitment-data.ts:2`) but the status Select renders only on offers/interviews/job-openings (`:411`) |
| `cm-framework-mapping.tsx` | 1 select | 1 — `FRAMEWORK_STATUS_OPTIONS` (`:112`) is form-only; the frameworks list has no status filter despite draft/active/archived |

Dead capability, no control: `LibraryListParams` accepts `status` (`services/competency/libraries.ts:343`)
and the table renders a `status` column of Active/Inactive (`library-config.ts:176`) plus
`skill_status` (Active/Futuristic, `:177`) — no control anywhere offers either.

---

## The 14 literal arrays that are correct

Listed so this audit is not read as "22 more bugs". Each was checked against its type union or
backend enum and is complete:

`performance-tabs.tsx:180` `GOAL_STATUS_OPTIONS` ✓ · `:1645` `BONUS_STATUS_OPTIONS` ✓ · `:2128`
`CALIBRATION_STATUS_OPTIONS` ✓ · `cm-development-career.tsx:121` `PLAN_STATUS_OPTIONS` ✓ · `:152`
`LEARNING_STATUS_OPTIONS` ✓ · `:166` `CAREER_PATH_STATUS_OPTIONS` ✓ ·
`recruitment-center.tsx:412-419` per-tab status sets ✓ · `interview-tools-drawer.tsx:253` panel
status ✓ · `offboarding-center.tsx:2404` exit type ✓ · `approval-queue.tsx:25, :36` ✓ ·
`onboarding-tabs.tsx:286` `due_in_days` 7/30/90 (date buckets, not data values) ·
`hiring-team-panel.tsx:200` `HIRING_TEAM_ROLES` mirrors `HiringTeamController::ROLES` ✓ *(though
the API returns `data.roles` and the filter ignores it, `:63`)* · `cm-candidate-assessments.tsx:88`
`OUTCOMES` ✓ · plus the three honestly-disabled controls at `talent-dashboard.tsx:393`,
`mobility-center.tsx:1098`, `offboarding-center.tsx:803`.

**A literal is right when the vocabulary is fixed and wrong when it describes data.** Employment
type, Mandatory/Recommended, page sizes and readiness bands are fixed vocabularies — deriving
them from rows on screen would be worse, because an empty table would empty the filter.

## Screens that are clean end to end

`cm-audit.tsx` · `cm-command-center.tsx` · `cm-skill-taxonomy.tsx` · `performance-center.tsx` ·
`onboarding-center.tsx` / `onboarding-tabs.tsx` / `onboarding-sheets.tsx` (~12 filters, all server
`options`) · `cm-employee-profiles.tsx` · `cm-assessment-console.tsx` · `capability-progress-record.tsx`.

## Previously reported, verified fixed

Both earlier finds are genuinely closed in the current tree — re-read, not assumed:
`recruitment-center.tsx:477-497` now appends `Rejected` after `PIPELINE_STAGES`, and `tableStatus`
was made per-tab (`:204-207`). `cm-assessment-workspace.tsx:117-135` derives its options from
`campaigns` and emits a `'__unset'` sentinel for the all-null `type` column.

---

## Changed in this pass

**`cm-certifications.tsx` — the Expiry filter was silently overwritten on 2 of 4 tabs.**

`params` spread `...tabParams` **last** (`:763`), and `tabParams` sets `expiry_window: '60'` on
the Expiring Soon tab and `'expired'` on Expired. So on those tabs the Expiry dropdown accepted a
selection, displayed it, and was overwritten before the request left. A control that visibly does
nothing — **exactly the complaint that started this review, on the screen it was made about.**

The tab now supplies the **default** and an explicit choice wins, via a single `effectiveExpiry`
value that the request, the dropdown and the three KPI cards all read. The screen can no longer
show "Expiry: All" while filtering to 60 days, and the "All" card can no longer read as active
while a tab preset is in force.

### Also added in this pass

`scripts/dead-controls.mjs` gained a **known-negatives** fixture and the self-test now asserts
both directions: every rule still catches its known positive, and none of them flags correct
code.

That was prompted by finding a false positive of my own: `unreachable-view` reported
`cm-libraries-taxonomy.tsx:54`, where `view === 'library'` appears only inside `cn()` because
the render is written `view === 'ai-stack' ? <A/> : <B/>`. Setting `'library'` plainly does
change what renders, through the else branch — **reachability by negation is still
reachability.** The rule now exempts a value whose sibling is read in a non-styling context.

The first attempt at that exemption was itself broken in a way worth writing down: it built
the pattern as a dynamic `new RegExp` inside a **template literal**, where a single-backslash
`\b` is the backspace character and `\s` is just the letter `s`. So the pattern
matched nothing, the exemption never applied, and the scan still reported the false positive —
it needed `\\b` there. It is now a regex literal filtered in JS instead: a rule that
fails open by one backslash is not worth the cleverness.

Scan result: **0 dead controls across 76 files**, self-test green both ways.

## Recommended order for the remaining five

Not done — these are reported, not fixed, since each needs a product decision about what the
missing option should say.

1. `admin-center.tsx:360` — drop `Onboarding`/`Performance`, or derive from the rows' `module` values
2. `cm-competency-library.tsx:139` — add `Not submitted`, and teach the backend to match
   `COALESCE(approve_status,'') = ''`
3. `library-tab.tsx:380` — add `Rejected`; better, derive it like its seven neighbours
4. `offboarding-center.tsx:704` — key on `kpi.id` not `kpi.title`, add a real status Select covering
   all 6, and include search/status in `activeFiltersCount`
5. `interview-tools-drawer.tsx:142` — treat `undefined` as `'submitted'` in the predicate so it
   matches the badge
6. `mobility-center.tsx:131` — remove the unwired `filterStatus`, or give it a control
