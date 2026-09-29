# 17. Feature Matrix & Final Summary

## Feature Matrix — Cross-Repo ERP Audit

| Module | Web status | API status | DB3 status | Overall |
|---|---|---|---|---|
| HRMS (leave/attendance/onboarding) | OK — 5 calls verified | OK — routes/controllers match | OK — all 8 tables populated | **Implemented** |
| Talent Management | OK (one unaudited legacy file with documented "phantom" routes, superseded) | OK — 5 calls verified | OK — `talent_offer_acceptances` 0 rows by design | **Implemented** |
| Payroll | OK — 5 calls verified | OK | OK, except `hrms_salary_certificate` 0 rows (feature guarded/unreachable) | **Implemented** |
| LMS / Course Enrollment | OK — 5/5 calls match | OK | OK — all tables populated | **Implemented** |
| Skill / Competency | OK — 5 calls verified | OK | OK — all 5 tables populated | **Implemented** |
| Career Journey (public) | OK — 5 calls verified | OK | OK — 2 tables empty (no activity yet, not a defect) | **Implemented** |
| Assessment / AI | Candidate assessment OK; AI console calls match routes | Candidate OK; `EvaluationController` queries tables absent on host | `ai_evaluations`, `ai_evaluation_cases` **NOT FOUND** on DB3/live | **Partial** |
| Custom Module Builder | No frontend caller exists at all | Controller exists but **no auth/session/menu middleware** on the group | Tables exist, 1 row each | **Broken** |
| Platform Services | OK (1 orphaned `PUT /process/{id}` with no UI) | OK | OK — all 6 tables populated | **Implemented** |
| Organization | Core screens OK; several legacy redirect shims / dead `settings/organization_data` routes | OK | OK — all 6 tables populated | **Implemented** |
| Notifications | OK — 4 calls verified | OK | OK (1 row, low volume) | **Implemented** |
| Agentic | OK — 5 calls verified | OK | OK — 4 tables empty (unused features, not defects) | **Implemented** |

## Action List

### P0 — Critical blocker

- Secure or remove the `custom-module` route group (`web.php:216-237`): zero auth/session/menu middleware on tenant-mutating table/column CRUD, directly reachable even though no SPA caller exists.
- AI Evaluation feature will 500 in production: `ai_evaluations`/`ai_evaluation_cases` tables missing on the live DB3 host despite live, route-correct controller code — migrate the tables or feature-flag the UI off until they exist.

### P1 — High

- `app/api/screenCandidate/route.ts` returns a hardcoded mock analysis (`competency_match: 0, cultural_fit: 'Low', …`) on JSON-parse failure, indistinguishable from a genuine low-fit result — surface a real error instead.
- `app/api/jobrole-task-description/route.ts` likely always fails: it reads a server-side session via `readLaravelSession()`, which is always `null` on the server — the identical bug already documented and fixed by removing a sibling route (`jobrole-tasks/REMOVED.md`).
- `POST /teacherListAPI` (`routes/user.php:60`) has no middleware at all — unauthenticated write surface; audit and gate.

### P2 — Medium (cleanup / orphaned surface)

- Confirm-and-remove candidate orphaned routes: `skill-heatmap*`, `reporting-line/*`, `readiness/gates*` (already flagged "temporarily unwired" in-file), `designation_leave`, `jobrole-skill/store`, `weighting-config`, legacy `settings/organization_data` resource, `hrms-job-title*`, `hrms-attendance-report`, `PUT /process/{id}`, and the 18 `lms_apiController` `studentXxxAPI` routes.
- `buildwithAIController` and `GammaApiController` look superseded by `lms/ai/*` — verify safe to delete rather than leave as dead surface.
- `Route::resource('subjectwise_graph', chapterController::class)` looks like a copy-paste alias bug — verify intent.

### P3 — Nice to have

- `app/api/voice/config|synthesize|transcribe` are non-functional stubs (hardcoded languages, `audioUrl: null`, 501) — implement or remove from reachable UI.
- Consolidate duplicated helpers: `getErrorMessage`/`parseRetryAfterSeconds` copy-pasted between `ai/chat` and `ai/field-edit` route handlers; duplicate `add-detail`/`information` redirect-shim pages under `app/organization/`.
- `app/ai/page.tsx` silently swallows capability-fetch errors (`.catch(() => {})`) — add a visible error state.
- Settings "Preferences"/"Organization Defaults" hardcode a 3-locale list — generalize.
