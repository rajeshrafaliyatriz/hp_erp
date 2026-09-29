# 03. Module Inventory

This section catalogues each audited module's frontend files, backend controllers/routes, the DB3 tables it touches, the contract-match verdict from reading both sides of real calls, and an overall implementation status.

---

## HRMS (Attendance / Leave / Onboarding)

| Module | Frontend files | Backend controllers/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| HRMS (attendance/leave/onboarding) | `services/hrms/leave.ts`, `services/hrms/index.ts`, `services/hrms/my-hr.ts`, `services/onboarding/next-steps.ts` | `Api\Leave\{LeaveDashboardController,LeaveRequestApiController,LeaveAllocationApiController,...}`, `Api\HRITDashboard\{AttendanceApiController,AttendanceDashboardApiController}`, `Api\AttendanceTrackingApiController`, `Api\MyHrController`, `Api\Onboarding\NextStepsController` — all registered in `routes/api.php` (leave group at line 903, attendance group at line 988, my-hr group at line 2836, onboarding at 1267‑1269) | `hrms_emp_leaves` (41 rows), `hrms_leave_types` (12), `hrms_attendances` (1263), `hrms_holidays` (14), `hrms_leave_allocation` (28), `hrms_leave_workflow_settings` (3), `hrms_leave_role_permissions` (27), `tbluser` (299) — all **[DB3 / live / 128.199.17.97]** | Match | Implemented |

Five real frontend→backend calls were verified by reading both sides:

1. `leaveService.getDashboard` (`services/hrms/leave.ts:494-498`, `GET /leave/dashboard`) matches `Route::get('/dashboard', [LeaveDashboardController::class,'index'])` (`routes/api.php:905`).
2. `leaveService.applyLeave` (`leave.ts:568-579`, `POST /leave/requests`) matches `LeaveRequestApiController@store` (`routes/api.php:917`), which writes into `hrms_emp_leaves` (`LeaveRequestApiController.php:444`).
3. `hrmsService.getEmployeeMonthlyAttendance` (`services/hrms/index.ts:435-443`, `GET /employee-attendance-monthly-report`) matches `AttendanceApiController@employeeMonthlyReport`, token-gated (`routes/api.php:888`).
4. `hrmsService.getAttendanceKpis` (`index.ts:404-405`, `GET /attendance/kpi`) matches `AttendanceDashboardApiController@kpi` (`routes/api.php:1042`).
5. `nextStepsService.get` (`services/onboarding/next-steps.ts:52-54`, `GET /onboarding/next-steps`) matches `NextStepsController@index` (`routes/api.php:1267`).

Payload/response shapes line up in every case (e.g. leave decision/bulk-decision, holidays, weekdays, workflow, roles, my-hr summary/payslips/pay-breakdown all have matching routes and controller methods; no frontend call in these files points at a missing or wrong-method route). Distinct onboarding-status controllers exist for a separate talent-onboarding flow (`app/Http/Controllers/Api/Onboarding/*`, tables `talent_onboarding_*`) but the frontend's only onboarding caller in scope (`next-steps.ts`) targets the HRMS-adjacent `onboarding/next-steps` endpoint, which is confirmed live. All queried DB3 tables exist with non-zero rows; schema `DESCRIBE` output for `hrms_emp_leaves`, `hrms_attendances`, `hrms_leave_allocation` is saved at `Docs/cross-repo-audit/_evidence/module-hrms-db3.txt`.

DB3 is a separate, differently-migrated host from production's default `DB_*` connection — these facts describe DB3 only.

---

## Talent Management

| Module | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Talent Management | `g2gv0/services/talent/{recruitment,onboarding,hiring-team,dashboard}.ts` | `talent_jobpostingcontroller`, `talent_jobapplicationcontroller`, `TalentOfferController`, `HiringTeamController`, `TalentDashboardController`, `V2OnboardingJourneyController` + `routes/api.php` | `talent_job_postings` (133), `talent_job_applications` (281), `talent_offers` (71), `talent_team_members` (6), `talent_onboarding_journeys` (1), `talent_offer_acceptances` (0) — all **[DB3 / live / 128.199.17.97]** | Match on verified calls | Implemented |

Verified 5 real frontend→backend pairs by reading both sides:

1. `recruitmentService.getJobs()` GET `/job-postings` → `Route::resource('job-postings', talent_jobpostingcontroller::class)` (`routes/api.php:319`), returns paginated envelope matching `TalentListResponse<JobPostingApi>`.
2. `acceptOffer()` POST `/talent-offers/{id}/accept` (`recruitment.ts:156-161`) → `TalentOfferController::accept` (`routes/api.php:369`; `TalentOfferController.php:498-536`) — response shape `{status,message,data:{offer_id,employee_id,created,invite_sent,invite_error}}` matches the frontend type exactly.
3. `hiringTeamService.list()` GET `/talent/hiring-team` → `HiringTeamController::index` (`routes/api.php:350`), backed by `talent_team_members` table (6 rows, has `sub_institute_id`/`user_id`/`role`/`active` columns matching payload).
4. `talentDashboardService.getDashboard()` GET `/talent/dashboard` → `TalentDashboardController::index` (`routes/api.php:2171`), which itself queries `talent_job_postings`, `talent_job_applications`, `talent_onboarding_journeys`, `s_performance_reviews`, `s_mobility_*`, `talent_offboarding_cases`.
5. `onboardingService.getOverview()` GET `/onboarding/overview` → `OnboardingOverviewController::index` (`routes/api.php:2235`).

One caveat found in comments (not independently re-verified against code): `services/talent/index.ts` is flagged by the codebase's own doc-comment (`onboarding.ts:6-8`) as containing "phantom" `/onboarding-tasks` and `/candidates` calls pointing at routes that do not exist — that file was not deep-audited here since `onboarding.ts` supersedes it for this screen.

`talent_offers.status` enum (`draft/sent/rejected/expired`) has no `accepted` value by design — acceptance state lives in the separate `talent_offer_acceptances` table (currently 0 rows, **[DB3 / live / 128.199.17.97]**, table exists per code path).

Raw tinker evidence saved to `hp_erp/Docs/cross-repo-audit/_evidence/module-talent-db3.txt`.

---

## Payroll

| Payroll | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Payroll | `services/hrms/payroll.ts`, `hooks/use-payroll*.ts`, `components/domain/hrms/hrit/payroll-management/**` | `app/Http/Controllers/Payroll/PayrollController.php`, routes in `routes/hrms.php:70-228` | `payroll_types` (13 rows), `employee_salary_structures` (10 rows), `employee_monthly_salary_data` (6 rows), `hrms_salary_certificate` (0 rows) **[DB3 / live / 128.199.17.97]** | Match | Implemented |

All five spot-checked frontend calls have a route+method+controller match:

- `getMonthlyPayroll` GETs `/monthly-payroll/create` (`payroll.ts:686` → `PayrollController@monthlyPayrollCreate`, `routes/hrms.php:215`).
- `saveMonthlyPayroll` POSTs `/monthly-payroll-store` (`payroll.ts:730` → `monthlyPayrollStore`, `routes/hrms.php:216`).
- `getPayrollRegister` POSTs `/payroll-report` (`payroll.ts:915` → `payrollReport`, `routes/hrms.php:110`).
- `getMonthLock`/`setMonthLock` use `Route::match(['get','post'], '/monthly-payroll-lock', ...)` (`routes/hrms.php:221-222`) matching both the GET read (`payroll.ts:757`) and POST write (`payroll.ts:775`).
- `getSalaryStructure`/`saveSalaryStructure` GET/POST `/employee-salary-structure` and `/employee-salary-structure/store` (`payroll.ts:550,604` → `employeeSalaryStructure`/`employeeSalaryStructureStore`, `routes/hrms.php:79-82`).

Payload shapes line up: e.g. `saveSalaryStructure`'s `emp[id][payrollTypeId][0..3]` FormData keys match the controller's `foreach ($request->emp as ...)` parsing at `PayrollController.php:485-546`, and `getPayrollRegister`'s single-year param matches the controller's `where('year', $searchedYear)` rather than the "2025-2026" pair the UI otherwise offers (frontend doc comment at `payroll.ts:901-910` explicitly documents this trap).

DB3 confirms all three core tables exist with live rows (`payroll_types`=13, `employee_salary_structures`=10, `employee_monthly_salary_data`=6) except `hrms_salary_certificate`=0, consistent with the F-110 comment block in `PayrollController.php:1159-1192` describing that flow as historically unreachable (now guarded). Raw tinker output saved to `C:\Users\MILAN\Downloads\hp_erp\Docs\cross-repo-audit\_evidence\module-payroll-db3.txt`.

---

## LMS / Course Enrollment

| Module | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| LMS / Course Enrollment | `g2gv0/services/lms/dashboard.ts` (`lmsDashboardService`) | `App\Http\Controllers\lms_course_enroll\LmsCourseEnrollController` via `routes/api.php` (NOT `routes/lms.php`, which is the `auth/session/menu` web-route file for the authoring UI) | `lms_course_enroll` (1500 rows), `sub_std_map` (108), `lms_course_settings` (8), `lms_course_prerequisites` (1) — all **[DB3 / live / 128.199.17.97]** | 5/5 checked calls match method + route + payload/response shape | Implemented |

Details (file:line):

- `dashboard.ts:227-228` `getEnrolledCourses()` → `GET /enrolled_courses` → `routes/api.php:1052` → `LmsCourseEnrollController@index` (`LmsCourseEnrollController.php:47-125`). Index joins `lms_course_enroll` + `sub_std_map` + `hrms_departments`, returns `{status, data:[...]}` matching the `EnrolledCourse[]` shape (`enrollment_id`, `enrollment_status`, `standard_name`, etc.) exactly.
- `dashboard.ts:234-242` `getAvailableCourses()` → `GET /available_courses` → `routes/api.php:1053` → `@available` (`LmsCourseEnrollController.php:137-217`), returns `is_enrolled` flags matching `AvailableCourse`.
- `dashboard.ts:245-249` `enroll()` → `POST /enroll` → `routes/api.php:1357` → `@store` (`LmsCourseEnrollController.php:391-496`). Response is a bare `{message, data, requires_approval}` (no `status` flag) — the frontend's `EnrollmentMutationResponse` type at `dashboard.ts:103-111` deliberately omits `status` and documents this asymmetry; confirmed correct.
- `dashboard.ts:256-257` `unenroll()` → `DELETE /enroll/{courseId}` → `routes/api.php:1359` → `@destroy` (`LmsCourseEnrollController.php:585-652`), which looks the row up by `course_id` (not enrollment id) — matches the frontend comment calling out that asymmetry.
- `dashboard.ts:264-272` `updateEnrollment()` → `PUT /enroll/{enrollmentId}` → `routes/api.php:1358` → `@update` (`LmsCourseEnrollController.php:504-582`), scoped by `id`+`user_id`+tenant — matches.

`sub_std_map` is confirmed as the course table (id/display_name/subject_category/standard_id), `lms_course_settings` and `lms_course_prerequisites` back the eligibility gate in `checkEnrolmentEligibility()` (`LmsCourseEnrollController.php:235-348`), all present with non-zero rows on **[DB3 / live / 128.199.17.97]**. Raw tinker stdout saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-lms-db3.txt`.

---

## Skill / Competency

| Skill / Competency | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Skill / Competency | `services/competency/{library.ts,libraries.ts,skill-detail.ts}` | `CompetencyLibraryCrudController` (`routes/api.php:2744-2771`), `skillLibraryController` (`routes/api.php:1485-1510`) | `competency` (240 rows), `competency_kasba_item` (312), `s_competency_frameworks` (35), `s_users_skills` (4466), `master_skills` (5640) — all **[DB3 / live / 128.199.17.97]** | Match | Implemented |

Verified 5 real calls, both sides read:

1. `competencyLibraryService.list` → `GET /competency-library/competency-list` → `CompetencyLibraryCrudController::index` (`routes/api.php:2745`), reads `competency`+`competency_kasba_item` — matches `CompetencyLibraryListResponse`.
2. `.create`/`.update` → `POST/PUT /competency-library/competency[/{id}]` → `store`/`update` (`routes/api.php:2763-2764`; `CompetencyLibraryCrudController.php:257-385`), validated payload shape (`name`, `items[].kasba_type/item_id/item_label/weight`, `levels[].level/descriptor/indicators`) matches `CompetencyLibraryPayload` in `library.ts:255-299` field-for-field, including the `items`+`levels` combined-transaction write at `CompetencyLibraryCrudController.php:335-371`.
3. `.getLevels` → `GET /competency-library/competency/{id}/levels` (`routes/api.php:2770`, method `levels`).
4. `skillDetailService.get` → `GET /skill_library/{id}/edit` → `skillLibraryController::edit` (`skillLibraryController.php:549`), which returns `editData`, `userJobroleData`, `userproficiency_levelData`, `userAttitudeData/BehaviourData/KnowledgeData/abilityData`, `userApplicationData`, `userViewKnowledge/Ability/Application`, `skillName` — matches `SkillDetailResponse` in `skill-detail.ts:70-84` exactly.
5. `skillDetailService.kasaUsage` / `competencyLibrariesService.taxonomy/workFunctions/skillTaxonomyTree` → `GET /competency/library/kasa/{type}/{id}/usage` and sibling `/competency/library/*` routes (`routes/api.php:500-570`) → `CompetencyLibraryController` methods, path-for-path matching `libraries.ts:77-465`.

Model table names confirmed by reading `app/Models/libraries/userSkills.php:13` (`s_users_skills`) and `skillLibraryModel.php:15` (`master_skills`); `competency`/`competency_kasba_item`/`s_competency_frameworks` confirmed via direct `DB::table()` calls in the controller (`CompetencyLibraryCrudController.php:304,309,336,354`).

Note: the backend `skill-heatmap` route group (`routes/api.php:1993-1998`, `SkillHeatmapController`) is not called by any `services/competency/*` file — only referenced in an unrelated AI module catalog — so it is out of scope for this module's frontend-backend contract, not a defect.

All five tables exist and are populated on **[DB3 / live / 128.199.17.97]**; DESCRIBE output for `competency`, `competency_kasba_item`, `s_users_skills` matches the TS field names used above (`approve_status` enum, `kasba_type` enum, `title`/`category`/`sub_category`). Raw tinker output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-competency-db3.txt`.

---

## Career Journey

| Career Journey | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Career Journey | `g2gv0/lib/careers-api.ts:117-196` (`careersApi`, `offerApi`), consumed by `app/careers/[slug]/page.tsx:120`, `app/careers/[slug]/jobs/[id]/page.tsx:51,332`, `app/careers/track/[token]/page.tsx:55`, `app/offer/[token]/page.tsx:35,63` | `talent/CareersController.php` (organisation, posting, apply, track) + `talent/OfferResponseController.php` (show, respond), wired at `routes/api.php:203-204,230,233,249,252` | `talent_job_applications`, `talent_candidates`, `talent_job_postings`, `talent_offers`, `talent_offer_acceptances`, `talent_candidate_assessments`, `institute_detail`, `hrms_departments` — all **[DB3 / live / 128.199.17.97]** | Match | Implemented |

All 5 frontend calls checked line up exactly with backend routes/methods:

- `GET /careers/{slug}` → `CareersController::organisation` (`careers-api.ts:118-122` ↔ `api.php:203`).
- `POST /careers/{slug}/postings/{id}/apply` multipart → `::apply` (`careers-api.ts:134-138` ↔ `api.php:233`, `CareersController.php:124-279`) returns `{application_id, track_url, track_expires, emailed}` matching the frontend's expected shape exactly.
- `GET /careers/track/{token}` → `::track` (`careers-api.ts:157-159` ↔ `CareersController.php:355-460`) returns the same narrowed `ApplicationTracking` fields the TS type declares (candidate/organisation/application/timeline/assessment/offer), deliberately omitting internal status and assessment scores per the controller's own doc comment.
- `GET`/`POST /offer-response/{token}` (`careers-api.ts:183-195` ↔ `OfferResponseController.php:42-149`) also match field-for-field, including `already_decided`.

One non-bug wrinkle worth noting: `talent_offers.status` is `enum('draft','sent','rejected','expired')` (**[DB3 / live / 128.199.17.97]**, DESCRIBE), while the accept/declined "decision" the frontend reads comes from a separate `talent_offer_acceptances.decision` column (pending/accepted/declined) confirmed in `app/Services/Talent/OfferLinkService.php:64-78` — correct design, not a mismatch.

All 8 candidate tables exist on DB3/live with real or zero row counts (`talent_job_applications`=281, `talent_candidates`=216, `talent_job_postings`=133, `talent_offers`=71, `institute_detail`=5, `hrms_departments`=1236, `talent_candidate_assessments`=0, `talent_offer_acceptances`=0 — the latter two empty likely because no assessment/offer-response activity has occurred yet on this host, not because the feature is broken). Raw evidence saved to `hp_erp/Docs/cross-repo-audit/_evidence/module-career-db3.txt`.

---

## Assessment / AI

| Assessment / AI | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Assessment / AI | `app/assessment/[token]/page.tsx` (→ `lib/careers-api.ts` `assessmentApi`); `app/ai/*` console pages (→ `lib/intelligence/ai-capabilities.ts`, `ai-evaluations.ts`, `ai-configuration.ts`, etc.) | `routes/api.php:266-272` → `talent/CandidateAssessmentResponseController@show/saveAnswer/submit`; `routes/ai.php` → `AI/CapabilityController`, `AI/EvaluationController` et al. | `competency_assessment_test`, `competency_assessment_question`, `competency_assessment_response`, `talent_candidate_assessments`, `competency_assessment_attempt`, `talent_job_applications`, `institute_detail`, `ai_models` (all exist); `ai_evaluations`, `ai_evaluation_cases` **NOT FOUND** — all checks **[DB3 / live / 128.199.17.97]** | Candidate-assessment contract matches exactly; AI-console route/controller contract matches but two of its tables are absent on DB3/live | Partial |

Two independent surfaces live under this module.

**(1) Candidate assessment.** `g2gv0/app/assessment/[token]/page.tsx:67-159` calls `assessmentApi.show/saveAnswer/submit` (`g2gv0/lib/careers-api.ts:234-274`) against `GET/POST /candidate-assessment/{token}`, `/answer`, `/submit`. These match `hp_erp/routes/api.php:266-272` exactly (64-char token regex, correct HTTP verbs) into `app/Http/Controllers/talent/CandidateAssessmentResponseController.php`, whose `show` (line 57), `saveAnswer` (132), `submit` (211) query `competency_assessment_test`, `competency_assessment_question`, `competency_assessment_response`, `talent_candidate_assessments`, `competency_assessment_attempt`, `talent_job_applications`, `institute_detail` — all confirmed present on **[DB3/live/128.199.17.97]** (test=1, question=8 rows; response/candidate-assessments/attempt=0 rows — schema seeded but never yet sat). The 410 "one uniform reason" contract and the answer-key exclusion (`correct_option`/`model_answer` in `DESCRIBE competency_assessment_question`, deliberately not selected) both hold as documented.

**(2) AI & Intelligence console.** `g2gv0/app/ai/*` pages call `lib/intelligence/*.ts` clients (`fetchCapabilities` → `/capabilities`, `fetchEvaluations`/`createEvaluation`/`runEvaluation`/`deleteEvaluation` → `/evaluations*`) which match `hp_erp/routes/ai.php` route-for-route and verb-for-verb into `AI/CapabilityController` and `AI/EvaluationController` (`AI/EvaluationController.php:50` options, `81` index, `102` show, `149` store, `234` run, `256` destroy). However `EvaluationController` reads/writes `ai_evaluations` and `ai_evaluation_cases` (lines 86, 113, 179, 194, 272-273, 289), and both tables are **missing** on **[DB3/live/128.199.17.97]** (`SQLSTATE[42S02]: Base table or view not found`). Every AI Evaluation route is therefore contract-correct in code but would 500 at runtime against this specific host; `ai_models` exists but is empty (0 rows), so the model catalogue the config UI reads is currently blank there.

Separately, `app/Http/Controllers/ai_generated_assessment/*` and `build_with_AI/buildwithAIController` are registered in `routes/api.php` (lines 284-288, 1476-1477) but no file under `g2gv0/` references `/ai-generated-assessment` or `/save-generated-course` — dead backend surface as far as this frontend is concerned, not part of the verified contract.

Raw DB3 evidence saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-assessment-db3.txt`.

---

## Custom Module Builder

| Custom Module Builder | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Custom Module Builder | None found — `app/module/[moduleId]/**/page.tsx` (`g2gv0/app/module/[moduleId]/page.tsx:1-29`, `[menuId]/page.tsx:1-13`, `[submenuId]/page.tsx:1-13`) are generic route shells that just render `<GtgAppShell/>`; zero references to `custom-module`/`custom_module`/`CustomModule` anywhere under g2gv0 (grep across `*.ts`/`*.tsx`, 0 hits) | `App\Http\Controllers\custom_module\CustomModuleController` (`hp_erp/app/Http/Controllers/custom_module/CustomModuleController.php`), 15 routes registered only in `routes/web.php:216-237` under `Route::group(['prefix'=>'custom-module'], ...)` with **no middleware array at all** (no `auth`/`session`/`menu`, unlike every sibling group) | `custom_module_tables` (1 row), `custom_module_table_columns` (1 row) — both confirmed to exist via `DESCRIBE` + `count()` — **[DB3 / live / 128.199.17.97]**; dynamically-created per-module tables (`Z_*`) are also touched but none exist yet given only 1 config row | No contract to check — there is no frontend caller to compare against | **Broken** (unreachable from the SPA) |

Detail: The frontend has no Custom Module Builder feature at all — `app/module/[moduleId]` (g2gv0) is a bare shell used for other modules resolved by sidebar menu data, and no service file under g2gv0 issues any request containing "custom-module" or "custom_module" (confirmed by repo-wide grep, 0 matches).

The backend feature is real and fairly deep: `CustomModuleController` (`hp_erp/app/Http/Controllers/custom_module/CustomModuleController.php:66-806`) implements table-builder CRUD (`tables`, `tableCreate`, `tableStore`, `tableDelete`, `tableColumnCreate/Store/Delete`, `createDBTable`, `crudIndex/Create/Store`, `viewDelete`) but every route is registered exclusively in `routes/web.php:216-237` as session/Blade-style GET/POST/DELETE endpoints (`custom_module_table.create`, `custom_module_crud.store`, etc.) with no JSON/API namespace and, notably, no `auth`/`session`/`menu` middleware on the whole `custom-module` prefix group (contrast with the `school_setup` group two lines above, which does carry `['auth','session','menu']`) — `tableDelete()` compensates by resolving tenant from token/session internally (`CustomModuleController.php:290-301`), but the rest of the endpoints (`tables`, `tableStore`, `crudStore`, etc.) still read `$request->session()->get('sub_institute_id')` directly with no equivalent guard.

Since there is no Next.js consumer, there is no contract-match question to adjudicate — the module simply was never ported to the SPA, so every one of the 15 web.php routes is dead code from the frontend's perspective.

DB3 confirms the backing tables exist with matching schemas to the controller's field usage (`table_name`, `module_name`, `module_type`, `display_under`, `helper_function`, `syear_wise` on `custom_module_tables`; `column_name`, `type`, `length`, `field_type`, `field_value`, `table_id` on `custom_module_table_columns`) but hold only 1 row each **[DB3 / live / 128.199.17.97]** — the feature is essentially unused in this environment even on the backend side. Raw tinker evidence: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-custom_module-db3.txt`.

---

## Platform Services (Scheduler / Workflow / Event Bus)

| Platform Services (scheduler/workflow/event bus) | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Platform Services | `lib/platform/scheduler.ts`, `lib/platform/workflow.ts`, `lib/platform/event-bus.ts`, `lib/platform/client.ts` (transport), pages: `app/platform-services/{scheduler,workflow,event-bus}/page.tsx` | `SchedulerController` (index/save/runNow), `WorkflowController` (index/store/update/destroy/simulate/history), `EventBusController` (summary/stream/consumers/failures/catalogue/options/replay) — `routes/platform.php:92-137` | `g2g_platform_scheduled_tasks`, `g2g_platform_task_runs`, `g2g_event`, `g2g_event_delivery`, `g2g_platform_workflows`, `g2g_platform_workflow_versions` — all **[DB3 / live / 128.199.17.97]** | Match | Implemented |

All 12 frontend calls checked (5 verified in depth) have an exact backend counterpart, same HTTP verb, same path, mounted under `/api/platform` via `lib/platform/client.ts:50` matching `routes/platform.php`'s `api/platform` prefix. Verified pairs:

- `GET /scheduler/tasks` → `SchedulerController::index` (`lib/platform/scheduler.ts:100` ↔ `routes/platform.php:107`, `SchedulerController.php:34`).
- `POST /scheduler/tasks/run` → `SchedulerController::runNow` (`scheduler.ts:134` ↔ `routes/platform.php:109`).
- `GET /events/summary` → `EventBusController::summary` (`event-bus.ts:137` ↔ `routes/platform.php:92`, `EventBusController.php:27`).
- `GET /workflow/points` and `POST /workflow` → `WorkflowController::index`/`store` (`workflow.ts:148,152` ↔ `routes/platform.php:132,134`).

Response fields also line up: `ScheduleReader` reads `g2g_platform_scheduled_tasks`/`g2g_platform_task_runs` matching the `ScheduledTask`/`SchedulerPayload` TS interfaces (e.g. `disabled`, `task_key`, cron columns); `EventBusReader` reads `g2g_event`/`g2g_event_delivery` matching `EventRow`/`ConsumerRow`; `WorkflowController` reads/writes `g2g_platform_workflows`/`g2g_platform_workflow_versions` matching `WorkflowChain`/`WorkflowVersion`.

All six tables exist on DB3 with non-zero rows: `g2g_platform_scheduled_tasks`=1, `g2g_platform_task_runs`=2, `g2g_event`=345, `g2g_event_delivery`=61, `g2g_platform_workflows`=3, `g2g_platform_workflow_versions`=3 **[DB3 / live / 128.199.17.97]**; DESCRIBE confirms columns (`sub_institute_id`, `flow_key`, `steps` longtext, `status` enum on delivery) match reader/controller field usage.

Note: frontend/backend comments both flag Workflow as functionally "declared but only one point (`hrms.leave.approval`) is enforced" and Event Bus as read-only by design — those are documented limitations, not contract breaks. Raw tinker output: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-platform_services-db3.txt`.

---

## Organization

| Organization | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Organization | `services/organization/{index,setup-status,module-enablement,role-permissions,settings}.ts`, pages under `app/organization/**` | `Api/Organization/{OrganizationProfileController,OrganizationSetupController,ModuleEnablementController,OrganizationSettingsController}` + `HRMS/DepartmentManagementController`, routes in `routes/api.php` | `hrms_departments`, `org_details`, `school_setup`, `institute_detail`, `tbluser`, `org_sister_details` — all **[DB3 / live / 128.199.17.97]** | Matched on all 5 sampled calls | Implemented |

Verified 5 real frontend→backend pairs by reading both sides:

1. `organizationService.getOrganizationProfile` → `GET /organization/profile` (`services/organization/index.ts:390` → `routes/api.php:1254` → `OrganizationProfileController::show`, `app/Http/Controllers/Api/Organization/OrganizationProfileController.php:73`).
2. `saveOrganizationProfile` (multipart) → `POST /organization/profile` (`index.ts:467` → `api.php:1255` → `::save` at line 198), profile-gated `admin,hr` matching the frontend's own note that this replaced a session-cookie web route.
3. `setupStatusService.get`/`createRoles` → `GET /organization/setup-status`, `POST /organization/setup/roles` (`setup-status.ts:62,73` → `api.php:1182-1183` → `OrganizationSetupController::status`/`createRoles`).
4. `moduleEnablementService.list`/`save` → `GET/POST /organization/modules`, admin-gated (`module-enablement.ts:60,69` → `api.php:1238-1239`).
5. `organizationSettingsService.get`/`save`/`audit` → `GET/PUT /organization/settings`, `GET /organization/audit` (`settings.ts:101,130,157` → `api.php:1198-1199,1217`).

Department CRUD (`createDepartment`, `updateDepartment`, `getDepartmentImpact`, `mergeDepartment`, `setDepartmentHead/Parent`, `reorderDepartment`, SOP/policy/rule sub-resources) all match `Route::prefix('departments-management')`/`department-sops`/`department-policies`/`department-rules` groups (`routes/api.php:1374-1470`), with HTTP verbs identical on every sampled pair.

No contract mismatches found in the sampled set; extensive inline comments in the frontend service files themselves describe prior mismatches (e.g. a stale web-session route, a hardcoded industries list, `org_details` vs `school_setup` divergence) as already fixed, consistent with what the routes/controllers show today.

DB3 verification **[DB3 / live / 128.199.17.97]**: `hrms_departments` 1236 rows, `org_details` 12, `school_setup` 12, `institute_detail` 5, `tbluser` 299, `org_sister_details` 4 (all via `DB::connection('live')->table(x)->count()`, never information_schema). `DESCRIBE` confirmed `hrms_departments` carries `code`, `description`, `head_user_id`, `sort_order` (columns the frontend types expect) and `org_details`/`school_setup` schemas match the controller's field usage. Raw tinker output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-organization-db3.txt`.

---

## Notifications

| Notifications | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Notifications | `services/notifications.ts` (g2gv0), `components/shell/notifications-menu.tsx` (g2gv0) | `App\Http\Controllers\Api\Notifications\NotificationController` (hp_erp), routes `routes/api.php:2493-2496` | `g2g_notification` (1 row) **[DB3 / live / 128.199.17.97]** | Match | Implemented |

The frontend `notificationService` (`services/notifications.ts:49-70`) makes 4 calls — `GET /notifications` (list, `limit`/`unread_only` params), `GET /notifications/unread-count`, `PATCH /notifications/{id}/read`, `PATCH /notifications/read-all` — all consumed by `notifications-menu.tsx:51-124`. Each has an exact route+method+controller-method match: `routes/api.php:2493` → `index`, `:2494` → `unreadCount`, `:2495` → `markAllRead`, `:2496` → `markRead`.

Response shapes match precisely: `NotificationController::index` (`app/Http/Controllers/Api/Notifications/NotificationController.php:29-55`) returns `{status, notifications, unread}` exactly as the frontend's `InboxResponse` expects, and its `->get([...])` column list (`:45-48`) is verbatim identical to `DESCRIBE g2g_notification` — confirmed via tinker against `DB::connection('live')` **[DB3 / live / 128.199.17.97]**: `g2g_notification` exists with 1 row and columns `id, sub_institute_id, user_id, event_id, event_type, channel, subject, body, action_url, recipient_reason, read_at, created_at`.

A same-path decoy exists at `routes/api.php:1629-1631` (`Api\TaskManagement\NotificationController` on `task_management_notifications`, 7 rows **[DB3 / live / 128.199.17.97]**) but it is scoped under `Route::prefix('task-management')` (`:1605`), so it does not collide with the unprefixed `/notifications` the frontend actually calls — verified no route conflict.

No `user_id` query param exists on either side, matching the explicit "recipient is always the token owner" comments in both `services/notifications.ts:9-11` and the controller's class doc (`:19-23`). Evidence saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-notifications-db3.txt`.

---

## Agentic

| Agentic | Frontend files | Backend controller/routes | DB3 tables touched | Contract-match verdict | Status |
|---|---|---|---|---|---|
| Agentic | `services/agentic/{agents,runs,workflows,insights,excel}.ts` | `App\Http\Controllers\Api\Agentic\{AgentController,RunController,WorkflowController,AnalyticsController,ReflectionController}`, `ExcelAutomationAgentController`; `routes/api.php:2417-2477, 2003-2009` | `agentic_agents` (26 rows), `agentic_agent_runs` (206), `agentic_run_tasks` (806), `agentic_tool_invocations` (23), `agentic_agent_configs` (0), `agentic_workflows` (1)/`agentic_workflow_steps` (3)/`agentic_workflow_runs` (0)/`agentic_workflow_step_runs` (0), `agentic_messages` (2), `agentic_optimizations` (5), `agentic_reflection_runs` (1) — all **[DB3 / live / 128.199.17.97]** | Match | Implemented |

Verified 5 real frontend→backend calls, all backed by real routes/controllers/tables, not fixtures:

- `runService.start` → `POST /agentic/agents/{id}/run` (`agents.ts:161`, `api.php:2424`) hits `RunController::start` (`RunController.php:199`), which checks `status==='deployed'`, validates `inputs` against `input_schema`, inserts into `agentic_agent_runs`+`agentic_run_tasks`, and for `execution_mode='http'` dispatches via `Http::send` and maps the response into `{id,status,output,error_message}` — the exact shape `runService.start`'s return type declares (`runs.ts:160`).
- `agentService.list`/`meta` (`agents.ts:270,267`) match `AgentController::index`/`meta` (`AgentController.php:262,644`) including `AgenticListResponse` pagination envelope.
- `workflowService.run`/`updateStepRun` (`workflows.ts:154,167`) match `WorkflowController::run`/`updateStepRun`, which write `agentic_workflow_runs`/`agentic_workflow_step_runs` and advance sequential steps (`WorkflowController.php:387,501`).
- `analyticsService.dashboard` (`insights.ts:152`) matches `AnalyticsController::dashboard`, computed from `agentic_agent_runs` (per `ReflectionController.php:56` pattern for the sibling reflection endpoint at `:175`, same table).
- `excelAgentService.upload` (`excel.ts:82`) matches `ExcelAutomationAgentController::upload` at `routes/api.php:2006`, a separate non-agentic-prefixed controller as the frontend comment states.

Two minor drift points worth flagging: `workflowService.messages`/`sendMessage` call `/agentic/messages` (`workflows.ts:178,184`) which is backed by table `agentic_messages` (not `agentic_agent_messages` as initially guessed — confirmed via `WorkflowController.php:567,605`), and `reflectionService` types reference an `Optimization`/`optimizations` shape backed by table `agentic_optimizations` (`ReflectionController.php:208`, not `agentic_reflection_optimizations`) — naming is internal-only and does not break the contract since the frontend never references table names directly.

All row counts and DESCRIBE output are saved at `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/module-agentic-db3.txt`.

---

## Summary

| Module | Verdict | Status |
|---|---|---|
| HRMS (attendance/leave/onboarding) | Match | Implemented |
| Talent Management | Match on verified calls | Implemented |
| Payroll | Match | Implemented |
| LMS / Course Enrollment | 5/5 checked calls match | Implemented |
| Skill / Competency | Match | Implemented |
| Career Journey | Match | Implemented |
| Assessment / AI | Candidate-assessment matches; AI console matches code but two tables missing on DB3/live | Partial |
| Custom Module Builder | No frontend caller exists to compare against | **Broken** (unreachable from the SPA) |
| Platform Services (scheduler/workflow/event bus) | Match | Implemented |
| Organization | Matched on all 5 sampled calls | Implemented |
| Notifications | Match | Implemented |
| Agentic | Match | Implemented |

All DB3 facts in this document describe the **[DB3 / live / 128.199.17.97]** host specifically — a separate, differently-migrated host from production's default `DB_*` connection.
