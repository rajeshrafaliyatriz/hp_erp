# AUDIT — TALENT MANAGEMENT (module 3)

**Pinned at** `hp_erp` a7d78c53 (2 untracked audit prompts, no tracked file modified) ·
`g2gv0` a954e1a (clean).
**Application database** = `202.47.117.220/hp_erp`, MariaDB 10.11.9, 434 tables. This is the host
`.env DB_HOST` names and the one every controller queries. A second deployment,
`128.199.17.97/hp_erp` (MariaDB 10.1.48, 288 tables), is referred to below as **the 128.199
deployment** and never as "live". Every row count in this report names its host.
**Probe tenants** — 3 (`administrator`), 6 (`administrator` and `employee`). Backend served locally
via `php artisan serve`; no HTTP was sent to any remote host.

---

## 1. VERDICT — **RED**

Any authenticated employee can read every job application in their organisation — candidate name,
email, mobile, expected salary and CV path — because `GET /api/job-applications` has no role gate and
the table has no viewer-ownership concept. This was executed, not inferred: a token whose profile is
`employee` with `data_scope=self` returned all 22 live tenant-6 rows. That single fact is
disqualifying on its own, and it sits beside a Mobility & Succession sub-module whose every table is
empty on the application database.

The module is **not** RED for the reason the previous audit would have given. Tenant isolation on the
modern surface is sound, server-side validation is real and enforced, and routes carrying no
middleware still reject anonymous callers. The defects here are concentrated in **authorization** and
in **stages that were built but never used**.

---

## 2. SCOPE, AND THE THREE SCREENS THAT MOVED

Talent Management owns the eleven routes declared in `g2gv0/hooks/content-map-m3.ts:26-38`. Three of
them — `employee-profiles`, `development-and-career-paths`, `certifications` — were moved out of
Competency Management on 2026-08-18 and still carry `/module/competency-management/...` wording in
the source comment at `content-map-m3.ts:11-21`; the URL is an identifier, not a statement of
ownership.

**In scope:** `Api/Talent/*` (10), `Api/Mobility/*` (7), `Api/Performance/*` (10), `Api/Onboarding/*`,
`Api/Offboarding/*`, `TalentDashboardController`, the legacy ATS under
`app/Http/Controllers/talent/**` and `talent_management/**`, and exactly seven Competency controllers
behind the moved-in screens (`EmployeeCompetencyProfile`, `DevelopmentPlan`, `DevelopmentPlanReport`,
`CareerPath`, `LearningAssignment`, `Certification`, `CertificationRequirement`).

**Out of scope,** and belonging to the Competency Management audit: the remaining ~32
`Api/Competency` controllers (library, studio, ESO, assessment cycles, KASBA, frameworks, approvals,
audit), `Api/CompetencyDashboard`, `Api/Readiness`.

**One deliberate exception.** `Api/Competency/NineBoxController` is audited **as a consumer** because
`components/domain/talent/performance/nine-box-grid.tsx` renders it — its hardcoded band constants
and the existence of a second, incompatible 9-box scale are reported here (F-63). How competency
ratings reach the capability axis is Competency's question, not this one.

---

## 3. SCORECARD

| Check | Verdict | Basis |
|---|---|---|
| Front door | AMBER | Reachable, but the module has no entry in `lib/gtg-navigation.ts` and its role table is dead code |
| 360° lifecycle | RED | Whole stages present in all three layers with zero production rows |
| Role journeys | RED | 106 of 124 audited routes have no role gate; proven at the API |
| External actors | RED | No candidate surface of any kind exists |
| 1 Data source | AMBER | Live for most screens; mock data on reachable screens (already `F-23`, `F-28`) |
| 2 API integrity | GREEN | Routes resolve, shapes agree, `route:list` exit 0 across 1847 routes |
| 3 CRUD completeness | AMBER | Reads and writes present; delete is hard on several child tables |
| 4 Validation, four layers | AMBER | API layer real and enforced (proven); DB constraints thin; errors never reach the user (F-55) |
| 5 Business rules | AMBER | Goal weightage capped at 100 per goal, never summed to 100 across an employee |
| 6 Data integrity | RED | 68 multi-table writes with no transaction; 5 tables with no tenant column |
| 7 Error handling | GREEN | 401/404 correct across every malformed-token and bad-id variant tested |
| 8 Real data and scale | AMBER | Pagination correctly capped; 12 `latin1` columns cannot hold Indic text or emoji |
| 9 RBAC + tenant isolation | RED | Tenant isolation GREEN; RBAC RED — see F-53, F-56 |
| 10 Integration / data flow | AMBER | Talent→LMS bridge exists at four points; Talent→Payroll read-only |
| 11 Workflow integrity | RED | `talent_workflows` has 8 rows and no writer; no Talent stage emits an event |
| 12 Calculation integrity | AMBER | Formulas correct; constants hardcoded that should be tenant configuration |
| 13 Audit trail | RED | `Api/Talent/*` and `Api/Mobility/*` write zero audit rows |
| 14 UX / operational readiness | AMBER | Loading/error states good on most screens; validation messages lost |
| 15 Production readiness | AMBER | Pagination capped, no N+1 observed in probes; 7 real `tsc` errors, 5 in Talent files |

---

## 4. LIFECYCLE — where it actually breaks

Constructed from the route inventory, the frontend map and the row census. Stages are grouped; the
break type is given per stage.

| # | Stage | FRONTEND | BACKEND | DATABASE (app DB rows) | WIRED? | Break |
|---|---|---|---|---|---|---|
| 1-2 | Requisition, job posting | recruitment-center.tsx | `talent_jobpostingcontroller` | `talent_job_postings` 126 | YES | — |
| 3 | Candidate applies | candidate-application-form.tsx (internal drawer) | `talent_jobapplicationcontroller@store` | `talent_job_applications` 279 | YES | — |
| 4 | Screening | candidate-detail-panel.tsx | `talent_screening_results_controller` | `talent_screening_results` 285 | YES | — |
| 5 | **Assessment** | kanban column only | none | `talent_resume_screenings` **0** | NO | **NONE-BUILT** |
| 6-7 | Interview + feedback | interview-tools-drawer.tsx | `talent_interviewschedules`, `talent_evaluation_form` | 146 / 124 | YES | — |
| 8 | Offer issued | recruitment-action-drawer.tsx | `TalentOfferController` | `talent_offers` 68 | YES | — |
| 9 | **Offer accepted** | **no screen** | `rejectOffer` only | `talent_offers` | NO | **FE-MISSING** |
| 10 | Candidate → employee | none | none | — | NO | **NONE-BUILT** |
| 11-12 | Onboarding journey, tasks | onboarding-center.tsx | `Api/Talent/Onboarding*` | journeys **1**, tasks **1**, documents **0**, notes **0** | YES | **DEAD-DATA** |
| 13 | Probation → confirmation | onboarding-tabs.tsx | `OnboardingJourneyController` | journeys 1 | YES | DEAD-DATA |
| 14-17 | Goals, appraisal, review, calibration | performance-center.tsx | `Api/Performance/*` | goals 17, reviews 235, appraisals 5, calibration 3 | YES | — |
| 18 | Nine-box | nine-box-grid.tsx | `Api/Competency/NineBoxController` | derived | YES | — |
| 19-20 | Rating, compensation, bonus | performance-tabs.tsx | compensation/bonus controllers | revisions 8, bonus 8 | YES | — |
| 21-24 | Dev plan, approval, LMS, certification | cm-development-career.tsx | 7 allowlisted controllers | plans 162, actions 380, certs 221 | YES | — |
| 22 | **Dev plan approved → notify** | — | consumer exists | — | NO | **NOT-WIRED** |
| 25 | Internal job posted | mobility-center.tsx | `InternalJobController` | `talent_internal_jobs` **0**, `s_mobility_jobs` **1** | YES | **DEAD-DATA** |
| 26-27 | Transfer, promotion | mobility-center.tsx | Mobility controllers | transfers **2**, promotions **0** | YES | **DEAD-DATA** |
| 28-29 | Succession, readiness | mobility-center.tsx | `SuccessionPlanController` | plans **0**, candidates **0**, pools **0** | YES | **DEAD-DATA** |
| 30-32 | Resignation, notice, clearance | offboarding-center.tsx | `OffboardingCaseController` | cases **3**, clearances **0** | YES | DEAD-DATA |
| 33 | Exit interview | offboarding-center.tsx | `ExitInterviewController` | `talent_exit_interviews` **0** | YES | **DEAD-DATA** |
| 34-35 | Final settlement, access revoked | none | none | — | NO | **NONE-BUILT** |
| 36 | Historical records | — | soft deletes present | — | YES | — |

**Break-type counts** (24 stage rows): WIRED 11 · DEAD-DATA 7 · NONE-BUILT 3 · FE-MISSING 1 ·
NOT-WIRED 1 · BE-MISSING 1.

**The distribution is the story.** This is not a module built ahead of its API. It is a module whose
API and tables were built, wired, and then **never used in production** — seven stages carry a
complete three-layer implementation and zero rows on the host the application queries.

### Stages with a screen but no API
- Offer acceptance (stage 9). `OfferStatus` includes `'Accepted'` (`recruitment-data.ts:5`) and
  nothing in the product can write it.
- Assessment (stage 5). A kanban column exists with no entity, no invite and no scoring behind it.

### Handoffs that do not exist
1. **Offer accepted → employee record.** No mechanism. All hiring work stops at the offer.
2. **Development plan approved → learning assigned.** `LearningAssigner` and `NotificationDispatcher`
   both subscribe to `development_plan.approved`; a repo-wide search for an emitter
   (`grep -rn "development_plan\.approved" app database routes`) finds only consumers and seeded
   templates. Same for `assessment.completed` and `employee.offboarded`.
3. **Exit case closed → access revoked.** No stage 35 exists in any layer.

---

## 5. FINDINGS REGISTER

Continues the sequence in `FIX-PLAN-v2.md` (F-01…F-52). The `D-001…D-056` sequence in `Docs/phase3/`
is untouched.

#### F-53 — Any authenticated employee can read every candidate's contact details and expected salary — CRITICAL
```
What:       GET /api/job-applications has no role gate, so a rank-and-file employee receives every
            job application in their organisation, including candidate PII.
Where:      routes/api.php:200-201 (route, middleware `api` only)
            app/Http/Controllers/talent/talent_jobapplicationcontroller.php:66 (index, tenant from token, no role check)
Evidence:   Route middleware is `api` only — no `profile:` gate. Executed with a token whose profile
            is `Employee` (id 17, role_key=employee, data_scope=self, tenant 6):
              22 rows returned; field names present:
              first_name, last_name, email, mobile, expected_salary, resume_path,
              current_location, education, experience, skills
            Ground truth: talent_job_applications tenant 6 = 22 live rows on 202.47.117.220/hp_erp.
            The table has no viewer-ownership column; the only owner-ish column is created_by,
            the recruiter who entered the row.
Impact:     Every employee can enumerate who is applying to their company, read their salary
            expectations and download their CVs. For an internal applicant this exposes that a
            colleague is job-hunting. This is personal data of people who are not users of the system
            and have no relationship with the reader.
Re-verify:  curl -s -H "Accept: application/json" --get http://127.0.0.1:8000/api/job-applications \
              --data-urlencode "token=$EMPLOYEE_TOKEN" --data-urlencode "sub_institute_id=6" \
              --data-urlencode "syear=2025" --data-urlencode "type=API" | python -c "import sys,json;d=json.load(sys.stdin);print(len(d['data']),sorted(d['data'][0].keys()))"
Fix sketch: Add profile:admin,hr,recruiter to the job-applications and candidate read routes; the
            alias map already resolves recruiter.
```

#### F-54 — Legacy ATS write methods take the tenant from the request, inside files whose read methods do not — CRITICAL
```
What:       Read methods in the legacy ATS were migrated to apiTenantId() (token-derived) but the
            write methods still assign sub_institute_id from the request body and persist it.
Where:      app/Http/Controllers/talent/talent_jobpostingcontroller.php:122  (written at :170)
            app/Http/Controllers/talent/talent_jobapplicationcontroller.php:308 (written at :411)
            app/Http/Controllers/talent/talent_interviewschedulescontroller.php:98 (written at :168)
            app/Http/Controllers/talent/talent_interviewschedulescontroller.php:337 (written at :412)
Evidence:   Same file, two different mechanisms:
              :68   $sub_institute_id = $this->apiTenantId($request);        // index  - token
              :122  $sub_institute_id = $request->get('sub_institute_id');   // store  - request
              :170  $objtalent->sub_institute_id = $sub_institute_id;        // persisted
Impact:     A caller can create or move a job posting, application or interview schedule into another
            organisation's tenant. The record is then invisible to its true owner and visible to the
            attacker's chosen tenant.
Re-verify:  Static, per method:
            awk '/public function [a-zA-Z_]+\(/{m=$0;sub(/.*function /,"",m);sub(/\(.*/,"",m);meth=m}
                 /sub_institute_id[[:space:]]*=/{printf "%-22s L%-5s %s\n",meth,NR,$0}' \
              app/Http/Controllers/talent/talent_jobpostingcontroller.php
            NOT probed: proving it requires writing a row into another tenant. Declined under ground rule 8.
Fix sketch: Replace $request->get('sub_institute_id') with $this->apiTenantId($request) in store,
            update, customUpdate and destroy, matching what index already does.
```
**Status: VERIFIED-BY-SOURCE.** The read half of this class was probed and is clean — a tenant-6
token asking for `sub_institute_id=3` receives 12 rows, all tenant 6.

#### F-55 — Server-side validation works, and none of its messages reach the user — HIGH
```
What:       The API returns 422 with a full field-error map only when the request declares
            Accept: application/json. The frontend never sets that header, so every failed Talent
            form write is delivered to the client as a 302 redirect to HTML.
Where:      g2gv0/services/core/api-client.ts:96-99 (headers built; no Accept)
            g2gv0/services/core/api-client.ts:105-109 (error path)
Evidence:   Same request, two outcomes:
              with Accept: application/json  -> HTTP 422
                {"message":"The employee id field is required. (and 1 more error)",
                 "errors":{"employee_id":[...],"request_type":["The selected request type is invalid."]}}
              without it (what the app sends) -> HTTP 302, content_type text/html,
                redirect to http://127.0.0.1:8000/ ; the 422 body and its errors map are discarded.
            api-client.ts:96-99 sets only Content-Type and the auth header:
              headers: { ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
                         ...authHeader(), ...headers }
Impact:     A user filling in a Talent form and omitting a required field is not told which field.
            buildApiError cannot populate `errors` from an HTML body, so it falls through to the
            generic branch at api-client.ts:74. The validation exists and is wasted.
Re-verify:  curl -s -o /dev/null -w '%{http_code} %{content_type}\n' -X POST \
              -H "Authorization: Bearer $T3" -H "Content-Type: application/json" \
              -d '{"employee_id":"","request_type":"NOT_AN_ENUM"}' \
              http://127.0.0.1:8000/api/talent/mobility/requests
            then repeat with -H "Accept: application/json" and compare.
Fix sketch: Add 'Accept': 'application/json' to the default headers in api-client.ts.
```

#### F-56 — 106 of 124 audited routes carry no role gate, including every compensation and bonus decision — HIGH
```
What:       Role gating is applied to a small minority of Talent routes. Financial and rating
            decisions are reachable by any authenticated employee of the tenant.
Where:      routes/api.php:1416-1421 (compensation), :1424-1429 (bonus), :1408-1413 (appraisals),
            :1386 (PUT /performance/reviews/{id}), :1628 (the whole mobility prefix group)
Evidence:   Inventory of 124 in-scope routes (Api/Performance + the 7 allowlisted Competency
            controllers): role_gate distribution = { none: 106, 'profile:admin,hr': 9,
            'profile:admin,hr,manager': 7 }.
            Asymmetry inside one controller: POST /performance/reviews/bulk IS gated
            profile:admin,hr (api.php:1383) while PUT /performance/reviews/{id} — which overwrites
            manager_rating, overall_rating and potential_rating — is gated by nothing (api.php:1386).
            Executed: an employee token receives 200 on /api/performance/compensation,
            /api/performance/bonus, /api/performance/appraisals, /api/mobility/promotions.
Impact:     Salary revisions, bonus awards and appraisal ratings can be created and approved by the
            employee they concern. The approval workflow is advisory.
Re-verify:  for U in performance/compensation performance/bonus performance/appraisals mobility/promotions; do
              printf "%-32s %s\n" "$U" "$(curl -s -o /dev/null -w '%{http_code}' \
                -H "Accept: application/json" -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
                http://127.0.0.1:8000/api/$U)"; done
Fix sketch: Gate the decision endpoints (/decision, /bulk, PUT reviews/{id}, mobility writes) on
            profile:admin,hr at minimum.
```
**Scope note.** The 200s above prove *reachability*, not disclosure: tenant 6 holds almost no
Performance data, so the response bodies were empty. Whether an employee sees colleagues' salary rows
on a populated tenant is **UNMET** — it needs an employee login on tenant 3. See `Q-T1`.

#### F-57 — Mobility & Succession is a complete implementation with no production data — HIGH
```
What:       A routed screen, a full controller set and eleven tables exist for internal mobility and
            succession. Every one of those tables is empty on the application database.
Where:      g2gv0/hooks/content-map-m3.ts:32 (the routed screen)
            app/Http/Controllers/Api/Mobility/*.php (7 controllers), Api/Talent/SuccessionPlanController.php
Evidence:   COUNT(*) on 202.47.117.220/hp_erp:
              talent_succession_plans 0      talent_succession_candidates 0
              talent_internal_jobs 0         talent_mobility_requests 0
              s_mobility_applications 0      s_mobility_promotions 0
              s_mobility_succession_plans 0  s_mobility_talent_pools 0
              s_mobility_talent_pool_members 0
              s_mobility_jobs 1              s_mobility_transfers 2
            Write-path check for a swallowed exception: the Mobility controllers contain no empty
            catch and no catch returning success (grep -rn "catch" app/Http/Controllers/Api/Mobility
            returns narrow Carbon-parse fallbacks only). So the write path does not fail silently —
            the feature is unused.
Impact:     Two full generations of mobility tables (talent_* and s_mobility_*) are being maintained
            for a capability no tenant has ever completed. The screen demos perfectly and has never
            carried a real transfer.
Re-verify:  php -r '<.env PDO snippet>' with
            SELECT '202.47.117.220/hp_erp' host, COUNT(*) FROM talent_succession_plans;
Fix sketch: Decide which generation survives, delete the other, and confirm the survivor with one
            real transfer end to end.
```

#### F-58 — 68 multi-table writes run without a transaction, including every activity-log pairing — HIGH
```
What:       A write and its audit row are two separate statements with no transaction around them.
            A failure between them leaves the record without its log, or the log without its record.
Where:      app/Http/Controllers/Api/Competency/DevelopmentPlanController.php@storeAction
            (writes s_competency_plan_actions, s_competency_development_plans, s_competency_activity_log)
            app/Http/Controllers/Api/Competency/CareerPathController.php@store
            (writes s_competency_career_paths, s_competency_career_path_steps, s_competency_activity_log)
            plus 66 more across the audited set.
Evidence:   Of 124 inventoried routes, 68 write more than one table with transaction=false.
            The entire Api/Performance namespace contains zero DB::transaction calls
            (grep -rn "DB::transaction\|beginTransaction" app/Http/Controllers/Api/Performance -> no match).
Impact:     Partial writes. A career path can exist with no steps; a plan action can be recorded with
            no audit entry, which also defeats check 13.
Re-verify:  grep -rn "DB::transaction\|beginTransaction" app/Http/Controllers/Api/Performance | wc -l
Fix sketch: Wrap each controller's multi-table write in DB::transaction().
```

#### F-59 — Five Talent tables carry no tenant column and no proven parent join — MEDIUM
```
What:       Tenancy is in-row on this platform, but five in-scope tables have neither
            sub_institute_id nor a documented parent that supplies it.
Where:      s_mobility_talent_pool_members, talent_resume_screenings, talent_team_members,
            talent_workflow_approvers, talent_workflow_stages
Evidence:   information_schema on 202.47.117.220/hp_erp: of 53 in-scope tables, these five have
            SUM(COLUMN_NAME='sub_institute_id') = 0.
            s_mobility_talent_pool_members additionally has no deleted_at, yet
            Api/Mobility/MobilityTalentPoolController@removeMember calls $member->delete() — a hard
            delete with no audit.
Impact:     Every read of these tables depends on the caller remembering to join through the parent.
            One forgotten join is a cross-tenant read.
Re-verify:  SELECT '202.47.117.220/hp_erp' host, TABLE_NAME,
              SUM(COLUMN_NAME='sub_institute_id') has_tenant
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hp_erp'
              AND TABLE_NAME IN ('s_mobility_talent_pool_members','talent_resume_screenings',
              'talent_team_members','talent_workflow_approvers','talent_workflow_stages')
            GROUP BY TABLE_NAME;
Fix sketch: Add sub_institute_id, or document and enforce the parent join in a scope.
```

> **RESOLVED for two of the five, Sprint 5 item 8** — `talent_resume_screenings` and
> `talent_team_members` both carry `sub_institute_id` as of migration `2026_09_03_170000`, on both
> hosts, along with `deleted_at`. They are no longer candidates for deletion: both now have
> controllers, routes, screens and seeded tenant-6 data.
>
> **Two corrections to the finding as written above.** First, `talent_team_members` was described as
> having "no documented parent join" and was reported elsewhere in this work as having no code path
> at all. It has a live one: `Services/Org/DepartmentMergeService.php:89` registers it in
> `DEPARTMENT_ID_TABLES`, so `impact()`, `merge()` and `release()` all query it and three routes
> reach them. Second, neither table had ever had a migration in this repository — they existed only
> on the databases, which is why nothing in `database/migrations/` mentioned them.
>
> The other three (`s_mobility_talent_pool_members`, `talent_workflow_approvers`,
> `talent_workflow_stages`) are untouched and the finding stands for them.

#### F-60 — Interview feedback cannot store Indic text or emoji — MEDIUM
```
What:       Twelve text columns in the Talent schema are latin1. They physically cannot hold
            Gujarati, Hindi or an emoji, in a product sold into India.
Where:      talent_evaluation_form: key_strengths, areas_of_concern, additional_comments, notes,
            recommendation, evaluation_criteria, status
            talent_interview_panel: panel_name, description, target_positions,
            available_interviewers, status
Evidence:   Charset census on 202.47.117.220/hp_erp across talent_/s_performance_/s_mobility_:
            utf8mb4 = 226 columns, latin1 = 12 columns — and all twelve are the free-text interview
            feedback and panel fields.
Impact:     An interviewer writing feedback in Gujarati, or a panel named with an emoji, loses data
            or hits a write error. These are exactly the fields most likely to contain local text.
Re-verify:  SELECT '202.47.117.220/hp_erp' host, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hp_erp'
              AND CHARACTER_SET_NAME='latin1'
              AND (TABLE_NAME LIKE 'talent%' OR TABLE_NAME LIKE 's_performance%' OR TABLE_NAME LIKE 's_mobility%');
Fix sketch: ALTER those columns to utf8mb4 with a conversion migration.
```

#### F-61 — No Talent table carries `syear` — MEDIUM
```
What:       The platform's tenancy model is documented as sub_institute_id + syear on root tables.
            Not one of the 53 in-scope Talent tables has a syear column.
Where:      all 53 tables matching ^(talent_|s_performance_|s_mobility_|s_competency_...)
Evidence:   information_schema on 202.47.117.220/hp_erp: SUM(COLUMN_NAME='syear') = 0 for every one
            of the 53. Meanwhile the frontend sends syear on every request
            (g2gv0/lib/laravel-context.ts:60-73) and the API accepts it without effect. Executed
            against tenant 3, four calls that differ only in syear:
              syear=2025 -> rows=25 total=222
              syear=1899 -> rows=25 total=222
              syear=9999 -> rows=25 total=222
              omitted    -> rows=25 total=222
            The parameter is accepted, carried on every request, and changes nothing.
Impact:     Talent data has no academic/financial-year dimension. Historical years cannot be
            separated, and a year filter in the UI cannot be honoured by any query. A user who
            believes they are looking at 2025 is looking at every year at once.
Re-verify:  for S in 2025 1899 9999; do curl -s -H "Accept: application/json" \
              -H "Authorization: Bearer $T3" "http://127.0.0.1:8000/api/performance/reviews?syear=$S" \
              | python -c "import sys,json;d=json.load(sys.stdin);print(d['pagination']['total'])"; done
Fix sketch: Decide whether Talent is year-scoped. If it is, add syear and filter on it; if not,
            stop sending it and reject it.
```

#### F-62 — Ratings validate to 0–10 and are labelled on a 1–5 scale — MEDIUM (latent)
```
What:       Validation accepts a rating from 0 to 10. The label function clamps to 1..5, so any
            value above 5 would render as "Outstanding" and 0 as "Below Expectations".
Where:      app/Http/Controllers/Api/Performance/PerformanceReviewController.php:272-275 (rules)
            app/Http/Controllers/Api/Performance/Concerns/ResolvesPerformanceContext.php:310-325 (label)
Evidence:   rules: 'manager_rating' => 'nullable|numeric|min:0|max:10'
            label: $rounded = max(1, min(5, $rounded)); ... return $display . ' - ' . $bands[$rounded];
            Executed: PUT /api/performance/reviews/41 with manager_rating 999 -> HTTP 422
            ("must not be greater than 10"), rows 235 -> 235, no write.
Impact:     Latent, not active. Measured on 202.47.117.220/hp_erp, tenant 3: 222 reviews,
            manager_rating>5 = 0, overall_rating>5 = 0, max observed 4.40. Nothing is mislabelled
            today. The defect is that nothing prevents it, and s_performance_cycles.rating_scale_max
            is never read (grep -rn "rating_scale_max" app -> a comment only).
Re-verify:  SELECT '202.47.117.220/hp_erp' host, COUNT(*) n, SUM(manager_rating>5) gt5,
              MAX(manager_rating) mx FROM s_performance_reviews WHERE sub_institute_id=3;
Fix sketch: Make max: follow the cycle's rating_scale_max, or clamp validation to 5.
```

#### F-63 — Two incompatible 9-box scales, both with hardcoded bands — MEDIUM
```
What:       The product computes a 9-box in two places on two different scales, with no reconciliation.
Where:      app/Http/Controllers/Api/Competency/NineBoxController.php:48,51
            app/Http/Controllers/Api/Talent/SuccessionPlanController.php:214-215
Evidence:   NineBoxController: private const PERF_BANDS = ['low' => 2.5, 'medium' => 3.5];
                               private const CAP_BANDS  = ['low' => 2.5, 'medium' => 3.5];
            SuccessionPlanController: 'potential_score'   => 'nullable|integer|min:1|max:3',
                                      'performance_score' => 'nullable|integer|min:1|max:3',
            grep -rn "PERF_BANDS\|CAP_BANDS" app -> only NineBoxController; no writer, no tenant setting.
Impact:     A succession candidate rated 3/3 and an employee in the high:high box are not the same
            statement, and no code maps one to the other. Cut-offs that decide who is "high
            potential" are a company policy choice frozen into a class constant.
Re-verify:  grep -n "PERF_BANDS\|CAP_BANDS" app/Http/Controllers/Api/Competency/NineBoxController.php
            grep -n "potential_score\|performance_score" app/Http/Controllers/Api/Talent/SuccessionPlanController.php
Fix sketch: Pick one scale; move the band cut-offs into tenant configuration.
```

#### F-64 — `talent_workflows` is read-only: eight rows, no writer, and no approval path consults it — MEDIUM
```
What:       An approval-workflow engine exists as three tables and a read endpoint. Nothing writes
            it and no Talent approval consults it.
Where:      app/Http/Controllers/Api/Talent/AdminWorkflowController.php (index only routed, routes/api.php:1546)
Evidence:   grep -rn "talent_workflows\|talent_workflow_stages\|talent_workflow_approvers" app
            returns 6 hits, all in AdminWorkflowController and all reads.
            Rows on 202.47.117.220/hp_erp: talent_workflows 8, stages 5, approvers 3.
            AdminWorkflowController@show is implemented but not routed.
Impact:     The Administration screen displays a workflow configuration that governs nothing. An
            administrator editing it would change no behaviour — except they cannot, because there
            is no write endpoint.
Re-verify:  grep -rn "talent_workflows" app --include=*.php
Fix sketch: Either wire the approval controllers to consult it, or remove the screen.
```

#### F-65 — A route-file comment names two migrations that do not exist — LOW
```
What:       routes/api.php documents the Talent mobility and offboarding tables as being created by
            two migrations that are not on disk.
Where:      routes/api.php:1467-1470
Evidence:   The comment cites 2026_07_30_130000_create_talent_mobility_tables and
            2026_07_30_140000_create_talent_offboarding_tables.
            ls database/migrations | grep 2026_07_30 -> seven files, neither of those. The real files
            are 2026_07_30_120001_create_talent_offboarding_tables.php and
            2026_07_30_120002_create_talent_mobility_requests_table.php.
Impact:     Small in itself, and included because it is the concrete proof of this repo's
            comment-drift: an auditor or developer trusting this comment reaches for a file that
            does not exist.
Re-verify:  ls database/migrations | grep 2026_07_30
Fix sketch: Correct or delete the comment.
```

#### F-66 — see the F-59 note above (resolved for two of the five tables)

Reserved. The F-59 update in section 5 records the two tables adopted with a tenant column in
Sprint 5 item 8; this id keeps the register's numbering continuous into the live re-audit findings.

#### F-67 — A tenant-1 admin can rewrite and steal a tenant-6 application — HIGH (executed on the 128.199 host, now FIXED)
```
What:       talent_jobapplicationcontroller::update() resolved the caller's tenant and then ignored
            it, loading the row with an unfiltered find($id). Any authenticated admin of any tenant
            could PUT another institute's application. Worse than a read: the write took first_name
            and, because sub_institute_id was reassigned from the request body, MOVED THE ROW INTO
            THE ATTACKER'S TENANT.
Where:      app/Http/Controllers/talent/talent_jobapplicationcontroller.php update() and updateStatus()
Evidence:   Executed on 128.199.17.97 during the live lifecycle re-audit. Tenant-1 admin (user 1)
            sent PUT /api/job-applications/21 {status:Rejected, first_name:HIJACKED,
            sub_institute_id:1} against a tenant-6 row -> HTTP 200 "Application updated
            successfully!". Database after: application 21 first_name='HIJACKED', status='Rejected',
            sub_institute_id=1. The row was restored from the app-DB copy immediately.
Impact:     Cross-tenant write and record theft, from any admin account, over the ATS. The single
            worst finding in this module - it corrupts data across the tenant boundary, silently.
Re-verify:  The exact request above. After the fix it returns 404 and the row is untouched, while
            tenant-6's own HR still updates it (200, updated_by = the token owner).
Fix:        DONE. The lookup is now `where('id',$id)->where('sub_institute_id',$tenant)->first()`, so a
            foreign row is a 404. sub_institute_id is no longer reassigned on the write, and
            updated_by is taken from the token (apiUserId), not $request->user_id, in both update()
            and updateStatus().
```

#### F-68 — Every v2 talent Eloquent create 500s on the 128.199 host (MariaDB 10.1) — HIGH (environment-specific, now FIXED)
```
What:       A model that uses $guarded without $fillable makes Eloquent verify each attribute is a
            real column on first mass-assignment, via getColumnListing(). That query selects
            `generation_expression` from information_schema - a column MariaDB added in 10.2. The
            128.199.17.97 host runs 10.1.48, where the introspection query itself errors, so every
            Model::create() in the v2 talent modules threw a 500 there.
Where:      13 controllers across Api/Onboarding, Api/Offboarding, Api/Performance calling
            <Model>::create(); the 17 models under app/Models/{Onboarding,Performance} with
            $guarded=['id'] and no $fillable.
Evidence:   POST /api/onboarding/journeys on the 128.199 host ->
            "SQLSTATE[42S22]: Unknown column 'generation_expression' in 'field list'". It is why that
            host held 0 onboarding journeys, 0 exit cases and had never completed a lifecycle. The
            application's own default host (202.47.117.220) runs MariaDB 10.11 and never hits it,
            which is how it went unseen.
Impact:     On any deployment against MariaDB 10.1, the entire v2 onboarding / offboarding /
            performance create surface is dead. The candidate->employee spine survives because it
            writes through raw DB::table, not Eloquent.
Re-verify:  OnboardingJourney::create([...]) against connection 'live' before the fix throws; after
            the fix it succeeds. Same for PerformanceCycle::create.
Fix:        DONE. A shared trait, app/Models/Concerns/SkipsGuardableColumnCheck, overrides
            isGuardableColumn() to return true - every real column is guardable, so behaviour for
            real attributes is unchanged; only the one introspection query the old engine cannot
            answer is skipped. Applied to all 17 affected models. The whole live lifecycle then
            completed: journey created, confirmed, terminated->exit case, cycle launched, review rated.
```

#### F-69 — Offer creation returned 503 for an allowlisted tenant, and blank-updated a posting — HIGH (both FIXED)
```
What:       Two defects on the offer/posting write path, both hit during the live re-audit.
            (a) TalentOfferController::store() gated the whole request on MailGate::allowed() - the
                GLOBAL email switch - and returned 503 if it was off. Tenant 6 is on the
                G2G_NOTIFY_EMAIL_TENANTS allowlist, but the global flag is off, so creating an offer
                for an allowlisted tenant failed 503 even though the offer row had already saved.
            (b) talent_jobpostingcontroller::update() writes every column unconditionally from
                $request->x with no "only if present" guard, so a status-only PUT blanked the entire
                posting (title, salary, description...).
Where:      TalentOfferController.php:214 (was); talent_jobpostingcontroller.php update()
Evidence:   (a) POST /api/talent-offers for tenant 6 on 128.199 -> 503
                "Outbound email is disabled for this environment (G2G_NOTIFY_EMAIL)."
            (b) PUT /api/job-postings/216 {status:Active} on 128.199 left posting 216 with an empty
                title and every other field null. Restored from the app-DB copy.
Impact:     (a) HR cannot make an offer for an allowlisted tenant, and when it half-works the
                candidate has an offer while HR sees an error. (b) any partial posting update
                destroys the posting.
Re-verify:  (a) After the fix, the same request returns 200, the offer is created, and the email
                actually sends for tenant 6 (mail.sent = true). (b) reported; the update() rewrite is
                out of this pass's scope beyond the note - it has no UI caller today.
Fix:        (a) DONE. store() now checks allowedForTenant($tenant) and, like candidateLink(), keeps
                the offer whether or not the mail sends, reporting mail.sent. (b) DONE. update() now
                writes only the fields the request sends (`$request->has($field)`); an absent field
                keeps its stored value. Proven on 128.199: a status-only PUT to posting 216 kept its
                title, location and every other field.
```

---

## 6. CORRECTIONS TO PRIOR WORK

**`Docs/phase3/06-feature-audit/talent.md:23` is no longer accurate.** It records
`talent_interviewpanelController@getInterviewPanel` / `api/interview-panel/list` as an executed
cross-tenant failure — "tenant B data returned when impersonating". Re-tested against the current
code, it does not reproduce: a tenant-6 token naming `sub_institute_id=3` receives 8 rows, all tenant
6, while a tenant-3 token receives 12 rows, all tenant 3. The mechanism is
`talent_interviewpanelController.php:35-45`, where `panelTenantId()` tries `apiTenantId()` first and
only falls back to the session. Recorded here as a **corrected** claim, not a refuted auditor: the
finding was presumably true before that helper landed.

**`FIX-PLAN-v2.md` cross-references, not new findings.** The mock-data defects on reachable Talent
screens are already registered and are not renumbered here: **F-23** (`talent-profile-view.tsx:49-50`
renders `mockProfileData` and ignores its `profileId`) and **F-28** (`admin-center.tsx:157` renders
five hardcoded KPI cards above a live paginated table). Both re-confirmed present at the pinned
commit.

**One prior concern is dead and should not be carried forward.** The request-fallback branch in
`ResolvesApiIdentity.php:56-76` is unreachable in current data: `tbluser` rows with
`sub_institute_id` NULL or 0 number **0 of 2370** on the application database and **0 of 299** on the
128.199 deployment. The code comment at :66-68 asserting "historical rows predate tenant assignment"
is not supported by either host.

---

## 7. WHAT WAS PROVEN CORRECT

Recorded because an audit that only lists defects is not a measurement.

- **Tenant isolation on the modern surface holds.** `GET /api/performance/reviews/41`, a tenant-3
  row, returns **404** to both tenant-6 tokens — the correct answer, not a 403 that would confirm
  existence. List totals scope correctly: 222 for tenant 3, 1 for tenant 6.
- **Routes with no middleware are not unauthenticated.** `/api/talent/dashboard`,
  `/api/performance/overview`, `/api/mobility/overview` and `/api/talent/succession/plans` all return
  **401** anonymously. 120 of the 124 inventoried routes derive the tenant from the token.
- **Server-side validation is real.** Four endpoints returned 422 with correct field errors and wrote
  nothing (row counts identical before and after): mobility requests, performance goals, performance
  reviews, performance appraisals.
- **Error handling is correct.** 401 for missing, malformed, unknown and wrong-scheme tokens; 404 for
  nonexistent, non-numeric and negative ids; no crash on `syear=1899`, `page=-5`, a 1000-character
  search or an emoji query.
- **Pagination is capped.** `limit=100000` still returns 25 rows against a 222-row set.
- **The `profile:admin,hr` gate works** where it is applied — 403 for an employee token.
- **The Talent→LMS bridge exists**, at four independent points, contradicting any claim that it does
  not: `LearningAssignmentController`, `CourseCompetencyMapController`, `Services/Events/LearningAssigner`,
  `Services/Events/RemediationRecommender`.

---

## 8. OPEN QUESTIONS

- **Q-T1 — Does an ordinary employee see colleagues' compensation on a populated tenant?**
  `/api/performance/compensation`, `/bonus` and `/appraisals` return 200 to an employee token, but the
  only employee login available is on tenant 6, which holds no Performance rows. Needs an
  employee-level login on tenant 3 (222 reviews). This decides whether F-56 is HIGH or CRITICAL.
- **Q-T2 — Are the seven DEAD-DATA stages unused, or unusable?** Zero rows plus no swallowed
  exception means nobody completed the flow; it does not say why. Needs a product decision or one
  supervised end-to-end attempt on a test tenant.
- **Q-T3 — Which mobility generation is intended to survive**, `talent_*` or `s_mobility_*`? Both are
  empty; keeping both guarantees drift.
- **Q-T4 — Is Talent meant to be year-scoped?** F-61 cannot be graded without that decision.

### Obligations this audit did not meet

Stated plainly rather than dropped.

- **Part C, eight of nine roles.** Only `administrator` and `employee` logins were available. The
  seven other role journeys are unproven at the API. One structural claim needs no probe and holds:
  `RequireProfile::ALIASES` has no `department_head` entry, so no route argument can currently grant a
  Department Head anything.
- **Part F golden transactions** were traced through row counts, not executed — running them requires
  writes to a shared remote database, declined under ground rule 8.
- **Part B** was assembled from the route inventory, the frontend map and the row census rather than
  from three independent blind inventories: the agent fleet doing that work stopped on a session
  limit. The stage rows above are evidence-backed but were not produced by the intended
  cross-checking process, so treat individual WIRED verdicts as good but not adversarially verified.
- **Declined probes**, per plan: `POST /api/mobility/promotions` with `status:"Completed"` (rewrites
  `tbluser` and `org_designation`, no transaction, no audit row), mobility transfer and succession
  writes, and any payload that could succeed.

---

## 9. MASTER-SHEET ROW

| Module | Front door | Lifecycle | Roles | External | Data live | API | CRUD | Validation | Rules | RBAC/Tenant | Integration | Calc | Scale | Errors | Audit | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Talent Management | AMBER | RED | RED | RED | AMBER | GREEN | AMBER | AMBER | AMBER | RED / GREEN | AMBER | AMBER | AMBER | GREEN | RED | **RED** |

RBAC/Tenant is split deliberately: **tenant isolation GREEN, RBAC RED**. Collapsing them to one
colour is what produced the previous audit's wrong headline.

---

## F-70 — AI marking of written answers silently produced nothing, and always had

**Severity: HIGH.** `AssessmentScoringService::scoreShortAnswers()` never returned a mark. Every
short-answer and coding answer fell through to `awaiting_review`, indistinguishable from "the model
was unavailable". No error was raised, and the calls were billed.

**How it presented.** HTTP 200, `finish_reason=stop`, ~40 completion tokens of **pure whitespace**.
Reproduced 7 consecutive times on one prompt, across three system messages, with and without
`top_p`, at three `max_tokens` values. It read as a DeepSeek fault.

**What it actually was.** Resending identical bytes with `response_format` REMOVED made it legible
in one call — the reply came back as:

```
_id=34 score=30 feedback="Correctly explains that an index enables bin…
```

The model was not answering. It was **imitating the shape of the input data**, which was fed as
`response_id=34 / MAX SCORE: 30 / QUESTION: …`. In JSON mode that continuation is unemittable, so
the API returned whitespace instead.

**Three contributing causes, in the order they mattered:**

| # | Cause | Effect when fixed |
|---|---|---|
| 1 | Answers fed as `key=value` text, so the model imitated the format | **6/6 markings, zero retries** |
| 2 | System message ending `"…and nothing else - no prose, no markdown fences"` | Measurably shifted the rate; never eliminated it |
| 3 | Prompt ending on a JSON literal (`Return JSON: {…}`) — one more thing to continue | Schema moved before the data |

Only (1) is the root cause. (2) was reproduced independently on both the short-answer and coding
prompts, so it is real but secondary — the corrected wording marked correctly twice, then blanked
three times on the same bytes.

**Fixed in:**
- `AssessmentScoringService::answersAsJson()` — answers are now a JSON array, so imitation produces
  the wanted shape. Instructions stay prose, because those are not meant to be copied.
- `AssessmentScoringService::markingSystemPrompt()` — ends at "a single valid JSON object."
- `DeepSeekService::chat()` / `perturb()` — a blank is retried with a perturbed prompt, and the
  **last attempt drops JSON mode**, which is the only precondition the blank was ever seen under.
  `chatJson()` gained `extractJsonObject()` (brace-counted, string-literal aware) so that fallback's
  prose-wrapped object still parses. This net has not fired since (1) landed.

**Measured after the fix:** 6/6 complete markings with zero retries, then 3/3 full lifecycle runs.

**Same-class risk, flagged not changed:** `AiCourseController.php:137` ends its system message with
"Reply with a single JSON object and nothing else." — the (2) pattern, in a different feature that
was not measured here.

---

## F-71 — MCQ options were silently truncated on live, marking correct answers wrong

**Severity: HIGH. Predates candidate assessment — the employee flow has always had it.**

`competency_assessment_question.correct_option` and
`competency_assessment_response.selected_option` were both `VARCHAR(50)`, and the value
stored in them is the **option's full text**, not a letter: the generator writes the option
verbatim, the taker sends it verbatim, and the scorer compares the two as strings.

Fifty characters is shorter than a normal multiple-choice option. Generating a hiring paper for
*Senior Physiotherapist* produced this correct answer, at **101 characters**:

> "Modify the plan based on the client's performance, motivation, safety, and outcome measures collected."

**The two hosts fail differently, and live fails worse:**

| Host | `sql_mode` | Result |
|---|---|---|
| App `202.47.117.220` | `STRICT_TRANS_TABLES` | Error 1406, generation fails loudly — the good outcome |
| **Live `128.199.17.97`** | no strict mode | **Insert succeeds, value cut to 50 chars.** `selected_option` then holds a differently-truncated copy of whatever the candidate clicked, so **every long MCQ marks itself wrong, for everybody, with no error anywhere.** |

**Fixed by** `2026_09_04_120000_widen_assessment_option_columns` — both columns to `VARCHAR(255)`
on both hosts, proved identical afterwards. Neither column is indexed (verified on both hosts
before writing the migration), so the 767-byte prefix cap does not apply. `down()` **refuses**
rather than narrowing when any row would be truncated — a rollback must not corrupt data to
succeed.

**Also fixed alongside:** `RecruitmentAssessmentGenerator::MAX_OPTION_CHARS = 240` caps the model,
`acceptable()` drops an over-long question rather than losing the whole paper to a DB error, and
the candidate API's `max:50` validator — which would otherwise have rejected exactly the options
the migration had just made storable — now reads the same const.

---

## F-72 — two MCQ scorers had already drifted apart

`AiAssessmentController::submit()` compared `selected_option` to `correct_option` **exactly**.
The candidate magic-link path, written separately, lower-cased and trimmed `answer_text`. Same
paper, two different marks, and nothing would have reported the disagreement — the same failure
shape as the four employee-row writers that produced `EmployeeFactory`.

Now one implementation, `AssessmentScoringService::scoreMultipleChoice()`, called by both. The
comparison is exact on a trimmed string: the option label is echoed back from what the server
sent, so a case difference means the client altered it.

---

## Frontend sweep — 45 dead-control claims, 2 verified, 43 UNVERIFIED

A parallel audit of the talent frontend produced 45 dead-control claims. **The verification pass
did not run** — the session hit its limit and all 46 verifier agents died. Two were then
confirmed by hand; the rest are **claims, not findings**, and must be re-verified before anyone
acts on them.

**Verified by direct inspection:**

- **`mobility-center.tsx:1122` — invented analytics.** Literal `46` total moves, `20 (43%)`
  transfers, `16 (35%)` promotions in a CSS-drawn donut. `movement_summary` appears **0 times**
  in the component and **1 time** in `services/talent/mobility.ts` — the real figures arrive over
  the wire and are discarded. Actual tenant-6 counts: **4 transfers, 0 promotions.**
- **`offboarding-center.tsx:696` — the History view blanks the page.** `viewLayout` is typed
  `'list' | 'kanban' | 'history'` (:106) and the History button sets it (:701), but only `'list'`
  (:743) and `'kanban'` (:887) have render branches. Clicking History empties the content area.

**Unverified claim distribution:**

| Count | File | Kind |
|---|---|---|
| 8 | `offboarding-center.tsx` | hardcoded data shown as live |
| 7 | `mobility-center.tsx` | button with no handler |
| 5 | `admin-center.tsx` | button with no handler |
| 4 | `offboarding-center.tsx` | no handler |
| 3 | `mobility-center.tsx` | hardcoded data shown as live |
| 3 | `mobility-center.tsx` | `cursor-pointer` div with no `onClick` |
| 2 | `offboarding-center.tsx` | orphan state |
| 10 | `mobility-center.tsx` / `admin-center.tsx` | one each: mis-wired form saving to the wrong record, inert dropdown, dead checkbox, invented person + date, invented record id, four KPI tiles sharing one destination, and others |

The offboarding cluster is concentrated: **12 of 15 sit in the drawer's Overview tab
(lines 1307-1430)**, which is still the original static mockup — even though `ExitCase.owner`,
`employee.manager` and the file's own `getClearanceProgress()` helper all exist and are ignored.

---

## F-73 — a job-role name could overwrite an employee's role id, and did

**Severity: HIGH. Reproduced live during this work.**

`MobilityTransferController::completeTransferInProfile()` and
`MobilityPromotionController::completePromotionInProfile()` both ended with:

```php
$allocatedStandards = $jobroleId ?: $transfer->to_jobrole;   // or proposed_designation
```

`tbluser.allocated_standards` holds a **numeric `s_user_jobrole` id** — 21 of 21
populated rows in tenant 6, verified on both hosts. Every join that reads it compares
against that id. So a lookup miss wrote the typed NAME into the column and silently
detached the employee from their job role for every one of those joins, while the row
still looked plausible on screen.

**This was demonstrated, not inferred.** Completing one test promotion set real
employee 28's `allocated_standards` to `'Senior Analyst'`; the correct value, still
present on the untouched live host, was `'4342'`. It was restored from there and the
tenant re-verified at 21 of 21 numeric.

**Fixed:** a miss now leaves the column alone and logs at info. The mobility record
still completes and `org_designation` is still written — only the employee's master
row is left unrewritten with a value the rest of the system cannot read. The
client-side job-role picker (instead of free text) is the other half and is not yet
done.

---

## F-74 — completing a promotion with no designation returned a 500 and rolled back

`s_mobility_promotions.proposed_designation` is nullable; `org_designation.designation`
is NOT NULL. `store()` requires the field, so an API-created promotion always has one —
but a row from an import, a seed or a direct edit does not, and completing it raised

```
SQLSTATE[23000] 1048 Column 'designation' cannot be null
```

**inside the transaction**, so the status change rolled back with it. The user pressed
Complete, saw a 500, and nothing happened — with nothing indicating the missing field
was the cause. Now refused before the transaction with a message naming it.

---

## F-75 — Mobility's headline analytics were invented in the CONTROLLER

The frontend sweep reported that `mobility-center.tsx` renders literal counts and
discards the real `movement_summary` from the wire. Half right, and the half it missed
is worse: **the wire figures were invented too.**

```php
// "Fallback dummy to look complete if database is fresh"
'transfers'     => max(20, $completedTransfers),    // real count: 4
'promotions'    => max(16, $completedPromotions),   // real count: 0
'lateral_moves' => max(10, $completedTransfers / 2),
'ready_now'     => max(6,  $readyNow),              // real count: 0
'no_successor'  => 0,                               // hardcoded
```

A **floor on real data is worse than a constant**: with 4 transfers it reports 20; with
25 it reports 25. The number is sometimes true and nothing on screen says which, so
"46 people moved this year" against a reality of 4 is indistinguishable from a fact —
and it sits in the same grid as `top_departments`, which is genuinely live.

`lateral_moves` was `$completedTransfers / 2`: not a count of anything, and a fraction
where a headcount was displayed. `no_successor` being a hardcoded `0` is the most
misleading of the five — it is the one figure that exists to prompt action, and it could
never be anything but reassuring.

**Fixed:** all five report real counts. `lateral_moves` now counts completed transfers
whose job role did not change (a real definition, derivable from `from_jobrole` /
`to_jobrole`); `no_successor` counts active plans with no successor. The two cards were
wired to that data with a conic-gradient ring, so the arcs and the legend are computed
from one array and cannot disagree.

```
movement_summary   : 20/16/10 = 46  ->  4/0/0 = 4
succession_coverage: 6/10/6/0 = 22  ->  0/0/0/0 = 0
```

---

## F-76 — Mobility's filter endpoint leaked 1,226 departments across 13 tenants

`MobilityOverviewController::filters()` selected `hrms_departments` with **no
`sub_institute_id` predicate** — the only list in its own method missing one, since
`employees` and `jobroles` both had it. Any authenticated user of any organisation could
read every other organisation's department names.

```
departments returned: 1226 (13 tenants)  ->  50 (this tenant)
```

The leak stayed invisible because picking a foreign department then made the job filter
return nothing, which read as a broken filter rather than a disclosure.

**Also fixed with it:** the department filter compared the id the client sends against
the department NAME stored on `s_mobility_jobs`, so **every option returned zero rows**.
A numeric value is now resolved through `hrms_departments` tenant-scoped; a name still
matches directly; an unresolvable id filters to nothing rather than being ignored, since
silently dropping the filter would present the full list as the contents of one
department.

---

## F-77 — `employee.hired` had no consumer, so hiring never started onboarding

**Severity: HIGH. The single biggest break in the employee lifecycle.**

`EmployeeFactory` emits `employee.hired` on offer acceptance, correctly and in the
same transaction as the hire. **Nothing read it.** The catalogue said so in writing:

```php
'employee.hired' => [
    'verdict' => 'DEFERRED',
    'trigger' => 'OnboardingLauncher is built (X-14).',
    'reason'  => 'Its only declared consumer, OnboardingLauncher, was never written.',
],
```

`app/Services/Events/OnboardingLauncher.php` did not exist.

So the chain Recruitment → Onboarding was severed at exactly one joint. The hire was
recorded properly — `tbluser` row, application moved to `Hired`, event written — and
then the journey had to be started by a human walking to a different screen and
re-selecting the same offer from a dropdown. `POST /onboarding/journeys/from-offer/{id}`
existed and worked; its only caller in either codebase was that dialog.

On the **candidate self-service path** it was worse: the candidate accepts through
their magic link, there is no operator on a screen at all, and nothing started until
somebody noticed.

**Fixed:**
- `App\Services\Talent\OnboardingJourneyFactory` — journey creation lifted out of the
  controller so both callers share one implementation. The controller's
  `createJourney()` now delegates to it, so its eleven call sites are unchanged. This
  is the `EmployeeFactory` pattern: the audit found four writers of employee rows that
  had drifted apart, and two writers of "what a new journey looks like" would go the
  same way.
- `App\Services\Events\OnboardingLauncher` — the reactor, handling `employee.hired`.
- Registered in `ReactEvents::REACTORS`. **This line is load-bearing**: `EventRecorder`
  only INSERTs into `g2g_event`; delivery is the scheduled `events:react` command whose
  reactor list is that hardcoded const, so the class is inert without it.
- `EventCatalogue`: `employee.hired` moved from `NOT_SHIPPED` to `SHIPPED` naming its
  consumer, with the deferral left as a comment beside it — the treatment
  `task.reopened` and `certification.issued` already have.

**Proved 8/8** on tenant 6: emit a real `employee.hired` → reactor consumes it →
journey created with all **7 stages seeded**, identical to a hand-made one → a second
delivery creates no second journey → a direct-entry hire with no `offer_id` is recorded
as `skipped` with the reason, not as a failure.

### Still open on this leg, found by the same trace

- **A new hire never emits `employee.role_assigned`**, so `LearningAssigner` never
  assigns their job role's mandatory courses. The Mobility fix (F-73) plugged the
  transfer and promotion holes; the hire hole is separate and remains.
- **Creating an onboarding journey emits no event at all**, so nothing downstream of
  Onboarding is ever told it started.

---

## F-78 — a candidate hired from an offer got a department but NO job role

**Severity: HIGH.** `OfferAcceptanceService` passed `department_id`, name, email, mobile,
profile and joining date to `EmployeeFactory` — and nothing about the person's role. So
a candidate hired through Recruitment landed in the Employee Directory with
`allocated_standards` empty: no job role, and therefore no competency expectations, no
role-based learning, nothing for the 9-box or succession planning to read.

Someone added by hand through the **Add Employee wizard** got a role. The identical
person arriving through **recruitment** did not. Both paths already share
`EmployeeFactory`, so the difference was purely in what the caller passed.

### The trap in the obvious fix

`talent_job_postings.jobrole_id` looks like the answer — but it points at **`s_jobrole`**,
the global 3,347-role catalogue used to generate assessments.
`tbluser.allocated_standards` holds an **`s_user_jobrole`** id, this tenant's own list.

```
of 21 populated allocated_standards values in tenant 6:
   14 exist in s_user_jobrole
    0 exist in s_jobrole
```

Copying one into the other would be **F-73 in a new form** — a plausible-looking number
from the wrong table, silently detaching the employee from every join that reads it.

**Fixed** by resolving the posting's **title** against this tenant's `s_user_jobrole`,
bound as a value rather than joined (the two columns have different collations —
`utf8mb4_unicode_ci` vs `utf8mb4_general_ci` — so a column-to-column join raises 1267).
Measured on tenant 6: **12 of 13 posting titles resolve exactly.** A miss leaves the
column NULL, never the title text.

---

## F-79 — a new hire never triggered their role's mandatory learning

`employee.role_assigned` is what `LearningAssigner` reacts to in order to assign a job
role's **mandatory** courses. It was emitted by the HR Employee Directory on a role
change, and (since F-73) by Mobility on transfer or promotion completion.

**It was never emitted on hire.** So an existing employee moved into a role received
that role's induction training, and a brand-new hire given the identical role on day one
received nothing — the one person who most needs it was the only one who never got it.

`EmployeeFactory` now emits it alongside `employee.hired`, in the same transaction, and
only when a role was actually assigned.

---

## F-80 — starting an onboarding journey told nobody

Creating a journey emitted no event at all, so Onboarding was the end of the chain:
anything that should follow a person's induction had no way to know it had begun. The
activity log is a human-readable trail on one screen — it is not an event and nothing
consumes it.

`OnboardingJourneyFactory` now emits `onboarding.journey_started`, carrying the
journey code, employee id (nullable and meaningfully so), offer, department, position and
joining date. **No consumer exists yet, deliberately** — the fact is on the record from
today and a later reactor can replay it. Writing the consumer first is precisely how
`employee.hired` ended up emitted-and-ignored (F-77).

---

## F-81 — four tabs, four copies of the same journey picker

The Onboarding screen's four tabs — Preboarding, Onboarding Journey, Probation &
Confirmation, Lifecycle Timeline — each rendered their own "No hire selected" card with
their own **Browse journeys** button, plus one more in the sidebar and two more inside
the Preboarding tab. Seven copies of a control the header's **More Actions** dropdown
already carries as "Browse all journeys", "New journey" and "Start from accepted offer".

The effect was that every tab looked like a different form for the same thing.

**Fixed:** one empty state above the tabs, offering Browse and New once. The tabs render
nothing when no journey is selected. Probation is excluded because it is a **list across
all hires** and reads correctly with nobody selected.

---

## F-82 — the offer letter never reached the person it was about

An offer letter was generated, stored and emailed at offer time — all 14 tenant-6 offers carry an
`offer_letter_url` — and then it stopped there. `staff_document` held **0 rows** for tenant 6. Once a
candidate became an employee, the single most important document of their hiring was in a bucket and
in their inbox, and nowhere on their record.

**Fixed** by `App\Services\Events\OfferLetterFiler`, a consumer of `employee.hired` that files the
letter into **both** places, because they answer different questions:

- **`staff_document`** — the permanent personnel record, already rendered by the Employee Directory's
  Upload Document tab. No new endpoint or screen was needed.
- **`talent_onboarding_documents`** — scoped to the journey the new hire is working through, so it is
  in front of them in week one.

### Two bugs caught before shipping, both silent

The first draft wrote to `document_type` and `public/staff_document/`. Neither is what the Employee
Directory uses:

| Wrote to | Directory actually uses | Consequence |
|---|---|---|
| `document_type` | **`student_document_type`** (`tbluserController:784`, INNER JOIN) | row dropped by the join — invisible |
| `public/staff_document/` | **`public/hp_staff_document/`** (`:1339`) | row visible, download 404s |

`student_document_type` already contains **id 3, "offer", user_type staff** — resolved by name rather
than hardcoded. The regression guard is an assertion that runs the directory's exact inner join.

**Proved 15/15**, including the before/after that shows what a consumer is: `staff_document` 0 rows
and `g2g_event_delivery` empty before, `status=done` and 1 row after, with nobody pressing anything.

---

## F-83 — `reportmanager` accepted a name into a bigint column

`TalentOfferController::store()` validated `reportmanager` as **`nullable|string`** while
`talent_offers.reportmanager` is **`bigint(20) unsigned`**, and the UI was a free-text box asking HR
to type a raw numeric user id.

A recruiter typing a **name** would error on the app host (`STRICT_TRANS_TABLES`) and be **silently
coerced to `0` on live**, which is not strict — an offer letter reporting to employee zero. Same
class as F-73 and F-78: a plausible value from the wrong space written into an id column.

**Fixed** on both sides. The rule is now `nullable|integer|exists:tbluser,id`, and the field is a
Select fed by the existing tenant-scoped `GET /api/competency/employee-options`.

**Deliberately NOT auto-filled.** `talent_job_postings` has no hiring-manager column — only
`created_by`, which the backend already uses as the offer letter's *signer*. Whoever raised a
requisition is not necessarily who the hire reports to, and a plausible unchecked id on an offer
letter is worse than an empty field.

**Proved 9/9**: a chosen id round-trips and joins to a real employee; `'Priya Sharma'` returns **422**
with no row written; a nonexistent id returns 422.

---

## F-84 — the offer form pre-filled nothing, and inherited another form's values

Eleven blank fields, though `candidates` and `jobs` were already in memory with name, email,
`expectedCtc` and `jobOpening`. The `interview` action had seeded from `preselectedCandidate` all
along; `offer` never did. There was also **no per-candidate "Create offer" button anywhere** — the
only entry point was a global menu with no candidate context, so HR re-picked the person from a list
of everyone.

Worse, `values` is shared by all six drawer actions and was reset only **after a successful save**, so
opening `job-view`/`job-edit` (which copy the whole job row into it) and then Create Offer started the
offer form with the **job's** `start_date` and `benefits` filled in.

**Fixed**: reset on action change; `buildOfferDefaults()` extracted and asserted **9/9**; "Create
offer" added to the candidate row menu and the drawer's Offer tab (which previously reported "No offer
found" and offered no way to make one).

**The assertion that matters**: `use-recruitment.ts:69` maps a null expected salary to the *string*
`'—'` for display. Seeding it blindly would have posted an em dash as the offered salary — invisible
in a screenshot, wrong in the database.

---

## F-85 — the five onboarding workstreams were built end to end and never fed

**IT Provisioning · Learning & Training · Payroll Setup · Benefits Enrollment · Compliance**

Every piece of this feature existed except the one that makes it work:

| Piece | State |
|---|---|
| `WORKSTREAMS` const naming all five | built (`ResolvesOnboardingContext:27`) |
| `GET /api/onboarding/workstreams` — `GROUP BY category` with derived status | built (`OnboardingTaskController:398`) |
| Five cards on the Preboarding tab, wired as category filters | built |
| Full task CRUD, bulk actions, CSV export, activity log | built |
| **Anything that creates a task** | **did not exist** |

Journey creation seeded **stages only**. The single writer of a task row was a human pressing "Add
Task". Across the whole installation there was **1 task**, so all five cards read
*"No tasks yet / Not Started"* on every journey in every organisation — permanently.

The contrast that makes it plain: **offboarding already had this.**
`OffboardingCaseFactory::DEFAULT_CLEARANCE_TASKS` applies an eight-item IT/HR/Finance/Admin checklist
to every new exit case. The exit door had a default checklist; the entry door had none.

**Fixed** with `ONBOARDING_TASK_TEMPLATE` (15 tasks across the five categories) seeded by
`OnboardingJourneyFactory::seedTasks()`, beside the existing `seedStages()` — so a journey the
`OnboardingLauncher` reactor starts automatically on hire is born with its checklist too.

**Due dates are offsets from `joining_date`**, negative before / positive after — the model
[SuccessFactors uses](https://help.sap.com/docs/successfactors-onboarding/implementing-onboarding/onboarding-tasks)
("days before Start Date"). Payroll at −5 because a missed cutoff costs a month; IT at −3 so the
laptop exists on day one; compliance at +1; benefits at +7. **A journey with no joining date gets NULL
due dates, not offsets from today** — an invented deadline shows red for a reason nobody can trace.

`owner_label` names the accountable team (Finance / IT / HR / Manager) as **information**;
`owner_id` stays null and HR completes, because this module has no IT or Finance login gate and
assigning to a role nobody holds would strand the task.

**Proved 8/8** on tenant 6 through the real path — accept an offer, let the reactor create the
journey, then read the endpoint:

```
BEFORE   IT / Learning / Payroll / Benefits / Compliance    all "No tasks yet"
AFTER    it=4  learning=2  payroll=4  benefits=2  compliance=3
         due dates are offsets from the JOINING date        PASS
         no joining date produces NULL due dates, not 1970  PASS
```

### Still open on this feature

- **No data capture yet.** A task records that IT provisioning happened; there is no asset register
  (serial numbers), no benefits enrolment table, and no policy-acknowledgement table with a version.
  Those three need schema on both hosts.
- **Payroll needs no schema** — `tbluser` already has `bank_name`, `account_no`, `ifsc_code`,
  `pan_no`, `aadhar_no`, `pf_no`, `esic_no`, `uan_no`, `pf_deduction`. It needs a form.
  Note the Payroll module itself is Blade-only with **no `/api/*` surface**, so the Next.js screen
  must write through `EmployeeDirectoryController`, not the payroll controller.
- **Learning should report, not duplicate.** `LearningAssigner` already assigns a role's mandatory
  courses on `employee.role_assigned` (which now fires on hire, F-79). The Learning group should
  surface those `lms_course_enroll` rows rather than keep a second list.
- **`onboarding.journey_started` still has no consumer** and is not yet in `EventCatalogue`.

---

## F-86 — a completed onboarding task was the only record that anything happened

**Severity: high.** Closes the "Still open on this feature" list under F-85.

F-85 gave the five workstreams a checklist. A checklist is a claim, not a record. Ticking *"Issue
laptop and peripherals"* left the organisation with no idea **which** laptop, so at exit there was
nothing to reclaim; ticking *"Acknowledge employee handbook"* left no version, so a re-issued
handbook silently read as already signed; and payroll setup had nine `tbluser` columns and no screen
that wrote them.

### What was missing, and what already existed

| Workstream | Before | Now |
|---|---|---|
| IT Provisioning | nothing | `talent_onboarding_assets` — serial number, issue and return dates |
| Benefits Enrollment | nothing | `talent_employee_benefits` — provider, policy no, cover, nominee |
| Compliance | `master_compliance` is an **organisational** register, not per-employee | `talent_policy_acknowledgements` — keyed on employee + policy + **version** |
| Payroll Setup | nine `tbluser` columns, no writer | a form on the journey; **no schema added** |
| Learning & Training | `lms_course_enroll` (1,468 rows), written by `LearningAssigner` | **read** and shown; deliberately not writable here |

Learning stays read-only on purpose. A second list of "what this person must learn" would drift from
the role mapping that produced the first one, which is the exact failure this module keeps repeating.

### Three decisions worth naming

**The version is part of the compliance fact.** `talent_policy_acknowledgements` is unique on
`(employee_id, policy_key, policy_version)` — 488 bytes, inside the 767-byte prefix cap on live's
`ROW_FORMAT=Compact`. Acknowledging the same version twice is `updateOrInsert`, not a second row, so
a compliance count cannot be inflated by a double click; acknowledging a **new** version is a new
row, which is the whole reason the version is in the key.

**The serial makes it a register.** Issuing a serial already held by someone else is rejected 422
**naming the current holder**, and the number frees up once the asset is returned — so the same
laptop can be reissued, but two people cannot hold it at once.

**Payroll writes only what was sent.** The endpoint enumerates the nine permitted columns rather than
taking them from the request (`tbluser` has 99 columns and sits behind the same token), and writes
only keys actually present. Saving the bank half of the form cannot blank a UAN captured earlier —
the Employee Directory writes these same columns. The UI reinforces it: only changed fields are sent,
and the button reads *"Save 3 changes"* or is disabled.

PAN, Aadhaar and UAN are **format**-checked, not just length-checked. A PAN that is not a PAN fails
at the TDS return months later, for somebody else to unpick.

### Where it appears

Under the five workstream cards on the existing **Preboarding** tab, opened by clicking a card — the
card was previously a filter chip and nothing else. **No sixth tab**: F-81 had just de-duplicated the
tabs and another one would undo that.

### Proved 20/20 over HTTP on tenant 6, through the real hire path

```
IT           serial stored, not just a tick                       PASS
             the same serial cannot be issued twice               PASS  (422, names the holder)
             once returned, the serial can be issued again        PASS
Benefits     coverage is exact — DECIMAL(12,2), not a float       PASS  500000.00
Compliance   the same version twice makes ONE row                 PASS
             a NEW version is a new acknowledgement               PASS
             the IP is recorded for the audit                     PASS
Payroll      a malformed PAN is refused                           PASS  422
             values land on tbluser, where the Directory reads    PASS
             saving one field does not blank the others           PASS
Learning     READS enrolments rather than keeping its own list    PASS
Tenancy      another tenant gets 404, not 403                     PASS
```

Migration ran one file at a time on **both** hosts and was proved equal, 14/14: same columns, same
index names, widest index 488 bytes, **no `json` column** (live is MariaDB 10.1) and **no `ENUM`** —
asset and benefit types are `VARCHAR` + PHP const, so adding a type is never an `ALTER` on live.
`down()` refuses to drop a non-empty table. Every test row was removed afterwards.

### Still open

- **`onboarding.journey_started` still has no consumer** and is not in `EventCatalogue` (carried from
  F-80/F-85).
- Asset **return** is recorded here but offboarding does not yet read it — the exit checklist still
  says "collect equipment" without listing what was issued. The data now exists for it to.
