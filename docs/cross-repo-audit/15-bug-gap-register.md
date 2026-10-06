# 15. Bug & Gap Register

Findings sourced from research not already tracked in `FIX-PLAN-v2.md` / `AUDIT-TALENT-MANAGEMENT.md` / hrit-audit / organization-audit / phase3 (checked via grep before assigning IDs). The one pre-existing, platform-wide authorization defect (cross-tenant access via request-supplied identity) is **F-01** in FIX-PLAN-v2 and is only cited where directly relevant below, not re-derived.

## Summary table

| ID | Severity | Title | Repo+File evidence | DB3 tag |
|---|---|---|---|---|
| CRA-001 | Critical | `hp_erp/package.json` has unresolved git merge-conflict markers | `hp_erp/package.json` (devDependencies block, `<<<<<<< HEAD` / `>>>>>>>`) | — |
| CRA-002 | High | CORS wildcard origin combined with credentials support | `hp_erp/config/cors.php:18-32` (`allowed_origins:['*']`, `supports_credentials:true`) | — |
| CRA-003 | High | `excel-agent` routes have zero route middleware and accept only a header-blind body/query token | `hp_erp/routes/api.php:2002-2009`; `ExcelAutomationAgentController` (`tokenUser($request->input('token'))`) | — |
| CRA-004 | High | Custom Module Builder: 15 routes with no auth/session/menu middleware at all | `hp_erp/routes/web.php:216-237` (`custom-module` prefix group); `CustomModuleController.php` | `custom_module_tables`(1), `custom_module_table_columns`(1) [DB3 / live / 128.199.17.97] |
| CRA-005 | High | `s_skill_matrix` has no tenant column at all | Schema audit — `s_skill_matrix` DESCRIBE | `s_skill_matrix` 169 rows, no `sub_institute_id` [DB3 / live / 128.199.17.97] |
| CRA-006 | High | Onboarding module: zero role/permission middleware on ~38 routes | `hp_erp/routes/api.php:2235-2305`; `OnboardingJourneyController`, `OnboardingTaskController` | `talent_onboarding_journeys`(1), `talent_onboarding_tasks`(1), `talent_onboarding_activity_log`(7) [DB3 / live / 128.199.17.97] |
| CRA-007 | High | Career/Offer flow structurally broken on this host: `talent_offer_acceptances` empty against 68 "sent" offers | `OfferLinkService::mint()`, `OfferResponseController::show` | `talent_offers` 71 rows (68 `status='sent'`), `talent_offer_acceptances` **0 rows** [DB3 / live / 128.199.17.97] |
| CRA-008 | High | AI Evaluations tables missing on DB3 — routes 500 at runtime on this host | `hp_erp/routes/ai.php` → `AI/EvaluationController.php:86,113,179,194,272-273,289` | `ai_evaluations`, `ai_evaluation_cases` **NOT FOUND** [DB3 / live / 128.199.17.97] |
| CRA-009 | Medium | Platform Services doc claims contradicted by code on 3 counts | `NotificationSender.php:12-19`; `config/platform_services.php:190-201,495-557`; `IntegrationController`/`SmtpIntegrationTester`/`WebhookIntegrationTester` | `g2g_platform_workflows` 3 rows [DB3 / live / 128.199.17.97] |
| CRA-010 | Medium | Token-in-URL fix (F-05) claimed "Done" but dozens of live call sites still leak | See per-file list below | — |
| CRA-011 | Medium | Neo4j routes registered but structurally non-functional | `DepartmentGraphController`, `JobRoleGraphController`, `OrganizationGraphController` (`routes/api.php:281-283`); `Neo4jService.php`; `LmsDataContentNeo4j` model | `lms_data_content_neo4j` table absent [DB3 / live / 128.199.17.97] |
| CRA-012 | Medium | `screenCandidate` silently returns a hardcoded mock result on parse failure | `g2gv0/app/api/screenCandidate/route.ts:175-183` | — |
| CRA-013 | Medium | Voice endpoints are complete stubs presented as live API surface | `g2gv0/app/api/voice/synthesize/route.ts:30`, `voice/transcribe/route.ts:17-23`, `voice/config/route.ts:15-19` | — |
| CRA-014 | Medium | Duplicate menu/rights table pairs store the tenant column in incompatible types | `tblmenumaster` vs `tblmenumaster_g2g`; `tblgroupwise_rights` vs `tblgroupwise_rights_g2g` | `tblmenumaster*`/`tblgroupwise_rights_g2g` store `sub_institute_id` as `text` (comma-list); `tblgroupwise_rights` stores it as `bigint` [DB3 / live / 128.199.17.97] |
| CRA-015 | Medium | Three-frontend architecture ambiguity — no deployment config states which is live | `hp_erp/routes/web.php` (293 lines, Blade-only, zero references to Next.js/Inertia); `hp_erp/frontend` (second Next.js app); `g2gv0` (audited app); `vite.config.js` | — |
| CRA-016 | Low | Nango integration is a confirmed, fully non-functional stub | `hp_erp/app/Http/Controllers/NangoController.php` (69 lines) | — |
| CRA-017 | Low | `conversational-mcp-core` package is an orphaned scaffold | `g2gv0/packages/conversational-mcp-core/src/{index.ts,types.ts}`; `tsconfig.json:48-71` | — |
| CRA-018 | Low | `hrms_departments_mapping` is an unused duplicate of `hrms_departments` | Schema audit | `hrms_departments_mapping` 0 rows vs `hrms_departments` 1236 rows, near-identical schema [DB3 / live / 128.199.17.97] |
| CRA-019 | Low | `PUT /process/{id}` is a genuinely orphaned backend endpoint | `hp_erp/routes/platform.php:191` (`ProcessController::update`); `g2gv0/lib/platform/process.ts` (no `updateProcess`) | — |
| CRA-020 | Low | Hardcoded, unverified Gemini model id in screening fallback | `g2gv0/app/api/screenCandidate/route.ts:13` (`GEMINI_ENDPOINT`, model `gemini-3.6-flash`) | — |
| CRA-021 | Low | `routes/lms.php` doc-comment/actual-path mismatch for `lmsAssignment` | `g2gv0/services/lms/assignment.ts` (comments say `/api/lmsAssignment/...`); `hp_erp/routes/lms.php` (`Route::prefix('lms')`, no `/api`) | — |
| CRA-022 | Low | Copy-paste controller aliasing in `routes/lms.php` | `hp_erp/routes/lms.php:242` (`Route::resource('subjectwise_graph', chapterController::class)`) | — |
| CRA-023 | Low | Legacy course-builder endpoints likely superseded but still live | `hp_erp/routes/api.php:1476-1480` (`buildwithAIController`, `GammaApiController`) | — |

## Detail by finding

### CRA-001 — Critical — Unresolved git merge-conflict markers in `package.json`
**Evidence:** `hp_erp/package.json` (devDependencies block, `<<<<<<< HEAD` / `>>>>>>>`)

The Vite/Blade asset manifest contains literal unresolved merge-conflict markers, which will break `npm install`/`npm run build` for the legacy Blade asset pipeline the moment it's run clean.

### CRA-002 — High — CORS wildcard origin combined with credentials support
**Evidence:** `hp_erp/config/cors.php:18-32` (`allowed_origins:['*']`, `supports_credentials:true`)

Laravel's `HandleCors` reflects any request `Origin` back verbatim when `*` is paired with credentials, so **any** website can make cookie/session-credentialed cross-origin requests to the API — no allowlist restricts this to the two real frontends (`g2g.scholarclone.com`, `localhost:3000`).

### CRA-003 — High — `excel-agent` routes have zero route middleware and a header-blind self-guard
**Evidence:** `hp_erp/routes/api.php:2002-2009`; `ExcelAutomationAgentController` (`tokenUser($request->input('token'))`)

Unlike every other audited surface, `/excel-agent/credentials`, `/test-connection`, `/upload`, `/template` carry **no** `api.token`/`profile:` middleware at all; the controller's self-guard reads only the body/query `token` field and never `bearerToken()`, so this endpoint has no header-based auth path whatsoever — distinct from (and worse than) the general token-in-URL issue tracked as F-05.

### CRA-004 — High — Custom Module Builder: 15 routes with no auth/session/menu middleware at all
**Evidence:** `hp_erp/routes/web.php:216-237` (`custom-module` prefix group); `CustomModuleController.php`
**DB3 tag:** `custom_module_tables`(1), `custom_module_table_columns`(1) [DB3 / live / 128.199.17.97]

Every sibling web route group carries `['auth','session','menu']`; this one carries nothing. Most endpoints (`tables`, `tableStore`, `crudStore`, etc.) read `$request->session()->get('sub_institute_id')` directly with no auth guard verifying a session exists at all — a real unauthenticated write surface, reachable by anyone who can hit the URL, even though g2gv0 never calls it.

### CRA-005 — High — `s_skill_matrix` has no tenant column at all
**Evidence:** Schema audit — `s_skill_matrix` DESCRIBE
**DB3 tag:** `s_skill_matrix` 169 rows, no `sub_institute_id` [DB3 / live / 128.199.17.97]

This is a per-user (not shared-catalogue) competency table with no way to scope by tenant at the DB layer — any query must join out to `s_users_skills`/`tbluser` to recover tenant context or it silently returns cross-tenant rows. Distinct from FIX-PLAN F-10 (which concerns a missing `type` column on the same table, already fixed).

### CRA-006 — High — Onboarding module: zero role/permission middleware on ~38 routes
**Evidence:** `hp_erp/routes/api.php:2235-2305`; `OnboardingJourneyController`, `OnboardingTaskController`
**DB3 tag:** `talent_onboarding_journeys`(1), `talent_onboarding_tasks`(1), `talent_onboarding_activity_log`(7) [DB3 / live / 128.199.17.97]

Tenant scoping is correctly derived from the Sanctum token per-controller, but **no route carries `profile:`/role middleware and no controller checks a role** — any authenticated user of any role (not just HR/admin) can create journeys from offers, complete onboarding stages, and delete tasks/documents/notes, unlike comparable modules elsewhere that wrap writes in `profile:admin,hr`.

### CRA-007 — High — Career/Offer flow structurally broken on this host
**Evidence:** `OfferLinkService::mint()`, `OfferResponseController::show`
**DB3 tag:** `talent_offers` 71 rows (68 `status='sent'`), `talent_offer_acceptances` **0 rows** [DB3 / live / 128.199.17.97]

`OfferLinkService::mint()` is the sole writer of `talent_offer_acceptances`, which every `/offer-response/{token}` resolution depends on. With 0 rows against 68 sent offers, those sends bypassed the mint path on this host — no candidate currently has a usable `/offer/[token]` link, so steps 7–8 of the candidate journey (open offer → accept/decline) cannot be exercised end-to-end today.

### CRA-008 — High — AI Evaluations tables missing on DB3 — routes 500 at runtime on this host
**Evidence:** `hp_erp/routes/ai.php` → `AI/EvaluationController.php:86,113,179,194,272-273,289`
**DB3 tag:** `ai_evaluations`, `ai_evaluation_cases` **NOT FOUND** [DB3 / live / 128.199.17.97]

Every `/api/ai/evaluations*` route (index/store/run/destroy) is contract-correct in code and matched exactly by the g2gv0 frontend (`lib/intelligence/ai-evaluations.ts`), but would throw `SQLSTATE[42S02]: Base table or view not found` against this specific DB3 host — a working feature that is unusable here purely due to a migration gap.

### CRA-009 — Medium — Platform Services doc claims contradicted by code on 3 counts
**Evidence:** `NotificationSender.php:12-19`; `config/platform_services.php:190-201,495-557`; `IntegrationController`/`SmtpIntegrationTester`/`WebhookIntegrationTester`
**DB3 tag:** `g2g_platform_workflows` 3 rows [DB3 / live / 128.199.17.97]

1. Notification is documented as supporting SMS/WhatsApp/email/in-app but only email+in-app exist in code.
2. Document and Integration are documented as "Planned, not built" but Integration has real, working SMTP/webhook CRUD+test and per-module document upload/versioning already exists (just fragmented).
3. Workflow is documented as "Working today" but only 1 of 8 declared workflow points (`hrms.leave.approval`) is actually enforced — the other 7 are declared but read by nothing.

### CRA-010 — Medium — Token-in-URL fix (F-05) claimed "Done" but dozens of live call sites still leak
**Evidence:**
- `services/task/index.ts` (~35+ sites)
- `services/hrms/payroll.ts` (8 of 9 calls)
- `services/organization/settings.ts:100-101,157-158`
- `services/agentic/excel.ts:68,98-102`
- `services/competency/skill-detail.ts:119-126`
- `services/account/index.ts:220`
- `services/platform/organizations.ts:72-74`
- `services/organization/employee-directory.ts:180-196`
- `services/competency/kasba-rating-by-item.ts:58-63,96-101` (POST/PUT bodies leaking too, since `params` always hits the URL regardless of verb)

FIX-PLAN-v2's remediation log marks F-05 "Done" ("api-client.ts now attaches the header on every call; query param stays as a temporary fallback"), but this audit found the fallback is still the **primary** path on a large majority of sampled call sites — only one call site (`downloadMonthlyPayslip`) is confirmed fully migrated off `?token=`. See F-05 (confirmed still open, contrary to its logged closure).

### CRA-011 — Medium — Neo4j routes registered but structurally non-functional
**Evidence:** `DepartmentGraphController`, `JobRoleGraphController`, `OrganizationGraphController` (`routes/api.php:281-283`); `Neo4jService.php`; `LmsDataContentNeo4j` model
**DB3 tag:** `lms_data_content_neo4j` table absent [DB3 / live / 128.199.17.97]

No `NEO4J_*` env var or config exists anywhere in the codebase, so these three routed, non-diagnostic controllers would fail at runtime if called. Separately, `Neo4jSyncController` (root and `lms/` variants), `lms/GraphController`, and `lms/GraphControllerNew` are genuinely dead code (unreferenced or only in commented-out routes), and the `LmsDataContentNeo4j` model points at a table that doesn't exist on this host at all.

### CRA-012 — Medium — `screenCandidate` silently returns a hardcoded mock result on parse failure
**Evidence:** `g2gv0/app/api/screenCandidate/route.ts:175-183`

When the 3-provider AI fallback chain (DeepSeek → OpenRouter → Gemini) returns unparseable JSON, the route falls back to a hardcoded mock analysis object (`competency_match: 0, cultural_fit: 'Low', …`) returned as if it were a genuine screening result — a caller cannot distinguish a real "Low fit" candidate from a silent parsing failure.

### CRA-013 — Medium — Voice endpoints are complete stubs presented as live API surface
**Evidence:** `g2gv0/app/api/voice/synthesize/route.ts:30`, `voice/transcribe/route.ts:17-23`, `voice/config/route.ts:15-19`

`synthesize` always returns `audioUrl: null`; `transcribe` just echoes a client-supplied transcript or 501s; `config` returns a fully static hardcoded object (language list, `mode:"browser"`). No real server-side STT/TTS exists behind any of the three routes despite them being real, callable endpoints.

### CRA-014 — Medium — Duplicate menu/rights table pairs store the tenant column in incompatible types
**Evidence:** `tblmenumaster` vs `tblmenumaster_g2g`; `tblgroupwise_rights` vs `tblgroupwise_rights_g2g`
**DB3 tag:** `tblmenumaster*`/`tblgroupwise_rights_g2g` store `sub_institute_id` as `text` (comma-list); `tblgroupwise_rights` stores it as `bigint` [DB3 / live / 128.199.17.97]

Two live, actively-written parallel catalogues exist per pair (196/199 rows menu, 1328/4980 rows rights) with the *same table shape otherwise* but an incompatible tenant-column type across the pair — a scoping query written for one shape (scalar equality) run against the other (comma-list) would silently under- or over-match tenant rows.

### CRA-015 — Medium — Three-frontend architecture ambiguity — no deployment config states which is live
**Evidence:** `hp_erp/routes/web.php` (293 lines, Blade-only, zero references to Next.js/Inertia); `hp_erp/frontend` (second Next.js app); `g2gv0` (audited app); `vite.config.js`

g2gv0, a second separate Next.js app at `hp_erp/frontend`, and server-rendered Blade+Vite pages all genuinely coexist in the repo with no nginx/deployment config found to settle which is actually served in production — a real risk of auditing/fixing the wrong frontend or shipping stale code in the unused one(s).

### CRA-016 — Low — Nango integration is a confirmed, fully non-functional stub
**Evidence:** `hp_erp/app/Http/Controllers/NangoController.php` (69 lines)

Every method returns a canned response (`checkConnection` hardcodes `connected:false`; `getOauthUrl`/`connectGoogle`/`googleCallback` all 501 via `notConfigured()`); no HTTP client, SDK import, or `NANGO_SECRET_KEY`/`NANGO_HOST` usage exists anywhere. The class's own doc-comment confirms this is deliberate and unfinished — informational, not a hidden defect, but worth registering as a real integration gap rather than assuming Nango is wired.

### CRA-017 — Low — `conversational-mcp-core` package is an orphaned scaffold
**Evidence:** `g2gv0/packages/conversational-mcp-core/src/{index.ts,types.ts}`; `tsconfig.json:48-71`

Zero real importers found anywhere in g2gv0 (only the `tsconfig.json` alias and the package's own `package.json` self-reference it) — including `app/api/mcp/` itself, which is the actual standalone-MCP code and does not consume this package at all. Dead code, safe to remove or clarify intent.

### CRA-018 — Low — `hrms_departments_mapping` is an unused duplicate of `hrms_departments`
**Evidence:** Schema audit
**DB3 tag:** `hrms_departments_mapping` 0 rows vs `hrms_departments` 1236 rows, near-identical schema [DB3 / live / 128.199.17.97]

Both tables carry `department`, `parent_id`, `tasks`, `roles_responsibility`, `sub_institute_id`; the mapping table has never been used on this host and looks like a legacy/superseded duplicate worth confirming safe to drop.

### CRA-019 — Low — `PUT /process/{id}` is a genuinely orphaned backend endpoint
**Evidence:** `hp_erp/routes/platform.php:191` (`ProcessController::update`); `g2gv0/lib/platform/process.ts` (no `updateProcess`)

Every other Platform Services route (34 of 35, including sibling `updateWorkflow`/`updateField`) has a confirmed frontend caller in `lib/platform/*.ts`; this is the one PUT with no corresponding client call anywhere in the tree.

### CRA-020 — Low — Hardcoded, unverified Gemini model id in screening fallback
**Evidence:** `g2gv0/app/api/screenCandidate/route.ts:13` (`GEMINI_ENDPOINT`, model `gemini-3.6-flash`)

The third link in the DeepSeek→OpenRouter→Gemini fallback chain hardcodes a model id that was not independently verified to exist — if invalid, the fallback silently fails and (per CRA-012) masks itself as a real "Low fit" mock result.

### CRA-021 — Low — `routes/lms.php` doc-comment/actual-path mismatch for `lmsAssignment`
**Evidence:** `g2gv0/services/lms/assignment.ts` (comments say `/api/lmsAssignment/...`); `hp_erp/routes/lms.php` (`Route::prefix('lms')`, no `/api`)

The frontend service file's own comments document calling `/api/lmsAssignment/stats` etc., but the Laravel route lives under prefix `lms` (not `api/lms`) — either an undocumented proxy/rewrite adds `/api`, or the comments are stale; worth a quick check of the axios base URL to confirm this isn't a live 404.

### CRA-022 — Low — Copy-paste controller aliasing in `routes/lms.php`
**Evidence:** `hp_erp/routes/lms.php:242` (`Route::resource('subjectwise_graph', chapterController::class)`)

An unrelated resource name (`subjectwise_graph`) is wired to `chapterController`, reading as a copy-paste leftover rather than intentional aliasing — low risk since no frontend caller was found for this route, but worth cleaning up before it's mistaken for real functionality.

### CRA-023 — Low — Legacy course-builder endpoints likely superseded but still live
**Evidence:** `hp_erp/routes/api.php:1476-1480` (`buildwithAIController`, `GammaApiController`)

No caller found in `g2gv0/services/**` for `/save-generated-course`, `/index` (buildwithAI), or the `gamma-api` resource; these look superseded by the newer `lms/ai/*` (DeepSeek+Gamma) and `lms/courses/*` endpoints — candidates for removal after confirming no other consumer exists.

---

**Note on scope**: several additional orphaned-endpoint candidates surfaced during the route-file audits (`/skill-heatmap`, `/reporting-line/*`, `/readiness/gates*` — the last already self-documented in-file as "temporarily unwired") are not repeated here as they were already flagged with clear rationale in the per-file research and don't represent new architectural risk beyond CRA-019/023.
