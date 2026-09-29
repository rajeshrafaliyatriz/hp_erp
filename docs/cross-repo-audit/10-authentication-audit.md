# 10. Authentication Audit

## 10.1 hp_erp: Sanctum issuance, `/login`, CORS

### Token issuance

| Flow | Location | Detail |
|---|---|---|
| Password login | `app/Http/Controllers/auth/authController.php:643` | `$user->createToken(DeviceLabel, ['*'], now()->addDays(IDLE_DAYS))->plainTextToken` |
| OTP/SMS login | `app/Http/Controllers/auth/authController.php:972` | Same `createToken(...)` pattern as password login |
| Google OAuth login | `Api/GoogleAuthController.php:202` | Mints a token the same way for Google OAuth login |

`TouchTokenActivity` middleware slides the expiry forward on each authenticated hit (idle-timeout style renewal, not a fixed absolute expiry).

### `/login`

- `routes/web.php:109` — `Route::resource('login', authController::class)`
- `routes/web.php:127` — `user_login`

Both are **session/web routes, not API routes**. `config/sanctum.php` sets `guard => ['web']` and a `stateful` list including `localhost`, `127.0.0.1`, `hp.triz.co.in` — so first-party SPA requests from those hosts can also authenticate via the session cookie (`EnsureFrontendRequestsAreStateful`-style), **independent of the Bearer token**.

### CORS (`config/cors.php`)

```
allowed_origins => ['*']
supports_credentials => true
```

Laravel's CORS handler reflects the literal request `Origin` back (required for the browser to accept credentialed cross-origin requests), so **any origin can make cookie/credentialed requests to this API**. This is a real gap, not just a lint issue — it undermines the "only stateful domains get the cookie" intent in `sanctum.php`. **Recommendation:** narrow `allowed_origins` to the actual frontend hosts.

### Enforcement is per-route, not global

`app/Http/Middleware/RequireApiToken.php` (alias `api.token`, registered `bootstrap/app.php:105`) is Sanctum-based (`bearerToken() ?: input('token')`), but its own doc comment states that `routes/api.php` has **no blanket middleware group**, and a prior sweep found **25 unguarded routes**.

**Spot-checks:**

| Route | Location | Guarded? |
|---|---|---|
| `/account/me` | `api.php:1815-1816`, inside `middleware('api.token')` group | ✅ |
| `/organization/profile` (GET) | `api.php:1254`, `->middleware('api.token')` | ✅ |
| `/task-management/tasks/{id}/documents*` | `api.php:2726-2729`, `->middleware('api.token')` | ✅ |
| `/excel-agent/*` (`credentials`, `test-connection`, `upload`, `template`) | `api.php:2002-2009` | ❌ **No route middleware at all** |

`/excel-agent/*` self-guards inside `ExcelAutomationAgentController` via `tokenUser($request->input('token'))` — but that check reads **only** the body/query `token` field, never `bearerToken()`. So excel-agent is the one audited surface where the query/body token isn't legacy cruft — it's **the only credential the endpoint accepts**, which is why `services/agentic/excel.ts` (g2gv0) never migrated it off query-string tokens.

---

## 10.2 g2gv0: exhaustive `token`-in-URL grep

`ApiClient.request()` (`services/core/api-client.ts:132-139`) glues **any** `config.params` onto the URL as a query string for **every HTTP method** — GET/POST/PUT/DELETE alike. So a `token` key lands in the URL whenever it's passed via `params`/`get()`/`delete()`'s second arg, or a POST/PUT's `options.params`, regardless of the auto-attached Authorization header.

### Still leaking token into the URL (representative, not exhaustive count)

| File | Lines / detail |
|---|---|
| `services/task/index.ts` | Dozens of `.get(...)`/`.delete(...)` calls passing `{ token: context.token, ... }` as params, e.g. lines 188-190, 260-262, 266-268, 286-288, 335-337, 358-360, 391-393, 523-525, 646-648, 797-798, 980-982 (~35+ sites total, same pattern throughout the file) |
| `services/hrms/payroll.ts` | Every `webClient.get(...)` except the one fixed call: lines 511, 550, 640, 686, 704, 758, 835, 941 — all via `withLaravelParams(context)` |
| `services/organization/settings.ts` | `:100-101` (`get`), `:157-158` (`audit`) |
| `services/agentic/excel.ts` | `:68` (`credentials`, GET) and `:98-102` (`templateUrl` → `buildApiUrl`, a bare href with a live token — worst case, same class of bug already fixed for the payslip download) |
| `services/competency/skill-detail.ts` | `:119-126` (GET) |
| `services/account/index.ts` | `:220` (`me`, GET) |
| `services/platform/organizations.ts` | `:72-74` (`list`, GET) |
| `services/organization/employee-directory.ts` | `:180-196` (`listParams`/`baseParams`, used by its GET listing calls) |
| `services/competency/kasba-rating-by-item.ts` | `:58-63, 96-101` — notable because these are **POST/PUT bodies that still leak**, since `params` always hits the URL regardless of method |

### Migrated / not a URL leak

| File | Detail |
|---|---|
| `services/hrms/payroll.ts:1200-1201` | `downloadMonthlyPayslip` explicitly `query.delete('token')`, comment "sent as Authorization: Bearer instead." **The one genuinely fixed call site.** |
| `services/agentic/excel.ts:77` (upload) | FormData/body case — `token` goes in the multipart body, not the URL |
| `services/account/index.ts:248` (updatePhoto) | FormData/body case |
| `services/task/index.ts:455, 990` | FormData/body cases — redundant but not URL-visible |
| `services/talent/recruitment.ts:23-25` | Defines its own `Authorization: Bearer` helper, no query param |

### proxy.ts vs backend

Confirmed per pre-confirmed recon: `proxy.ts:15` only checks cookie presence. Backend independently enforces on the routes checked above (`api.token`/`auth:sanctum`), **except excel-agent**, which has no route-level guard and relies on a controller-local, header-blind token check.
