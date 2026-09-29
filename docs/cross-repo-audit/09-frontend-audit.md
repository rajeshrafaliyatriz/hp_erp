# 09. Frontend Audit

Scope: `g2gv0/app/*` route-level screen audit — API calls, loading/error/empty state handling, hardcoded/mock data, and dead/duplicated code, per directory.

---

## `app/dashboard`

### Dashboard Home — `app/dashboard/page.tsx`

Role-switch wrapper only (`main-dashboard.tsx:39-76`): HR/admin gets `HrDashboard` (via `useHrDashboard` hook, F-66-era rewrite), everyone else gets `MeDashboard` (`useMeDashboard`). Both show loading/error via `ErrorState`/`Skeleton` (`hr-dashboard.tsx:49-50`, `me-dashboard.tsx:50`). In-file comments (lines 9-38) document this replaced a 537-line static screen with fabricated org-wide numbers — no mock literals remain here; verify inside the two hooks if deeper audit needed.

### Attendance Tracking — `app/dashboard/attendance/page.tsx` → `attendance-tracking/page.tsx`

| Aspect | Finding |
|---|---|
| Data | `useAttendance()` hook → `hrmsService.getMyAttendance` / `getAttendanceSelfSummary` (`hooks/use-attendance.ts:163-164`) and `punchAttendanceIn/Out` (lines 290-292) |
| Loading | `<Skeleton>` in `TodayAttendancePanel` (line 505-507) and `RecentAttendancePanel` (689-691) |
| Error | Explicit `error` banner with **Try again** (`page.tsx:315-325`) |
| Empty | "No attendance records found" (716-721) |
| Widgets | Five widgets (`EmployeeSnapshotWidget`, `QuickActionsWidget`, etc., lines 344-373) each get their own `loading` prop but share one skeleton `Loading...` fallback (135-141) — no distinct empty-state per widget, worth checking those files directly |
| Mock data | None — extensive comments (106-119, 208-217, 224-284) confirm all prior hardcoded shift/alerts/requests literals were removed in favor of API-backed roster (F-98/F-112/F-113) |
| Dead/duplicate | None obvious; `CalendarDays` icon import previously unused is now wired (304-309) |

### Attendance Report — `app/dashboard/attendance-report/page.tsx` → `attendance-reports/page.tsx`

| Aspect | Finding |
|---|---|
| Data | Four parallel `hrmsService` calls via `Promise.allSettled` — `getAttendanceKpis`, `getAttendanceWeeklySummary`, `getDepartmentAttendanceReport`, `getEarlyGoingAttendanceReport` (lines 593-620), plus `getAttendanceReportIndex` (519) and `getAttendanceEmployees` (547) for filter dropdowns |
| Loading/Error | `apiLoading`/`apiError` with **per-dataset failure naming** (`REPORT_DATASET_LABELS`, 66-71; message built at 644-650); inline error banner with retry (1123-1135); loading strip + `opacity-50`/`aria-busy` overlay on stale content (1143-1152) — a deliberate fix per comments (1114-1121) for a prior "looks broken" bug |
| Mock data | None — comment at 408 states "API rows only... never falls back to sample data" |
| Dead/duplicate | None found; heavy inline documentation of past bugs (F-161, F-175, F-177-180, F-199) but current code is single-path |

### Leave Management — `app/dashboard/leave-management/page.tsx` → `leave-dashboard/page.tsx`

| Aspect | Finding |
|---|---|
| Data | `useLeaveDashboard()` and `useLeaveRequestDetail()` (`hooks/use-leave.ts`, not read directly but referenced lines 11, 122-149) |
| Loading | Full `DashboardSkeleton` (97-113, 222-224) |
| Error | `<ErrorState>` with retry (226-234); separate `actionError`/`actionMessage` alerts for approve/reject actions (238-252) |
| Empty | No explicit "empty" UI checked inside child cards (e.g., zero pending requests) — not verified past this file |
| Mock data | None in this file — `getCurrentDate()` from `lib/leave-management-data` used for header date only, not literal data |
| Dead/duplicate | Comment F-181 (37, 128-131) notes trend data was previously fetched-and-discarded, now rendered via `LeaveTrendChart` (266) — fixed, not currently dead |

**Overall (`app/dashboard`):** all four routes are thin `'use client'` wrappers with role/permission gating (`useAuth`, `AccessDeniedPage`) delegating to `@/domain/hrms/...` implementation files. Extensive in-code comments document a completed remediation pass (F-numbered) removing prior mock data and dead handlers — no remaining hardcoded/mock literals or dead components found in the four page-level files inspected.

---

## `app/organization`

| Route | Type | Detail |
|---|---|---|
| `page.tsx` (`:4`) | Redirect | Bare redirect to `/organization/departments`. No API, no state |
| `departments/page.tsx` | Redirect shim | Waits on `useAuth`/`useSidebarNavigation`, then `router.push(resolveAccessLink(DEPT_MANAGEMENT_ACCESS_LINK))` (line 21). Real department screen lives elsewhere (menu-driven). No API call, no loading/error UI beyond `if (isLoading) return null` |
| `add-detail/page.tsx` | Redirect shim | Identical logic to `information/page.tsx`, target `ORG_PROFILE_ACCESS_LINK` (line 21 each) — effectively duplicate files, same logic/imports, just different component names. Candidate for merging into one shared redirect component |
| `information/page.tsx` | Redirect shim | Same as above |
| `compliance-management/page.tsx` | Redirect shim | Redirects to `COMPLIANCE_LIBRARY_ACCESS_LINK` (line 53). Comment block (lines 9-35) documents this used to mount a real `ComplianceLibraryManagement` guarded by a stale `Role` union (`['employee','manager','hr'].includes(user.role)`) that **inverted access** (let employees in, blocked admins/HR). Now dead, replaced by redirect — bug is gone but confirms this route itself does nothing |
| `setup/page.tsx` | Real screen | Calls `setupStatusService.get(context)` (line 87, `services/organization/setup-status.ts:62`) and `setupStatusService.createRoles(context)` (line 135, `setup-status.ts:73`), both via `apiClient` → `/organization/setup-status` and `/organization/setup/roles`. Loading: skeleton pulses (237-242). Error: `Alert variant="destructive"` (222-228). Empty/done: review list (243-290). No mock data — comment block (27-33) documents removal of a prior hardcoded "ABC Technologies Pvt. Ltd." fixture and a `localStorage` "gtg-portal-live" flag. Embeds `ModuleConfiguration` (line 309) for the "modules" step inline |
| `readiness/page.tsx` | Real screen (wrapper) | Thin wrapper (`return <OrganizationReadiness />`, line 12) around `components/domain/organization/organization-readiness.tsx`, which fetches directly via `fetch()` against `${resolveApiBaseUrl()}/readiness/gates` (line 131) and `/readiness/gates/acknowledge` (line 164) — **the only screen in this tree bypassing the `apiClient`/services layer**. Loading (`readiness-loading` testid, line 183), error (`readiness-error`, line 202), confirm-dialog flow before disabling a capability (lines 268-309). No mock data |
| `employees/page.tsx` | Real screen (lazy) | Lazy-loads `EmployeeDirectory` from `@/domain/organization/employee-directory`, Suspense fallback plain skeleton div (line 12). Real component (`components/domain/organization/employee-directory.tsx`) calls `employeeDirectoryService.list()` (line 126) and `.referenceData()` (line 154). Loading via `DataTable isLoading` (line 476); error banner with Retry (439-446); empty state distinguishing "no results" vs "no employees yet" (484-495). Comment block (52-60) documents removal of prior mock fallback data (`defaultMockEmployees`, "John Doe"/"Jane Smith") |

**Dead code found**: `employee-directory.tsx:32` imports `organizationService` from `@/services/organization` but never uses it — unused import.

**Overall pattern**: of 8 routes, 5 are pure redirect shims to screens that live outside `app/organization/` (routed via the sidebar/menu system instead); only `setup`, `readiness`, and `employees` contain real UI/API logic under this path.

---

## `app/assessment`

Route tree contains a single screen: `app/assessment/[token]/page.tsx` (279 lines). No `layout.tsx` exists for this segment, and no other nested routes exist under `app/assessment/`.

### `/assessment/[token]` — Candidate Assessment Paper
`C:/Users/MILAN/Downloads/g2gv0/app/assessment/[token]/page.tsx`

**API calls** (all via `assessmentApi` in `lib/careers-api.ts:233`):
- `assessmentApi.show(token)` — loads the paper — `page.tsx:68`, defined `careers-api.ts:234-236`
- `assessmentApi.saveAnswer(token, questionId, {...})` — debounced autosave per question — `page.tsx:108`, defined `careers-api.ts:245-259`
- `assessmentApi.submit(token)` — final submit, burns the link — `page.tsx:148`

**Loading**: explicit skeleton, three pulsing placeholder blocks — `page.tsx:177-182` (`loading` state set `page.tsx:49`, cleared in `.finally()` at `page.tsx:87-89`).

**Error**: dedicated branch, deliberately undifferentiated (unknown/expired/used-up all render the same backend sentence) — `page.tsx:183-190`, design rationale documented in the file's header comment (`page.tsx:27-33`). A second, narrower error surface exists for submit failures only — `formError` rendered at `page.tsx:232-236`, plus a non-blocking inline autosave-failure note at `page.tsx:262-264` (`saveState === 'error'`).

**Empty**: no explicit "no questions" branch — if `paper.questions` is `[]`, `AssessmentPaper` renders with `questions=[]` and falls through to whatever that child component does; the outer page has no guard for it (`page.tsx:161-168, 218-230`). Submit button is however guarded against zero answers: `disabled={submitting || answeredCount === 0}` (`page.tsx:246`).

**Hardcoded/mock data**: none found. All content is server-sourced (`paper.*`); the only literal fallback strings are UI copy defaults (`'Assessment'` at `page.tsx:219`, `'Your assessment'` at `page.tsx:209`), not mock data.

**Dead/duplicated components**: none inside this file. The header comment explicitly calls out that this page intentionally mirrors `app/offer/[token]/page.tsx` "exactly, on purpose" (`page.tsx:25`) — a deliberate structural duplication between the two public, token-based, shell-less pages rather than a shared layout/component, worth flagging as a refactor candidate (extract a shared "public token page" shell) but not dead code.

**Notes**:
- `secondsLeft={null}` is passed to `AssessmentPaper` unconditionally — the countdown UI is permanently disabled from this call site by design (`page.tsx:224-228`), reads as an intentional simplification, but there's no visible way to ever enable it in this route.
- `submit()` re-flushes all pending debounce timers via `persist()` before calling `assessmentApi.submit` (`page.tsx:143-147`) — no loading/skeleton shown during that inner flush, only the outer `submitting` boolean.

---

## `app/careers`

Confirmed no `layout.tsx` exists under `app/careers/` — each page is standalone (uses `use(params)` directly, no shared shell).

### 1. Organisation listing — `[slug]/page.tsx`
- **API**: `careersApi.organisation(slug)` (`page.tsx:121`), typed via `lib/careers-api.ts:117-124`.
- **Loading**: `loading` state, masthead skeleton (`page.tsx:178-185`) + 3 skeleton role cards (`page.tsx:353-361`).
- **Error**: `error` state as a dedicated destructive card (`page.tsx:258-267`).
- **Empty**: two distinct empty states — no postings at all (`page.tsx:363-374`) vs. filters returning nothing (`page.tsx:376-387`).
- **Hardcoded data**: none found; all copy is structural strings, not mock records. `CLOSING_SOON_DAYS=7`, `RECENTLY_POSTED_DAYS=14` (`page.tsx:62-63`) are business constants, not mock data.
- **Dead/duplicate**: none. Filtering is deliberately client-side (comment at `page.tsx:145-147`); no unused imports spotted.

### 2. Job posting + apply form — `[slug]/jobs/[id]/page.tsx`
- **API**: `careersApi.posting(slug, id)` (`page.tsx:52`) to load; `careersApi.apply(slug, postingId, form)` (`page.tsx:332`) to submit, using `lib/careers-api.ts:125` and the `apply` export.
- **Loading**: `loading` → pulse skeleton blocks for header/body (`page.tsx:86-89`); form submit has its own `submitting` state disabling the button (`page.tsx:623,626`).
- **Error**: page-load error via `loadError` as a destructive block (`page.tsx:90-97`); form-level `formError` above fields (`page.tsx:428-435`) plus per-field `errors` mapped from server `CareersError.fieldErrors` (`page.tsx:343-350`).
- **Empty**: no explicit "no posting" empty state beyond the error path (a missing posting surfaces as `loadError`, not a separate empty UI) — acceptable since a missing job is genuinely an error case, not an empty list.
- **Hardcoded data**: none — vocab options (`EMPLOYMENT_TYPES`, `WORK_MODES`, etc.) come from `lib/recruitment-vocabulary.ts`, not inlined literals.
- **Dead/duplicate**: none found; local `Input`/`Field`/`Fact`/`Section` helpers (`page.tsx:214-244, 632-681`) are page-scoped, not duplicates of a shared `ui/` component — could arguably reuse `components/ui/input.tsx` if one exists, but not confirmed duplicate.

### 3. Application tracking — `track/[token]/page.tsx`
- **API**: `careersApi.track(token)` (`page.tsx:56`).
- **Loading**: skeleton card (`page.tsx:78-86`).
- **Error**: dedicated "link no longer works" card with guidance text (`page.tsx:90-102`) — file's own comment (`page.tsx:88-89`) flags this as the one screen needing a strong error state since it's the candidate's only entry point.
- **Empty**: N/A — token either resolves or errors; no list/empty state needed.
- **Hardcoded data**: none; timeline stage copy (`page.tsx:218-224`) is conditional on `data.timeline.current`/`closed`, not literal mock content.
- **Dead/duplicate**: none found.

**Overall**: No `layout.tsx` in this tree — each route is self-contained by design (per doc-comments explaining the public/no-shell rationale). No mock/hardcoded data literals across the three screens; loading/error/empty handling is consistently and explicitly implemented in each.

---

## `app/offer`

Single route: `app/offer/[token]/page.tsx` (274 lines). No `layout.tsx` at any level under `app/offer/`, no nested segments — this is the entire route tree.

**API calls**
- `offerApi.show(token)` on mount (`page.tsx:35-36`) → `GET /offer-response/:token` (`lib/careers-api.ts:183-185`).
- `offerApi.respond(token, choice, note)` on submit (`page.tsx:63`), a raw `fetch` `POST /offer-response/:token` with `{decision, note}` (`lib/careers-api.ts:187-195`).

**Loading**: explicit — `loading` boolean gates a skeleton (two pulsing `bg-muted` blocks), `page.tsx:24,49,78-82`.

**Error**: explicit, two-layered:
- Load failure: `loadError` renders a dedicated "This link cannot be opened" card with icon (`page.tsx:25,45-48,83-88`).
- Submit failure: `formError` renders inline above the choice buttons, deliberately not a toast per the comment at `page.tsx:150` (`page.tsx:30,65-70,151-158`).

**Empty**: no distinct empty-state UI; if `offer` is null with no `loadError` and `loading` false, the component renders nothing (`page.tsx:209`, the `: null` fallback). Not reachable in the normal flow (a failed `show()` always sets `loadError`), so this is dead branch coverage rather than a real gap.

**Hardcoded/mock data**: none found. No literal offer data; all offer fields (`position`, `organisation`, `salary`, `start_date`, `location`, `employment_type`, `expires_at`, `candidate_name`) come from the fetched `OfferResponse`. Copy strings are static UI text, not mock data.

**Dead/duplicated components**: none in this file. `Fact` (`page.tsx:214-235`) and `ChoiceButton` (`page.tsx:237-274`) are small, page-local, each used exactly twice — no duplication. The "already decided" short-circuit (`page.tsx:41-43`, using `offer.already_decided`) reuses the same `done` state and render branch (`page.tsx:123-143`) as a fresh submit, so no duplicated success/decline UI.

**Notable design note** (from the file's own doc comment, `page.tsx:9-19`): this page intentionally mounts no shared shell (`GtgAppShell`/`GtgPageShell`) since it's a public, tokenized, no-login page — worth knowing before assuming a missing shell is a bug.

---

## `app/module`

### Route tree structure
Only three physical routes exist, all client components that render nothing of their own — each delegates entirely to `GtgAppShell`:

- `app/module/[moduleId]/page.tsx:23-29` — `ModuleRootPage`
- `app/module/[moduleId]/[menuId]/page.tsx:6-12` — `ModuleMenuPage`
- `app/module/[moduleId]/[menuId]/[submenuId]/page.tsx:6-12` — `ModulePage`

**Duplication**: `[menuId]/page.tsx` and `[menuId]/[submenuId]/page.tsx` are byte-identical apart from the exported function name (`ModuleMenuPage` vs `ModulePage`) — both are just `<ProtectedLayout><GtgAppShell/></ProtectedLayout>`. No dead code beyond this; the triplication exists only because Next's App Router needs a `page.tsx` at every depth for the URL to resolve (the root file's own comment, `[moduleId]/page.tsx:6-22`, documents that the root variant was missing entirely until recently and every module-level link 404'd).

None of the three route files makes an API call, checks loading/error, or contains literal data — all of that lives one layer down, in `components/shell/gtg-app-shell.tsx`, which every route renders.

### Shared shell behavior (`gtg-app-shell.tsx`)
- **Screen resolution**: `ContentRenderer` (`gtg-app-shell.tsx:142-175`) calls `loadContentRoute(active, pathname)` (`hooks/use-content-map.ts:31-49`), which dynamically `import()`s a per-module content map (`content-map-m0` … `content-map-m7`, `content-map-reports`) keyed by `active.moduleId`, then matches by `accessLink`/`submenuId`/`menuId`. Actual API calls/rendering happen inside whatever component each map resolves to — outside `app/module/` scope, not audited here.
- **Loading**: `route === undefined` renders `<ContentSkeleton/>` (`gtg-app-shell.tsx:160-162, 36-47`); before `active` itself resolves, `<ContentSkeleton/>` also covers that gap (`gtg-app-shell.tsx:517`).
- **Error**: `ContentErrorBoundary` (`gtg-app-shell.tsx:61-118`) shows a "This screen could not be shown" card with Try again / Reload, keyed to reset on route change via `resetKey={pathname}` (line 169) — a documented fix (F-192) for a boundary that used to stay tripped.
- **Empty/unbuilt**: `ComingSoonFallback` (`gtg-app-shell.tsx:120-140`) renders when no component resolves — either a specific message from `COMING_SOON_CONTENT` or a generic "not available yet" message.

### Hardcoded/mock data
- `hooks/use-content-map.ts:51-56` — `COMING_SOON_CONTENT` has exactly one hardcoded entry (menu id `'50'`, Compensation), a literal title/description shown in place of a real screen.
- Module id `'300'` is called out in a comment (`use-content-map.ts:8-11`) as an intentionally-shared synthetic id across two databases — worth flagging as fragile but not mock data per se.

### Note on scope
The route tree itself (`app/module/`) contains no screen-specific logic, so a true per-screen audit (API calls, loading/error handling, mock data per module) requires auditing the nine `content-map-m*`/`content-map-reports` files and their target components — outside this directory.

---

## `app/platform`

The route tree under `app/platform/` contains exactly **one screen**: `organizations/new`. No `layout.tsx` exists at `app/platform/` — the route is wrapped only by the page's own `ProtectedLayout`.

### `/platform/organizations/new` — Create Organisation

**File:** `app/platform/organizations/new/page.tsx:1-38`

Thin shell: `ProtectedLayout` (auth-only gate, no client-side role check by design — see comment at `page.tsx:15-29`) wraps a `Suspense` boundary around a lazily-imported form component (`page.tsx:6-10, 32-36`). Suspense fallback is a bare `<div className="h-screen bg-muted/20" />` (`page.tsx:33`) — a loading placeholder, not a skeleton component.

All real logic lives in `components/domain/platform/create-organization-form.tsx` (592 lines).

**API calls** (`services/platform/organizations.ts:71-79`, via `platformOrganizationsService`):
- `list(context)` → `GET /platform/organizations` — called on mount via `loadExisting` (`create-organization-form.tsx:164-176`, wired in `useEffect` at `182`) to populate the "Already on the platform" sidebar and duplicate-name detection.
- `create(context, input)` → `POST /platform/organizations` — called from `create()` (`create-organization-form.tsx:214-265`) on the final wizard step.

**Loading/error/empty states:**
- No `isLoading` state or skeleton for `loadExisting` — a `grep` for `isLoading|skeleton|loading` returns nothing. If the list call is slow, the sidebar just silently shows "None yet." until data arrives (empty and loading states are indistinguishable) (`create-organization-form.tsx:359-360`).
- `loadExisting` errors are swallowed silently by design (comment: "A roster that will not load is not a reason to block creation") (`create-organization-form.tsx:172-175`) — no user-visible error surfaced for that failure path.
- `create()` has an explicit `busy` state (`143`, passed to `WizardFooter` at `402`) and a banner `Alert` for generic errors (`418-424`), plus per-field errors mapped from the server's 422 response (`242-258`) — this path is well-handled.
- Explicit empty state for the "Already on the platform" list: "None yet." (`359-360`).

**Hardcoded/mock data:** None found. All content is either static copy (labels/help text) or derived from live API responses/form state. No mock arrays or fake data literals.

**Dead/duplicated code:** None apparent within this component. Single-purpose 3-step wizard (`organisation` → `administrator` → `review`, `55-63`) built from shared components (`WizardLayout`, `WizardFooter`, `StepperStep`) rather than duplicating wizard chrome locally. The success/"Done" view (`268-350`) and the wizard steps (`426-588`) are two clearly distinct, non-overlapping render branches gated on `created` state — no duplication between them.

### Notes on scope
`app/platform-services/*` is a separate, much larger sibling tree (scheduler, workflow, event-bus, add-process, fields-configuration, integration) not inside `app/platform/` and therefore outside the literal path requested — flagged in case the intended target was broader than the single-screen `platform/` directory.

---

## `app/platform-services`

### Console — `page.tsx`
No API calls (deliberate — reads compiled `PLATFORM_SERVICES` registry, `page.tsx:19-22,27`). No loading/error states needed since there's no fetch. No hardcoded literals of concern (data is the registry, not inline mocks). Clean, single-purpose.

### `[service]/page.tsx` (dynamic fallback)
No direct API calls itself — delegates to `ServiceDetail`/`ServiceShell` (`page.tsx:26-27,60-62`). Handles the "unknown slug" case explicitly with a friendly not-found panel instead of a 404 (`page.tsx:37-57`) — no loading/error state needed at this layer since it's a synchronous registry lookup (`page.tsx:35`).

| Screen | API calls | Loading/Error/Empty | Mock data | Dead/duplicate |
|---|---|---|---|---|
| Add Process (`add-process/page.tsx`) | `fetchProcesses`, `fetchPlatformRegistry` (`188`), `convertProcedure`, `createProcess`, `publishProcess`, `deleteProcess`, `fetchProcessHistory`, `employeeDirectoryService.list` (`92-93`) | `loading`/`error` state (`178-181`); `PanelError`/`PanelLoading`/`StaleNotice` (`217-219`); empty-list copy (`746-748`) | `EXAMPLE` template text (`61-69`) — intentional placeholder, not mock data disguised as real | Silent-catch pattern on employee fetch (`108-110`) is a deliberate empty-state degrade, documented in comment |
| Event Bus (`event-bus/page.tsx`) | `fetchEventBusSummary`, `fetchEventStream`, `fetchEventTypes`, `fetchConsumers`, `fetchFailures`, `fetchEventCatalogue`, `replayEvent` (imports `41-56`) | Shared `usePanel` hook (`115-155`) gives every tab consistent loading/error/stale handling; explicit "not installed" empty state (`166-173`) | None | Well-factored — one hook reused four times rather than four ad hoc fetch blocks (good, not duplicated) |
| Fields Configuration (`fields-configuration/page.tsx`) | `fetchCustomFields`, `createCustomField`, `updateCustomField`, `deleteCustomField` (`27-37`) | Loading/error/empty complete: `73-97`, `268-270`, "not installed" state (`279-284`), empty-table row (`513-519`) | None — `TYPES` (`42`) is a real fixed enum, not fake records | None |
| Integrations (`integration/page.tsx`) | `fetchIntegrations`, `saveIntegration`, `testIntegration`, `deleteIntegration` (`34-42`) | Loading/error present (`55-79`, `89-90`) | None | No explicit empty-list state if `providers` is `[]`, but unreachable in practice (registry always declares providers) — minor gap, not user-facing risk |
| Scheduler (`scheduler/page.tsx`) | `fetchScheduledTasks`, `runTaskNow`, `saveScheduleOverride` (`26-31`) | Loading/error/stale complete (`138-140`); explicit "ledger not installed" empty state (`169-176`) | None | None |
| What's Coming (`whats-coming/page.tsx`) | None — pure derived view over `PLATFORM_SERVICES`/`AI_CAPABILITIES` registries (`35-36`, `50-69`), by design (per file's own header) to avoid a second stale source of truth | Empty state handled (`84-86`) | None | None |
| Workflow (`workflow/page.tsx`) | `fetchWorkflowPoints`, `fetchPlatformRegistry`, `createWorkflow`, `updateWorkflow`, `deleteWorkflow`, `simulateWorkflow`, `fetchWorkflowHistory` (`27-42`) | Loading/error/stale complete (`266-268`); registry-inconsistency banner (`270-287`) is a notable extra error surface | None | None |

### Dead/duplicate components
None found. `console-parts.tsx` is explicitly scoped local to this section (vs. `components/shared/console-ui.tsx`) with a documented rationale (`console-parts.tsx:4-12`) rather than accidental duplication. `TaskAssignments` in `add-process/page.tsx` is intentionally shared between two call sites (`126-175`) rather than copy-pasted.

**Overall**: consistent loading/error/empty handling across all screens via shared `PanelError`/`PanelLoading`/`StaleNotice`/`RefreshButton` primitives; no fabricated/mock data found — only a legitimate example template string and static enums.

---

## `app/settings`

### `/settings` (route: `app/settings/page.tsx`)

Thin wrapper rendering `<SettingsShell/>` from `components/settings/settings-shell.tsx` inside a `Suspense` (needed because the shell calls `useSearchParams`). No `layout.tsx` exists under `app/settings/`.

`SettingsShell` is a master-detail hub driving 11 lazily-loaded sections (`settings-shell.tsx:21-61`), gated by `useAccount()` and `sectionsForRole` (`lib/settings-sections.ts:198`). Loading/error/empty are all handled explicitly: rail skeleton at `settings-shell.tsx:409-411`, mobile-chip skeleton at `287-289`, pane skeleton (`PaneSkeleton`, `510-518`), and an `account.error` banner with retry at `247-273`. Errors are additionally scoped per-section via a keyed `SectionBoundary` (`354-366`).

Per-section API calls and states:

| Section | API calls | Notes |
|---|---|---|
| Profile (`sections/profile-section.tsx`) | `accountService.updateProfile` (308), photo upload via `apiClient.putForm` (293) | Loading/error/saved states (84, 340-347) |
| Security (`security-section.tsx`) | `accountService.sessions/changePassword/endSession/endOtherSessions/activity` (70,95,116,131,161); two-factor delegated to `two-factor-block.tsx` (`accountService.twoFactorStart/Confirm/RecoveryCodes/Disable`, 105-133) | Skeletons/empty at 457,544,547 |
| Preferences (`preferences-section.tsx`) | `accountService.promotePreferences/forgetDevicePreferences` (93,108) | Comment at line 38 flags **hardcoded locale list** ("the frontend currently hardcodes THREE of them") |
| Notifications (`notifications-section.tsx`) | No direct fetch — reads `account.preferences` only | Has `ErrorState` import (9) but is mostly passive |
| Saved Views (`saved-views-section.tsx`) | Reads `localStorage` via `LISTABLE_KEYS` (4,53), not an API — comment (16-26) explicit this is device-local, not server state | `SectionEmpty` at 144 |
| People & Access (`people-access-section.tsx`) | `apiClient.get('/…pending…')` (154), `apiClient.post` invite (192) | Skeleton/empty at 273-285 |
| Modules (`modules-section.tsx` → `module-configuration.tsx`) | `moduleEnablementService.save` (module-configuration.tsx:228) | Loading/error handled (94,110,276-282) |
| Delivery (`delivery-section.tsx`) | `apiClient.get/put/post` (89,221,250) | Loading/error at 266-287 |
| Organization Defaults (`organization-defaults-section.tsx`) | `organizationSettingsService.get/save` (85,181) | Hardcoded-locale comment again at line 24 |
| Security Policy (`security-policy-section.tsx`) | Same service, `get/save` (107,181) | Comment line 20 notes a since-removed **hardcoded OTP backdoor mobile number** found during this rewrite |
| Audit (`audit-section.tsx`) | `organizationSettingsService.audit` (104) | Skeleton/empty/pagination at 330-368 |
| Roles & Access (`roles-access-section.tsx`) | `apiClient.get('/organization/roles')` (137), `apiClient.put` (169) | Empty-roles handling at 368-439 |
| Coming Soon (`coming-soon-section.tsx`) | None — pure static fallback for unmapped/soon section ids, by design | — |

No dead/duplicate components found; `ModulesSection` intentionally reuses `ModuleConfiguration` (shared with the standalone page below), documented at `modules-section.tsx:14-22`.

### `/settings/module-configuration`

Client route (`app/settings/module-configuration/page.tsx`) lazy-loads `ModuleConfigurationPage` (`components/settings/module-configuration-page.tsx`), which wraps the same `ModuleConfiguration` component. Calls `moduleEnablementService.save` (`module-configuration.tsx:228`). Handles loading/busy/saved states explicitly (`module-configuration-page.tsx:70-93,124-131,153`).

### `/settings/portal-review`

Not a real screen: `page.tsx` is a bare `redirect('/organization/setup')` (line 39). Its 30-line comment documents the 461-line predecessor that was deleted — every metric read from an unwritten `globalThis` Map, cards showed hardcoded "Completed", "Go Live" wrote an unread `localStorage` key, and three Review buttons disabled themselves without reviewing anything. Confirmed dead code, correctly removed rather than left as a duplicate.

---

## `app/profile`

Route tree under `app/profile/` contains a single file — no `layout.tsx`, no nested routes.

### `/profile` — `page.tsx` (full route)

**File:** `g2gv0/app/profile/page.tsx:1-62`

This is not a profile screen. It is a redirect stub — the entire route body is:

```
useEffect(() => { router.replace('/settings?s=profile') }, [router])
return <p className="sr-only">Taking you to your profile.</p>
```
(`page.tsx:51-53`, `page.tsx:61`)

- **API calls:** none. No `services/*` import, no fetch, no data hook (confirmed via read — file has zero network calls).
- **Loading/error/empty states:** none applicable — no data-fetching path to have states for. The only rendered content is an `sr-only` accessibility string (`page.tsx:61`) so screen-reader users aren't left with silence during the one-frame redirect; sighted users see nothing, by design (comment at `page.tsx:55-59` explicitly rejects adding a spinner/"Redirecting…" text as noise).
- **Hardcoded/mock data:** the redirect target string `'/settings?s=profile'` (`page.tsx:52`) is the only literal, and it's a route path, not mock data.
- **Dead/duplicated components:** the file itself is the remnant of a deliberate de-duplication, documented in its own header comment (`page.tsx:6-47`):
  - This page used to be a real 5-card read-only profile view fetching HRMS endpoints directly, duplicating `/settings?s=profile` (which fetches `/account/me` and is the only editable version).
  - The duplication caused a real bug (a user seeing a colleague's data — ambiguous which of the two pages was wrong).
  - Resolution: `/settings?s=profile` became canonical. Personal details, address, job title, and department merged into that screen; bank details moved over as new read-only content (previously unique to `/profile`).
  - Two of the five original cards — **reporting line** and **attendance** — were dropped, not migrated, because they were wired to hardcoded empty arrays and rendered an empty state for every user on every visit (`reporting_manager_id` unset on 0/299 accounts) — correctly judged as dead UI, not worth preserving.
  - The route file itself was kept (rather than deleted) purely to 301-style forward old bookmarks/links via `router.replace` (not `push`, so back-navigation doesn't ping-pong between the two URLs).

**Net assessment:** `app/profile/` has no outstanding audit findings — a one-frame redirect with no data, no states to mishandle, and no live duplication; the duplication it references was already resolved by removing the competing screen. The real profile screen to audit next is `app/settings/page.tsx` (`?s=profile`), which is outside this route tree and wasn't in scope here.

---

## `app/ai`

### Layout (`app/ai/layout.tsx:1-42`)
Wraps every screen in `ProtectedLayout` + `GtgPageShell`. No data fetching, no loading/error states (none needed — pure chrome).

| Screen | API calls | Loading/Error/Empty | Mock data / Dead code |
|---|---|---|---|
| Console (`app/ai/page.tsx:1-175`) | `fetchCapabilities()` (`@/lib/intelligence/ai-capabilities`, line 24) for live per-org record counts; static registry data (`AI_CAPABILITIES`, `SOLUTIONS`) from `@shared/ai-intelligence-core` | Loading: none shown — `live` state starts `{}`, renders `—` per row (`LiveCount`, line 153) until data arrives. Error: swallowed silently (`.catch(() => {})`, line 48) — intentional per comment (35-37), but a persistent API failure is invisible to the user. Empty: implicit via `—`/"Not installed"/"No records" (154-169) | None; no dead code |
| Dynamic capability (`app/ai/[capability]/page.tsx:1-88`) | No API call — pure registry lookup (`getCapabilityBySlug`, line 27) rendered via `CapabilityShell` (which itself fetches live data) | Handles "unknown slug" explicitly (`UnknownCapability`, 62-87) — a data-not-found state, not loading/error. Serves 8 of 12 capabilities; 4 have dedicated static routes below | — |
| CapabilityShell (`_components/CapabilityShell.tsx:1-89`) | Shared frame reused by providers/models/prompts/policies/[capability] | Delegates live-data loading/error to `CapabilityLiveData` (not opened in depth this pass, referenced line 84) | — |
| Conversational AI (`app/ai/conversational-ai/page.tsx:1-388`) | `fetchGroundingContext`, `fetchConversations`, `fetchConversation`, `askAssistant` (`@/lib/intelligence/ai-conversations`, lines 29-36) | Loading: `Loader2` spinner for grounding panel (261); no visible loading state for the conversation list while `askAssistant` is in flight beyond disabled/spinning Ask button (240). Error: `error` state inline (198-210) with link to `/ai/providers` when `needsCredential`. Empty: "Ask something to start" (216-218), "Nothing yet" for conversations (359-361) | None; failed turns rendered per-turn (308-320) by design |
| Evaluation (`app/ai/evaluation/page.tsx:1-738`) | `createEvaluation`, `deleteEvaluation`, `fetchEvaluation`, `fetchEvaluationOptions`, `fetchEvaluations`, `runEvaluation` (`@/lib/intelligence/ai-evaluations`, lines 37-46) | Full loading (`Loader2`, 201-208), error (265-270), empty ("No evaluations yet", 287-289; "Select an evaluation…", 315-317); `notice` success state also handled (258-263) | No mock literals; `window.confirm` used for delete (164) — acceptable but untestable/no custom dialog |
| Models (`app/ai/models/page.tsx:1-21`) | Thin wrapper delegating to `ModelManager` (`_components/ModelManager.tsx`, not read this pass) | — | — |
| Policies (`app/ai/policies/page.tsx:1-670`) | `createAiPolicy`, `fetchAiPolicyOptions`, `fetchAiPolicies`, `retireAiPolicy`, `updateAiPolicy` (`@/lib/intelligence/ai-policies`, lines 8-16) | Loading (244-251), error both pre-load (253-270) and post-load banner (311-316, explicitly added per comment to not lose it after a failed save), empty via "No scope-specific assignments" (541-545) | No hardcoded data; `ScopeTargetField` (621) correctly source-driven, not mock |
| Providers (`app/ai/providers/page.tsx:1-21`) | Thin wrapper delegating to `ConfigurationManager` (`_components/ConfigurationManager.tsx`, not read this pass) | — | — |
| Recommendations (`app/ai/recommendations/page.tsx:1-477`) | `decideRecommendation`, `fetchRecommendationChain`, `fetchRecommendations` (`@/lib/intelligence/ai-recommendations`, lines 34-41) | Loading (166-173, 280-284), error (229-234, 275-279), empty ("Nothing in this view", 238-241; "Select a recommendation…", 271-274) | None |
| Usage & Cost (`app/ai/usage-cost/page.tsx:1-581`) | `fetchUsageEvents`, `fetchUsageOptions`, `fetchUsageSummary`, `saveUsageQuota` (`@/lib/intelligence/ai-usage`, lines 26-33) | Loading (92-98), error (141-146), empty ("No calls in this window", 398) | `WINDOW_LABELS` (39-44) is a legitimate static label map, not mock data |
| Reports (`app/ai/reports/[id]/page.tsx:1-294`) | `getAiReport`, `regenerateAiReport`, `saveAiReport` (`@/lib/intelligence/ai-reports`, line 28) | Loading (247-251), error (243-244), empty ("nothing to show", 286-290) | Report HTML sandboxed in an iframe (274-279) with `allow-same-origin allow-modals` — deliberate XSS mitigation per file-header comment |
| Prompts list/new/[id]/edit (4 files) | List (`prompts/page.tsx:1-27`) and detail pages delegate to shared `_components/TemplateForm.tsx`, `TemplateList.tsx`, `TemplateView.tsx` (not opened in depth) | `new/page.tsx` and `[id]/edit/page.tsx` both call `fetchTemplate` (`@/lib/intelligence/ai-templates`) and share `TemplatePageState`/`TemplatePageShell` for loading/error | No duplication flagged — `TemplateForm` is intentionally shared between Add and Edit (per comments) |

### Dead/duplicate code
None found — no `mock`/`dummy`/`TODO`/`fake` literals anywhere under `app/ai/`. `CapabilityShell` explicitly exists to eliminate duplication across the 4 static-route capabilities (`_components/CapabilityShell.tsx:6-15`).

---

## `app/login`

Only one route: `app/login/page.tsx` (11 lines), a thin wrapper rendering `<LoginPage/>` inside `<Suspense>`. All logic lives in `components/auth/login-page.tsx` (client component), which switches between three "steps" — credentials, 2FA challenge, forgot-password — via local state rather than nested routes.

### Sign-in step (`login-page.tsx:62-459`, form: `login/credential-form.tsx`)
- **API calls**: `useAuth().login(email, password, second, rememberMe)` (`login-page.tsx:254`, from `components/auth/gtg-auth.tsx`); post-login redirect resolution calls `accountService.me(context)` (`login-page.tsx:134`, `services/account/index.ts:219`).
- **Loading**: `isLoading` state (`login-page.tsx:150`) drives `aria-busy` and an in-place `Spinner` swap on the submit button (`credential-form.tsx:129-147`) rather than a skeleton.
- **Error**: `error` state (`login-page.tsx:149`) renders into an `Alert`/`AlertDescription` with `role="alert"` and is focus-moved via `errorRef` (`login-page.tsx:160-164, 387-398`) — a deliberate accessibility touch, not a generic toast.
- **Empty state**: N/A (form, not a list).
- **Hardcoded data**: none functional. Copy strings only ("Welcome back to your workspace.").
- **Dead/duplicated**: comment at `credential-form.tsx:11-27` documents intentionally *removed* dead controls (unwired "Sign in with Google", non-functional language selector) — good sign, not a current issue.

### 2FA challenge step (`login/two-factor-step.tsx`)
- **API call**: same `login()` call, re-invoked with `{code}` or `{recoveryCode}` (`login-page.tsx:246-278, 288-290`); no separate service function — the second factor is just a retry of the same auth call.
- **Loading/Error**: shares `isLoading`/`error` state with the credential step; the same `Alert` region covers both steps (no separate error UI in `two-factor-step.tsx`).
- **Empty**: N/A.
- **Hardcoded**: `LENGTH = 6` digit code (`two-factor-step.tsx:11`) — a real constraint, not mock data.

### Forgot-password step (`login/forgot-password-panel.tsx`)
- **API call**: `passwordService.forgot(forgotEmail.trim())` (`login-page.tsx:187`, `services/auth/password.ts:36-52`).
- **Loading**: `forgotBusy` state (`login-page.tsx:175`), same spinner-swap pattern (`forgot-password-panel.tsx:106-118`).
- **Error handling**: deliberately collapsed — success and failure render the identical neutral `forgotNotice` message (`login-page.tsx:186-197`), by design (anti user-enumeration), so there's no distinct visible "error state" here.
- **Empty**: N/A.

### Decorative panel (`login/spiral-gallery.tsx`)
- No API calls. `SPIRAL_ITEMS` is a hardcoded array of 9 static image paths + labels (`spiral-gallery.tsx:57-67`) — intentional marketing content, not mock data standing in for a real feed.

### Cross-cutting
- No loading/error/empty pattern for the route itself (no route-level `loading.tsx`/`error.tsx` in `app/login/`); all state handling is local to `LoginPage`.
- No dead or duplicated components found among the four `login/*` files; each is single-purpose and only used once.

---

## `app/api`

**Scope mismatch:** this tree contains only backend Route Handlers (`route.ts`/`route.tsx`) — there is no `page.tsx` or `layout.tsx` anywhere under `app/api/`, so "loading/error/empty UI state" doesn't apply. Below is the closest equivalent audit: caller, error handling, and hardcoded/dead/duplicated code, per route.

| Route | Purpose / caller | Findings |
|---|---|---|
| `ai/chat/route.ts` | Backs the AI assistant chat surface; calls `lib/ai` (`generateConversationResponse`/`streamConversationResponse`) | Handles quota (429, line 142) and generic 500 errors. `getErrorMessage`/`parseRetryAfterSeconds`/`isQuotaExceededError` (lines 21-39) are **duplicated verbatim** in `ai/field-edit/route.ts` (101-110, 174-178) |
| `ai/field-edit/route.ts` | Proxy to hp_erp's `/api/ai/generate` for the "sparkle" field-edit assistant | Best-documented route in the tree; distinguishes quota (283) vs. provider-credential (302) vs. generic (316) failures with user-facing copy. No hardcoded data; requires `Authorization` header (243) |
| `conversation/history/route.ts` | GET/DELETE requiring `userId`/`sessionId` query params | 400 if missing (14, 31). Thin pass-through to `history.service` |
| `jobrole-task-description/route.ts` | Job-role task description lookup | **Likely broken, same bug as the removed sibling.** Calls `readLaravelSession()` (line 12), but `lib/laravel-session.ts:52` returns `null` whenever `typeof window === 'undefined'` — true for every Next.js server route. `app/api/jobrole-tasks/REMOVED.md` documents removing an identical route for exactly this reason ("the session is always null... returned 401 before reaching the Laravel call"). This route reads the session the same way and **was not caught by that cleanup** — worth re-checking whether it is ever actually reachable |
| `jobrole-tasks/REMOVED.md` | Postmortem, not code | Documents a deleted route with zero callers and the session bug above. No action needed but corroborates the finding on `jobrole-task-description` |
| `mcp/capabilities`, `mcp/health`, `mcp/queryBusinessData`, `mcp/tools/call` | Thin proxies into `lib/mcp/*` / hp_erp's data-source runtime | Zod-validated bodies, consistent `{error}` JSON on failure. No hardcoded business data; `queryBusinessData`'s `analysisType` enum (lines 8-23) is a fixed catalog, not mock data |
| `screenCandidate/route.ts` | Three-provider fallback chain (DeepSeek → OpenRouter → Gemini) | `GEMINI_ENDPOINT` hardcodes model `gemini-3.6-flash` (line 13) — **verify this model id actually exists**. On JSON-parse failure it falls back to a **hardcoded mock analysis object** (`competency_match: 0, cultural_fit: 'Low', …`, lines 175-183) returned as if it were a real result — a caller can't distinguish a real "Low fit" from a parse failure |
| `talent/poster/route.tsx` + `poster-art.tsx` | Server-rendered PNG via Satori/`next/og` | Not a screen. Explicit `fail()` JSON-error path (line 50) rather than a placeholder image — deliberate design, documented in comments |
| `voice/config/route.ts` | Voice config | Returns a fully static object every call: hardcoded language list (`en-IN`/`hi-IN`/`gu-IN`, lines 15-19) and `mode: "browser"` — **effectively a stub with no backend logic** |
| `voice/synthesize/route.ts` | TTS | Stub: always returns `audioUrl: null` (line 30) — **no real server-side TTS exists** |
| `voice/transcribe/route.ts` | STT | Stub: just echoes back a client-supplied transcript or 501s (line 17-23) — **no real server-side STT exists** |

---

## Cross-cutting observations

- **Redirect-shim pattern**: multiple route trees (`app/organization`, `app/profile`, `app/settings/portal-review`) contain pages that do nothing but redirect to a canonical screen elsewhere, several with header comments documenting a prior real (and buggy) implementation that was deliberately deleted in favor of the redirect. This is a recurring, intentional consolidation pattern across the codebase, not incidental dead code.
- **Mock-data remediation pattern**: `app/dashboard/*`, `app/organization/employees`, `app/organization/setup`, and `app/profile` all carry in-code comments documenting removal of previously hardcoded/mock data (fabricated dashboard numbers, `defaultMockEmployees`, "ABC Technologies Pvt. Ltd." fixture, hardcoded empty reporting-line/attendance arrays) — a real remediation effort with F-numbered tracking, largely completed for the screens audited here.
- **Remaining live gaps**: `app/platform/organizations/new` (silent `loadExisting` error swallow, indistinguishable loading/empty state), `app/ai/page.tsx` console (silently swallowed capability-fetch errors), `app/api/jobrole-task-description/route.ts` (same broken server-side session read as an already-removed sibling route), and `app/api/screenCandidate/route.ts` (hardcoded mock analysis object returned indistinguishably from a real "Low fit" result, plus an unverified `gemini-3.6-flash` model id) are the concrete open items surfaced in this pass.
- **Voice endpoints are stubs**: `app/api/voice/config`, `voice/synthesize`, and `voice/transcribe` are all non-functional placeholders (static config, null audio, echo-or-501) rather than real STT/TTS integrations.
