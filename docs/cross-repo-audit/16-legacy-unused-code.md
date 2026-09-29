# 16. Legacy & Unused Code

## Auth/Session Layer

- `api-client.ts` attaches `Authorization: Bearer` from `readLaravelSession()` (lines 23-26) to every request.
- Doc comments in the same file (lines 10-21, 37-39, 189-193) admit that **legacy payroll routes still send the token via a `?token=` query param**, unmigrated to the `Authorization` header pattern.
- The token itself lives under a `localStorage`/`sessionStorage` key `"userData"` (`laravel-session.ts`), with the storage tier chosen by "remember me".
- `proxy.ts:15` gates `/dashboard,/organization,/profile,/settings,/module` **only** on the presence of cookie `gtg-session` — explicitly commented as mock/client-side-only, with **no token validation**. Real enforcement must live in the Laravel API layer (not verified per-module here).
- `api-config.ts` branches base URLs on a hostname heuristic (`isProductionEnvironment()`), env-var driven, with a `/api` ultimate fallback.

## Standalone MCP Server

- `.env.local.example` defines a wholly separate DB config for a standalone MCP server (`app/api/mcp/`, `lib/ai/mcp/`, `lib/mcp/`, `packages/conversational-mcp-core/`), **distinct from Laravel's own DB**.

## Package Aliasing

`packages/{ai-intelligence-core,conversational-ai-core,conversational-mcp-core,platform-services-core}` are **not** an npm/pnpm workspace — they are only reachable via `tsconfig.json:48-71` `@shared/*` path aliases.

Real-importer check (grepping for `@shared/<pkg>` outside `tsconfig.json` and each package's own `package.json`):

| Package | Status | Evidence |
|---|---|---|
| `ai-intelligence-core` | Used | 6 real importers (`app/ai/**`, `app/platform-services/whats-coming/page.tsx`, `components/shell/gtg-user-menu.tsx`) |
| `platform-services-core` | Used | 7 real importers (`app/platform-services/**`, `hooks/use-platform-destination.ts`, `components/shell/gtg-user-menu.tsx`) |
| `conversational-ai-core` | Used, but thin | Exactly one real importer: `lib/ai/index.ts:11` (`import {...} from "@shared/conversational-ai-core"`) |
| `conversational-mcp-core` | **Orphaned scaffold** | **Zero real importers.** Only hits are `tsconfig.json` (the alias) and the package's own `package.json` self-reference. Confirmed by cross-check: `app/api/mcp/` (the actual standalone-MCP code cited above) contains no reference to `conversational-mcp-core` at all — it doesn't consume this package; the alias points to a `packages/conversational-mcp-core/src/{index.ts,types.ts}` nobody imports. |

## Database

- `hp_erp/config/database.php`: connection `live` (~125-139) reads `DB3_*` via `env()` with no fallback; the comment block (108-124) says `DB3_*` previously existed but was inert until this connection was added, and warns it is **not migrate-safe as a whole**.
- `mysql_Dev` (98-106) reads `DB2_*`.

## Routes

Split across `routes/api.php` (2863 lines) + `lms.php`, `hrms.php`, `settings.php`, `user.php`, `user-api.php`, `ai.php`, `platform.php`, all registered in `bootstrap/app.php:9-44`.

## Neo4j Split (as-is, not flattened)

- `DepartmentGraphController`, `JobRoleGraphController`, `OrganizationGraphController` (routed, `routes/api.php:281-283`) plus `app/Services/Neo4jService.php` (also used by diagnostic-only `routes/web.php:96,99` `/neo4j-test`) are **routed but non-functional** — no `NEO4J_*` env var or config exists anywhere.
- `Neo4jSyncController` (root and `lms/` variants), `lms/GraphController`, `lms/GraphControllerNew` are **genuinely dead** — unreferenced, or referenced only in commented-out routes.

## Three-Frontend Discrepancy

g2gv0 (Next.js 15/React 19, the one audited above), `hp_erp/frontend` (a second, separate Next.js app), and Blade+Vite server-rendered pages (`hp_erp/vite.config.js` builds `resources/css/app.css` + `resources/js/app.js` for laravel-vite-plugin) genuinely coexist.

- `hp_erp/routes/web.php` (293 lines) shows only Blade controllers/views (e.g. `return view('welcome')` at line 106) and **zero references** to Next.js, Inertia, or either `frontend/` app — no route wires to g2gv0 or `hp_erp/frontend`.
- **NOT VERIFIED**: which of the two Next.js apps (if either) is actually deployed/linked in production — no nginx/deployment config exists in the repo (only `public/.htaccess`, which is Laravel's default rewrite rule, uninformative here) to settle it.
- Also noted in passing: `hp_erp/package.json` (the Vite/Blade asset manifest) contains **unresolved git merge-conflict markers** (`<<<<<<< HEAD` / `>>>>>>>`) around the `devDependencies` block — a separate, real defect worth flagging even though outside this audit's scope.

## Nango

`NangoController.php` is a **Google Calendar OAuth stub only** (`routes/api.php:2031-2032`, `routes/web.php:134-135`); `Platform/IntegrationController` labels it `"oauth_stub"`. No real Nango SDK/API usage found.

---

## Word-Doc Claim Verification (Platform Services Doc)

### Verification Results — hp_erp / G2G Platform Services

| # | Claim | Verdict | Evidence |
|---|---|---|---|
| 1 | RBAC status: "Working today" | TRUE | `app/Http/Middleware/RequireMenuRight.php`, `RequireProfile.php`, `TaskPermissionMiddleware.php` — real DB-backed checks (`tblgroupwise_rights_g2g`, `tbluserprofilemaster.role_key`), wired via `bootstrap/app.php:94-119` |
| 2 | Workflow status: "Working today" | TRUE | `app/Services/Leave/LeaveApprovalWorkflow.php` + `hrms_leave_approval_steps` table drive real sequential state; `app/Http/Controllers/Platform/WorkflowController.php` (config console) |
| 3 | Notification status: "Working today" | TRUE | `app/Services/Notifications/NotificationSender.php` writes real rows/sends real mail |
| 4 | Scheduler status: "Working today" | TRUE | `routes/console.php` — `php artisan schedule:list` output shows 6 live cron entries (readiness, events:project, events:react, certifications:scan-expiry, sync:data, leave:escalate) |
| 5 | Document status: "Planned, not built" | PARTIAL | File storage exists per-module (`task_documents`, `task_management_attachment_versions`, `staff_document`, `s_performance_attachments`) but no unified store — doc's "not built" is right for a *central* service, wrong that nothing exists |
| 6 | Integration status: "Planned, not built" | FALSE | `app/Http/Controllers/Platform/IntegrationController.php` + `g2g_integration_credentials` table + `SmtpIntegrationTester`/`WebhookIntegrationTester` (`2026_09_28_130000_create_g2g_integration_credentials_table.php`) — real save+test for SMTP/webhook is live |
| 7 | Audit status: "Working today" | TRUE | `g2g_audit_log` (migration `2026_08_10_150000`), `AuditLogProjector.php`; only `index`/`export` routes exposed (routes/api.php:1647-1648) — no write/delete route |
| 8 | Event Bus: "viewing screen only" | PARTIAL | Mostly read-only (`summary`,`stream`,`consumers`,`failures`), but `POST /events/replay` exists and works for projector-kind consumers (`EventBusController.php:123`) — not purely view-only, though reactors (side-effecting consumers) are explicitly blocked |
| 9 | RBAC enforces on every screen | FALSE | `RequireMenuRight.php` docblock: "A route with no declaration is not silently allowed... enforcement lands per-route, never globally" — explicitly not universal |
| 10 | Workflow: sequential, stateful steps | TRUE | `hrms_leave_approval_steps`: step N is `pending` only after step N-1 `approved`; `recordDecision()` in `LeaveApprovalWorkflow.php:635-796` |
| 11 | Notification: SMS, email, in-app, WhatsApp | FALSE | Only `inapp` and `email` exist (`2026_08_11_000100_create_g2g_notification_tables.php:64`, `NotificationSender.php`) — no SMS/WhatsApp code anywhere |
| 12 | Scheduler: real recurring cron | TRUE | Same as #4 |
| 13 | Document: store+version in one place (not built) | PARTIAL | See #5 — not built as one place, but versioning exists fragmented (e.g. `task_management_attachment_versions`) |
| 14 | Integration: payment gateways/govt portals/SMS (not built) | TRUE | Registry (`config/platform_services.php:530-560`) only declares `smtp`/`webhook`/oauth-stub/LMS providers — no payment/government/SMS provider anywhere in codebase |
| 15 | Audit: permanent, tamper-proof record | TRUE (as far as app layer) | `g2g_audit_log` unique(`event_id`), no API write/delete path; "permanent" not independently guaranteed at DB-privilege level — UNVERIFIED beyond app layer |
| 16 | Event Bus: view-only, no write/action claimed | PARTIAL | Contradicted narrowly by `/events/replay` (#8) |
| 17-22 | Fee-payment walkthrough (RBAC gate → Workflow step → approval → notify parent → audit → Event Bus view) | UNVERIFIABLE | No "fee" module exists in hp_erp (`grep -ri fee` returns nothing outside `.git` noise). The *mechanism* is real and demonstrated end-to-end for Leave approval (RBAC via `task.permission`, sequential steps, `LeaveNotifier`, audit projection, event bus), but the specific fee-payment scenario is illustrative, not an implemented feature to check |
| 23 | New module (e.g. Transport) could plug into Workflow/Notification/RBAC | PLAUSIBLE/TRUE | `WorkflowController` validates against a registry (`config/platform_services.php`) designed for pluggable "points"; `RequireProfile`/`RequireMenuRight` are generic route middleware — architecture supports this, but no Transport module exists to confirm in practice |
| 24 | Live services exactly: Access Control, Workflow, Notification, Scheduler, Audit, Event Bus (view-only) | PARTIAL | All six are real; Event Bus isn't purely view-only (#8/#16); Integration also has real (partial) capability contradicting a clean "not live" line |
| 25 | Not-built exactly: Document, Integration | FALSE | Integration has real, working SMTP/webhook CRUD+test (#6); only Document (as a unified store) fits "not built" |

**No DB3/`live` connection facts were needed** — every claim above resolved from `hp_erp` source/migrations/routes; no g2gv0 (Next.js) evidence was needed either since all checkable claims are server-side. No queries were run against `DB::connection('live')`.
