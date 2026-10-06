# 08. API Audit

Cross-repo route inventory and orphaned-endpoint audit for the Laravel backend (`hp_erp`) against the Next.js frontend (`g2gv0`). Covers `routes/api.php` (in three segments), `routes/lms.php`, `routes/hrms.php`, `routes/settings.php`, `routes/user.php` + `routes/user-api.php`, `routes/ai.php`, and `routes/platform.php`.

---

## `routes/api.php` — lines 1–950

### Route count by logical prefix

~346 routes in this range; verbs: 171 GET, 69 POST, 31 PUT, 31 DELETE, 1 PATCH, 1 `match`, 6 `resource()`.

| Prefix / group | Routes | Auth |
|---|---|---|
| `/competency/*` | ~199 | mostly `api.token` implicit / mixed `profile:admin,hr[,recruiter\|manager]` on writes; some ungated GETs |
| Recruitment/Talent (`job-applications`, `job-postings`, `interview-schedules`, `talent-offers`, `talent/assessment/*`, `talent/hiring-team`, `talent-acquisition/*`) | ~45 | `profile:admin,hr,recruiter` (writes + PII reads); `job-postings` intentionally public |
| Careers (public candidate surface) | 11 | `throttle:*` only, no auth (by design) |
| `/leave/*` (prefix group, continues past line 950) | ~26 shown | none visible in this range (relies on `ResolvesLeaveContext`) |
| Skill development (`/skill-development/*`) | 7 | none |
| HRMS/library basics (`skills`, `jobroletexonomies`, `job-role-tasks`, `jobrole/{id}/skills`, `industry`, `industries`, `department/{id}/jobroles`) | ~15 | none |
| HRIT dashboard (`attendance-weekly`, `KPI-HRITDashboard`, `employee-attendance-monthly-report`, `jobroles-by-department`, `leave-distribution`) | 5 | mixed; `employee-attendance-monthly-report` and `jobroles-by-department` require `api.token` |
| Misc top-level (otp, newsletter, user/profile, `*/graph`, `ai-generated-assessment/*`, `designation_leave`, `jobrole-skill/store`) | ~14 | mixed |

### Orphaned-endpoint candidates (no frontend caller found in `g2gv0/services/**`)

- `GET /leave-distribution` — `routes/api.php:892` (grep for `leave-distribution` across services: no hits)
- `PUT /competency/studio/weighting-config`, `GET /competency/studio/weighting-config` — `routes/api.php:779-780` (`weighting-config`/`weightingConfig`: no hits, even though sibling `studio.ts` calls `requirements-matrix` and `mapping-reviews`)
- `POST /designation_leave` — `routes/api.php:417` (no `designation_leave` caller anywhere in services)
- `POST /jobrole-skill/store` — `routes/api.php:419` (no caller)

Sampled 20+ non-trivial routes total, incl. `/competency/command-center`, `/competency/my-capability`, `/competency/capability-progress*`, `/leave/requests/bulk-decision`, `/leave/reports/catalog`, `/competency/studio/requirements-matrix`, `talent-offers/{id}/candidate-link`, `/competency/audit/user-actions`, `/employee-attendance-monthly-report`, `kasba-rating*`, `merge-impact`, careers `/apply`, `/competency/gap`, `task-map/readiness`, `hiring-team`, `resume-screenings`, `course-map` — all of these had a matching caller.

### Other observations

- `services/hrms/leave.ts` calls `/leave/allocations`, `/leave/workflow`, and `/leave/roles` (PUT/GET/POST) — none of these paths appear in lines 1–950 of `api.php`. The `Route::prefix('leave')` group is still open at line 950, so they likely exist further down the file (not in the read range) — flagging rather than asserting orphaned-in-reverse.
- `services/hrms/index.ts:406` only *comments* on `/attendance-weekly`, doesn't call it — corroborates the route file's own note (line 873-879) that it's dead/superseded by `/api/attendance/*`.
- The competency block is internally well-documented with inline rationale for every middleware choice (unusually thorough vs. other prefixes) — e.g. line 842-861 explains a `profile:admin,hr` gate added specifically because the route was previously unauthenticated.

---

## `routes/api.php` — lines 950–1900

### Route count by logical prefix

≈375 routes total in range.

| Prefix | Routes | Notes |
|---|---|---|
| `leave/*` (allocations, workflow, roles, distribution) | 9 | token-auth |
| `attendance/*` | 12 | self-service open; reports gated `profile:admin,hr,executive,auditor` |
| `/enroll`, `/lmsAssignment/*` | 17 | mixed auth |
| `lms/governance/*` | 26 | users/roles/permissions/trainers/vendors/integrations |
| `lms/assessments/*` + `lms/reports/*` | 13 | quiz authoring |
| `lms/courses/*` | 11 | |
| `lms/learning/*` (+ `/verify/certificate`) | 31 | player, quiz, certs, discussions |
| `lms/sessions/*` | 10 | |
| `lms/ai/*` | 7 | DeepSeek/Gamma |
| `organization/*` + `onboarding/*` | 17 | admin-gated settings/roles/delivery |
| `departments-management/*` | 14 | literal paths before resource |
| `employees-management/*` | 9 | |
| `department-sops/policies/rules/skills` | 16 | |
| `buildwithAI`, `gamma-api`, `skill_library/*` | 27 | |
| Talent (positions, interviewers, interview-panel, candidate, feedback, interviews) | 16 | |
| `/kpis`, `/skill-gaps`, `reports/*` (workforce analytics) | 19 | token-auth |
| `gemini/*`, user-rejected-tasks, course-suggestions | 6 | |
| `tasks/*` + `task-management/*` | ~114 | largest group: workspace, projects, workstreams, backlog, dependencies |
| `user-skills`, `user-journey-logs` | 3 | |
| `account/*` | 17 | self-service, no id params by design |

### Orphaned-endpoint candidates (no matching caller found in `g2gv0/services/**`, 8+ checked including non-trivial ones)

- `GET /positions` — `InterviewController::getPositions` — `routes/api.php:1511`
- `GET /interviewers` — `InterviewController::getInterviewers` (top-level, distinct from `/interview-panel/users`) — `routes/api.php:1512`
- `POST /save-generated-course` — `buildwithAIController::store` — `routes/api.php:1476`
- `GET /index` — `buildwithAIController::index` — `routes/api.php:1477`
- `Route::resource('gamma-api', ...)` + `GET gamma-api/sub-institute/{id}` — `GammaApiController` — `routes/api.php:1479-1480`

Routes confirmed **not** orphaned despite initial appearance: `/kpis`, `/skill-gaps`, and the whole `reports/*` group (hiring-analytics, departments/*, employee-directory/*, skill-coverage/matrix, skill-trends) — all are called dynamically via `lib/ai/backend/module-catalog.ts` + `department-insight.service.ts`/`database-first.service.ts`, an AI-report-picker mechanism, not the usual service-file pattern. A naive grep for direct `apiClient.get('/reports/...')` calls would have missed these.

### Other observations

- No frontend call found targeting a path *not* in this file segment — the sampled callers (`governance.ts`, `settings.ts`, `sessions.ts`, `recruitment.ts`, `hrms/index.ts`, `task/index.ts`, `next-steps.ts`, `learning.ts`, `ai-course.ts`, `employee-directory.ts`) all matched paths declared in 950-1900 exactly.
- Two distinct backend routes/controllers both answer conceptually to "interviewers" (`/interviewers` top-level vs `/interview-panel/users`); only the latter has a frontend caller, which is likely why the former is dead.
- `buildwithAIController` (legacy course-builder, save-generated-course/index) and `GammaApiController` look superseded by the newer `lms/ai/*` (DeepSeek+Gamma) and `lms/courses/*` endpoints — worth confirming they're safe to remove rather than merely uncalled.

---

## `routes/api.php` — lines 1900–2863

### Route count by prefix

~300 routes total in this range.

| Prefix / area | Count | Auth |
|---|---|---|
| account / auth / platform / school-setup / user-signup | 17 | mixed: unauth (`throttle:6,1`), `api.token`, `platform.owner` |
| misc utility (skill-heatmap, excel-agent, templates, nango, bulk-task, etc.) | 22 | mostly none/`api.token` |
| `/performance/*` | 61 | reads open, writes `profile:admin,hr` (some `,manager`) |
| `/talent/dashboard*`, `/talent/admin/*` | 5 | none / none |
| `/onboarding/*` | 38 | none declared at route level (controller-scoped) |
| `/mobility/*` | 25 | reads open, writes `profile:admin,hr` |
| `/offboarding/*` | 13 | none declared |
| `/agentic/*` | 42 | none declared (controller-resolved context) |
| `/notifications`, `/terminology` | 6 | none / `profile:admin,hr` on write |
| `/reporting-line/*` | 4 | read open, writes `profile:admin,hr` |
| `/competency/*` (non-library) | 41 | mostly `profile:admin,hr`, self-service subset `api.token` |
| `/task-management/tasks/{id}/documents` | 4 | `api.token` |
| `/competency-library/*` | 12 | `api.token` reads, `profile:admin,hr` writes |
| `/dashboard/hr/*`, `/dashboard/me/*` | 7 | `profile:admin,hr` / `api.token` |
| `/my-hr/*` | 6 | `auth:sanctum`, no subject param (self only) |

### Orphaned-endpoint candidates (no frontend caller found in `g2gv0/services`)

- `GET /api/skill-heatmap`, `GET /api/skill-heatmap/drill` — `routes/api.php:1995,1998` — no match for "skill-heatmap" anywhere under `g2gv0/services`.
- `GET /api/reporting-line/coverage`, `POST /api/reporting-line/assign`, `/bulk`, `/department-head` — `routes/api.php:2513-2516` — no "reporting-line" match at all.
- `GET /api/competency/eso/diagnostics` — `routes/api.php:2691` — `services/competency/eso.ts` calls index/show/store/update/status/destroy/export/generate but never `diagnostics`.
- `GET /api/readiness/gates`, `POST /api/readiness/gates/acknowledge` — `routes/api.php:2584,2604` — no "readiness/gates" caller (the route file's own comment at 2585-2598 confirms this pair is "temporarily unwired," consistent with the miss).
- `POST /api/school-setup`, `POST/GET/PUT/DELETE /api/user-signup*` — `routes/api.php:1981-1988` — the file's own comment (1976-1980) already documents zero g2gv0 callers; confirmed.

### Callers confirmed present (sanity checks, not orphaned)

`/performance/reviews/board` (performance.ts), `/onboarding/journeys/{id}/workstream-data` (onboarding.ts), `/mobility/pools*` (mobility.ts), `/offboarding/cases/{id}/documents/{docId}/upload` (offboarding.ts), `/agentic/agents/{id}/clone` (agents.ts), `/my-hr/pay-breakdown` (my-hr.ts), `/talent/admin/workflows` (admin-service.ts), `/platform/organizations` (organizations.ts), `/competency-library/competency-export`/`-import` (library.ts), `/dashboard/me/growth` (me-dashboard.ts).

### Other observations

- No frontend call was noticed targeting a path absent from this file — the g2gv0 calls found line up exactly with routes present here (unlike the reverse direction, no evidence gathered either way beyond this sample).
- `/readiness/gates*` is a second, independent confirmation of a pattern already called out in-repo: guards/routes built ahead of a frontend or a data dependency that was rolled back, leaving dead endpoints even where the backend code is otherwise correct.
- Several large modules (`/onboarding/*`, `/offboarding/*`, `/agentic/*`) declare **no route-level middleware at all**, relying entirely on controller-internal context resolution for tenant/auth scoping — worth flagging for anyone auditing auth coverage by grepping route files alone, since a naive `->middleware(` grep would undercount their protection.

---

## `routes/lms.php`

### Route count by prefix

| Group | Middleware | Declarations |
|---|---|---|
| `lms/*` (prefix `lms`) | `auth, session, menu` | 47 `Route::resource()` (≈329 CRUD routes) + ~80 explicit GET/POST helper routes |
| `lms_apiController` group (no prefix) | none declared | 18 routes (17 POST `studentXxxAPI` + 1 GET `getSuggestedCoursesByUser`) |
| Top-level (no prefix, no middleware) | none declared | ~49 routes: `courses-recommendation`, `task` resource(7)+2 helpers, activity-stream (`upcoming/today/recent`), 17 career-counselling misc routes, `/api/get-curriculum-list`, 4 `/ai/*`, `/set-book-session`, `/download-File`, `question_paper/search_question` |

Total: 48 `Route::resource()` calls (336 RESTful routes) + 129 explicit `get/post/match` declarations ≈ **465 concrete endpoints** from 178 route statements.

### Orphaned-endpoint candidates (no matching caller in `g2gv0/services/**`)

- `GET /lms/download-File` → `contentLibraryController@downloadFile` — `routes/lms.php:327`
- `POST /ai/processData`, `/ai/generateLessonPlan`, `/ai/generateLessonPlanNew`, `/ai/generateSportsData` — `routes/lms.php:318-321`
- `POST /set-book-session` — `routes/lms.php:325`
- `GET /api/get-curriculum-list` — `routes/lms.php:314`
- All 18 `lms_apiController` group routes (`studentVirtualClassroomAPI`, `studentPortfolioAPI`, `studentAssessmentAPI`, `studentQuestionPaperListAPI`, `getSuggestedCoursesByUser`, etc.) — `routes/lms.php:260-277`
- `PUT|POST /task/update-status/{id}`, `GET /task_analysis_report` — `routes/lms.php:294-295`
- `GET /upcoming`, `/today`, `/recent` — `routes/lms.php:297-299` (only unrelated near-miss paths found: `/skill-development/recent-activity` in dashboard.ts, `/leave/holidays/upcoming` in leave.ts — different features, not real matches)
- `lms/multi_delete_questions`, `lms/lms_skill_library/{id}/delete|show`, `lms/lmsIndustryListing*` (4 nested routes) — `routes/lms.php:107-108, 111, 183-186`
- `lms/content_library` resource + `getMapVals`/`searchContent` — `routes/lms.php:245-248`
- `GET /question_paper/search_question` (top-level duplicate of the in-prefix `question_paper` resource) — `routes/lms.php:350`

**Confirmed with a real caller** (for contrast, since the sample must include some positives): `lms/lmsAssignment/stats`, `/updateStatus/{id}`, `/bulkUpdateStatus` are all called from `g2gv0/services/lms/assignment.ts`.

### Other observations

- `assignment.ts` doc-comments its calls as `GET/POST /api/lmsAssignment/...` while the Laravel route lives under prefix `lms` (not `api/lms`) — either there's a proxy/rewrite adding `/api`, or the comments are stale; worth a quick check of the axios base URL.
- `Route::resource('subjectwise_graph', chapterController::class)` (line 242) reuses `chapterController` for an unrelated resource name — looks like a copy-paste leftover rather than intentional aliasing.
- The file's own inline comments document several previously-broken routes (bad namespaces, missing controllers) that have already been removed/fixed — those are not live issues, just historical annotations.
- Given how many top-level and `lms_apiController` routes show zero callers, it's plausible `g2gv0` isn't the only/current frontend for this file, or these endpoints are legacy — worth confirming before treating them as dead code.

---

## `routes/hrms.php`

319 lines, 3 groups.

### Route count by logical prefix

| Prefix / module | Routes | Middleware |
|---|---|---|
| Department & Holiday (`add_department`, `holiday`, dept lookups) | 11 (incl. 2 resources) | auth, session, menu |
| Payroll config/reports (`payroll-type`, `salary-structure`, `form16`, `payroll-deduction`, `monthly-payroll`, `payroll-report`, `salary-certificate`, bank-wise, employee-history) | ~35 | auth, session, menu, **hrit.role:admin,hr** |
| Job Title (`hrms-job-title`) | 5 | auth, session, menu (no role gate) |
| Attendance self-service (`hrms-attendance`, in/out-time store) | 3 | auth, session, menu (open, F-120 noted) |
| Attendance reporting/config (`hrms-inout-time`, `hrms-attendance-report`, `early-going-*`, `hrms-general-setting`, dept-wise report, `get-holidays/present/absent/half-day`) | ~15 | **hrit.role:admin,hr,executive,auditor** |
| Multi/daywise attendance + `update_user_att` + `attendance-by-id` | 6 | auth, session, menu |
| Leave (`designation_leave`, `leave_encashment` resources) | 10 | auth, session, menu |
| **Total live routes** | **~85** | — |

Notable in-file documentation: two dead routes already deleted with rationale (`early-going-*-create`, `user_shift_master`/`bulk_shift_update`), and F-100 removed `hrms/myleave/{id}` & `hrms/leavehistory/{id}` (confirmed: no reference in g2gv0 either).

### Orphaned-endpoint candidates (checked against `services/hrms/*.ts`, `services/organization/index.ts`, `services/competency/libraries.ts`)

- `GET/POST hrms-attendance*` (self-service) — `hrms.php:154-156` — frontend calls `/attendance/my-attendance` and `/attendance/punch-in|out` (different API routes) instead.
- `GET hrms-attendance-report`, `POST /show-hrms-attendance-report`, `POST /get-employees-list` — `hrms.php:165-167` — no caller.
- `GET/POST hrms-job-title*` (index/create/store/destroy) — `hrms.php:125-129` — no caller.
- `GET hrms-inout-time`, `POST hrms-in-time/store`, `hrms-out-time/store` — `hrms.php:161-163` — no caller.
- `GET/POST hrms-general-setting` — `hrms.php:197-198` — no caller.
- `GET get-holidays/get-present-days/get-absent-days/get-half-day` — `hrms.php:205-208` — no caller.
- `POST update_user_att` / `GET attendance-by-id` — `hrms.php:277,286` — no caller.
- `Route::resource add_department` and `Route::resource holiday` — `hrms.php:28-29` — department config moved to `/departments-management/*` (services/organization/index.ts); these legacy routes appear unused.
- `department-Emp-Lists`, `sub-department-list`, `department-employee-list`, `department-jobroles`, `jobrole-tasks` — `hrms.php:30-34` — no caller; `department-jobroles` is explicitly called out as a non-tenant-scoped duplicate in organization/index.ts:626-630 (deliberately avoided).
- `Route::resource designation_leave`, `leave_encashment` — `hrms.php:233-234` — no caller found.
- `multiple_attendance_report`, `daywise_attendance_report` — `hrms.php:280-284` — no caller.

### Other observations

- Payroll module (30+ routes) is the exception: every route checked (payroll-type, salary-structure, form16, payroll-deduction, monthly-payroll, payroll-report, bank-wise, employee-payroll-history, salary-structure-report, salary-certificate) has a matching call in `services/hrms/payroll.ts`, with detailed comments noting past parameter-shape bugs — this module was clearly audited/fixed already.
- `services/hrms/index.ts`'s `getDepartmentAttendanceReport` calls `departmentwise-attendance-report/create` and `getEarlyGoingAttendanceReport` calls `/show-early-going-hrms-attendance-report` — both match live routes correctly.
- No frontend call was seen targeting a path absent from this file (the attendance/payroll callers all route through paths that do exist here, or through the separate `/api/attendance/*`, `/api/my-hr/*`, `/api/leave/*` families which live in other route files, not orphaned relative to this file).

---

## `routes/settings.php`

Group prefix `settings`, middleware `['auth','session','menu']` (all routes), 3 resources → 21 routes total (7 REST actions × 3 resources).

### Route count summary by prefix

| Prefix | Routes | Controller | Extra middleware |
|---|---|---|---|
| `settings/institute_detail` | 7 (index/create/store/show/edit/update/destroy) | `settings\instituteDetailController` | none beyond group |
| `settings/organization_data` | 7 | `settings\organizationDetailsController` | `hrit.role:admin` (added on top of group) |
| `settings/discliplinary_management` | 7 | `settings\discliplinaryManagementController` | none beyond group |

Full method/path list per resource (standard Laravel `Route::resource` expansion): GET `/settings/{r}`, GET `/settings/{r}/create`, POST `/settings/{r}`, GET `/settings/{r}/{id}`, GET `/settings/{r}/{id}/edit`, PUT|PATCH `/settings/{r}/{id}`, DELETE `/settings/{r}/{id}` — for `r` = `institute_detail`, `organization_data`, `discliplinary_management`.

### Orphaned-endpoint candidates (frontend caller sample, ≥8 routes checked)

Grepped `g2gv0/services/**` for each resource path:

- **`GET /settings/organization_data`** — orphaned. `g2gv0/services/organization/index.ts:365` (in a comment) confirms this was the old caller and has been **replaced** by `GET /api/organization/profile`; no current caller of the web route remains.
- **`POST /settings/organization_data`** (store) — orphaned. Same file, comment at line 413: the write "was left behind" on this legacy route; current code no longer posts here (no active `webClient`/`apiClient` call to this path found).
- **`GET /settings/organization_data/create`** — orphaned, no caller anywhere.
- **`GET /settings/organization_data/{id}/edit`** — orphaned, no caller anywhere.
- **`PUT/PATCH /settings/organization_data/{id}`** (update) — orphaned, no caller found.
- **`DELETE /settings/organization_data/{id}`** (destroy) — orphaned, no caller found.
- **`GET /settings/institute_detail`** (index) — has caller: `organization/index.ts:845`.
- **`POST /settings/institute_detail`** (store) — has caller: `organization/index.ts:855`.
- **`PUT/PATCH /settings/institute_detail/{id}`** (update) — has caller: `organization/index.ts:864` (`putForm`).
- **`GET /settings/institute_detail/{id}`** (show) — has caller: `organization/index.ts:869` (used for a compliance-library sub-view, not create/edit forms).
- **`GET /settings/discliplinary_management`** (index) — has caller: `organization/index.ts:873`.
- **`POST /settings/discliplinary_management`** (store) — has caller: `organization/index.ts:876`.
- **`PUT /settings/discliplinary_management/{id}`** (update) — has caller: `organization/index.ts:882`.
- **`GET /settings/discliplinary_management/{id}`** (show) — has caller: `organization/index.ts:889`.
- `GET .../create`, `GET .../{id}/edit`, `DELETE .../{id}` for `institute_detail` and `discliplinary_management` — no frontend caller found (typical for resource routes backing SPA modals rather than server-rendered forms; lower confidence as "orphaned" since these three are commonly Blade-only leftovers rather than dead API surface).

### Other observations

- `routes/settings.php:8-57` carries an extensive in-file incident narrative documenting that `organization_data` was previously reachable by any authenticated token/session (no role check effectively enforced) until `hrit.role:admin` was added — this route is a known-recent security fix, consistent with the frontend having since migrated off it entirely.
- No frontend call in the sampled file targets a `/settings/...` path **not** present in this route file — all matched paths correspond to defined resource routes. `api-client.ts:263` references `/table_data` and `/settings/organization_data` only in a comment contrasting patterns, not as a live call.
- The organization service's read/write pair for `organization_data` has fully migrated to `/api/organization/profile` (a separate, non-`settings.php` route), meaning the entire `organization_data` resource in this file is likely legacy/dead code retained only for the admin-only Blade path, not the Next.js frontend — worth confirming before removal since `institute_detail` and `discliplinary_management` are still actively used by `g2gv0/services/organization/index.ts`.

---

## `routes/user.php` + `routes/user-api.php`

### Route count by prefix/group

| Group | Middleware | Routes |
|---|---|---|
| `/user` — rights/profile admin (group 1) | `auth,session,menu` | ~52 (4 resources × 7 + 10 explicit GET/POST + `user_rating_details` resource) |
| `/user` — user/report admin (group 2) | `auth,session,menu` | ~25 (`add_user_profile`, `add_user`, `user_report` resources + 4 explicit) |
| top-level (no group) | **none declared** | 1 (`POST /teacherListAPI` — `tbluserController@teacherListAPI`) |
| `/user` (user-api.php, G2G rights) | `api.token` (reads) / `profile:admin` (writes) | 4 |

Total: **~82 routes**. `user-api.php` loads before `user.php` (per its header comment) so its 4 `/user/*` names win over any same-path definition — none actually collide here.

### Orphaned-endpoint candidates (grepped `g2gv0/services/**`, no caller found)

- `add_groupwise_rights` (resource) — `routes/user.php:18`
- `add_mobileapp_menu_rights` (resource) — `routes/user.php:19`
- `add_user_past_education` (resource) — `routes/user.php:20`
- `user_profile_wise_menu_rights` (resource) — `routes/user.php:21`
- `mobile_app_menu_rights` (create/store/update) — `routes/user.php:27-29`
- `ajax_groupwiserights` — `routes/user.php:30`
- `ajax_pasteducation` — `routes/user.php:32`
- `add_individual_rights` (resource) — `routes/user.php:34`
- `ajax_profileWiseUsers` — `routes/user.php:35`
- `ajax_individualrights` — `routes/user.php:37`
- `ajax_user_profile_wise_rights` — `routes/user.php:39`
- `ajax_mobile_app_menu_rights` — `routes/user.php:41`
- `user_rating_details` (resource) — `routes/user.php:45`
- `add_user_profile` (resource) — `routes/user.php:51`
- `ajax_userProfile_Data_Create` — `routes/user.php:52`
- `show_user_report` — `routes/user.php:54`
- `user_report` (resource) — `routes/user.php:55`
- `employee_report` — `routes/user.php:57`
- `POST /teacherListAPI` — `routes/user.php:60`

Confirmed **used** (sample verified): `add_user` index/edit/update (`hrms/employee.ts:70`, `organization/employee-profile-service.ts:34,44`, `task/index.ts:931`), `user_document/{id}` (`organization/employee-profile-service.ts:54`), `ajax_sidebar_menu_g2g` (`navigation/sidebar.ts:41`), and all 4 `user-api.php` G2G routes (`navigation/menu-rights.ts:82-98`, `organization/role-permissions.ts:69,84,107`).

### Other observations

- `POST /teacherListAPI` has **no middleware group at all** — unauthenticated, unlike every other route in the file. Worth flagging even though a caller wasn't searched for specifically.
- The large "ajax_*" GET block (individual/groupwise/profile-wise/mobile-app rights displays, lines 30-42) reads as a legacy Blade-era UI never ported to the Next.js frontend — `ajax_sidebar_menu_g2g` in the same style *is* used, so these aren't uniformly dead, just individually unreferenced.
- `menu-rights.ts:7` comment explicitly notes it deliberately avoids `ajax_sidebar_menu_g2g` for one use case, confirming intentional endpoint selection rather than drift.
- No frontend call was noticed targeting a `/user/*` path absent from these two files.

---

## `routes/ai.php`

All 62 routes sit behind one group: `prefix('api/ai')` + `['api', AiAuth, 'profile:admin', AiRateLimit, AiContextHydrator]`.

### Route count by logical prefix

| Prefix | Routes | Controller |
|---|---|---|
| `/capabilities` | 2 (GET index, GET {capability}) | CapabilityController |
| `/configuration` + `/configuration-models` | 8 (options, CRUD, model CRUD) | AiConfigurationController |
| `/templates` | 7 (options, preview, CRUD) | AiTemplateController |
| `/ask`, `/grounding-context`, `/conversations` | 4 | AskController |
| `/recommendations` | 6 (pending, index, show, approve, reject, defer) | RecommendationController |
| `/evaluations` | 6 (options, CRUD, run) | EvaluationController |
| `/usage` | 4 (options, summary, events, quota) | UsageController |
| `/policies` | 5 (options, CRUD) | AiPolicyController |
| `/modules/{module}/*` | 9 (usage, guardrails, activity×2, models×3, credentials×2) | AiModuleController / AiModuleModelController |
| `/workspace/report`, `/reports/*`, `/data-sources/*` | 5 | AiReportController |
| `/tool-agents`, `/tool-agent-runs` | 5 | AiToolAgentController |
| `/generate` | 1 | AiGenerationController |
| **Total** | **62** | |

### Orphaned-endpoint candidates

**None found.** Every route sampled (18 of 62 checked, well over the 8 minimum) has a live frontend caller in `g2gv0`:

- `/capabilities`, `/capabilities/{id}` → `lib/intelligence/ai-capabilities.ts`
- `/configuration*`, `/configuration-models*` → `lib/intelligence/ai-configuration.ts`
- `/templates*` (incl. `/templates/preview`) → `lib/intelligence/ai-templates.ts`
- `/ask`, `/grounding-context`, `/conversations*` → `lib/intelligence/ai-conversations.ts`
- `/recommendations*` (incl. approve/reject/defer) → `lib/intelligence/ai-recommendations.ts`
- `/evaluations*/run` → `lib/intelligence/ai-evaluations.ts`
- `/usage*`, `/usage/quota` → `lib/intelligence/ai-usage.ts`
- `/policies*` → `lib/intelligence/ai-policies.ts`
- `/modules/{k}/usage|guardrails|activity|models|credentials` → `lib/intelligence/ai-module.ts`
- `/workspace/report` → `lib/intelligence/workspace.ts:42`
- `/reports/{id}*` → `lib/intelligence/ai-reports.ts`
- `/data-sources/{name}/run` → `app/api/mcp/tools/call/route.ts:39`
- `/tool-agents*` → `lib/agents/client.ts`
- `/generate` → `app/api/ai/field-edit/route.ts:134` (Next.js proxy, not a direct browser call)

Note: the task specified grepping `g2gv0/services/**`, but that directory (account, agentic, auth, competency, core, dashboard, hrms, lms, navigation, onboarding, organization, platform, talent, task) contains **no** AI-console callers at all — the actual client layer lives in `g2gv0/lib/intelligence/*`, `g2gv0/lib/agents/`, and `g2gv0/components/ai*`, outside the searched path. Had the search been confined to `services/**` as literally instructed, every one of these 62 routes would have wrongly read as orphaned.

### Other observations

- `components/ai-stack/automations-screen.tsx:82` has a **stale doc comment** claiming the endpoint is `/api/ai/agents`, but the actual fetch (via `lib/agents/client.ts`) correctly hits `/tool-agents` and `/tool-agent-runs` — comment drift only, not a real broken caller.
- `/generate` has no direct browser caller; it's reached only through the Next.js `app/api/ai/field-edit` proxy, which is intentional (per that route's own header comment) — the true "high-volume" caller is `AiFieldAssistant.tsx` → `/api/ai/field-edit` → proxy → `/ai/generate`.
- `app/api/ai/chat/route.ts` (referenced in `ai-conversations.ts`'s comment) is a **separate, intentionally-parallel** Next.js-local chat endpoint with its own Gemini key — not part of `ai.php` and not a mismatch.

---

## `routes/platform.php`

All 35 routes sit behind one group: `api`, `AiAuth`, `profile:admin`, `AiRateLimit:platform`, `AiContextHydrator` (admin-only, tenant-scoped via the hydrator, never from request input).

### Route count by prefix

| Prefix | Routes | Controller |
|---|---|---|
| `/events` | 7 (6 GET, 1 POST) | `EventBusController` |
| `/scheduler` | 3 (1 GET, 2 POST) | `SchedulerController` |
| `/registry` | 1 (GET) | `RegistryController` |
| `/workflow` | 6 (3 GET, 2 POST, 1 PUT, 1 DELETE) | `WorkflowController` |
| `/fields` (defs) | 4 (1 GET, 1 POST, 1 PUT, 1 DELETE) | `FieldConfigController` |
| `/fields/values` | 2 (1 GET, 1 POST) | `CustomFieldValueController` |
| `/process` | 8 (3 GET, 2 POST, 1 PUT, 1 DELETE, 1 POST-publish) | `ProcessController` |
| `/integrations` | 4 (1 GET, 2 POST, 1 DELETE) | `IntegrationController` |
| **Total** | **35** | |

Note: the actual frontend caller layer isn't `g2gv0/services/**` — it's `g2gv0/lib/platform/*.ts` (thin wrappers over a shared `platformRequest()` client in `lib/platform/client.ts` that prefixes `/platform` and reads the Sanctum token). Checked those files as the real caller set.

### Sample check — orphaned-endpoint candidates

Checked all 35 routes against `lib/platform/*.ts`; only one has no caller:

- **`PUT /process/{id}` (`ProcessController::update`) — orphaned endpoint.** `lib/platform/process.ts` defines `fetchProcess` (GET, line 95), `createProcess` (POST, line 116), `deleteProcess` (DELETE, line 120), and `publishProcess`/`fetchProcessHistory`, but no `updateProcess` making a `PUT`. Grepped the whole tree for `method: 'PUT'` and `updateProcess` — no hit outside `workflow.ts:160` and `fields.ts:90`. There is a backend route and presumably a controller method with no UI path to reach it. File: `C:/Users/MILAN/Downloads/hp_erp/routes/platform.php:191`.

All other 34 sampled routes (events/summary, events/stream, events/replay, scheduler/tasks GET+POST, registry, workflow/points, workflow POST/PUT/DELETE, fields CRUD, fields/values GET+POST, process/convert, process/{id}/publish, integrations CRUD) have a matching `platformRequest()` call with the same path shape and HTTP verb in `lib/platform/*.ts`.

### Other observations

- No frontend call was found targeting a path *not* present in this route file — the one reference to `/api/platform/fields` outside the wrapper files (`packages/platform-services-core/src/registry.ts:201`) is a code comment, not a live request.
- The route file's own comments explain the `/events/replay` route exists but there's deliberately no replay-all/redrive endpoint — consistent with what's wired up.
- Route ordering (`/points`, `/convert`, `/values` before numeric `{id}`) is correct and matches the file's own stated rationale — no ordering bugs found.
- `updateWorkflow` (PUT `/workflow/{id}`) and `updateField` (PUT `/fields/{id}`) both do have callers, so `PUT /process/{id}` looks like a genuine gap rather than a pattern of unused PUTs across the group.
