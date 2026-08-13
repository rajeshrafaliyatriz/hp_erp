# G2G Platform — Verified Audit & Fix Plan (v2)

**Date:** 2026-08-04
**Author:** Independent re-audit (supersedes `AUDIT-FINDINGS-v1.md`)
**Repos:** `hp_erp` (Laravel 12 backend) · `g2gv0` (Next.js 16 frontend)
**Status:** AUDIT COMPLETE — NO CODE CHANGED. Awaiting go-ahead per phase.

---

## 0. HOW TO USE THIS DOCUMENT

This file is the durable source of truth for the remediation effort. It is written to be picked up **cold**, months later, with no memory of the conversation that produced it.

- **Section 2** = what v1 got wrong. Read before trusting v1.
- **Section 4** = the findings register. Every finding has a stable ID (`F-01`…), file:line evidence, and a re-verification command.
- **Section 5** = the phased fix plan. Work top to bottom; phases are ordered by dependency, not convenience.
- **Section 7** = the progress tracker. **Update it as work lands.**
- **Section 8** = open questions that block specific fixes. Do not guess these.

**Ground rule carried from the audit brief:** nothing in this document has been implemented. Every claim below was verified against source on 2026-08-04.

---

## 1. EXECUTIVE VERDICT

**Not ready to ship.** Two blocking classes of defect:

1. **Authorization is broken platform-wide.** Any user holding *any* valid token can read and write *any other organisation's* HR, competency, performance, and talent data by editing one query parameter — and can attribute those writes to any user they choose. This affects **70 of 126 API controllers**. It is not a theoretical exposure; it is a one-parameter change.
2. **The lifecycle the product is named for does not exist.** LMS completions, task outcomes, and competency scores do not flow into one another. The modules share an employee table and nothing else.

Everything else is downstream of these two.

**The good news, and it is genuinely good:** the Task Management module is a *correct* reference implementation of the security model. `ResolvesTaskContext` already does exactly what the other eight traits should do, and its code comments explicitly describe the vulnerability that was fixed there. Phase 1 is largely **propagating a fix that already exists in this codebase** — not inventing one.

---

## 2. CORRECTIONS TO `AUDIT-FINDINGS-v1.md`

v1 was directionally useful but has errors that would misdirect the fix effort. Recorded here so nobody re-derives them.

| v1 claim | Reality | Impact on plan |
|---|---|---|
| "The API is unauthenticated" (its #1 Critical) | **Overstated and it hid the real bug.** There is indeed no `$middleware->api()` group, but 110 of 126 API controllers self-guard via `Resolves*Context` traits or `findToken`. The real defect is that those guards **validate the token and then ignore who it belongs to**. v1's framing points at a middleware fix that would not close the actual hole. | Rewrites Phase 1 entirely |
| "Where is the per-employee competency score stored? I could not find it" (its open Q5) | **Found: `s_skill_matrix`** (`user_id`, `skill_id`, `skill_level`, `interest_level`, `knowledge`, `ability`). Migration `2025_06_11_100709`. | LMS→Competency bridge is small work, not a schema design project |
| "No Task→LMS bridge exists" | **A bridge exists** — `SkillMatchingController` + `POST /api/employee/course-suggestions`. It is built on the *K-12 school* curriculum tables, which is its own problem, but it is not absent. | Changes F-12 from "build" to "repoint" |
| "Mock data problem is narrow and concentrated (67 hits), cheap to finish" | **Understated.** v1 missed rendered mocks in Department Policies/Rules/SOPs, Talent Admin KPIs, and the Employee Directory error-fallback. | Phase 4 is larger than v1 implied |
| TS7006 implicit-any list (`use-recruitment.ts`, `use-salary-*`, `use-sessions.ts`, `use-talent-dashboard.ts`) | **All false positives** — artifacts of v1's failed `npm install`. A clean `tsc` reports none of them. | Delete from backlog |
| "Two 'Coming soon' modules (Compensation + Payroll)" | **One.** `COMING_SOON_CONTENT` has a single entry, `'50'` Compensation. | Minor |
| "`next build` / `eslint` could not be run" | **Both run clean here.** Results in F-20/F-21. | Fills the Part B gap |
| `DELETE /feedback/{id}` marked "UNVERIFIED, candidate Medium" | **Confirmed broken AND user-reachable** — live "Delete feedback" button, no backend route, no error handling. | Upgraded to High (F-16) |

---

## 3. VERIFIED FACT BASE

Re-runnable. If these numbers drift, the plan needs revisiting.

| Metric | Value | How to re-verify |
|---|---|---|
| Laravel controllers / models / migrations | 287 / 199 / 210 | `find app/Http/Controllers -name '*.php' \| wc -l` |
| API controllers | 126 | `find app/Http/Controllers/Api -name '*.php' ! -path '*Concerns*' \| wc -l` |
| Controllers on **insecure** context traits | **70** | see F-01 table |
| Controllers on the **secure** trait (`ResolvesTaskContext`) | 16 | `grep -rl ResolvesTaskContext app/Http/Controllers \| wc -l` |
| API controllers with **no auth check at all** | 16 (≈8 legitimately public: signup, Google auth, Gemini) | see F-02 |
| `app/Events` / `app/Listeners` / `app/Observers` | **do not exist** | `ls app/Events` → No such file |
| `app/Jobs` | 1 file (`SendNewsletterJob.php`) | `ls app/Jobs` |
| Frontend page routes | 25 static + 2 dynamic | `find app -name page.tsx` |
| Frontend local API routes | 4 | `find app/api -name route.ts` |
| `next build` | **passes** (types skipped) | `npm run build` |
| `tsc --noEmit` | **9 errors** | `npx tsc --noEmit` |
| `eslint .` | **46 problems (32 err / 14 warn)** | `npx eslint .` |
| Frontend test framework | **none** | `package.json` — no jest/vitest/playwright |
| `typecheck` npm script | **absent** | `package.json` scripts |

---

## 4. FINDINGS REGISTER

Severity: **C**ritical (security / data integrity / core workflow broken) · **H**igh · **M**edium · **L**ow

### 4.1 Security & Authorization

---
#### F-01 — Cross-tenant data access and identity spoofing across 9 modules — **CRITICAL** — ✅ **CLOSED 2026-08-04**

**This is the single most important finding in the audit.**

> **Fix landed.** All 9 traits now delegate to `App\Http\Controllers\Api\Concerns\ResolvesApiIdentity`, which resolves the caller from `$accessToken->tokenable` and derives both `user_id` and `sub_institute_id` from that user. Token expiry is now checked. 70/70 affected controllers verified by reflection. **Live two-tenant testing still outstanding** — see Section 6.

Nine `Resolves*Context` traits authenticate the token, then **discard the token's owner** and take both the tenant and the acting user straight from the request body/query.

`app/Http/Controllers/Api/Competency/Concerns/ResolvesCompetencyContext.php:24-43` (pattern is identical in all nine):

```php
$token = $request->input('token');
if (!$token) { return 401; }
if (!PersonalAccessToken::findToken($token)) { return 401; }   // ← owner discarded

$subInstituteId = $request->input('sub_institute_id') ?? $request->header('sub_institute_id');

return [
    'sub_institute_id' => (int) $subInstituteId,                                    // ← attacker-controlled
    'user_id' => is_numeric($request->input('user_id')) ? (int) $request->input('user_id') : null, // ← attacker-controlled
];
```

**Exploit (no tooling required):** log in as an ordinary employee of org 5. Take the token the app already put in your URL. Call any competency/performance/talent endpoint with `&sub_institute_id=9` → you now read and write org 9's data. Add `&user_id=1` → your writes are recorded in the audit log as the CEO.

**The fix already exists in this repo.** `ResolvesTaskContext.php:28-46` does it correctly, and its comment names the exact bug:

```php
$accessToken = PersonalAccessToken::findToken($token);
$user = $accessToken?->tokenable;
if (!$user) { return 401; }
return [
    'user_id' => (int) $user->id,
    // User-first, NOT request-first. Taking sub_institute_id from the
    // request when present let any authenticated user read another
    // organisation's data by changing one query parameter.
    'sub_institute_id' => (int) ($user->sub_institute_id ?: $request->input('sub_institute_id', 0)),
];
```

Someone found and fixed this in Task Management and never propagated it.

**Blast radius:**

| Trait | Controllers | Module |
|---|---|---|
| `ResolvesCompetencyContext` | 17 | Competency |
| `ResolvesTalentContext` | 11 | Talent |
| `ResolvesPerformanceContext` | 10 | Talent/Performance |
| `ResolvesLeaveContext` | 8 | HRIT |
| `ResolvesAgenticContext` | 7 | Agentic |
| `ResolvesMobilityContext` | 7 | Talent |
| `ResolvesOnboardingContext` | 6 | Talent |
| `ResolvesAttendanceContext` | 3 | HRIT |
| `ResolvesOffboardingContext` | 1 | Talent |
| **Total exposed** | **70** | |
| `ResolvesTaskContext` *(correct)* | 16 | Task |

**Secondary defect:** `findToken()` does **not** check token expiry or revocation — only the Sanctum *guard* does. Every one of these traits, including the correct one, accepts an expired token.

**Re-verify:** `grep -A3 'findToken' app/Http/Controllers/Api/*/Concerns/Resolves*.php`

---
#### F-02 — API routes with no authentication at all — **CRITICAL**

`bootstrap/app.php` `withMiddleware()` registers aliases only. There is no `$middleware->api(...)`, no `auth:sanctum` on the API group. Routes are protected only if their controller self-guards.

These do not:

| Controller | Routes | Exposes |
|---|---|---|
| `Api/CompetencyDashboard/CompetencyDashboardController` | 7 (`routes/api.php:245-251`) | Workload heatmap, KPIs, role similarity, coverage scorecards, health radar, skills funnel, alignment — **for any tenant** |
| `Api/SkillHeatmapController` | 2 (`:970,:973`) | Org-wide skill heatmap + drill-down |
| `Api/SkillMatchingController` | 2 (`:846-847`) | Any user's rejected tasks + skill gaps |
| `Api/SuggestedCourseController` | 1 (`:849`) | Unauthenticated **write** to `suggested_course` |
| `Api/HRITDashboard/JobroleApiController` | — | Job role data |

`Api/DBController.php` is an empty stub, imported at `routes/api.php:77` but never routed — dead, harmless.
`Api/signup_api/*`, `GoogleAuthController`, `Api/Gemini/*` are unauthenticated **by design** — confirm, don't "fix".

---
#### F-03 — LMS authorization reads the caller's role from client input, and fails open — **CRITICAL** — ✅ **CLOSED 2026-08-04**

> **Fix landed.** 7 LMS controllers now use `App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity`. The role comes from `tbluser.user_profile_id` → `tbluserprofilemaster.name` (the chain `TaskPermissionMiddleware` already used), the fail-open empty-profile branch is gone, `tenantId()` is token-derived and self-resolving, and `isInstructor()` no longer trusts the request. Audit rows in `hpbrain_audit_logs` now record the real actor. Original 403 wording preserved; all ~200 call sites untouched. **See also F-44** — the same guard had a simpler bypass.

`LmsGovernanceController.php:58`, `LmsLearningController.php:48`, `LmsAssessmentController.php:59`, `AiCourseController.php:63`:

```php
$profile = strtolower(trim((string) $request->input('user_profile_name', '')));
```

The role is **whatever the caller types**. `&user_profile_name=admin` grants institute administration.

`LmsLearningController.php:48-51` is worse — it **fails open**:
```php
if ($profile === '') { return null; }   // ← no profile supplied = permitted
```
Omitting the parameter entirely grants course-authoring rights. (`LmsGovernanceController` correctly refuses on empty; the learner/authoring surfaces do not.)

`tenantId()` in the same controllers is also request-supplied — same cross-tenant issue as F-01.

---
#### F-04 — Only Task Management has any role-based authorization — **HIGH**

`TaskPermissionMiddleware` is correctly built (token → `tokenable` → `tbluser.user_profile_id` → profile name) and gates 8 privileged abilities. It is applied to **51 routes, all under `/api/task-management/*`**.

No other module has server-side role checks. Consequence: any authenticated employee can `POST /api/competency/employee-profiles/{id}/skills` and rate **any colleague's** competency, or read every performance review in the tenant. UI hiding is the only control, and it is not a control.

The middleware is also a **binary** Employee / non-Employee gate — no distinction between HR, Manager, and Admin. Adequate for now; will not survive real HR workflows.

---
#### F-05 — Bearer tokens travel in the query string — **HIGH**

`TaskPermissionMiddleware:43` and `ResolvesTaskContext:23`: `$request->bearerToken() ?: $request->input('token')`. The other eight traits accept **only** `$request->input('token')` — no header path at all.

The frontend depends on this. `services/core/api-client.ts:16-20` documents it: *"Every other endpoint authenticates with the token query param instead."* No `Authorization` header is ever set.

Tokens in URLs land in web-server access logs, browser history, proxy/CDN logs, and leak via `Referer`. Combined with F-01, a token harvested from a log grants cross-tenant access.

---
#### F-06 — Third-party API key shipped to the browser — **HIGH**

`lib/api-config.ts:45` exposes `NEXT_PUBLIC_HP_API_KEY`. Because it is called from **client-side** code — `services/organization/employee-profile-service.ts:82` — Next.js inlines it into the JavaScript bundle. Any visitor can read it from devtools.

`employee-profile-service.ts:85` then sends it as a **URL query parameter** to a third backend:
```js
const response = await fetch(`${resolveHpApiBaseUrl()}/get-kaba?${params.toString()}`)
```
Default host: `https://hp.triz.co.in`. **See Q-3 — this third backend is the source of KASBA competency definitions and is outside both audited repos.**

---
#### F-07 — Onboarding API route: in-memory storage + no authorization (IDOR) — **CRITICAL**

`app/api/onboarding/route.ts:8-9`:
```js
const store = globalThis.gtgOnboardingStore ?? new Map<string, OnboardingSummary>();
```

Two defects:
1. **Not persistence.** Lost on every restart, redeploy, and serverless cold start. On multi-instance deploys (repo carries `@vercel/analytics`), two requests from one user hit different instances and see different state.
2. **No authorization.** `GET`/`PATCH` take `userId` from the query string and perform **zero** identity check. Any caller reads or overwrites any user's onboarding record.

Note the confusion this creates: Laravel already has a real `/api/onboarding/*` module with a real database behind it, and `onboarding-center.tsx` uses it correctly. This Next route is a parallel, unauthenticated, non-persistent shadow of it.

---
#### F-08 — Stale secrets in `.env` and an unrotated token — **MEDIUM**

`.env` is correctly gitignored and untracked. But it carries `NEXT_PUBLIC_SUPABASE_SERVICE_ROLE_KEY` — a service-role key, RLS-bypassing, marked `NEXT_PUBLIC_`.

**Verified mitigation:** no code references `supabase` anywhere, so Next does **not** bundle it. Severity is Medium (stale secret to rotate/remove), **not** Critical. Stated explicitly because it looks Critical at a glance.

The file's own comments record an unresolved action item:
> *"The token they contained (4461|sOpjo6…) should be revoked server side if it has not been already."*

**Confirm this rotation happened.** Also present and unreferenced: `OPENROUTER_API_KEY`, `GAMMA_API_KEY`, `LLM_API_KEY`, `GENKIT_ENV`, plus `DB_*` credentials.

---
#### F-09 — CSRF exempts localhost and a production host — **MEDIUM**

`bootstrap/app.php` `validateCsrfTokens(except:)` lists `http://localhost:8000/*`, `http://localhost:3000/*`, `http://127.0.0.1:8000/*`, and `https://hp.triz.co.in/*`. Dev conveniences committed to config. Needs an explicit decision, not a silent default.

---

### 4.2 Data Integrity & Schema

---
#### F-10 — `s_skill_matrix.type` column does not exist — adding a competency rating fails on any clean database — **CRITICAL**

`EmployeeCompetencyProfileController.php:272-281` inserts:
```php
$matrixId = DB::table('s_skill_matrix')->insertGetId([
    'user_id' => $id,
    'skill_id' => $request->input('skill_id'),
    'skill_level' => $request->input('skill_level'),
    'interest_level' => $request->input('interest_level', 0),
    'type' => 'competency',        // ← no migration creates this column
    ...
]);
```

Every migration touching `s_skill_matrix`:
- `2025_06_11_100709_create_s_skill_matrix_table.php` → `id, user_id, skill_id, skill_level, interest_level, created_by, updated_by, deleted_by, timestamps, softDeletes`
- `2025_07_02_122809_add_colums_skill_matrix.php` → adds `knowledge`, `ability`
- `2025_12_30_062000_...` → drops a foreign key

**No `type` column anywhere.** A freshly migrated database throws `SQLSTATE[42S22] Unknown column 'type'` on every attempt to rate an employee's competency — the core write path of the Competency module.

This works in production only if the column was added by hand, out of band — which means **schema and migrations have diverged**. That is the more serious version of this finding.

`grep -rn "s_skill_matrix" app | grep type` → the string appears **once**, in this insert. Nothing reads it.

**Re-verify:** `grep -rn "skill_matrix" database/migrations/`

---
#### F-11 — KASBA has three competing storage models and two of five dimensions have no per-employee storage — **HIGH**

The product is specified on KASBA (Knowledge, Attitude, Skill, Behaviour, Ability). Storage is inconsistent:

| Store | Holds | Grain |
|---|---|---|
| `s_skill_matrix` | `skill_level`, `knowledge`, `ability` | per user × skill |
| `user_rating_details` | `skill_ids`, `knowledge_ids`, `ability_ids`, `attitude_ids`, `behavior_ids` — comma-separated ID blobs | per user × job role |
| `s_user_knowledge` / `s_user_ability` / `s_user_attitude` / `s_user_behaviour` | library definitions | global |

Consequences:
- **`attitude` and `behaviour` have no scored per-employee column.** `s_skill_matrix` stores K, S, A — never A(ttitude) or B(ehaviour). `EmployeeCompetencyProfileController` reads only `m.knowledge` and `m.ability` (lines 106-107, 392-393).
- `app/Models/skill/matrix.php` declares `$fillable = [... 'type', 'knowledge', 'ability', 'behaviour', 'attitude']` — **three of those columns do not exist**. Mass-assignment through this model will fail.
- Spelling split: model says `behaviour` (UK), `user_rating_details` says `behavior_ids` (US).
- `user_rating_details` stores relations as comma-separated text — unindexable, unjoinable, no referential integrity.

**Blocked on Q-1.** Do not restructure this until the intended KASBA model is confirmed.

---
#### F-12 — Task→LMS course recommendation runs on K-12 school tables — **HIGH**

A Task→LMS bridge **does** exist (v1 said it didn't): `SkillMatchingController::getUserRejectedTasks` + `getCoursesForUserRejectedTasksSkills`, routes `api.php:846-847`, persisted via `POST /api/employee/course-suggestions`.

The logic is sound — find a user's rejected tasks, extract required skills, diff against `s_skill_matrix`, recommend courses for the gaps. But it queries the **school ERP curriculum tables**:

```php
$query = DB::table('sub_std_map as ssm')          // K-12 subject↔standard map
    ->where('ssm.sub_institute_id', $subInstituteId)
    ->select(['ssm.id as course_id', 'ssm.standard_id', 'ssm.subject_id',
              'ssm.display_name as title']);
```

`taskModel` is also `App\Models\front_desk\taskModel` — the school front-desk task model, not the Task Management module's.

So an HRMS employee with a skill gap is recommended **K-12 school subjects**. It matches on `display_name` string similarity with a LIKE fallback. **See Q-2.**

Additional defects:
- `SuggestedCourseController` implements **only `store`**. Suggestions are written and can never be read back — no index/show route exists. The feature is write-only.
- Triggers exclusively on `approve_status = 'Rejected'`. Completed tasks, missed deadlines, and low task quality produce no signal.

---
#### F-13 — No LMS→Competency bridge — **CRITICAL (core workflow)**

The brief's central premise: completing a course should move a competency score. It does not.

- **The trigger exists.** `LmsLearningController` tracks real completion — `completed_at` (`:249`, `:365-366`), `status = 'completed'`, and issues certificates (`:1484`).
- **The target exists.** `s_skill_matrix.skill_level`.
- **Nothing connects them.** No LMS controller writes to `s_skill_matrix` (`grep -rl s_skill_matrix app/Http/Controllers/Api/Lms*` → empty). The only writes are the two manual admin endpoints in `EmployeeCompetencyProfileController`.
- **No mechanism could carry it.** `app/Events`, `app/Listeners`, `app/Observers` **do not exist as directories**. `app/Jobs` holds one unrelated newsletter job.

This is a *small* piece of work — both ends are built. It is Critical because it is the product's reason to exist, not because it is hard.

---
#### F-14 — No Task→Competency and no Competency→Talent automated flow — **CRITICAL (core workflow)**

Same root cause as F-13. No listener, observer, job, or write path links task outcomes to competency scores, or competency scores to succession/talent records. Any apparent linkage is a read-time join inside a single controller, not a durable record — so competency **history** (the thing a promotion decision needs) is never accumulated.

---
#### F-15 — `routes/talent.php` is dead and references a non-existent controller — **MEDIUM**

`routes/talent.php` declares `Route::Resource('talents', talentmanagementController::class)` (7 routes).

Two independent defects:
1. `bootstrap/app.php` loads `lms.php`, `user.php`, `settings.php`, `hrms.php` in the `then:` callback. **`talent.php` is not loaded.** A repo-wide grep for `talent.php` returns zero references.
2. `App\Http\Controllers\talent_management\talentmanagementController` **does not exist**. That directory contains only `talentmanagement_jobapplicationController.php` and `talentmanagement_jobpostingController.php`.

Harmless today precisely because it is never loaded. It is a landmine: registering the file to "enable talent routes" will fatal immediately.

---

### 4.3 Broken Functionality

---
#### F-16 — "Delete feedback" button calls a route that does not exist, and swallows the error — **HIGH**

`services/talent/recruitment.ts:199` → `apiClient.delete('/feedback/{id}')`.

Backend declares (`routes/api.php:811-816`): `GET /feedback`, `GET /feedback/{id}`, `POST /evaluation`, `GET /pending-feedback`, `PUT /feedback/{id}`. **No DELETE.** → 405.

It is user-reachable: `components/domain/talent/recruitment/interview-tools-drawer.tsx:397` renders a destructive "Delete feedback" button wired to it. The call is `void`-ed with a `.then()` and **no `.catch()`** — the rejection is unhandled, the dialog never closes, and the user is shown nothing. They will assume the delete worked.

---
#### F-17 — Task approve/reject buttons do nothing — **HIGH**

`components/domain/task/task-approvals-view.tsx:153,160`:
```jsx
onClick={(e) => { e.stopPropagation(); /* Mock Reject */ }}
onClick={(e) => { e.stopPropagation(); /* Mock Approve */ }}
```

No API call, no toast, no state change. The approvals queue is decorative.

**The backend is ready** — `PATCH /api/task-management/workspace/{id}/approval` exists, is permission-gated (`task.permission:task.approve`), and `services/task/index.ts:314` already wraps it. This is a wiring job.

Same file: `:20` treats completed tasks as approved; `:22-39` fabricates a rejected task to populate the "rejected" tab; `:140` hardcodes "3 Comments" on every row.

---
#### F-18 — Broken import ships to production because type errors are ignored — **HIGH**

`next.config.mjs`:
```js
typescript: { ignoreBuildErrors: true }
```
Confirmed active — the build log prints `Skipping validation of types`. Every type error below reaches production.

The one that matters: `lib/gtg-nav-visibility.ts:2`
```ts
import { GTG_NAVIGATION } from '@/lib/gtg-navigation'   // TS2305: no such export
```
`lib/gtg-navigation.ts` exports no `GTG_NAVIGATION`. Line 146 calls `GTG_NAVIGATION.map(...)` → `TypeError: undefined is not a function` the moment it runs.

Currently latent: nothing imports `gtg-nav-visibility`. So it is dead code with a live landmine, not an outage.

Second real bug: `components/domain/talent/administration/admin-center.tsx:222` passes `onValueChange` to a `Select` whose props do not declare it. **The prop is silently dropped — that dropdown does nothing.**

---
#### F-19 — Agentic module: 8 built screens, unreachable — **HIGH**

`hooks/content-map-m7.ts` exports `M7_CONTENT` with 8 lazy-loaded screens (`ag-agent-dashboard`, `ag-agent-library`, `ag-create-agent`, `ag-run-log`, `ag-analytics`, `ag-multi-agent`, `ag-reflection`, `ag-agent-workspace`).

`hooks/use-content-map.ts:6-14` registers `m0, '1', '2', '3', '4', '5', '204'`. **No m7 key.** `loadContentRoutes()` returns `undefined` for anything unregistered.

Repo-wide grep for `M7_CONTENT`: exactly one hit — its own definition.

Dead alongside it: `services/agentic/*` (6 files), `hooks/use-agentic.ts`, and 7 backend controllers on `ResolvesAgenticContext`.

Note `lib/gtg-navigation.ts:54-57` defines `AG_CREATE_AGENT_ACCESS_LINK`, `AG_AGENT_LIBRARY_ACCESS_LINK`, `AG_RUN_LOG_ACCESS_LINK`, `AG_ANALYTICS_ACCESS_LINK` — the module **was** meant to be reachable. **See Q-4.**

---
#### F-20 — TypeScript: 9 errors — **MEDIUM**

Full clean run (`npx tsc --noEmit`, complete `node_modules`):

| File:line | Error |
|---|---|
| `lib/gtg-nav-visibility.ts:2` | TS2305 — no exported member `GTG_NAVIGATION` *(→ F-18)* |
| `lib/gtg-nav-visibility.ts:146,148,151` | TS7006 — implicit `any` |
| `components/domain/talent/administration/admin-center.tsx:222` | TS2322 — `onValueChange` not on `SelectProps` *(→ F-18)* |
| `components/domain/talent/administration/admin-center.tsx:222` | TS7006 — implicit `any` |
| `services/talent/offboarding-service.ts:15,40,61` | TS2345 — `Record<string, string \| number>` → `Record<string, string>` |
| `.next/types/validator.ts:170` | TS2307 — stale build artifact, ignore |

`offboarding-service.ts` is a genuine contract break: `apiClient.get()` types `params` as `Record<string,string>` but numeric IDs are passed. Works at runtime (stringifies); the type contract is wrong.

---
#### F-21 — ESLint: 46 problems (32 errors, 14 warnings) — **MEDIUM**

| Rule | Count |
|---|---|
| `react-hooks/set-state-in-effect` | 22 |
| `react-hooks/exhaustive-deps` | 5 |
| `@typescript-eslint/no-empty-object-type` | 4 |
| `react/no-unescaped-entities` | 2 |
| `react-hooks/purity` | 1 |
| `react-hooks/preserve-manual-memoization` | 1 |
| `react-hooks/immutability` | 1 |
| `@typescript-eslint/ban-ts-comment` | 1 |

Highest value: `react-hooks/purity` at `components/domain/talent/offboarding/offboarding-center.tsx:130` — `Date.now()` called during render. Under the React 19 compiler this is a real correctness hazard, not style. The 22 `set-state-in-effect` hits are cascading-render performance problems.

---
#### F-22 — No tests, no typecheck script — **HIGH**

`package.json` scripts: `dev`, `dev:clean`, `build`, `build:clean`, `clean`, `start`, `lint`. No `typecheck`. No jest / vitest / playwright / testing-library anywhere. **Zero frontend tests.**

Backend has `phpunit.xml`; suite not executed here (no database).

With `ignoreBuildErrors: true` (F-18) and no `typecheck` script, **nothing in CI can catch a type error.** That is how F-18's broken import survived.

---

### 4.4 Mock & Non-Functional Data

v1 called this "narrow and concentrated." It is not. Each item is listed individually per the brief.

**Rendered to users — these are live defects:**

| # | Location | Detail | Sev |
|---|---|---|---|
| F-23 | `components/domain/talent/profile/talent-profile-view.tsx:50` | `const profile = mockProfileData` — the **entire** Talent Profile view. No API call. This is the screen a promotion decision is made on. | **C** |
| F-24 | `components/domain/organization/employee-directory.tsx:88,137` | Mock employees are the initial state **and the error fallback**. On API failure it `console.error`s and silently renders 5 fabricated employees. Users cannot tell real from fake. Also: if `hasStoredEmployeeSession()` is false the fetch never runs and mocks are all you ever see. | **C** |
| F-25 | `components/domain/organization/department-management/policies-tab.tsx:372` | `useState<Policy[]>(MOCK_POLICIES)` — Department Policies tab is fabricated | **H** |
| F-26 | `components/domain/organization/department-management/rules-tab.tsx:402` | `useState<Rule[]>(MOCK_RULES)` — Department Rules tab is fabricated | **H** |
| F-27 | `components/domain/organization/department-management/department-details-panel.tsx:154` | `useState<Sop[]>(MOCK_SOPS)` — Department SOPs tab is fabricated. `sops-tab.tsx:562` serves the literal string `'This is a mock SOP document.'` as a download | **H** |
| F-28 | `components/domain/talent/administration/admin-center.tsx:157` | `{mockAdminKPIs.map(...)}` — every KPI card in Talent Admin Center is fabricated | **H** |
| F-29 | `components/profile/profile-dashboard.tsx:56-58` | Spreads `mockProfile`, overriding only `email` and `fullName` from the real session. Every other field on the user's own profile is fabricated. Re-exported from `components/profile/index.ts:5`, widening blast radius | **H** |
| F-30 | `components/domain/task/task-approvals-view.tsx:22-39,140` | Fabricated rejected task; hardcoded "3 Comments" | **H** |
| F-31 | `components/domain/talent/offboarding/offboarding-center.tsx:411-416,1875` | "Upload document" dialog takes a **typed filename** and marks the document Submitted. No file is transferred. A compliance step is satisfiable by typing a string | **H** |
| F-32 | `components/domain/talent/mobility-succession/mobility-center.tsx:817` | Business Unit filter is a static mockup placeholder | **M** |

**Defined but not rendered — dead weight and traps, not live defects:**

| # | File | Unused exports | Sev |
|---|---|---|---|
| F-33 | `talent/mobility-succession/mobility-data.ts` | `mockMobilityKPIs`, `mockInternalJobs` | M |
| F-34 | `talent/offboarding/offboarding-data.ts` | `mockOffboardingKPIs`, `mockExitCases` | M |
| F-35 | `talent/performance/performance-data.ts` | `mockPerformanceKPIs`, `mockEmployeeReviews`, `mockActivityFeed` | M |
| F-36 | `talent/administration/admin-data.ts` | `mockWorkflows` | M |
| F-37 | `hrms/hrit/attendance-management/attendance-reports/services/report-data.ts` | `earlyGoingMockData`, `departmentReportMockData`, `employeeReportMockData` | M |

**Verified NOT mock (do not "fix"):** `talent/onboarding/onboarding-center.tsx` is fully API-backed — its header comment says so and the code agrees. `tm-audit-logs.tsx`, `tm-permissions.tsx`, `lib/laravel-context.ts` carry comments recording that hardcoding was *removed*.

---
#### F-38 — Four dead service objects still exported — **HIGH**

Documented in-repo, still shipping. Zero UI callers today; they are autocomplete traps that fail at runtime on first use.

- `services/lms/index.ts:5` — `lmsService` → `/courses`, `/assignments`, `/certifications` (none exist)
- `services/talent/index.ts:13,18` — `/performance-reviews` (live module is `/api/performance/*`), `/onboarding-tasks` (live module is `/api/onboarding/*`)
- `services/hrms/index.ts` — `getAttendanceRecords`, `checkIn`, `checkOut`, `getComplianceItems` → `/attendance`, `/attendance/check-in`, `/attendance/check-out`, `/compliance`. Live surface is `/api/attendance/my-attendance`, `/punch-in`, `/punch-out`, `/kpi`, `/weekly-summary`, `/report-filters`, `/employees`
- `services/task/index.ts` — `/tasks`, `/tasks/{id}/assign`, `/projects`. Live surface is `/api/task-management/*`

The comments say "left in place rather than deleted so no existing import breaks." Since there are no importers, **delete them.**

---
#### F-39 — Local Next API routes break when the API base URL is configured — **HIGH**

`lib/api-config.ts:26-33` — `resolveApiBaseUrl()` returns `'/api'` **only when no env var is set**. Once `NEXT_PUBLIC_API_BASE_URL_DEV|PROD` is configured (required for the other ~446 calls to reach Laravel), the base becomes `https://<laravel-host>/api`.

`services/task/index.ts:603` calls `apiClient.get('/jobrole-task-description')`. That is a **local Next route** (`app/api/jobrole-task-description/route.ts`). With a configured base it is sent to Laravel, which has no such route → 404.

It works only in the unconfigured fallback case — i.e. it works on a dev machine and breaks in every real deployment.

Same class: `app/api/jobrole-tasks`, `app/api/onboarding`, `app/api/screenCandidate`.

---
#### F-40 — SSR and client can resolve different API base URLs — **MEDIUM**

`lib/api-config.ts:1-16` — `isProductionEnvironment()` uses `process.env.NODE_ENV` on the server but **`window.location.hostname`** in the browser. A production build accessed over a localhost tunnel resolves the PROD base during SSR and the DEV base on the client. Same page, two backends.

---
#### F-41 — Deep-linking and hard refresh silently drop the user on the default screen — **HIGH (UX/trust)**

`hooks/use-sidebar-navigation.ts:103-111`:
```ts
const getRoutePath = (active) => pathByKey.get(...) ?? '/dashboard'
const parseRoutePath = (pathname) => keyByPath.get(pathname) ?? null
```

Every module URL is an exact-match lookup against `access_link` values fetched from `tblmenumaster_g2g`. Consequences:

- Hard-refresh or paste a module URL: if it is not an **exact** string match (trailing slash, case, query string), `parseRoutePath` returns `null`, `active` stays `DEFAULT_ACTIVE`, and the user lands on the default screen with **no error** — the URL bar still shows where they meant to be.
- Any nav item whose `access_link` is missing/malformed silently routes to `/dashboard` on click.
- The frontend cannot render a module the backend menu table does not describe.

This is the mechanical reason Journey 6 (cross-module navigation) fails. **See Q-5.**

---
#### F-42 — Invalid PHP file in the controller tree — **LOW**

`app/Http/Controllers/Api/CompetencyDashboard/CompetencyDashboardController as SubCompetencyDashboardController.php`

The filename and the class declaration both contain ` as ` — a `use X as Y` statement pasted where a filename belongs:
```php
class CompetencyDashboardController as SubCompetencyDashboardController extends Controller  // parse error
```

Harmless at runtime: PSR-4 never autoloads it, and `routes/api.php:19`'s alias correctly resolves to the real `CompetencyDashboardController` (all 7 methods verified present). But it breaks `php -l` sweeps, static analysis, and any classmap autoload. Delete it.

---

### 4.5 Found during remediation (2026-08-04)

These were not in the original audit. They surfaced while implementing Phase 1.

---
#### F-43 — `routes/lms.php` references a controller class that does not exist — **HIGH**

`routes/lms.php:4` imports `App\Http\Controllers\front_desk\book_list\book_listController` and routes to it at `:163` (`ajax_LMS_SubjectwiseChapterForBooklist`).

`app/Http/Controllers/front_desk/` contains only `BulkTaskController.php`, `TaskUpdateController.php`, `syllabus/`, `taskController.php`. There is no `book_list` directory.

**Discovered because `php artisan route:list` cannot complete** — it throws `ReflectionException: Class ... does not exist`. Any request to that route 500s, and the broken reference blocks route caching (`php artisan route:cache`), which is a production deployment step.

**Status:** OPEN. Unrelated to the Phase 1 changes — verified pre-existing.

---
#### F-44 — LMS authentication skipped entirely when `type=API` is absent — **CRITICAL**

Found while fixing F-03. Every LMS `guardApiToken()` opened with:

```php
if ($request->input('type') !== 'API') {
    return null;          // ← no token required, request proceeds
}
```

Authentication applied only if the caller *opted in* by sending `type=API`. Omitting one query parameter bypassed it completely — a strictly simpler bypass than the role spoofing in F-03.

Present in 8 controllers: `LmsLearningController`, `LmsGovernanceController`, `LmsAssessmentController`, `LmsCourseController`, `LmsPartnerController`, `LmsSessionController`, `AiCourseController`, `SkillDevelopmentController`.

**Status:** ✅ **CLOSED** — escape removed; a token is now always required.

---
#### F-45 — Any user can read any other user's learning record — **CRITICAL**

`SkillDevelopmentController` read identity from the request in **7 places**:

```php
$userId = $request->user_id ?? $request->header('user_id');
$subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
```

Combined with F-44 (no token needed), `GET /api/skill-development/progress?user_id=N` returned **any** employee's skill progress, learning streak, achievements, peer comparison, calendar and recent activity — to an unauthenticated caller. Endpoints affected: `/progress`, `/streak`, `/weekly-goal`, `/achievements`, `/peer-comparison`, `/calendar`, `/recent-activity`.

This controller uses no `Resolves*Context` trait, so the F-01 fix did not reach it.

**Status:** ✅ **CLOSED** — identity is token-derived; `contextUserId()` / `contextTenantId()` added.

**Lesson for the remaining work:** F-01's trait fix does **not** cover controllers that hand-roll their own auth. Task 1.4 must sweep for that pattern rather than assume trait coverage is complete.

---
#### F-03b — The first F-03 fix was incomplete: `user_id` stayed request-derived in LMS — **CRITICAL** — ✅ **CLOSED 2026-08-04**

Caught by the route sweep immediately after F-03 was marked done. Closing the token, role and tenant holes left the *fourth* leg standing: **39** reads of `user_id` straight from the request across the 7 LMS controllers.

Three distinct consequences:

1. **IDOR.** `requireUser()` (`LmsLearningController:55`), `LmsSessionController::index` and `::deadlines` used it to mean "the caller". So "my courses", "my sessions" and "my deadlines" returned **whoever the caller named**.
2. **Forged attribution.** `created_by` / `updated_by` / `deleted_by` / `reissued_by` across Governance, Partner, Assessment, Course and AiCourse recorded whoever the caller named.
3. **A safety check that checked nothing.** `LmsGovernanceController:484`:
   ```php
   $actorId = (int) $request->input('user_id');
   if ((int) $id === $actorId) { return 'You cannot deactivate your own account.'; }
   ```
   Both sides were attacker-influenced, so the guard was decorative.

Verified that in every one of the 39 cases `user_id` meant *the actor or the caller*, never the subject — the subject is always a route parameter or an explicit field — so replacing it wholesale with the token owner is semantically correct.

**Status:** ✅ CLOSED — `contextUserId()` added to `ResolvesLmsIdentity`; all 39 reads replaced.

---
#### F-46 — 120 API routes still hand-roll their own authentication — **CRITICAL**

The original audit counted controllers by directory and trait. A proper sweep of all **689** routes in `routes/api.php` — resolving each route's controller and classifying its auth mechanism — shows the problem is substantially larger than reported.

**120 routes across ~42 controllers** call `PersonalAccessToken::findToken()` directly instead of using a shared trait. Each one reproduces some subset of the F-01 defect: token validated, owner discarded, identity read from the request.

Worst offenders by request-derived identity reads:

| Controller | Routes | Reads | Module |
|---|---|---|---|
| `AJAXController` | 1 | **26** | Shared/core |
| `LmsCourseEnrollController` | 6 | **17** | LMS |
| `talent_jobapplicationcontroller` | 3 | 9 | Talent |
| `EmployeeDirectoryAnalyticsController` | 8 | 8 | Organization |
| `DepartmentSkillController` | 1 | 7 | Organization |
| `talent_interviewschedulescontroller` | 3 | 7 | Talent |
| `tbluserController` | 1 | 7 | Organization |
| `talent_jobpostingcontroller` | 1 | 6 | Talent |
| `feedbackController` | 5 | 5 | Talent |
| ~33 others | 91 | 1–4 each | all modules |

`HrmsLeaveController` (9 reads) is also in this class, now behind `api.token` but still request-derived internally.

**Why this matters more than the raw count suggests:** these are not obscure endpoints. `AJAXController` is the shared table-data endpoint, `tbluserController` is employee master data, `EmployeeDirectoryAnalyticsController` is the org-wide directory. Fixing the traits closed the *front* door; this is the volume of side doors.

**Recommended approach:** the same one that worked for F-01 and F-03 — migrate each controller onto `ResolvesApiIdentity`, largest read-count first, verifying by re-running the route sweep after each batch. Do **not** attempt it in one pass; these controllers do not share a single shape the way the traits did.

**Status:** ◐ Mostly closed. 40 controllers migrated; reads down 109 → 25; unauthenticated routes 38 → 0. The 25 survivors are listed in Section 7 with their disposition.

---
#### F-47 — The verification script was wrong twice, and both times it under-reported — **HIGH (process)** — ✅ **CLOSED 2026-08-04**

Recorded because the *tool* was the risk, not the code. `scripts/audit-auth-sweep.py` reported "safe" for routes that were wide open.

**Defect 1 — classified per file, not per method.** `AJAXController` was reported "hand-rolled auth" because the 2655-line file contained `findToken()` *somewhere*. The method actually routed to, `getUsersMappings()`, called no guard at all: `GET /api/get-employee-tasks?user_id=N&sub_institute_id=M` returned any employee's tasks and skills in any organisation, to anyone. Fixed by resolving each route to its specific method.

**Defect 2 — group membership by line number.** The script treated *every* route after the `Route::prefix('task-management')` line as covered by that group's middleware. The group closes at api.php:949; the file runs to 1426. So **236 routes in the back half of the file were reported protected without being checked.** Fixed by brace-matching the group closure, and generalised to any `->middleware(...)->group(...)`.

Defect 2 was hiding 8 genuinely open routes, found the moment it was fixed:

| Route | Exposure |
|---|---|
| `POST /update-fcm-token` | Overwrite **any** user's push-notification device token — redirect or silence a colleague's notifications |
| `GET /export-department-jobroles/{subInstituteId}` | CSV export of **any** organisation's departments and job roles; tenant is a path segment |
| `Route::resource('templates')` + 2 | Full CRUD on HR templates |
| `GET /career-journey` | Career journey data |
| `POST /bulk-task/import` | Bulk task import |
| `GET /skill-heatmap`, `/drill` | Org-wide skill heatmap |

All now gated. `updateFcmToken` additionally takes the user from the token rather than the request.

**The lesson worth keeping:** a green check from a verifier you have not tested is not evidence. Both defects made the tool *more* reassuring than reality, which is the dangerous direction. When a sweep reports a large clean bucket, confirm it is measuring what you think — the 236-route "group-middleware" bucket should have been implausible on its face.

---

#### F-50 — Nine controllers depended on an uninstalled package — **CRITICAL** — ✅ **CLOSED 2026-08-04**

`GenTux\Jwt` appears in **15 files** and is **absent from `composer.json`** — never installed. A PHP class that `use`s a missing trait cannot be loaded at all, so every one of those controllers was fatal on every request. Worse, Laravel reflects over controller classes when building the route table, so they broke `route:list` and `route:cache` **for the entire application**, not just themselves.

Two controllers (`HrmsLeaveController`, `ApplyLeaveController`) already had the import commented out — so this had been hit before and worked around locally rather than fixed.

**48 `jwtToken()->validate()` guards across 14 files** now use `apiTokenIsValid()`, backed by Sanctum, which is what the rest of the codebase authenticates with. Depending on a second, uninstalled token system was the root problem.

---
#### F-51 — Route files still used Laravel 7 syntax that Laravel 8 removed — **HIGH** — ✅ **CLOSED 2026-08-04**

Four live routes used the string form `'lms\lessonplan\lms_lessonplanController@method'`. Laravel 8 removed the implicit `App\Http\Controllers\` prefix, so the string resolved to a root-namespace class that does not exist — 500 on dispatch, and `route:cache` broken. The class is real and already imported; the array form fixes it.

`lmsSyllabusController` had the same outcome from a different cause: used at two routes but **never imported**, and route files declare no namespace, so the bare name resolved to `\lmsSyllabusController`.

Also found: `ajax_daywisedata` was declared twice, identically — the second silently replaced the first.

**Dead routes removed** in the same pass, all verified unreferenced in `resources/` and `public/`: the entire `bazar` group (9 routes, controllers never existed), `user_contact_details` (7 routes), `/paraphraseNew`, and `ajax_LMS_SubjectwiseChapterForBooklist` (F-43).

---
#### F-52 — Duplicate route names and resources broke `route:cache` — **HIGH** — ✅ **CLOSED 2026-08-04**

`php artisan route:cache` refuses to serialise two routes sharing a name. At runtime the later declaration silently replaces the earlier one, so nothing looks wrong until deploy.

Found: **8 duplicate route names** and **8 duplicate resource URIs**. Twelve were exact copy-paste duplicates (an entire five-route block in `hrms.php` appeared twice; five `Route::resource` lines in `api.php` were declared twice). The rest were GET/POST pairs sharing one name.

Exact duplicates were removed. Where two *different* routes shared a name, the name was kept on the declaration that already won at runtime (the last registered), so no `route('...')` call in any Blade view changed target.

`scripts/audit-route-controllers.py` now also fails on duplicate names and duplicate resource URIs, so this is caught before deploy rather than at it.

---
#### F-48 — `/reports/employee-directory/skills/matrix` takes ~50 seconds — **HIGH (performance)**

Measured live, twice: **49.4s** and **55.3s** for a single request against tenant 3. It is correct and correctly tenant-scoped — just extremely slow.

Consequences: any sane HTTP client or load balancer times out first, so in practice the Employee Directory skills matrix does not render; and each call holds a PHP-FPM worker and a database connection for the better part of a minute, so a handful of concurrent users can exhaust the pool.

The neighbouring report endpoints return in well under a second, so this is one query, not a general problem. Needs an `EXPLAIN` and almost certainly an index.

**Status:** OPEN. Not a security issue — recorded here because it was found during security testing and is user-visible.

---
#### F-49 — Report queries have no stable sort — **LOW**

`/reports/employee-directory/attrition` orders by `attritionRate` with many ties and no secondary key, so identical requests return the same rows in a different order. Cosmetic in the UI (lists reshuffle between refreshes), but it also defeats naive response comparison — it produced a false "cross-tenant leak" during this session's testing. Add a deterministic tiebreaker (`, d.id`).

**Status:** OPEN.

---

## 5. PHASED FIX PLAN

Ordering is **security → data integrity → core workflow → correctness → cleanup**. Do not reorder to get visible wins earlier.

Effort: **S** ≤ 1 day · **M** 2–4 days · **L** 1–2 weeks

---
### PHASE 0 — Guardrails (do first, ~1 day)

Nothing else is safely verifiable without these.

| # | Task | Files | Effort |
|---|---|---|---|
| 0.1 | Add `"typecheck": "tsc --noEmit"` to `package.json` scripts | `g2gv0/package.json` | S |
| 0.2 | Set `typescript.ignoreBuildErrors: false`. **Expect the build to fail** — fix F-20's 8 real errors in the same change | `g2gv0/next.config.mjs` | S |
| 0.3 | Install Vitest + Testing Library. One smoke test. No coverage target yet — just make tests *possible* | `g2gv0/package.json` | S |
| 0.4 | CI: run `typecheck`, `lint`, `build` on every PR | CI config | S |
| 0.5 | Confirm the `4461|sOpjo6…` token in `.env` comments was revoked. Remove unreferenced `SUPABASE_*`, `OPENROUTER_*`, `GAMMA_*`, `LLM_*` keys | `g2gv0/.env` | S |

**Exit criteria:** a PR that introduces a type error cannot merge.

---
### PHASE 1 — Authorization (BLOCKING — nothing ships before this) — ~1.5 weeks

**Do 1.1 first. It is the highest-value change in the entire plan and the pattern already exists.**

| # | Task | Detail | Effort |
|---|---|---|---|
| 1.1 | **Fix the 9 context traits** (F-01) | Port `ResolvesTaskContext:28-46` verbatim into each: resolve `$accessToken->tokenable`, take `user_id` from `$user->id`, take `sub_institute_id` from `$user->sub_institute_id`. **Treat the request's `sub_institute_id` as untrusted** — at most compare and 403 on mismatch. Extract to one shared base trait so this can never drift again | **M** |
| 1.2 | Reject expired/revoked tokens (F-01 secondary) | `findToken()` checks neither. Add an explicit `expires_at` check in the shared trait | S |
| 1.3 | **Fix LMS authorization** (F-03) | Delete every `$request->input('user_profile_name')` role read. Resolve the profile from the token's user, as `TaskPermissionMiddleware:66+` does. **Remove the fail-open branch at `LmsLearningController:48-51`** | **M** |
| 1.4 | Guard the unauthenticated controllers (F-02) | `CompetencyDashboardController` (7 routes), `SkillHeatmapController` (2), `SkillMatchingController` (2), `SuggestedCourseController` (1), `HRITDashboard/JobroleApiController`. Apply the fixed trait. **Explicitly confirm and document** that signup/GoogleAuth/Gemini are intentionally public | **M** |
| 1.5 | Add an API middleware group | `bootstrap/app.php` — a defence-in-depth `$middleware->api()` with token auth, so a new route is protected by default rather than by author diligence. **Does not replace 1.1** | S |
| 1.6 | Extend role gating beyond Task (F-04) | Generalise `TaskPermissionMiddleware` into an ability-based middleware usable by all modules. Minimum: competency ratings, performance reviews, and talent records must be writable only by HR/Manager/Admin | **M** |
| 1.7 | Move tokens out of the URL (F-05) | Backend: accept `Authorization: Bearer`. Frontend: set the header in `api-client.ts`. Keep the query fallback temporarily, log its use, then remove. Coordinate — `employee-directory.tsx:109` hand-builds a URL with `token=` and bypasses `apiClient` entirely | **M** |
| 1.8 | Fix the onboarding Next route (F-07) | Preferred: **delete it** and use Laravel's real `/api/onboarding/*`, which `onboarding-center.tsx` already uses correctly. If it must stay: real persistence + authorization. **Blocked on Q-6** | S–M |
| 1.9 | Get the HP API key out of the browser (F-06) | Move `fetchJobRoleKaba` behind a Next server route; drop the `NEXT_PUBLIC_` prefix; stop passing the key as a query param. **Blocked on Q-3** | S |
| 1.10 | Decide the CSRF exclusions (F-09) | Remove localhost entries from committed config | S |

**Exit criteria:**
- A token issued to org A returns **403, not data**, for every endpoint called with `sub_institute_id=B`.
- Supplying `user_id` in a request cannot change the recorded actor in `s_competency_activity_log`.
- `&user_profile_name=admin` grants nothing.
- Omitting `user_profile_name` grants nothing.
- **Test these at the API level with curl. A hidden button is not a control.**

---
### PHASE 2 — Data integrity — ~1 week

| # | Task | Detail | Effort |
|---|---|---|---|
| 2.1 | **Reconcile `s_skill_matrix` schema** (F-10) | Diff the production table against the migrations. Either add a migration for `type` or remove it from the insert. **Then find out how they diverged** — that process gap will recur | S |
| 2.2 | Fix `app/Models/skill/matrix.php` `$fillable` (F-11) | Remove `type`, `behaviour`, `attitude` until columns exist, or add the columns. Right now mass assignment through this model fails | S |
| 2.3 | Settle the KASBA model (F-11) | Choose one store. Recommendation: extend `s_skill_matrix` with `attitude` + `behaviour` and retire `user_rating_details`' comma-separated ID blobs. Settle `behaviour` vs `behavior` **once**. **Blocked on Q-1** | **L** |
| 2.4 | Add a migration-drift check to CI (F-10) | `migrate:fresh` against a scratch DB, then schema-diff | S |
| 2.5 | Delete `routes/talent.php` (F-15) | Dead, unloaded, references a non-existent controller | S |
| 2.6 | Delete the invalid ` as ` controller file (F-42) | Verified unreferenced; the alias resolves to the real class | S |

---
### PHASE 3 — Cross-module bridges (the product's reason to exist) — ~2–3 weeks

**Blocked on Q-1 and Q-2. Do not start before they are answered.**

| # | Task | Detail | Effort |
|---|---|---|---|
| 3.1 | Create the event infrastructure | `app/Events`, `app/Listeners`, `app/Observers` **do not exist**. Create them, register a service provider, and configure a queue. Everything in this phase depends on it | S |
| 3.2 | **LMS → Competency** (F-13) | Fire `CourseCompleted` where `LmsLearningController` sets `completed_at`/`status='completed'` (`:365-366`). Listener maps course → skill and updates `s_skill_matrix.skill_level`. **Append, never overwrite** — a promotion decision needs history, so add a `s_skill_matrix_history` table | **M** |
| 3.3 | Repoint course recommendation off K-12 tables (F-12) | `SkillMatchingController` queries `sub_std_map` (school subject↔standard map) and `front_desk\taskModel`. Repoint at the LMS course tables. **Blocked on Q-2** | **M** |
| 3.4 | Make suggestions readable (F-12) | `SuggestedCourseController` has only `store`. Add index/show routes and surface them in the LMS UI — otherwise the feature is write-only | S |
| 3.5 | Broaden the recommendation trigger (F-12) | Currently fires only on `approve_status='Rejected'`. Include completions, missed deadlines, quality signals | **M** |
| 3.6 | **Task → Competency** (F-14) | On task completion/approval, record a competency signal against the task's `skill_id`. Distinguish *evidence* from *score* | **M** |
| 3.7 | **Competency → Talent** (F-14) | Persist competency snapshots on talent/succession records so a promotion decision reads history, not a live join | **M** |
| 3.8 | **Talent → LMS** feedback loop | Skill gap identified in Talent → assign/recommend learning. `SuggestedCourse` is the natural carrier once 3.3/3.4 land | **M** |
| 3.9 | Cross-module navigation | From an employee profile, reach their tasks, courses, competencies, and talent record. Depends on F-41 | **M** |

---
### PHASE 4 — Replace mock data with live data — ~1.5 weeks

| # | Task | Files | Effort |
|---|---|---|---|
| 4.1 | **Talent Profile view** (F-23) | `talent-profile-view.tsx:50` — wire to the real API. Highest user-visible impact: this is the promotion-decision screen | **M** |
| 4.2 | **Employee Directory** (F-24) | `employee-directory.tsx:88,137` — remove the mock initial state **and the mock error fallback**. Show a real error state. Silently serving fabricated employees on API failure is worse than an error | S |
| 4.3 | Department Policies / Rules / SOPs (F-25/26/27) | `policies-tab.tsx:372`, `rules-tab.tsx:402`, `department-details-panel.tsx:154`. **Check whether backend endpoints exist first — they may need building** | **M** |
| 4.4 | Talent Admin Center KPIs (F-28) | `admin-center.tsx:157`. Fix the dead `onValueChange` Select (F-18) in the same pass | S |
| 4.5 | Profile dashboard (F-29) | `profile-dashboard.tsx:56-58` + remove the `index.ts:5` re-export | S |
| 4.6 | Task approvals (F-17/F-30) | `task-approvals-view.tsx:153,160` → `PATCH /api/task-management/workspace/{id}/approval`. Backend is ready and permission-gated. Remove the fabricated rejected task and the hardcoded comment count | S |
| 4.7 | **Offboarding document upload** (F-31) | `offboarding-center.tsx:411-416` — real file upload. A compliance step currently satisfied by typing a filename | **M** |
| 4.8 | Delete unused mock modules (F-33–37) | 5 files, ~11 exports. Pure deletion | S |
| 4.9 | Mobility Business Unit filter (F-32) | `mobility-center.tsx:817` | S |

---
### PHASE 5 — Correctness & dead code — ~1 week

| # | Task | Effort |
|---|---|---|
| 5.1 | Delete the four dead service objects (F-38). No importers — just remove | S |
| 5.2 | Fix `DELETE /feedback/{id}` (F-16): add the backend route **and** add error handling to `interview-tools-drawer.tsx:397`. The missing `.catch()` is the worse half | S |
| 5.3 | Fix local-vs-Laravel API routing (F-39): call local Next routes with an absolute path bypassing `apiClient`, or move them to Laravel | S |
| 5.4 | Fix SSR/client base-URL divergence (F-40) | S |
| 5.5 | Fix deep-linking (F-41): tolerant path matching, and a visible error instead of a silent `/dashboard` redirect. **Blocked on Q-5** | **M** |
| 5.6 | Resolve the Agentic module (F-19): register `m7` or delete the 8 screens + `services/agentic/*` + 7 backend controllers. **Blocked on Q-4** | S or M |
| 5.7 | Fix `react-hooks/purity` at `offboarding-center.tsx:130` — `Date.now()` during render (F-21) | S |
| 5.8 | Work through the 22 `set-state-in-effect` errors (F-21) | **M** |
| 5.9 | Fix `offboarding-service.ts` param typing (F-20) | S |

---
### PHASE 6 — QA (cannot be completed from source alone) — ~1 week

**Everything below needs what Section 6 lists. It has not been done and must not be reported as done.**

| # | Task |
|---|---|
| 6.1 | Full CRUD matrix per module — create/read/update/delete, real backend validation messages |
| 6.2 | Pagination, sorting, filters, search — boundary pages, multi-filter, filter+search |
| 6.3 | **Permission testing at the API level as ≥2 roles** — the Phase 1 exit criteria |
| 6.4 | Cross-module write verification — perform each trigger, confirm the downstream module actually updates |
| 6.5 | Edge cases — 422/403/404/500, long text, special characters, injection, large datasets, timezones, rapid double-submit |
| 6.6 | The 6 user journeys in Part C of the brief |
| 6.7 | Keyboard-only pass per module; colour-only status signals; focus states |
| 6.8 | Tablet-width responsiveness for tables, drawers, dialogs |

---

## 6. WHAT PARTS B & C STILL NEED

Parts A (structural) and the code-level half of Part B are **complete and verified**. The rest cannot be produced from source, and inventing it would be worse than leaving it open.

Required:
1. A running frontend + Laravel API with a seeded database.
2. **Credentials for ≥2 roles** (one Admin/HR, one Employee) in **≥2 tenants** — two tenants are non-negotiable for verifying the F-01 fix.
3. Valid `moduleId`/`menuId`/`submenuId` values, or a dump of `tblmenumaster_g2g`. Task, Competency, and Talent have no static routes; their screens cannot be enumerated statically (F-41).
4. A decision on whether `hp.triz.co.in` is in scope (Q-3).

---

## 7. PROGRESS TRACKER

**Update this table as work lands.** It is the resumption point for a cold start.

| Phase | Scope | Status | Landed | Notes |
|---|---|---|---|---|
| 0 | Guardrails | ☐ Not started | — | |
| 1 | Authorization | ◐ **In progress** | 2026-08-04 | 1.1, 1.2, 1.3 done + F-44/F-45. Remaining: 1.4–1.10 |
| 2 | Data integrity | ◐ **Mostly done** | 2026-08-04 | 2.1, 2.2, 2.5, 2.6 done + F-43/F-50/F-51/F-52. `route:list` and `route:cache` work for the first time. 2.3 blocked on Q-1 |
| 3 | Cross-module bridges | ☐ Not started | — | Blocked on Q-1, Q-2 |
| 4 | Mock → live data | ☐ Not started | — | |
| 5 | Correctness & dead code | ☐ Not started | — | 5.5 blocked on Q-5, 5.6 on Q-4 |
| 6 | QA | ☐ Not started | — | Blocked on Section 6 |

### Phase 1 task status

| # | Task | Status | Evidence |
|---|---|---|---|
| 1.1 | Fix the 9 context traits (F-01) | ✅ **Done** | New `Api/Concerns/ResolvesApiIdentity.php`. All 9 traits delegate to it. **70/70 controllers verified** to resolve `resolveApiIdentity` by reflection |
| 1.2 | Reject expired tokens (F-01b) | ✅ **Done** | `expires_at` check in `ResolvesApiIdentity` |
| 1.3 | Fix LMS authorization (F-03) | ✅ **Done** | New `Api/Concerns/ResolvesLmsIdentity.php`. 7 LMS controllers rewired; role now from `tbluser.user_profile_id`; fail-open branch removed |
| — | F-44 `type=API` auth bypass | ✅ **Done** | Escape removed from all 8 affected controllers |
| — | F-45 SkillDevelopment IDOR | ✅ **Done** | 7 `user_id` + 7 `sub_institute_id` reads now token-derived |
| — | F-03b LMS `user_id` IDOR (incomplete first fix) | ✅ **Done** | 39 request-derived `user_id` reads replaced across 7 LMS controllers |
| 1.4 | Guard unauthenticated controllers (F-02) | ✅ **Done** | **Zero** unauthenticated routes outside the public allowlist. `scripts/audit-auth-sweep.py` exits 0 |
| — | F-46 hand-rolled auth | ◐ **Mostly done** | 40 controllers migrated to `ResolvesApiIdentity`. Request-derived identity reads **109 → 25**; unauthenticated routes **38 → 0**. 48/48 classes verified by reflection |
| — | F-47 checker was wrong twice | ✅ **Done** | See F-47 — both defects fixed and the checker is now trustworthy |
| 1.5 | Token-required middleware | ✅ **Done** | New `RequireApiToken` middleware, alias `api.token`, attached to 26 routes in `routes/api.php` |
| 1.6 | Role gating beyond Task (F-04) | ✅ **Done** | New `RequireProfile` middleware (`profile:admin,hr,...`), applied to **23 write routes** across Competency, Performance and Talent. Allow *and* deny paths verified against a live account |
| 1.7 | Tokens out of the URL (F-05) | ✅ **Done** | Backend already accepted `Authorization: Bearer` (verified live on 5 endpoints with no token in the URL). `api-client.ts` now attaches the header on every call; the query param stays as a temporary fallback |
| 1.8 | Onboarding Next route (F-07) | ☐ Blocked | Q-6 |
| 1.9 | HP API key out of browser (F-06) | ☐ Blocked | Q-3 |
| 1.10 | CSRF exclusions (F-09) | ✅ **Done** | `localhost:3000` and `hp.triz.co.in` removed; the two localhost entries now apply only when `APP_ENV=local` |

**Verification performed on the landed work:** `php -l` clean on every changed/new file; Laravel autoloads and reflects all affected classes (70/70 trait controllers, 8/8 LMS controllers); original 403 wording preserved; the route sweep reports **0 request-derived identity reads** across all 85 shared-trait routes and 443 module-trait routes.

### Phase 2 task status

| # | Task | Status | Evidence |
|---|---|---|---|
| 2.1 | Reconcile `s_skill_matrix` schema (F-10) | ✅ **Done** | **F-10 was wrong as written — see below.** The real defect found and fixed |
| 2.2 | Fix `matrix` model `$fillable` (F-11) | ✅ **No change needed** | **F-11 was wrong.** All 8 fillable columns exist in the live table |
| 2.3 | Settle the KASBA model | ☐ Blocked | Q-1 |
| 2.4 | Migration drift check | ◐ Partial | Drift measured and a corrective migration written; a CI check is still outstanding |
| 2.5 | Delete `routes/talent.php` (F-15) | ✅ **Done** | Deleted |
| 2.6 | Delete the invalid ` as ` controller file (F-42) | ✅ **Done** | Deleted |
| — | F-43 + F-50/F-51/F-52 | ✅ **Done** | `route:list` **1683 routes, exit 0**; `route:cache` **exit 0**. Both were completely broken |

### Corrections to F-10 and F-11 — from the live database

Both findings were written from the migrations alone. The live schema says otherwise, and the difference matters:

```
s_skill_matrix: id, user_id, type, skill_id, skill_level, interest_level,
                knowledge, ability, behaviour, attitude, created_by, ...
```

- **F-11 was simply wrong.** `attitude` and `behaviour` exist and hold data (19 and 20 of 169 rows). KASBA storage is complete. The model's `$fillable` is valid in full. No change was needed.
- **F-10 was right that something is broken, wrong about what.** `type` exists — as `enum('skill','knowledge','ability','attitude','behaviour')`. The controller inserted `'competency'`, which is **not a member**. With `STRICT_TRANS_TABLES` active (confirmed on this server) MySQL rejects the whole INSERT, so adding a competency rating always failed. The data corroborates it: 146 rows `'skill'`, 23 NULL, **zero `'competency'`**.

**Fixed** by inserting `'skill'` — the value `SkillMatrixController`, the writer that works, already uses.

**The drift is real, though, and is the durable finding.** Migrations create neither `type`, `attitude` nor `behaviour`; all three were added directly to the database. And the `migrations` table records **309** entries against **210** files on disk — **99 recorded migrations no longer exist as files**. A fresh `php artisan migrate` therefore cannot reproduce production, and the competency write path would fail on any new environment while working in the old one.

`database/migrations/2026_08_04_120000_align_s_skill_matrix_with_live_schema.php` closes the gap for this table. It is written to be idempotent — a no-op against production, corrective on a fresh database — and its `down()` deliberately drops nothing, because it did not create those columns and they hold live data.

**Not run.** The configured database is shared and remote; running migrations against it is the owner's call, not mine.

### Live verification — performed 2026-08-04

The static checks are no longer the only evidence. The patched backend was run locally (`php artisan serve`) against the configured database and driven with a real account.

**Account used:** `healthcare@gmail.com` → user id 6, profile **Admin**, `sub_institute_id` **3**.

**Safety constraint observed:** `.env` sets `DB_HOST=202.47.117.220` — a shared remote database, not a local one. Every test was therefore **read-only (GET)**. The role-gate allow path was exercised by calling the middleware directly with a stub `$next`, precisely so that an allowed POST/PUT/DELETE never reached a controller and never wrote.

| Test | Endpoints | Result |
|---|---|---|
| No token at all | 18 | **All 401** |
| Valid token, own tenant | 18 | All 200 — nothing broken by the fixes |
| Valid token, claiming tenants 1/2/4/5/9 | 18 | **Identical to own-tenant response in every case** — the request value is ignored |
| Valid token, claiming other `user_id` | 18 | Ignored, except the two documented subject reads (`/get-employee-tasks`, `/career-journey`), both tenant-bounded |
| LMS `type=API` omitted (F-44) | 1 | **401** |
| LMS `user_profile_name=admin` with no token (F-03) | 1 | **401** |
| Bearer header, no token in URL | 5 | All 200 |
| `RequireProfile` allow path | 2 | Admin permitted where listed |
| `RequireProfile` deny path | 3 | **403** where profile not listed |
| `RequireProfile` bad credentials | 2 | **401** |

**F-01, F-03, F-03b, F-44 and F-45 are now confirmed closed against a running system, not merely by inspection.**

**Still not covered:** a genuine second tenant's account. Claiming another tenant is proven to be ignored, but "an account belonging to org 4 cannot read org 3" has not been demonstrated from the other side. Also untested: a non-Admin account, so the role gate's deny path is proven only via direct invocation, not over HTTP.

### Two false alarms this run — recorded because the method matters

Both initially looked like cross-tenant leaks and both were the **test** being wrong:

1. `/skill-development/streak` embeds a `created_at` accurate to the second, so two *identical* requests produced different fingerprints.
2. `/reports/employee-directory/attrition` sorts by `attritionRate` with many ties and no tiebreaker, so MySQL returns tied rows in arbitrary order.

The fix was to scrub timestamps and sort lists before comparing. Had I reported without re-testing, both would have been filed as critical vulnerabilities. **Confirm non-determinism before calling a difference a leak.**

**Finding status:** 42 from the audit + 4 found during remediation (F-43, F-44, F-45, F-46) = **46 total. 5 CLOSED** (F-01, F-03, F-03b, F-44, F-45). 41 open.

### Route-level auth coverage (measured, `routes/api.php`, 689 routes)

| Bucket | Before | After | Meaning |
|---|---|---|---|
| No auth at all | 25 | **2** | The 2 are signup OTP — public by design |
| Route middleware (`api.token`) | 0 | **29** | Added this session |
| Hand-rolled auth | 120 | 120 | **F-46 — the main remaining risk** |
| Module trait (fixed) | 443 | 443 | F-01 closed |
| Shared trait (fixed) | 85 | 85 | F-03 / F-03b closed |
| Task group middleware | 16 | 16 | Was already correct |

**Superseded by a per-method sweep.** The table above counts routes by *file*, which hides per-method gaps — `AJAXController` classified as "hand-rolled" because the file contained `findToken()` somewhere, while the method actually routed to (`getUsersMappings`) had no guard at all and was fully open.

`scripts/audit-auth-sweep.py` now resolves each route to its specific method and follows one-level delegation (a thin public method calling a guarded private helper counts as guarded). Re-run it after every batch; it exits non-zero if any route is unauthenticated and not allowlisted.

| Per-method measure | Start | Now |
|---|---|---|
| Unauthenticated, not allowlisted | 38 | **0** |
| Authenticated but identity read from request | 109 reads | **25 reads** |
| Guarded | 328 | 560 |

**The 25 remaining reads, and why they are still there.** Most are *deliberate and documented* — `user_id` naming a genuine subject rather than the actor:

| Controller | Reads | Disposition |
|---|---|---|
| `LmsCourseEnrollController` | 10 | **Deliberate.** An admin records enrolments/completions for other staff. Bounded by the token-derived tenant instead |
| `AJAXController::getUsersMappings` | 1 | **Deliberate.** A manager views another employee's tasks. Tenant-bounded |
| `SkillMatchingController` | 2 | Needs an actor-vs-subject decision |
| `BulkTaskController` | 2 | Needs a decision |
| 10 others | 1 each | Need a decision, one at a time |

These are the cases where a blanket replace would *remove a feature*. Each needs the product question answered — "is this me, or someone I am allowed to act on?" — so they are intentionally left for review rather than guessed at.

**Method used, for repeatability:** `sub_institute_id` was fixed mechanically across 34 API-only controllers (68+ reads) because in an API request it is *always* the caller's own organisation — no judgement needed. `user_id` was never batch-replaced. Two controllers (`HrmsLeaveController`, `tbluserController`) also serve session-authenticated web routes and were handled individually; `HrmsLeaveController::store` now takes the token's tenant when there is one and the session's otherwise, because it is genuinely reachable both ways.

---

## 8. OPEN QUESTIONS — DO NOT GUESS THESE

Each blocks specific tasks. Answers should be recorded inline here.

**Q-1 — What is the intended KASBA storage model?** *(blocks 2.3, all of Phase 3)*
Three competing stores exist (F-11), and `attitude`/`behaviour` have no per-employee scored column at all. Is `s_skill_matrix` canonical and `user_rating_details` legacy? And is `s_users_skills` (`department`, `category`, `title`, `proficiency_level`, `assessment_method`) intended as a definition library only?
> **Answer:**

**Q-2 — Should course recommendations return K-12 school subjects?** *(blocks 3.3)*
`SkillMatchingController` queries `sub_std_map` — the school ERP's subject↔standard map — and `front_desk\taskModel`. Is this deliberate reuse (one tenant runs both products), or did HRMS course matching get built against the wrong tables?
> **Answer:**

**Q-3 — Is `hp.triz.co.in` a permanent third backend?** *(blocks 1.9)*
It is the source of KASBA competency data via `get-kaba`, called **from the browser** with an exposed API key (F-06). It is outside both audited repos. Permanent architecture, or temporary bridge?
> **Answer:**

**Q-4 — Is the Agentic module held back deliberately?** *(blocks 5.6)*
8 built screens, `m7` unregistered, yet `AG_*_ACCESS_LINK` constants exist in `gtg-navigation.ts`. One-line omission, or intentional hold? Determines register vs. delete.
> **Answer:**

**Q-5 — Is backend-driven, menu-table routing the intended architecture?** *(blocks 5.5, 3.9)*
Every module URL resolves through `tblmenumaster_g2g.access_link` exact-match (F-41). Deliberate, or a migration state? Determines whether deep-linking, per-module error boundaries, and static routes need designing.
> **Answer:**

**Q-6 — Why does a second onboarding implementation exist?** *(blocks 1.8)*
Laravel has a real, database-backed `/api/onboarding/*` that the UI already uses. `app/api/onboarding/route.ts` is a parallel in-memory, unauthenticated store (F-07). Safe to delete, or does something depend on it?
> **Answer:**

**Q-7 — Is `hp_erp` intentionally one monolith for both K-12 and G2G?**
286 controllers spanning `school_setup/`, `lms/pal/`, `lms/curriculum/` **and** `Api/Competency/`, `Api/Talent/`. Changes to `tbluser` and settings resources are cross-product. If deliberate, a compatibility policy is needed. If accidental, it should be unpicked before it grows.
> **Answer:**

**Q-8 — Should task outcomes affect competency scores?**
The brief assumes yes; the code says no (F-14). Carried forward from v1 unanswered. **This is the single biggest product question in the report.**
> **Answer:**

---

## 9. SEVERITY ROLL-UP

| Severity | Count | IDs |
|---|---|---|
| **Critical** | 8 | F-01, F-02, F-03, F-07, F-10, F-13, F-14, F-23, F-24 *(9 incl. both mock-data criticals)* |
| **High** | 17 | F-04, F-05, F-06, F-11, F-12, F-16, F-17, F-18, F-19, F-22, F-25, F-26, F-27, F-28, F-29, F-30, F-31, F-38, F-39, F-41 |
| **Medium** | 14 | F-08, F-09, F-15, F-20, F-21, F-32, F-33, F-34, F-35, F-36, F-37, F-40 |
| **Low** | 1 | F-42 |

### Module readiness

| Module | Structural (C/H) | Auth model | Mock data | Verdict |
|---|---|---|---|---|
| **Task Management** | 1C / 2H | ✅ **Correct** — reference implementation | approvals view | **Closest to ready.** Use as the pattern |
| **Organization / HRIT** | 1C / 3H | ❌ Leave + Attendance traits exposed | directory, dept tabs | Not ready |
| **LMS** | 1C / 2H | ❌ **Worst** — role from client input, fails open | none rendered | Not ready |
| **Competency** | 3C / 2H | ❌ 17 controllers + 10 unauthenticated routes | none rendered | **Not ready** — core write path also 500s (F-10) |
| **Talent** | 2C / 6H | ❌ 29 controllers across 4 traits | profile, admin, offboarding | **Least ready** |
| **Agentic** | 1H | ❌ 7 controllers | — | Unreachable — decide first (Q-4) |

---

## 10. THE ONE-PARAGRAPH VERSION

Someone on this team already found the cross-tenant authorization bug, fixed it correctly in Task Management, and wrote a clear comment explaining it — then it was never propagated to the other nine modules. That single omission is 70 controllers of cross-tenant exposure and is the whole of Phase 1. The second problem is that the six modules do not talk to each other: LMS tracks course completion, `s_skill_matrix` stores competency scores, and nothing joins them, because the event infrastructure to carry that signal has never been created. Both ends of every missing bridge already exist. Neither problem is architecturally hard. Both are blocking.

---

*Audit only. No code was modified. `AUDIT-FINDINGS-v1.md` is superseded by this document; keep it for history but treat Section 2 as the correction record.*
