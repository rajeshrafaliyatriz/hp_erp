# 12. Nango / MCP / AI Integrations

## 1. The g2gv0 standalone MCP server: real, but split into two unrelated "MCP" paths

**It is a genuine, self-hosted MCP protocol server, not a thin client to an external SaaS.**

- `lib/mcp/server/server.ts` builds a real `McpServer` (`@modelcontextprotocol/sdk/server/mcp.js`, name `bi-mcp-server`) registering five tools:
  - `queryBusinessData`
  - `skillGapAnalysis`
  - `recommendCourses`
  - `generateJobRoleCompetency`
  - `getContextualSuggestions`
  
  plus resources/prompts.
- `lib/mcp/server/http-server.ts` runs it stand-alone: `createServer` on `MCP_PORT` (default `3400`), wired to `StreamableHTTPServerTransport`, called via `startMCPServer()` at module load — **this is a separate Node process from the Next.js app.**
- It talks directly to MariaDB via `lib/mcp/server/datasource/mariadb.ts` (own pool, own `.env.local`/`.env` search path up the directory tree, `DB_HOST`/`PORT`/`USER`/`PASSWORD`/`NAME`) — confirming the `.env.local.example` comment about a DB "used by the standalone MCP server."

The Next.js app talks to that process as a **client**:
- `lib/mcp/client/mcpClient.ts` opens `StreamableHTTPClientTransport(MCP_SERVER_URL)` (default `http://localhost:3400`) per call.
- `app/api/mcp/queryBusinessData/route.ts` + `app/api/mcp/capabilities/route.ts` are that thin-client route — so for *this* path the earlier characterization ("thin client on another port") is exactly right.

**However, `app/api/mcp/tools/call/route.ts` — despite living in the same `/api/mcp/` folder — does not touch this MCP server at all.** Its own doc comment says so: it forwards to `resolveAiBaseUrl()/ai/data-sources/{tool}/run`, i.e., straight into hp_erp's Laravel `AiReportController::runSource` (`routes/ai.php:240`), using the caller's forwarded bearer token, "so a check reads real rows for the caller's organisation." This is the AI Stack Knowledge Base "Check" button's backend, unrelated to `bi-mcp-server`/MariaDB.

So **"the MCP server" in this codebase is actually two independent things sharing a URL prefix**:
1. A real local MCP protocol server (leave-analytics tools, direct-DB).
2. A Laravel-proxying route that merely reuses the `/api/mcp/` path name.

`packages/conversational-mcp-core` is thin — just type re-exports (`ProjectToolCatalogEntry`, `ProjectMcpCapabilitySnapshot`); no runtime logic, confirming it's a shared-types package, not itself a workspace member (per the pre-confirmed recon).

## 2. AI middleware chain — names don't match, but real middleware exists

None of the literal names `MdcAuth`, `McpAuth`, `McpRateLimit`, `McpContextHydrator` exist anywhere in hp_erp.

The actual classes, applied group-wide in `routes/ai.php:69`, are:

| Doc name (unmatched) | Real class | File |
|---|---|---|
| `MdcAuth` | `App\Http\Middleware\AiAuth` | `app/Http/Middleware/AiAuth.php` |
| `McpRateLimit` | `App\Http\Middleware\AiRateLimit` | `app/Http/Middleware/AiRateLimit.php` |
| `McpContextHydrator` | `App\Http\Middleware\AiContextHydrator` | `app/Http/Middleware/AiContextHydrator.php` |

Applied in order: `[api, AiAuth::class, 'profile:admin', AiRateLimit::class, AiContextHydrator::class]` — auth, then admin-role gate, then per-user throttling, then org-scope hydration, per the file's own header comment.

`AiPolicyController` (`app/Http/Controllers/AI/AiPolicyController.php`) and `AiConfigurationController` (`app/Http/Controllers/AI/AiConfigurationController.php`) both exist and are wired under the same guarded group (`/policies*`, `/configuration*`).

**Verdict:** the *substance* the doc likely described (auth → rate-limit → context-hydration around policy/config endpoints) is real and correctly ordered — only the class names in the doc are wrong/aliased.

## 3. NangoController — confirmed stub, zero outbound calls

Read in full (69 lines). Every method returns a canned JSON response — no HTTP client, no Nango SDK import, no `NANGO_SECRET_KEY`/`NANGO_HOST` usage anywhere in the file.

- `checkConnection` hardcodes `connected: false`.
- `getOauthUrl`, `connectGoogle`, `googleCallback` all return HTTP 501 via a shared `notConfigured()` helper.

The class's own doc-comment states it explicitly:

> "This is a deliberate stub, not an implementation... To implement: set NANGO_SECRET_KEY / NANGO_HOST, replace these bodies with real Nango calls."

Confirms the pre-confirmed recon exactly — **no real Nango integration exists.**

## AI/MCP DB3 table verification

All 16 tables claimed by the Word doc were checked on **[DB3 / live / 128.199.17.97]** via `Schema::connection('live')->hasTable()` and `DB::connection('live')->table()->count()` (no information_schema used). Raw output saved to `C:/Users/MILAN/Downloads/hp_erp/Docs/cross-repo-audit/_evidence/ai-mcp-tables-db3.txt`.

| Table | Exists on DB3? | Row count |
|---|---|---|
| ai_api_keys | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_models | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_module_model_bindings | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_templates | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_policies | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_policy_rules | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_policy_assignments | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_agents | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_recommendations | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_conversations | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_generation_requests | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_generation_outputs | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| ai_generated_reports | EXISTS [DB3 / live / 128.199.17.97] | 0 |
| ai_audit_logs | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| mcp_audit_logs | NOT FOUND [DB3 / live / 128.199.17.97] | - |
| knowledge_base | NOT FOUND [DB3 / live / 128.199.17.97] | - |

**Summary [DB3 / live / 128.199.17.97]:** of 16 claimed tables, 6 exist (all with 0 rows: `ai_api_keys`, `ai_models`, `ai_module_model_bindings`, `ai_policies`, `ai_policy_assignments`, `ai_generated_reports`); 10 are NOT FOUND. No `mcp_`-prefixed table exists at all on this host (`LIKE '%mcp_%'` returned zero rows). A broader `LIKE '%ai_%'` scan also surfaced adjacent AI tables not in the claim list — `ai_course_outlines`, `ai_daily_used_api`, `ai_modules` — not otherwise verified here. This is DB3's own state only; it is a separate, differently-migrated host and may not match the app's default `DB_*` production connection.

## Word-doc claim verification (AI Intelligence doc)

### Verification: Platform Services claims vs. real code

**Meta-note:** Claims 1–2, 4, 6, 9, 12, 15, 18, 21, 24–31 describe the *.docx text itself* (its structure, what it does/doesn't mention). The .docx was not re-opened (not persisted per the task's own note), so these are reported **as given** in the extraction, not independently re-verified — marked UNVERIFIABLE(doc) below. Only the substantive claims about real system behavior were checked against code/DB3.

| # | Claim | Verdict | Evidence |
|---|---|---|---|
| 1,2,4,6,9,12,15,18,21 | 8 named services in doc | UNVERIFIABLE(doc) | Not re-extracted from .docx this session |
| 3 | RBAC "Working today" | TRUE | `profile:admin` middleware gate, role_key checks in `routes/platform.php:64-76`; roles/permissions enforced across `app/Http/Controllers` (10+ files use hasRole/role checks) |
| 5 | Workflow "Working today" | PARTIAL | Code is real (`WorkflowController.php`, `PlatformRegistry::isEnforced()`), but **only 1 of 8 declared workflow points is actually enforced** — `hrms.leave.approval` via `LeaveApprovalWorkflow`; the other 7 are declared but "read by nothing" per `config/platform_services.php:190-201`. [DB3/live] `g2g_platform_workflows` has 3 rows |
| 7 | Notification: SMS, email, app, WhatsApp | **FALSE** | `NotificationSender.php:12-19` explicitly: "TWO CHANNELS" — `inapp` and `email` only. No SMS or WhatsApp channel exists anywhere in `NotificationSender`/`NotificationComposer`/`RecipientResolver` |
| 8 | Notification "Working today" | PARTIAL | In-app channel is live and ON by default; email channel is **built but deliberately OFF** (`G2G_NOTIFY_EMAIL` flag, off pending 3 conditions, `NotificationSender.php:20-46`). [DB3/live] `g2g_notification` count=1 row |
| 10 | Scheduler runs tasks automatically, no manual trigger | TRUE (mostly) | `SchedulerController.php`, reads Laravel's live schedule (`routes/console.php`); but a manual `runNow` route also exists (`routes/platform.php:109`), and one entry (`sync.data`) is documented as silently broken/misconfigured (`config/platform_services.php:408-430`) |
| 11 | Scheduler "Working today" | TRUE | [DB3/live] `g2g_platform_task_runs` count=2 — ledger is populated |
| 13 | Document: store files, track versions, one place | FALSE as "one place" | Document/version handling **already exists** but is fragmented across modules: `EmployeeDocumentController`, `OnboardingDocumentController`, `TaskDocumentController`, `TaskAttachmentVersionController` — not a unified service |
| 14 | Document "Planned — not built yet" | **FALSE** | Contradicted by #13's evidence — per-module document upload/versioning code already exists in the repo, it's just not consolidated |
| 16 | Integration: payment gateways, govt portals, SMS providers | **FALSE** | `config/platform_services.php:495-557` lists real integrations: Gemini AI, n8n, FCM push, Google Calendar OAuth, LMS partners, SMTP, webhook. **None are payment gateways, government portals, or SMS providers** |
| 17 | Integration "Planned — not built yet" | **FALSE** | `IntegrationController`, `SmtpIntegrationTester`, `WebhookIntegrationTester` are working, testable code; [DB3/live] `g2g_integration_credentials` count=2 rows (credentials actually saved) |
| 19 | Audit: "permanent, tamper-proof record" | PARTIAL | `AuditLogProjector` writes `g2g_audit_log` via `updateOrInsert` (idempotent, not literally tamper-proof/immutable — no append-only/hash-chain mechanism found; grep for "tamper"/"immutable"/"append-only" returned zero hits in `AuditLogProjector.php` itself — correction: these terms do appear in ~20 other `app/` files as code-comment language about unrelated append-only tables like `g2g_event`, so the original "zero hits in `app/`" framing was too broad; the substantive point stands unchanged — nothing marks `g2g_audit_log` itself as append-only/hash-chained). [DB3/live] `g2g_audit_log` count=51 |
| 20 | Audit "Working today" | TRUE | Confirmed by populated table above |
| 22 | Event Bus: one screen showing everything for a case | PARTIAL | `EventBusController`/`EventBusReader` provide summary/stream/consumers/failures — an operator's estate-wide view, not case-scoped per claim's framing; [DB3/live] `g2g_event` count=345, `g2g_event_delivery` count=61 |
| 23 | Event Bus "viewing screen only" | TRUE (mostly) | Read-only by design (`routes/platform.php:80-86`); one narrow exception: `/events/replay` route exists but is restricted to idempotent projectors only, never reactors — consistent with "viewing" framing |
| 25 | Fee-payment flow: Access Control→Workflow→Notification→Audit→Event Bus | UNVERIFIABLE | No fee-payment module or matching sequential invocation found anywhere in `hp_erp` or `g2gv0` |
| 26,27 | 6 working / 2 planned split | **FALSE** (per above) | Document is NOT unbuilt (#14); Integration is NOT unbuilt (#17); Workflow is only partially enforced (#5) |
| 28–31 | Doc has no file/DB/middleware/AI references | UNVERIFIABLE(doc) | Not independently re-checked; codebase itself has extensive AI infrastructure (`app/Domain/AI/`, `AiPolicyController`, etc.) that is real but orthogonal to whether the .docx mentions it |

### Key findings

Claim 7 (WhatsApp/SMS) and claims 14/16/17 (Document/Integration "not built") are demonstrably false against actual code — Integration is real and working (wrong description of *what* it integrates), and Document functionality already exists (just decentralized). Workflow enforcement is far shallower than "working" implies (1/8 points).
