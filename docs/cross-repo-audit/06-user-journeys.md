# 06. User Journeys

This section traces five representative end-to-end journeys — Frontend → Backend → Database — to see whether each screen's UI actions actually resolve to a working route, controller, and populated table on the live database.

---

## 1. Employee Onboarding

**Path:** Talent Management → Onboarding & Employee Lifecycle Center (`g2gv0/components/domain/talent/onboarding/onboarding-center.tsx`), reached via the generic module route `app/module/[moduleId]/[menuId]/page.tsx` → `GtgAppShell`, which resolves the sidebar's accessLink to this component through a content map.

### Frontend trace

| # | UI action | Component / hook | Service call | Endpoint |
|---|---|---|---|---|
| 1 | Sidebar "Onboarding" link | `hooks/content-map-m3.ts:33` (`accessLink: '/module/talent-management/onboarding'`) → mounts `OnboardingCenter` `components/domain/talent/onboarding/onboarding-center.tsx:167` → `useOnboardingFilters` | `onboardingService.getFilters` `services/talent/onboarding.ts:550-552` | `GET /onboarding/filters` |
| 2 | Same mount, KPI cards | `useOnboardingOverview` | `onboardingService.getOverview` `services/talent/onboarding.ts:544-549` | `GET /onboarding/overview` |
| 3 | "More Actions" → "Start from accepted offer", submit `onboarding-center.tsx:1262-1269` | `StartFromOfferDialog` | `mutations.createJourneyFromOffer` `hooks/use-onboarding.ts:497-500` → `onboardingService.createJourneyFromOffer` `services/talent/onboarding.ts:569-574` | `POST /onboarding/journeys/from-offer/{offerId}` |
| 4 | New journey auto-selected | `useJourneyDetail` `hooks/use-onboarding.ts:220` | `onboardingService.getJourney` `services/talent/onboarding.ts:561-563` (stages/contacts/timeline/documents/notes fetched alongside) | `GET /onboarding/journeys/{id}` |
| 5 | "Preboarding" tab task table | `useOnboardingTasks` `hooks/use-onboarding.ts:277` | `onboardingService.getTasks` `services/talent/onboarding.ts:616-622` | `GET /onboarding/tasks` |
| 6 | "Add Task" button `onboarding-center.tsx:516-518`, submit `:1228-1235` | `TaskSheet` | `mutations.createTask` `hooks/use-onboarding.ts:531-533` → `onboardingService.createTask` `services/talent/onboarding.ts:623-627` | `POST /onboarding/tasks` |
| 7 | Row menu "Mark Complete" `onboarding-center.tsx:846-852` | — | `mutations.completeTask` `hooks/use-onboarding.ts:542-546` → `onboardingService.completeTask` `services/talent/onboarding.ts:633-637` | `POST /onboarding/tasks/{id}/complete` |
| 8 | "Onboarding Journey" tab, stage node click | `onToggleStage` `onboarding-center.tsx:1149-1152` | `mutations.completeStage` → `onboardingService.completeStage` `services/talent/onboarding.ts:596-599` | `POST /onboarding/stages/{id}/complete` |

Note: the whole screen is confirmed API-backed by its own file-header comment (`services/talent/onboarding.ts:1-35`), which explicitly disclaims reuse of phantom endpoints in `services/talent/index.ts`.

### Backend trace

All routes registered in `routes/api.php:2235-2305`, loaded bare — **no `->group()` wrapper** (confirmed by scanning every `Route::group`/`Route::middleware` block between lines 2144-2310: none spans this section). Only the global `api` middleware group applies (`RequireApiToken` is **not** in it; `bootstrap/app.php:69,91` append only `TouchTokenActivity` and `RequireTwoFactorEnrolment`). Auth/tenant scoping instead happens **inside every controller method** via `ResolvesOnboardingContext::onboardingContext()` → `ResolvesApiIdentity::resolveApiIdentity()` (`app/Http/Controllers/Api/Concerns/ResolvesApiIdentity.php:34`): validates the Sanctum token (bearer or `token` param), rejects expired tokens, and derives `sub_institute_id` from the token owner (request-supplied tenant id is ignored when the user has one) — good tenant isolation.

**No route carries `profile:`/role middleware, and no controller checks a role. Any authenticated user of any role can create journeys, add/complete tasks, and complete stages.**

| # | Route | Controller@method | Notes |
|---|---|---|---|
| 1 | `GET /onboarding/filters` — `routes/api.php:2236` | `OnboardingOverviewController@filters` (`:197`) | No route middleware; token+tenant via `onboardingContext()`. Returns dropdown vocab. |
| 2 | `GET /onboarding/overview` — `routes/api.php:2235` | `OnboardingOverviewController@index` (`:43`) | Same auth. Computes 5 KPI tiles from `talent_onboarding_journeys`, tenant-scoped. |
| 3 | `POST /onboarding/journeys/from-offer/{offerId}` — `routes/api.php:2241` (`whereNumber`) | `OnboardingJourneyController@storeFromOffer` (`:171`) | Loads offer from `talent_offers` (tenant-scoped), rejects rejected/expired offers, blocks duplicate journeys per offer, seeds `DEFAULT_STAGES`/`ONBOARDING_TASK_TEMPLATE`. |
| 4 | `GET /onboarding/journeys/{id}` — `routes/api.php:2242` | `OnboardingJourneyController@show` (`:91`) | Tenant-scoped `findJourney()`. |
| 5 | `GET /onboarding/tasks` — `routes/api.php:2280` | `OnboardingTaskController@index` (`:44`) | Same auth. |
| 6 | `POST /onboarding/tasks` — `routes/api.php:2281` | `OnboardingTaskController@store` (`:90`) | No role gate on who may add tasks. |
| 7 | `POST /onboarding/tasks/{id}/complete` — `routes/api.php:2283` (`whereNumber`) | `OnboardingTaskController@complete` (`:204`) | Same auth. |
| 8 | `POST /onboarding/stages/{id}/complete` — `routes/api.php:2249` (`whereNumber`) | `OnboardingJourneyController@completeStage` (`:477`) | Tenant-scoped stage lookup, toggles `status`/`completed_at`, syncs journey, logs activity. |

**No broken links** — every frontend call has a matching route/controller/method with correct HTTP verbs and numeric constraints.

**Flag:** the entire onboarding surface relies on token+tenant checks done ad-hoc per-controller rather than route middleware, and has **zero role/permission enforcement** — e.g. any tenant employee (not just HR/admin) can call `storeFromOffer`, `completeStage`, or delete tasks/documents/notes (`DELETE /onboarding/tasks/{id}`, `/onboarding/notes/{id}`, `/onboarding/documents/{id}` — none role-gated either), unlike comparable modules elsewhere in the file that wrap writes in `profile:admin,hr`.

### Database trace

| # | Endpoint | Tables touched | Rows |
|---|---|---|---|
| 1 | `GET /onboarding/filters` | `talent_onboarding_journeys` (1), `hrms_departments` (1,236), `talent_onboarding_tasks` (1), `tbluser` (299), `talent_offers`/`talent_job_applications`/`talent_job_postings` (71/281/133), `document_type` (0) | [DB3 / live / 128.199.17.97] — document-type dropdown will render empty. |
| 2 | `GET /onboarding/overview` | `talent_onboarding_journeys` (1), `talent_onboarding_tasks` (1) | [DB3 / live / 128.199.17.97] — with only 1 journey/1 task tenant-wide, all 5 KPI tiles compute but are near-trivial. |
| 3 | `POST /onboarding/journeys/from-offer/{offerId}` | Reads `talent_offers`/`talent_job_applications`/`talent_job_postings` (71/281/133), `talent_onboarding_journeys` (1, dedupe check), `talent_offer_acceptances` (**0**); writes `talent_onboarding_journeys` + `talent_onboarding_journey_stages`, `talent_onboarding_activity_log` | [DB3 / live / 128.199.17.97]. **Break**: `talent_offer_acceptances` is empty, so `accepted_employee_id` lookup always returns null — every journey started from an offer on DB3 gets `employee_id = NULL`, silently disabling probation-confirmation mirror and exit-case creation (per the controller's own inline comment). |
| 4 | `GET /onboarding/journeys/{id}` | `talent_onboarding_journeys` (1), `talent_onboarding_journey_stages` (7) | [DB3 / live / 128.199.17.97] — works; the one seeded journey has its 7 seeded stages. |
| 5 | `GET /onboarding/tasks` | `talent_onboarding_tasks` (1) | [DB3 / live / 128.199.17.97] — works, trivially. |
| 6 | `POST /onboarding/tasks` | Writes `talent_onboarding_tasks`, requires parent row in `talent_onboarding_journeys` (1); logs `talent_onboarding_activity_log` (7) | [DB3 / live / 128.199.17.97] — works. |
| 7 | `POST /onboarding/tasks/{id}/complete` | Read/write `talent_onboarding_tasks` (1), log `talent_onboarding_activity_log` (7) | [DB3 / live / 128.199.17.97] — works. |
| 8 | `POST /onboarding/stages/{id}/complete` | Read/write `talent_onboarding_journey_stages` (7), syncs `talent_onboarding_journeys` (1), logs `talent_onboarding_activity_log` (7) | [DB3 / live / 128.199.17.97] — works. |

**Also touched but empty on DB3:** `talent_onboarding_documents` (0), `talent_onboarding_notes` (0), `document_type` (0) [DB3 / live / 128.199.17.97] — Documents/Notes tabs and the document-type filter render empty, not erroring.

### Verdict

The journey **works end-to-end mechanically** — every table the trace touches exists and the core path (journey → stages → tasks → completion) has live rows to prove it (1 journey, 7 stages, 1 task, 7 activity-log entries, all self-consistent). It **breaks silently in business logic, not SQL**: `talent_offer_acceptances` is empty [DB3 / live / 128.199.17.97], so `storeFromOffer()` can never auto-link `employee_id`, which cascades to skip probation-confirmation and exit-case wiring for any hire onboarded this way on this host. Combined with the backend trace's finding of zero role enforcement, this is a functioning but thin/under-populated slice of DB3 — one demo journey, no real onboarding volume yet.

Raw tinker output: `C:\Users\MILAN\Downloads\hp_erp\Docs\cross-repo-audit\_evidence\journey-onboarding-db3.txt`

---

## 2. Payroll Run

**Path:** the frontend's real "Payroll Run" flow is **Monthly Payroll** (`components/domain/hrms/hrit/payroll-management/monthly-payroll/page.tsx`). There is no separate "Payroll Run" wizard; this grid *is* the run: load the period, edit attended days per employee, generate (save), then issue payslips.

### Frontend trace

| # | UI action | Hook / component | Service call | Endpoint |
|---|---|---|---|---|
| 1 | Select Month/Year/Department, click "Search" | `MonthlyPayrollPage` (`monthly-payroll/page.tsx:282-297`) → `search()` in `useMonthlyPayroll` (`hooks/use-monthly-payroll.ts:86-141`) | `payrollService.getMonthlyPayroll` `services/hrms/payroll.ts:685-696` | `GET /monthly-payroll/create` |
| 2 | Edit an employee's "Total Days" input | `onChange` (`monthly-payroll/page.tsx:427`) → `recalculate()` (`hooks/use-monthly-payroll.ts:147-200`) | `payrollService.getMonthlySalaryBreakdown` `services/hrms/payroll.ts:703-711` | `GET /getMonthlyData` |
| 3 | (Implicit, on load) month-lock check | `<MonthLockCard>` (`monthly-payroll/page.tsx:334-340, 371-382`) | `payrollService.getMonthLock` `services/hrms/payroll.ts:757-763` | `GET /monthly-payroll-lock` — gates whether "Generate Payroll" is enabled |
| 4 | Click "Generate Payroll (N)" | `onClick={save}` (`monthly-payroll/page.tsx:193-205`) → `save()` (`hooks/use-monthly-payroll.ts:258-289`) | `payrollService.saveMonthlyPayroll` `services/hrms/payroll.ts:728-748` | `POST /monthly-payroll-store` — the actual "run" |
| 5 | Click payslip (file) icon on a saved row | `onClick` (`monthly-payroll/page.tsx:476-484`) → `usePayslipDownload().download()` (`hooks/use-payslip-download.ts:50-81`) | `downloadMonthlyPayslip` `services/hrms/payroll.ts:1196-1206` | `GET /monthly-payroll-report/pdf/{employeeId}/{month}/{year}` |
| 6 | Click delete (trash) icon → confirm | `remove()` (`hooks/use-monthly-payroll.ts:290-304`) | `payrollService.deleteMonthlyPayroll` `services/hrms/payroll.ts:790-804` | `POST /monthly-payroll-delete/{month}` (used to regenerate a row, not part of the happy path) |

Note: "Lock this month" is a separate optional action on `MonthLockCard` (`payrollService.setMonthLock` → `POST /monthly-payroll-lock`), but was not read in full detail since it's a secondary control, not part of the core run sequence.

### Backend trace

All six frontend calls resolve to real routes in `routes/hrms.php`, all inside one outer group (`routes/hrms.php:41-230`, middleware `['auth','session','menu']`), with the payroll block further wrapped in `hrit.role:admin,hr` (`routes/hrms.php:213-229`). **No broken links.**

**Middleware chain (same for all 6):**
- `auth` (`authMiddleware.php`) — accepts a session with `user_id` set, or a valid Sanctum token; else 401/redirect.
- `session` (`SessionMiddleware.php`) — no-op passthrough.
- `menu` (`MenuMiddleware.php`) — returns immediately for `type=API`/`JSON` requests (which every call here sends), so it enforces nothing in this trace; only affects Blade page loads.
- `hrit.role:admin,hr` (`RequireHritRole extends RequireProfile`) — resolves the caller's `role_key` from the Sanctum token first, session `user_id` second (never the request body), 403s unless admin/hr. Real, code-verified tenant-identity + role check.

| # | Route | Controller@method | Notes |
|---|---|---|---|
| 1 | `GET /monthly-payroll/create` — `routes/hrms.php:215` | `PayrollController@monthlyPayrollCreate` (line 2754) | Resolves tenant via `payrollTenantId()`/actor via `payrollActorId()` (token-then-session, never body); looks up caller's real profile from `tbluser`/`tbluserprofilemaster` server-side (fixes F-132, where trusting client's role_key returned 2 of 122 employees to an admin). |
| 2 | `GET /getMonthlyData` — `routes/hrms.php:226` | `PayrollController@getEmpMonthlyData` (line 2908) | Tenant-scoped via `payrollTenantId()`; pulls `PayrollType`/`EmployeeSalaryStructure` filtered by `sub_institute_id`. |
| 3 | `GET`/`POST /monthly-payroll-lock` — `routes/hrms.php:221-222` | `PayrollController@monthlyPayrollLock` (line 3176) | Delegates to `PayrollMonthLock` service. Comment at line 218-220 notes this intentionally shares the same `hrit.role:admin,hr` gate as the save it protects (F-129). |
| 4 | `POST /monthly-payroll-store` — `routes/hrms.php:216` | `PayrollController@monthlyPayrollStore` (line 3233) | Canonicalizes month spelling (F-137); checks `PayrollMonthLock::isLocked()` before writing, refuses if locked (F-129) — the actual write/"run". |
| 5 | `GET /monthly-payroll-report/pdf/{id}/{month}/{year}` — `routes/hrms.php:107` (same outer `hrit.role:admin,hr` block, lines 68-123) | `PayrollController@monthlyPayrollPdf` (line 2101) | Tenant-scoped lookup of `EmployeeMonthlySalaryData` + `EmployeeSalaryStructure`; returns null/logs rather than fataling when no salary structure exists (F-125 fix). |
| 6 | `POST /monthly-payroll-delete/{month}` — `routes/hrms.php:224` | `PayrollController@deleteMonthlyPayrolls` (line 3737) | Tenant via `payrollTenantId()`; validates `deleteId` array; deletes rows + payslip docs in a `DB::transaction`. |

**Flag:** none of the six is missing or under-gated — every route sits behind both `auth` and the explicit `hrit.role:admin,hr` check, with tenant ID taken from token/session, never the request body.

### Database trace [DB3 / live / 128.199.17.97]

| # | Method | Tables read/written | Notes |
|---|---|---|---|
| 1 | `monthlyPayrollCreate` | `tbluser` (299), `tbluserprofilemaster` (42), `employee_monthly_salary_data` (6), `payroll_types` (13) | Server-resolved profile/employee list (F-132 fix). All tables populated. |
| 2 | `getEmpMonthlyData` | `payroll_types` (13), `employee_salary_structures` (10), `hrms_emp_payroll_deduction` (12) | `employee_salary_structures` at only 10 rows against 299 `tbluser` rows means most employees have no structure — for them this returns `status_code=0` "Salary Structure Not Found" before any DB write. |
| 3 | `monthlyPayrollLock` (via `PayrollMonthLock` service) | `payroll_month_locks` (0) | Table exists and is queryable; zero rows just means nothing on this host has ever been locked — `state()` treats a missing row as unlocked, a valid default, not breakage. |
| 4 | `monthlyPayrollStore` | `tbluser` (299, ownership check per F-133), upserts `employee_monthly_salary_data` (6) keyed on (employee, month, year, tenant) | Internally reuses `getEmpMonthlyData`, inheriting its structure-gap dependency from step 2. |
| 5 | `monthlyPayrollPdf` | `employee_monthly_salary_data` (6), `employee_salary_structures` (10), `school_setup` (12), `tbluser`+`tbluserprofilemaster`, `hrms_emp_leaves` (41, LWP calc) | Per F-125 returns null (not fatal) when `employee_salary_structures` has no row for the employee — same 10-vs-299 gap as step 2. |
| 6 | `deleteMonthlyPayrolls` | `staff_document` (8), `employee_monthly_salary_data` (6) | Deletes inside one `DB::transaction`. Both tables non-empty. |

All nine tables touched across the six methods exist and are queryable on DB3/live; none is missing or structurally broken.

**Verdict:** The pipeline itself does not break — every route, table, and code guard (F-109/F-125/F-129/F-132/F-133/F-138/F-142) is intact and verified against real DB3/live data. The one real end-to-end limitation, visible only from row counts: `employee_salary_structures` has just 10 rows against 299 `tbluser` rows on this host, so `getEmpMonthlyData` (step 2) and the PDF (step 5) will legitimately refuse/skip for most employees — not a code defect, but the journey is only fully exercisable, on DB3/live today, for the ~10 employees who have a salary structure.

Evidence: `C:\Users\MILAN\Downloads\hp_erp\Docs\cross-repo-audit\_evidence\journey-payroll_run-db3.txt`

---

## 3. Skill Assessment

**Path:** **My Assessment**, under LMS module (`/module/lms/learning/my-assessment`), registered in `hooks/content-map-m4.ts:23`. Route resolves through `app/module/[moduleId]/[menuId]/page.tsx` → `GtgAppShell`, which renders `CmMyAssessment` for that menu.

### Frontend trace

| # | UI action | Component | Service call | Endpoint |
|---|---|---|---|---|
| 1 | Page loads / navigates to "My Assessment" | `hooks/content-map-m4.ts:23` → `CmMyAssessment` mounts `components/domain/competency/cm-my-assessment.tsx:30` | — | — |
| 2 | On mount, fetch my test | `cm-my-assessment.tsx:53` (`load()`) | `aiAssessmentService.mine(...)` `services/competency/ai-assessment.ts:305` | `GET /competency/ai-assessment/mine` |
| 3 | Sitting auto-starts once a test is loaded (no Begin button) | `cm-my-assessment.tsx:86` | `aiAssessmentService.start(testId, ...)` `services/competency/ai-assessment.ts:405` | `POST /competency/ai-assessment/start` |
| 4 | User answers in `<AssessmentPaper>`, clicks "Save and continue later" | `cm-my-assessment.tsx:272` (`onClick={() => void submit(false)}`) → `submit()` at `:143` | `aiAssessmentService.submitAnswers(answers, context, false)` `services/competency/ai-assessment.ts:339` | `POST /competency/ai-assessment/submit` (`final:false`), then re-runs step 2's `load()` |
| 5 | User clicks "Submit assessment" | `cm-my-assessment.tsx:275` (`onClick={() => void submit(true)}`) | `submit(true)` → same service call as step 4 | `POST /competency/ai-assessment/submit` (`final:true`) |
| 6 | If written answers need AI marking | `cm-my-assessment.tsx:170` | `aiAssessmentService.markMine(attempt_id, ...)` `services/competency/ai-assessment.ts:423` | `POST /competency/ai-assessment/attempts/{attemptId}/mark` |
| 7 | Result screen mounts (`showResult`/`state.submitted`) | `cm-my-assessment.tsx:205` renders `<CmAssessmentResult>` `components/domain/competency/cm-assessment-result.tsx:36`, on mount `:45` | `aiAssessmentService.myResult(...)` `services/competency/ai-assessment.ts:452` | `GET /competency/ai-assessment/my-result` |

Note: `services/competency/skill-detail.ts`, `assessment-review.ts`, and `assessment-workspace.ts` power HR/admin screens (grading, workspace), not this employee-facing journey; `CmAssessmentWorkspace` is the admin counterpart, deliberately a separate component (comment at `cm-my-assessment.tsx:12-14`).

### Backend trace

All five endpoints resolve to `App\Http\Controllers\Api\Competency\AiAssessmentController` in `routes/api.php`, each gated by the `api.token` alias only (no `profile:`/role check on any — correct here, since all five are self-scoped "my own record" actions, not admin actions).

**Middleware — `api.token` → `App\Http\Middleware\RequireApiToken`** (`bootstrap/app.php:105`): reads the bearer token (or `token` input), resolves it via `PersonalAccessToken::findToken()`, 401s if missing/invalid/expired. It does not itself resolve tenant — that happens inside each controller method via `competencyContext($request)` (trait `Concerns\ResolvesCompetencyContext.php:28`) → `resolveApiIdentity($request)` (`Concerns\ResolvesApiIdentity.php:34`), which re-resolves the same token, loads `$user->sub_institute_id` from the tokenable user, and **ignores any tenant id sent in the request** — the token owner's own org always wins (`ResolvesApiIdentity.php:56-64`). Tenant scoping is real, just per-controller rather than middleware.

| # | Route | Controller@method | Notes |
|---|---|---|---|
| 1 | `GET /competency/ai-assessment/mine` — `routes/api.php:2612` | `@mine` (`:512`) | Resolves caller's `sub_institute_id`/`user_id`, finds the published test for the user's job role + tenant, returns questions left-joined to caller's own prior responses. Deliberately omits `correct_option`/`model_answer`. |
| 2 | `POST /competency/ai-assessment/start` — `routes/api.php:2629` | `@start` (`:906`) | Validates `test_id`, checks `mayTake()` eligibility, gets-or-creates an attempt, starts the clock only on first open (`whereNull('started_at')`), returns server-computed `seconds_remaining`. |
| 3–4 | `POST /competency/ai-assessment/submit` (`final:false`/`true`) — `routes/api.php:2613` | `@submit` (`:593`) | No subject id (attempt resolved from caller's own context); validates `answers[]`, scores MCQs via `AssessmentScoringService`, explicitly does **not** move proficiency (per comment, Q-B3). |
| 5 | Re-trigger of step 1's `mine()`/`myResult()` | — | No separate route. |
| 6 | `POST /competency/ai-assessment/attempts/{id}/mark` — `routes/api.php:2632` (`whereNumber('id')`) | `@markMine` (`:1040`) | Ownership checked explicitly in code (`where('id',$id)->where('sub_institute_id',$sid)->where('user_id',$me)`, `:1052-1053`) — 404 if not caller's own attempt. Runs `AssessmentScoringService::scoreShortAnswers()` + `finalise()`. |
| 7 | `GET /competency/ai-assessment/my-result` — `routes/api.php:2630` | `@myResult` (`:965`) | Fetches caller's latest submitted attempt scoped to `sub_institute_id`+`user_id`, returns per-question score (still no answer key) plus pending rating proposals, marked `proficiency_unchanged`. |

**No broken links** — every frontend call has a matching route, correct controller/method, and correct HTTP verb. **No missing tenant/role gaps**: all five are intentionally un-role-gated (self-service, not admin) and each controller method independently derives `sub_institute_id`/`user_id` server-side from the token rather than trusting request input, so cross-tenant/cross-user access is blocked at the identity-resolution layer even though it's not expressed as route middleware.

### Database trace [DB3 / live / 128.199.17.97]

| # | Method | Tables read/written | Notes |
|---|---|---|---|
| 1 | `mine()` | `s_user_jobrole` (4,894), `jobrole_competency_map` (52), `competency_assessment_test` (1), `competency_assessment_question` (8), `competency_assessment_response` (0, left-joined — fine, just no prior answers), `tbluser` (299) | All tables exist; the one published test/question-set has real data — endpoint is servable. |
| 2 | `start()` | Reads `competency_assessment_test` (1), `s_user_jobrole`/`tbluser` (populated); writes `competency_assessment_attempt` (**0 rows currently**) | This endpoint has apparently never been successfully called end-to-end on this host, or every attempt was deleted. |
| 3–4 | `submit()` (`final:false`/`true`) | `competency_assessment_attempt` (0), `competency_assessment_response` (0, via `AssessmentScoringService`) | Both tables exist and are empty on DB3. |
| 5 | Re-trigger of `mine()`/`myResult()` | Same tables as steps 1 and 7 | — |
| 6 | `markMine()` | `competency_assessment_attempt` (0), `competency_assessment_response` (0); `AssessmentScoringService::finalise()` also touches `competency_kasba_item` (312), `competency_kasba_rating` (262), `competency_rating_history` (4), `competency_assessment_rating_proposal` (1), and conditionally `s_competency_assessment_cycles` (2) / `s_competency_assessments` (142) | Both required columns (`cycles.test_id`, `assessments.attempt_id`) exist on DB3, so that gate is satisfied. |
| 7 | `myResult()` | `competency_assessment_attempt` (0), `competency_assessment_rating_proposal` (1) | — |

**Anomaly found:** the single `competency_assessment_rating_proposal` row (id 19, `attempt_id=16`, `test_id=118`, `source=lms_quiz`) does **not** correspond to any row in `competency_assessment_attempt` (empty) or to the only `competency_assessment_test` row (id 7). It was written by a *different* journey — an LMS-quiz auto-apply-rating flow that shares this table — not by `AiAssessmentController`. It is not evidence this journey has run.

**Verdict:** Every table this journey touches exists on DB3 with correct schema (including the migration-gated columns for cycle-score write-back), and the one published test/questions have real data, so `mine()` and the eligibility check would return usable data today. But `competency_assessment_attempt` and `competency_assessment_response` are both **0 rows** [DB3 / live / 128.199.17.97] — no one has ever successfully completed a `start → submit → mark` cycle on this host. The journey is not "broken" in code, but it is **functionally unverified/unused on DB3**: it has never been exercised past step 1 in production data.

Raw output: `C:\Users\MILAN\Downloads\hp_erp\Docs\cross-repo-audit\_evidence\journey-skill_assessment-db3.txt`

---

## 4. LMS Course Completion

**Path:** `/module/lms/learning/my-learning` (routed via `app/module/[moduleId]/[menuId]/[submenuId]/page.tsx`, mapped in `hooks/content-map-m4.ts:22` to the `LearningDeliveryWorkspace` component). No separate "course completion" page exists — completion happens inline in this one workspace.

### Frontend trace

| # | UI action | Hook / component | Service call | Endpoint |
|---|---|---|---|---|
| 1 | Page loads, enrolled courses fetched | `useMyLearning()` mounts, `hooks/use-my-learning.ts:164` | `lmsLearningService.getMyCourses()` `services/lms/learning.ts:265` | `GET /api/lms/learning/courses` |
| 2 | Click a course card in "My Learning" picker | `learning-delivery-workspace.tsx:209` (`onClick={() => onSelect(course.id)}`) → `selectCourse` (`use-my-learning.ts:722`) → `loadDetail` (`:195`) | `lmsLearningService.getCourse()` `services/lms/learning.ts:272` | `GET /api/lms/learning/courses/{id}` |
| 3 | Watch/open a lesson, click "Mark as complete" or video `onEnded` | `learning-delivery-workspace.tsx:1092` / `:1060` → `markLesson(currentLesson, 'completed')` (`use-my-learning.ts:292`) | `lmsLearningService.saveProgress()` `services/lms/learning.ts:279` | `POST /api/lms/learning/progress` |
| 4 | Click "Next" to advance lessons | `learning-delivery-workspace.tsx:1082` → `goNext`/`selectLesson` (`use-my-learning.ts:381,397`) | Repeats step 3's `saveProgress` call for each subsequent lesson | `POST /api/lms/learning/progress` |
| 5 | Click "Mark as complete" (course-level, "Finished this course?") | `learning-delivery-workspace.tsx:1353` → `markCourseComplete()` (`use-my-learning.ts:471`) | `lmsLearningService.completeCourse()` `services/lms/learning.ts:293` | `POST /api/lms/learning/courses/{id}/complete` |
| 6 | Click "Claim certificate" (shown once all lessons at 100%) | `learning-delivery-workspace.tsx:695` (button, wired via `onClaim` at `:1365`) → `claimCertificate()` (`use-my-learning.ts:506`) | `lmsCertificateService.issue()` `services/lms/learning.ts:506` | `POST /api/lms/learning/certificates` (server enforces all-lessons-done; 422 otherwise) |

Note: step 5 (learner self-declaration) and step 6 (certificate, gated on 100% lesson completion) are independent, parallel completion paths, not sequential — a learner can do either or both.

### Backend trace

| # | Route | Controller@method | Notes |
|---|---|---|---|
| 1 | `GET /api/lms/learning/courses` — `routes/api.php:1287` | `LmsLearningController@courses` | No route middleware. Auth/tenant enforced in-controller: `guardApiToken()` (line 240) via `ResolvesApiIdentity::resolveApiIdentity` (401 if token missing/invalid/expired); `tenantId()`/`requireUser()` pull `sub_institute_id`/`user_id` off the token owner's `tbluser` row — never request input. Query scoped to `s.sub_institute_id` and `user_id` (self only). |
| 2 | `GET /api/lms/learning/courses/{courseId}` — `routes/api.php:1330` | `@course` (line 418) | Same pattern: `guardApiToken()` (line 420) + `tenantId()` as a `when()` filter on `sub_institute_id`. Returns course detail plus caller's own enrolment row. |
| 3–4 | `POST /api/lms/learning/progress` — `routes/api.php:1289` | `@saveProgress` (line 607) | `guardApiToken()` (609) + `requireUser()`/`tenantId()`. Validates payload, confirms `content_id` belongs to `course_id` before upserting `lms_content_progress`. Step 4 ("Next") reuses this exact route/method per lesson. |
| 5 | `POST /api/lms/learning/courses/{courseId}/complete` — `routes/api.php:1292` | `@completeCourse` (line 1745) | `guardApiToken()` (1747), re-derives `userId`/`subInstituteId` from token, checks a live enrolment (`sub_institute_id` join, own `user_id`, status in enrolled/in-progress/completed) before marking complete. |
| 6 | `POST /api/lms/learning/certificates` — `routes/api.php:1317` | `@issueCertificate` (line 2239) | `guardApiToken()` (2241) + `requireUser()`/`tenantId()`; dedupes on existing certificate, calls `courseCompletion($userId, $course_id, $subInstituteId)`, returns 422-equivalent (`status:false`, message naming outstanding lessons/sessions) when `done < total` — matches frontend expectation. |

**Middleware note (applies to all 6 steps):** none of these routes carry `->middleware('api.token')`/`'profile:...'`. The global `api` group (`bootstrap/app.php`) only adds `TouchTokenActivity` and `RequireTwoFactorEnrolment` — no baseline auth. Enforcement instead lives in `App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity`/`ResolvesApiIdentity`, called explicitly at the top of every method above, which validates the Sanctum token, rejects expired tokens, and derives `user_id`/`sub_institute_id` solely from the token owner — so despite the absent route middleware, auth and tenant scoping are real and consistently applied (a documented fix over a prior version that trusted request-supplied `type`/`user_profile_name`). **No broken links** — every frontend call has a matching route and controller method.

### Database trace [DB3 / live / 128.199.17.97]

| # | Method | Tables read/written | Row counts |
|---|---|---|---|
| 1 | `courses()` | `lms_course_enroll`, joined to `sub_std_map` and `hrms_departments`; totals/progress from `content_master` and `lms_content_progress`; session counts from `lms_session_registrations` and `lms_virtual_classroom` | 1,500 / 108 / 1,236 / 181 / 18 / 4 / 2 |
| 2 | `course()` | Same course/enrolment tables, plus `chapter_master` and `lms_course_settings` (sequential-unlock flag) | 183 / 8 |
| 3–4 | `saveProgress()` | Validates `content_id` against `content_master`, then upserts `lms_content_progress` | 181 / 18 |
| 5 | `completeCourse()` | Reads/updates `lms_course_enroll` joined to `sub_std_map`, updates `lms_assignments`; on genuine finish writes a `course.completed` row to shared event store `g2g_event` via `EventRecorder`; quiz gate reads `question_paper` + `lms_quiz_attempt` | 1,500 / 108 / 59 / 346 / 83 / 1 |
| 6 | `issueCertificate()` | Dedupes on `lms_certificates`; re-reads `sub_std_map`; re-runs completion/quiz-gate math against `content_master`, `lms_content_progress`, `lms_session_registrations`, `lms_virtual_classroom`, `question_paper`, `lms_quiz_attempt` | 1 (certificates) |

**All 14 tables exist and are non-empty on DB3/live** — no missing/broken table for this journey. Full tinker output: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/journey-lms_completion-db3.txt`.

**Data-thinness flags (not breakage, but worth noting):** `lms_content_progress` has only 18 rows against 1,500 enrolments — matches the controller's own inline comment that this table is historically near-empty on live (the prior sequential-unlock bug this caused was fixed, per the code comments, so it shouldn't re-lock lessons, but most enrolments will show 0% progress until learners actually interact post-fix). `lms_quiz_attempt` (1 row), `lms_virtual_classroom` (2) and `lms_session_registrations` (4) are similarly thin — the quiz-gate and session-gate code paths are real and wired but lightly exercised on this DB today.

**Verdict:** End-to-end, the journey **works on DB3/live at the schema/data level** — every table the backend trace touches is present, queryable, and populated, so no step returns a hard 500/missing-table failure. The only residual risk is behavioral, not structural: with so few `lms_content_progress`/`lms_quiz_attempt` rows historically, most existing enrolments will show 0% progress and no quiz attempts until learners generate fresh activity — a data-maturity gap, not a broken link.

---

## 5. Career / Offer Flow

**Path:** public job browsing → apply → track → (later) respond to offer. Spans two route trees (`/careers/*` and `/offer/*`), both backed by one service file, `lib/careers-api.ts` (not under `services/*` — deliberate, since `services/talent/*` requires a Laravel session a candidate doesn't have).

### Frontend trace

| # | UI action | Component | Service call | Endpoint |
|---|---|---|---|---|
| 1 | Browse open roles | `app/careers/[slug]/page.tsx:120-126` | `careersApi.organisation(slug)` `lib/careers-api.ts:118-122` | `GET /careers/{slug}` |
| 2 | Click a role card | `app/careers/[slug]/page.tsx:398-400` `<Link href=".../jobs/{posting.id}">` | Client-side navigation, no API call | — |
| 3 | View role detail | `app/careers/[slug]/jobs/[id]/page.tsx:51-56` | `careersApi.posting(slug, id)` `lib/careers-api.ts:124-128` | `GET /careers/{slug}/postings/{id}` |
| 4 | Fill & submit application ("Submit application") | `app/careers/[slug]/jobs/[id]/page.tsx:332` (`ApplyForm.submit`) | `careersApi.apply(slug, id, form)` `lib/careers-api.ts:134-151` (multipart, resume file) | `POST /careers/{slug}/postings/{id}/apply` |
| 5 | Confirmation screen shows `track_url` from step 4 | `app/careers/[slug]/jobs/[id]/page.tsx:337-341` | No further API call; candidate clicks "Open" to follow the tracking link | — |
| 6 | Track application status | `app/careers/track/[token]/page.tsx:55-57` | `careersApi.track(token)` `lib/careers-api.ts:157-159` | `GET /careers/track/{token}` |
| 7 | Offer arrives by email; candidate opens `/offer/[token]` | `app/offer/[token]/page.tsx:35-36` | `offerApi.show(token)` `lib/careers-api.ts:182-185` | `GET /offer-response/{token}` |
| 8 | Accept or Decline ("Send my response") | `app/offer/[token]/page.tsx:55,63` (`submit`) | `offerApi.respond(token, decision, note)` `lib/careers-api.ts:187-195` (JSON body `{decision, note}`) | `POST /offer-response/{token}` |

Note: step 6's tracking payload also surfaces an `offer` sub-object (`waiting`/`responded` flags) and an `assessment` sub-object, but the tracking page itself never links to `/offer/[token]` or `/candidate-assessment/[token]` — those links are only delivered by email, outside this frontend trace.

### Backend trace

| # | Route | Middleware | Controller@method | Notes |
|---|---|---|---|---|
| 1 | `GET /careers/{slug}` — `routes/api.php:203` | `throttle:30,1` (no auth/tenant middleware) | `CareersController::organisation` | Tenant resolved from the `{slug}` path segment (unique index), not header/token — by design, since a candidate has no session. Returns org display fields + open postings. |
| 2 | Click role card | — | Client-side navigation | No backend call. |
| 3 | `GET /careers/{slug}/postings/{id}` — `routes/api.php:204` (`->whereNumber('id')`) | `throttle:30,1` | `CareersController::posting` | Scopes by `org->sub_institute_id` derived from the slug before matching `id`, so a posting from another tenant 404s identically to a nonexistent one. |
| 4 | `POST /careers/{slug}/postings/{id}/apply` — `routes/api.php:233` | `throttle:5,1` (tightened vs. reads, since it writes + accepts a file) | `CareersController::apply` | Re-resolves org/posting (tenant-scoped), validates input, dedupes by (tenant, job, email), uploads resume to `digitalocean` disk, upserts `talent_candidates`, inserts `talent_job_applications` with `sub_institute_id` from the slug-resolved org (never request body), mints a tracking token via `ApplicationTrackingService`, optionally emails it, returns `track_url`. |
| 5 | Confirmation screen | — | No API call | Uses `track_url` from step 4. |
| 6 | `GET /careers/track/{token}` — `routes/api.php:230` | `throttle:30,1`; token constrained to `[A-Za-z0-9]{64}` at route level | `CareersController::track` | `ApplicationTrackingService::resolve($token)` is the sole identity check — unknown/expired/malformed all return 410 uniformly. Tenant from resolved link row. Returns coarse status, an `assessment.waiting` flag (never a score), and an `offer` sub-object with only `responded` (never the offer letter). |
| 7 | `GET /offer-response/{token}` — `routes/api.php:249` | `throttle:20,1`; same 64-char token constraint | `OfferResponseController::show` | Identity/tenant entirely from `OfferLinkService::resolve($token)` — the token's sha256 keys exactly one acceptance row. Returns offer letter fields (position, salary, start date) scoped to that row's `sub_institute_id`. |
| 8 | `POST /offer-response/{token}` — `routes/api.php:252` | `throttle:10,1` | `OfferResponseController::respond` | Validates `decision`, re-resolves token, calls `OfferAcceptanceService::accept/decline` with `actorId = null` and channel `'candidate'`, burns the token via `markUsed()` only after the decision is recorded successfully. |

**No broken links found.** All 8 frontend calls have matching routes and controller methods. No route in this flow carries auth/role middleware, but that is a deliberate, consistently-applied design (documented in both controllers' docblocks): tenant scoping is done inside each method via the slug (steps 1–6) or the token's resolved row (steps 7–8), never via `Route::middleware`. This is a real deviation from the app's usual "tenant from an authenticated Sanctum token" pattern, but each controller correctly avoids trusting any request-supplied tenant/id — scoping is always applied before the identifier is matched.

### Database trace [DB3 / live / 128.199.17.97]

| # | Method | Tables read/written | Rows |
|---|---|---|---|
| 1 | `CareersController::organisation` | `institute_detail` (5 total; 5 have `careers_slug` set), `talent_job_postings` joined to `hrms_departments` (133; 1,236) | Filtered to `status='active'` + not-deleted: **7 qualifying postings**. Works. |
| 2 | Click role card | — | No DB. |
| 3 | `CareersController::posting` | Same `talent_job_postings`/`hrms_departments` scan, `id`-matched | Works given the 7 active rows. |
| 4 | `CareersController::apply` + `upsertCandidate` | Writes `talent_candidates` (216), `talent_job_applications` (281); `ApplicationTrackingService::mint()` writes `talent_application_tracking` (**only 1 row** despite 281 applications) | All tables exist and are writable; no break. |
| 5 | Confirmation | — | No DB. |
| 6 | `CareersController::track` | Reads `talent_application_tracking` (1), joins `talent_job_applications`/`talent_job_postings`/`hrms_departments`/`institute_detail`; checks `talent_candidate_assessments` (**0 rows**, handled as null) and `talent_offers` (71) | Logically sound but only exercisable for the single application that has a tracking row today. |
| 7 | `OfferResponseController::show` → `OfferLinkService::resolve` | Sole reader of `talent_offer_acceptances` — **0 rows on DB3**, even though `talent_offers` has 68 rows in `status='sent'` | `mint()` is the only writer of that table, so no candidate has ever been issued a live `/offer-response/{token}` on this host. |
| 8 | `OfferResponseController::respond` → `OfferAcceptanceService` | Reads/writes `talent_offer_acceptances`, `talent_job_applications`, `tbluserprofilemaster` (42), `s_user_jobrole` (4,894); on accept creates a row in `tbluser` (299) via `EmployeeFactory` | All tables present with data; code path is unreachable today only because step 7 has no live token to feed it. |

**Verdict:** No missing/broken tables — every table the backend trace touches exists on DB3 with rows (except `talent_candidate_assessments`, which the code tolerates as empty). The journey breaks operationally, not structurally, between **steps 6→7**: `talent_offer_acceptances` is empty (0 rows) on DB3 while `talent_offers` already holds 68 "sent" offers, so those sends bypassed `OfferLinkService::mint()` on this connection — meaning no candidate currently has a usable `/offer/[token]` link against DB3, and steps 7–8 cannot be exercised end-to-end until a new offer is sent through this code path.

Evidence: `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/journey-career_offer-db3.txt`

---

## Summary Across Journeys

| Journey | Mechanically works? | Where it actually breaks | Root cause |
|---|---|---|---|
| Employee Onboarding | Yes | `storeFromOffer` → `employee_id` always NULL | `talent_offer_acceptances` empty [DB3 / live / 128.199.17.97] |
| Payroll Run | Yes | `getEmpMonthlyData`/PDF fail for most employees | `employee_salary_structures` only 10 rows vs. 299 `tbluser` [DB3 / live / 128.199.17.97] |
| Skill Assessment | Yes (schema/data present) | Never exercised past step 1 (`mine()`) | `competency_assessment_attempt`/`_response` are 0 rows [DB3 / live / 128.199.17.97] |
| LMS Course Completion | Yes | Most enrolments show 0% progress | `lms_content_progress` only 18 rows vs. 1,500 enrolments [DB3 / live / 128.199.17.97] |
| Career / Offer Flow | Yes, up to step 6 | Steps 7–8 unreachable — no live offer tokens exist | `talent_offer_acceptances` empty despite 68 "sent" offers [DB3 / live / 128.199.17.97] |

A consistent pattern across all five journeys: **no route/controller in any of these flows was found structurally broken** — every frontend call maps to a real route, controller, and method, and every table referenced exists with the correct schema on DB3/live. The recurring failure mode is **data-maturity/business-logic gaps**, not code defects: bridging tables that a workflow step depends on (`talent_offer_acceptances`, `employee_salary_structures`, `competency_assessment_attempt`, `lms_content_progress`) are empty or thin relative to the volume of "upstream" records that should have populated them, silently degrading or stalling the downstream steps rather than throwing errors.

A second recurring theme, independent of data volume: several of these journeys (Onboarding, LMS Course Completion, Skill Assessment for the self-service routes) rely on **auth/tenant scoping resolved ad-hoc inside each controller method** rather than expressed as route middleware — functionally correct and consistently token-derived (never trusting request-supplied tenant IDs), but architecturally inconsistent with modules like Payroll Run that use an explicit `hrit.role:admin,hr` middleware gate. Onboarding is additionally flagged for having **zero role/permission enforcement** on any of its write endpoints.
