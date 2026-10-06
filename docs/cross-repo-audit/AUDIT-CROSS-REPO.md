# Cross-Repo Audit — hp_erp / g2gv0 / DB3

## Scope

This audit covers three repositories/systems treated as one delivered product: **hp_erp** (the Laravel/PHP backend at `C:/Users/MILAN/Downloads/hp_erp`, including its Blade+Vite server-rendered surface and its own `frontend/` Next.js app), **g2gv0** (the primary Next.js/React frontend at `C:/Users/MILAN/Downloads/g2gv0`), and **DB3** (the MariaDB instance at `128.199.17.97`, reached from hp_erp exclusively through Laravel's `live` database connection). The goal is to establish, module by module, which parts of this stack form a real, exercised request path from frontend to backend to database, and which parts are stubs, dead code, or unverified assumptions — and to do so without inflating confidence on any claim this audit could not directly verify.

## DB Scope Disclaimer

> **DB3 (live, 128.199.17.97) is not confirmed to be the database hp_erp's application code queries by default in production** (the app's default `DB_*` connection is documented elsewhere as pointing at a different host, 202.47.117.220) — every database finding in this document describes DB3's own state as queried directly through Laravel's `'live'` connection, and may not describe production behavior.

This caveat applies globally to every database-related claim in this document and in every sibling section file listed in the Table of Contents below. Findings framed as "DB3 has X" or "table Y contains Z" are statements about DB3 in isolation, obtained via the `live` connection defined in `config/database.php`, not statements about what the application's default (`DB_*`) connection — and therefore, unverified, production traffic — actually reads or writes. Where a finding asserts that a *default*-connection code path *would* hit DB3, that inference is flagged inline; it is not this audit's default assumption.

## Phase 0 — Wiring Confirmation

This section synthesizes whether frontend → backend → DB3 is a real, exercised path, module by module, based on direct file:line evidence. It does not re-derive the underlying recon; it cites it and states the resulting verdict.

### Transport layer: token attach → base URL → route → middleware

- **Every g2gv0 request attaches a bearer token by default.** `services/core/api-client.ts:23-26` pulls the Sanctum token from `readLaravelSession()` and sets `Authorization: Bearer <token>` on outgoing requests. This is the one mechanism common to essentially all modules that use `ApiClient`.
- **A legacy sub-path still bypasses that header.** The same file's doc comments (lines 10-21, 37-39, 189-193) admit some callers — named there as "legacy payroll routes" — still send the token as a `?token=` query-string parameter. The authentication-audit research (below) shows this is not confined to payroll: `ApiClient.request()` (`services/core/api-client.ts:132-139`) appends **any** `config.params` object onto the URL for every HTTP verb, so `token` leaks into the URL wherever a caller passes it via `params`/`get()`/`delete()`'s second argument, independent of the auto-attached header. This is a live, exercised pattern across `services/task/index.ts` (~35+ call sites), `services/hrms/payroll.ts` (8 of 9 GETs), `services/organization/settings.ts`, `services/agentic/excel.ts`, `services/competency/skill-detail.ts`, `services/account/index.ts`, `services/platform/organizations.ts`, and `services/organization/employee-directory.ts` — i.e., the "legacy" framing in the api-client doc comment understates the current footprint.
- **Base URL resolution is environment-branched, not hardcoded**, per `lib/api-config.ts`: `resolveApiBaseUrl()`/`resolveWebBaseUrl()` pick a dev vs. prod `NEXT_PUBLIC_API_BASE_URL_*` value based on a hostname heuristic in `isProductionEnvironment()` (localhost/`127.0.0.1`/private-IP ranges/`.local`/`.test` = dev; anything else = prod), falling back through `NEXT_PUBLIC_API_URL` to a bare `/api`. This means the actual backend host g2gv0 talks to in any given deployment is not statically knowable from this audit alone — it is a runtime/env decision, which matters for every "is this wired" claim below: wiring is confirmed at the code-path level, not confirmed against a specific live environment's env vars (NOT VERIFIED which concrete host receives traffic in the account's current production deployment).
- **Session storage**: `lib/laravel-session.ts` stores the token under the `"userData"` key in `localStorage` or `sessionStorage` depending on "remember me" — a client-readable, non-httpOnly store, relevant to every XSS-adjacent finding in the security audit (13).
- **Route protection on the frontend is cosmetic.** `proxy.ts:15` gates `/dashboard`, `/organization`, `/profile`, `/settings`, `/module` purely on the presence of a `gtg-session` cookie, explicitly commented in-repo as mock/client-side-only — it performs no token validation. This means for **every module**, real enforcement (if it exists at all) has to be checked at the Laravel layer, not assumed from the Next.js proxy. That check was done per-module in the authentication audit's spot checks (below), not exhaustively for all ~15 route files, so any module not named there is NOT VERIFIED for backend-side enforcement.
- **Backend enforcement is per-route, confirmed inconsistent.** `app/Http/Middleware/RequireApiToken.php` (alias `api.token`, wired in `bootstrap/app.php:105`) is Sanctum-based and checks `bearerToken() ?: input('token')`; its own doc comment states routes/api.php carries no blanket middleware group, and a prior sweep found 25 unguarded routes. Confirmed guarded: `/account/me` (`routes/api.php:1815-1816`), `/organization/profile` GET (`:1254`), `/task-management/tasks/{id}/documents*` (`:2726-2729`) — all inside `middleware('api.token')`. Confirmed **unguarded at the route level**: `/excel-agent/*` (`:2002-2009` — `credentials`, `test-connection`, `upload`, `template`), which self-guards inside `ExcelAutomationAgentController::tokenUser($request->input('token'))` — a check that reads only the body/query `token`, never `bearerToken()`. This is the one audited case where the query-param token is load-bearing, not legacy cruft, which is exactly why `services/agentic/excel.ts` never migrated off it (`:68`, `:98-102` — the latter builds a bare `templateUrl` href carrying a live token, the same bug class already fixed once for payslip downloads).

### Module-by-module wiring verdicts

| Module / surface | Frontend → Backend | Backend → DB3 | Verdict |
|---|---|---|---|
| Account (`/account/me`) | `services/account/index.ts:220` (GET, leaks token via params) → `RequireApiToken`-guarded route (`api.php:1815-1816`) | Uses default Eloquent connection, not confirmed as `live`/DB3 | **Real, exercised path frontend↔backend.** DB3-specific claim NOT VERIFIED — see DB Scope Disclaimer. |
| Organization profile/settings | `services/organization/settings.ts:100-101,157-158` (GET, leaks token) → `/organization/profile` guarded (`api.php:1254`) | Same as above | **Real, exercised.** DB3 linkage NOT VERIFIED. |
| Task management | `services/task/index.ts` (~35+ GET/DELETE sites leaking token via params) → `/task-management/tasks/{id}/documents*` guarded (`api.php:2726-2729`) | Not independently re-verified against DB3 in this synthesis | **Real, exercised**, and the largest single concentration of the token-in-URL pattern (CRA-cross-ref: see security audit §13 for the full list). |
| HRMS / Payroll | `services/hrms/payroll.ts:511,550,640,686,704,758,835,941` (GET, leaking) vs. `:1200-1201` (the one call explicitly hardened — `query.delete('token')` with an inline comment noting it now relies on the Bearer header) | Not independently re-verified here | **Real, exercised, and internally inconsistent** — one endpoint in the same file was fixed for the exact class of bug the other eight still have. This is the clearest evidence the "migrate off query tokens" effort (referenced in `api-client.ts`'s own doc comments) is partial, not abandoned or fictional. |
| Excel Agent | `services/agentic/excel.ts:68,77,98-102` | `routes/api.php:2002-2009`, **no route middleware** — controller-local `tokenUser($request->input('token'))` only | **Real, exercised, and the weakest link in the audited surface**: it is the one module where the query-string token is the *only* credential the backend accepts (header-blind check), and where a generated download URL (`:98-102`) carries a live token in a shareable link. |
| Competency / Skills | `services/competency/skill-detail.ts:119-126` (GET, leaking); `services/competency/kasba-rating-by-item.ts:58-63,96-101` (POST/PUT bodies that *still* leak into the URL, since `ApiClient` appends `params` regardless of HTTP verb) | Not independently re-verified here | **Real, exercised.** Notable because the POST/PUT leak cases show the vulnerability class isn't confined to GETs — any call site passing `params` is exposed regardless of verb. |
| Platform / Organizations list | `services/platform/organizations.ts:72-74` (GET, leaking) | Not independently re-verified here | **Real, exercised.** |
| Employee directory | `services/organization/employee-directory.ts:180-196` (`listParams`/`baseParams` feed its GET listings) | Not independently re-verified here | **Real, exercised.** |
| Talent / Recruitment | `services/talent/recruitment.ts:23-25` — defines its **own** `Authorization: Bearer` helper, independent of the shared token-leak pattern | Not independently re-verified here | **Real, exercised, and the one module confirmed clean** of the query-token pattern in the sampled set. |
| Neo4j-backed graph endpoints (Department/JobRole/Organization) | Routed at `routes/api.php:281-283`; controllers exist (`DepartmentGraphController`, `JobRoleGraphController`, `OrganizationGraphController`) plus `app/Services/Neo4jService.php` (also reachable via diagnostic-only `routes/web.php:96,99` `/neo4j-test`) | **No `NEO4J_*` env var or config anywhere in the repo** | **Wired at the route level but non-functional end-to-end** — any request that reaches these controllers will fail at the Neo4j client construction step for lack of connection config. This is not "dead code" in the unreferenced sense (see next row); it is reachable, routed, and broken. Full detail in §16 (Legacy & Unused Code). |
| Neo4j sync/graph variants (`Neo4jSyncController` root+`lms/`, `lms/GraphController`, `lms/GraphControllerNew`) | No live caller found; unreferenced or referenced only in commented-out routes | N/A | **Genuinely dead**, distinct from the row above — this distinction is preserved deliberately per the recon's explicit instruction not to flatten the Neo4j split. |
| Nango / Google Calendar OAuth | `NangoController.php`, routed at `routes/api.php:2031-2032` and `routes/web.php:134-135`; `Platform/IntegrationController` labels it `"oauth_stub"` in its own code | N/A | **Stub, not a real Nango integration.** No evidence anywhere in hp_erp of the actual Nango SDK or Nango's hosted API being called — "Nango" here names a Google Calendar OAuth flow only. Do not read this repo as having Nango-brand connector infrastructure. |
| Standalone MCP server | `g2gv0/.env.local.example` defines its own `DB_HOST/PORT/NAME/USER/PASSWORD` block, explicitly commented as the standalone MCP server's own DB config, distinct from Laravel's `.env`; code in `app/api/mcp/`, `lib/ai/mcp/`, `lib/mcp/`, `packages/conversational-mcp-core/` | **Not part of the hp_erp↔DB3 path at all** — it is a parallel database connection owned by a Node/MCP process, not Laravel | **Out of scope for the frontend→backend→DB3 wiring question** — flagging it here specifically so it is not mistaken for another route into DB3. Its own DB target is a fourth, separate database this audit does not characterize. |

### Frontends — which one is "the" frontend

Three distinct frontend surfaces coexist in this codebase:

1. **g2gv0** (Next.js 16.2.6, `C:/Users/MILAN/Downloads/g2gv0`) — the surface actually audited throughout this document via `services/*` and `ApiClient`.
2. **hp_erp/frontend** (a second, separate Next.js 15.3.1 / React 19 app, per its own `README`/`package.json` — internally consistent, no Vite/Next contradiction within that folder).
3. **Blade + Inertia, server-rendered** — built via the root-level `C:/Users/MILAN/Downloads/hp_erp/vite.config.js`, a genuinely different rendering path from either Next.js app.

**Which of these is actually served in production is NOT VERIFIED by this audit.** `routes/web.php` was consulted for the Neo4j diagnostic route and the Nango OAuth callback, not exhaustively diffed for a frontend-selection signal, and no nginx/deployment config was available to this audit. Any claim elsewhere in this document about "the frontend" should be read as "g2gv0, the surface with `services/*` API-client code," not as a claim that g2gv0 is the one users actually reach.

### Net Phase 0 verdict

For every module sampled above with a named frontend call site and a named backend route, the frontend→backend leg is **real and exercised**, not aspirational — actual `services/*` functions call actual guarded (or, for excel-agent, unguarded-but-self-checked) Laravel routes. The backend→DB3 leg is **not independently re-verified in this synthesis for any module** beyond the general fact that hp_erp's `live` connection exists and is queryable (§07, Database Audit) — whether any given controller's Eloquent models resolve to `live`/DB3 versus the default connection was outside this pass's scope per module, and the DB Scope Disclaimer above governs every such claim elsewhere in this audit set. The Neo4j and Nango rows are the two confirmed exceptions to "real path": both are routed, both are non-functional/stub respectively, for the specific reasons in their rows.

## 1. Architecture Summary

**Backend — hp_erp.** Laravel 12 (`laravel/framework` ^12.0) on PHP ^8.2, using Sanctum ^4.0 for API auth. Routes are split across `routes/api.php` (2,863 lines) plus `routes/lms.php`, `routes/hrms.php`, `routes/settings.php`, `routes/user.php`, `routes/user-api.php`, `routes/ai.php`, and `routes/platform.php`, all registered from `bootstrap/app.php:9-44`. Enforcement of `api.token`/Sanctum guards is applied per-route rather than as a blanket group (§Phase 0 above; full detail in §04/§10).

**Frontend — g2gv0.** Next.js App Router (Next 16.2.6, React ^19), with a thin shared `ApiClient` (`services/core/api-client.ts`) fronting per-domain service modules under `services/*`. Client-side route gating (`proxy.ts`) checks only for a cookie's presence, not its validity — see Phase 0. Four internal packages (`ai-intelligence-core`, `conversational-ai-core`, `conversational-mcp-core`, `platform-services-core`) are consumed via `tsconfig.json:48-71` `@shared/...` path aliases from 12+ files despite not being declared as an npm/pnpm workspace — a build-tooling gap, not a runtime-wiring gap (detailed in §09).

**Database — DB3.** MariaDB 10.1.48 at `128.199.17.97`, reached only via Laravel's `live` connection (`config/database.php`, ~lines 125-139), which reads `DB3_*` env vars with no fallback and is explicitly commented as not migrate-safe as a whole (comment block, lines 108-124). A separate `mysql_Dev` connection (lines 98-106) reads `DB2_*`. Full schema/state findings are in §07, all subject to the DB Scope Disclaimer above.

**Integrations.** Nango is a Google Calendar OAuth stub only (`NangoController.php`; no real Nango SDK/API usage found). AI/MCP integration is real code (see package list in §2 and detail in §12) but runs against its own standalone database config in g2gv0, separate from both hp_erp's `DB_*` default and its `live`/DB3 connection. **Neo4j is confirmed non-functional** — routed controllers exist with no `NEO4J_*` configuration anywhere in the repo — and is explicitly **not** counted as an active integration in this summary; see §16 for the full dead/broken-code split.

## 2. Technology Stack Audit

### hp_erp (`composer.json`)

| Package | Version | Note |
|---|---|---|
| `laravel/framework` | ^12.0 | Core framework |
| PHP | ^8.2 | Language requirement |
| `laravel/sanctum` | ^4.0 | Token + stateful-cookie auth (§10) |
| `laudis/neo4j-php-client` | ^3.4 | Present but unconfigured — see Phase 0 / §16 |
| `kreait/firebase-php` | ^6.0 | Firebase integration |
| `inertiajs/inertia-laravel` | ^2.0 | Backs the Blade+Inertia server-rendered frontend surface (§Phase 0 frontends discussion) |
| `barryvdh/laravel-dompdf` | ^3.1 | PDF generation, with `dompdf/dompdf` ^3.1 |
| `phpmailer/phpmailer` | ^6.10 | Mail sending |
| `google/apiclient` | `*` (unpinned) | Used by Google OAuth/Calendar flows |
| `guzzlehttp/guzzle` | ^7.10 | HTTP client |
| `league/flysystem-aws-s3-v3` | ^3.29 | S3-compatible storage |
| `tightenco/ziggy` | ^2.0 | Route sharing to JS |
| `laravel/breeze` (dev) | ^2.3 | Scaffolding |
| `laravel/pint` (dev) | ^1.13 | Code style |
| `laravel/sail` (dev) | ^1.41 | Local Docker env |
| `phpunit/phpunit` (dev) | ^11.5.3 | Test runner |

### g2gv0 (`package.json`)

| Package | Version | Note |
|---|---|---|
| `next` | 16.2.6 | App Router |
| `react` / `react-dom` | ^19 | |
| `@radix-ui/react-alert-dialog`, `-dialog`, `-dropdown-menu`, `-popover`, `-slot` | — | Primitive UI |
| `@base-ui/react` | ^1.5.0 | |
| `@xyflow/react` | ^12.11.1 | Graph/flow UI, paired with `@dagrejs/dagre` ^3.1.1 for layout — relevant to how Neo4j-shaped UI, if any, would be rendered even though the backend graph endpoints are non-functional |
| `@tanstack/react-query` | ^5.101.4 | Data fetching/caching |
| `react-hook-form` | ^7.80.0 | with `@hookform/resolvers` ^5.4.0 |
| `recharts` | ^3.9.1 | Charting |
| `zod` | ^4.4.3 | Schema validation |
| `tailwindcss` (dev) | ^4.2.0 | with `class-variance-authority`, `tailwind-merge`, `shadcn` ^4.8.0 |
| `@ai-sdk/google` | ^4.0.28 | Vercel AI SDK, Google provider |
| `ai` | ^7.0.42 | Vercel AI SDK core |
| `@modelcontextprotocol/sdk` | ^1.29.0 | MCP SDK — backs `lib/mcp/`, `app/api/mcp/` |
| npm scripts `mcp:server` / `mcp:check` | — | `lib/mcp/server/http-server.ts`, `lib/mcp/server/scripts/check-mcp.ts` |

Every fact above is attributed to its source repo's own manifest (`hp_erp/composer.json` or `g2gv0/package.json`) as stated in the pre-confirmed recon; none is inferred from usage.

## Table of Contents

- [03. Module Inventory](./03-module-inventory.md)
- [04. Role & Permission Audit](./04-role-permission-audit.md)
- [05. Menu System Audit](./05-menu-system-audit.md)
- [06. User Journeys](./06-user-journeys.md)
- [07. Database Audit](./07-database-audit.md)
- [08. API Audit](./08-api-audit.md)
- [09. Frontend Audit](./09-frontend-audit.md)
- [10. Authentication Audit](./10-authentication-audit.md)
- [11. Multi-Tenancy Audit](./11-multi-tenancy-audit.md)
- [12. Nango / MCP / AI Integrations](./12-nango-mcp-ai-integrations.md)
- [13. Security Audit](./13-security-audit.md)
- [14. Performance Audit](./14-performance-audit.md)
- [15. Bug & Gap Register](./15-bug-gap-register.md)
- [16. Legacy & Unused Code](./16-legacy-unused-code.md)
- [17. Feature Matrix Summary](./17-feature-matrix-summary.md)

## Rules Followed

- [x] Every database claim in this document is tagged against, or governed by, the DB Scope Disclaimer above — no DB3 finding is stated as a production-behavior fact.
- [x] No `information_schema` row counts are used anywhere in this document.
- [x] No credentials (DB3 or otherwise) are printed anywhere in this document.
- [x] The Neo4j split is preserved as three distinct states — routed-but-unconfigured (`DepartmentGraphController`, `JobRoleGraphController`, `OrganizationGraphController`, `Neo4jService`), diagnostic-only (`/neo4j-test`), and genuinely dead (`Neo4jSyncController` root+`lms/`, `lms/GraphController`, `lms/GraphControllerNew`) — not flattened into a single "Neo4j is unused" statement.
- [x] No new findings were minted in this file; where this document references prior findings it uses the existing `CRA-` and `F-` series already present in the sibling section files (`CRA-001`…`CRA-023`, `F-01`…`F-199` observed in this audit set) rather than introducing a competing numbering scheme.

## Verification Log

Independent re-check pass, run fresh against live code and **[DB3 / live / 128.199.17.97]** (no prior audit output trusted; all facts below re-derived from scratch via `php artisan tinker --execute="..." → DB::connection('live')->...` / `Schema::connection('live')->hasTable(...)`, or direct grep/Read of source).

**DB3 facts re-run (10 row counts + 4 existence/schema checks, all matched exactly what §07/§12/§03/§15 report):**
`hrms_attendances`=1263, `hrms_emp_leaves`=41, `talent_job_postings`=133, `lms_course_enroll`=1500, `g2g_platform_workflows`=3, `g2g_audit_log`=51, `hrms_departments_mapping`=0, `custom_module_tables`=1, `g2g_notification`=1, `talent_offer_acceptances`=0 — every count reproduced exactly [DB3 / live / 128.199.17.97]. Existence checks reproduced exactly: `ai_evaluations` NOT FOUND, `ai_evaluation_cases` NOT FOUND, `ai_templates` NOT FOUND, `ai_conversations` NOT FOUND, `ai_policy_rules` NOT FOUND, `ai_audit_logs` NOT FOUND, `mcp_audit_logs` NOT FOUND, `knowledge_base` NOT FOUND, `lms_data_content_neo4j` NOT FOUND [DB3 / live / 128.199.17.97]; `s_skill_matrix` confirmed to have no `sub_institute_id` column, 169 rows [DB3 / live / 128.199.17.97].

**Neo4j split (§16) re-grepped from scratch:** confirmed not flattened. `DepartmentGraphController`/`JobRoleGraphController`/`OrganizationGraphController` are live, uncommented routes at `routes/api.php:281-283`; `app/Services/Neo4jService.php` calls `env('NEO4J_URI'/'NEO4J_USERNAME'/'NEO4J_PASSWORD')` but none of those keys exist in `.env`, `.env.example`, or anywhere under `config/` — confirmed genuinely unconfigured, not merely undocumented. `Neo4jSyncController` (root `app/Http/Controllers/` and `app/Http/Controllers/lms/`), `lms/GraphController`, `lms/GraphControllerNew` are referenced only inside a fully commented-out block at `routes/lms.php:329-349` — confirmed genuinely dead, distinct from the routed-but-broken trio above.

**Word-doc claim verdicts spot-checked (6, against real code):** NotificationSender.php:12-19 confirmed "TWO CHANNELS" (inapp/email only, no sms/whatsapp string anywhere in the file) — claim 7/11 (FALSE for SMS/WhatsApp) holds. NangoController.php read in full — confirmed a 69-line stub matching the doc's own quoted comment verbatim — claim (CRA-016) holds. `config/cors.php:18-32` confirmed `allowed_origins:['*']` + `supports_credentials:true` verbatim — CRA-002 holds. `routes/console.php` confirmed exactly 6 `Schedule::command(...)` entries (readiness, events:project, events:react, certifications:scan-expiry, sync:data, leave:escalate) — claim 4/12 holds. `config/platform_services.php:190-201` confirmed by its own in-file comment block ("THE ONLY POINT IN THIS LIST THAT IS ACTUALLY ENFORCED") that only `hrms.leave.approval` is enforced — the "1 of 8" claim (§12 claim 5, CRA-009) holds. **One correction made:** §12's claim 19 originally stated a grep for "tamper"/"immutable"/"append-only" "returned zero hits in `app/`" — false as literally written (~20 files in `app/` use those words describing unrelated append-only tables like `g2g_event`); corrected in place (`12-nango-mcp-ai-integrations.md`, claim 19 row) to scope the zero-hit claim to `AuditLogProjector.php` itself, where it does hold. The underlying verdict (g2g_audit_log is not itself append-only/hash-chained) was re-verified and is unaffected.

**Module-inventory rows spot-checked (5, route+line level):** LMS enroll routes (`enrolled_courses`→1052, `available_courses`→1053, `enroll` POST/PUT/DELETE→1357-1359) confirmed verbatim in `routes/api.php`. Notifications routes (`index`/`unreadCount`/`markAllRead`/`markRead`) confirmed verbatim at `routes/api.php:2493-2496`. `/account/me` confirmed inside the `api.token`-guarded group at `routes/api.php:1815`. Talent `job-postings` resource (line 319) and `hiring-team` index (line 350) confirmed as live, uncommented code (not inside the adjacent doc-comment block, which closes at line 308/348 respectively — checked full surrounding context to rule out a false-dead read). Payroll `monthly-payroll*`/lock routes confirmed under `hrit.role:admin,hr` at `routes/hrms.php:213-221`, and Custom Module Builder's `custom-module` prefix group confirmed to carry no middleware array at all (`routes/web.php:218`), contrasting with guarded sibling groups — CRA-004 holds.

**Structural checks on this file:** the DB3 Scope Disclaimer appears exactly once (line 9); no other unqualified "the database" found (remaining "database" occurrences are all qualified — "database-related claim", "standalone database config", "Database Audit" section title, the "Database — DB3." header). `CRA-` series (001-023) does not collide with the `F-` series (max `F-199` within this audit set, `F-224` project-wide) or the `D-` series (max `D-056` project-wide, not used in this audit set at all) — confirmed by direct grep/sort of the numeric suffixes, not assumed from distinct prefixes alone.

**Net result:** every re-run DB3 fact matched; the Neo4j split, five spot-checked module-inventory rows, and six spot-checked Word-doc verdicts all held up under independent re-derivation. One overclaim was found and corrected (§12 claim 19's grep-scope wording); no other corrections were needed.
