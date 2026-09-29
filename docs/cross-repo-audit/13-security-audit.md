# 13. Security Audit

## 13.1 Token-in-URL Exposure

*Spot check only — full grep assumed done by companion agent.*

Checked 3 payroll-related files as requested. Two confirmed instances of the pattern (GET route + `token` read via `$request->input()`, which pulls from query string on GET):

| Route | Controller:line | Issue |
|---|---|---|
| `Route::get('/payroll-type', ...)` → `PayrollController::payrollType` | `routes/hrms.php:70` | `$token = $request->input('token');` at `app/Http/Controllers/Payroll/PayrollController.php:105`, used for `?type=API&token=...` auth |
| `Route::get('/payroll-type-report', ...)` → `PayrollController::payrollTypeReport` | `routes/hrms.php:76` | Identical `$token = $request->input('token')` on a GET endpoint at `PayrollController.php:2640` |

A GET request puts the bearer-equivalent token in the URL, browser history, and proxy/access logs.

**Contrast**: `PayrollController.php:3390` already fixes this pattern for internal sub-requests by preferring `$request->bearerToken() ?: $request->input('token')` — the two GET endpoints above were not brought in line with that fix.

**Additional instance**: `app/Http/Controllers/Api/jobrolecontroller.php:40-43` (`Route::get('/jobrole/{id}/skills')`) has the same GET+query-token pattern.

**Recommendation**: Move these to `Authorization: Bearer` header only, or at minimum switch the routes to POST.

## 13.2 CORS Configuration vs. Actual Origins

`config/cors.php:18-32`:

```php
'allowed_origins' => ['*'],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

Wildcard origin combined with `supports_credentials: true` is a real misconfiguration, not a no-op: Laravel's `HandleCors` middleware, when `allowed_origins` contains `*` and credentials are enabled, reflects the requesting `Origin` header back verbatim (since a literal `Access-Control-Allow-Origin: *` is invalid with credentialed requests).

**Net effect**: any origin can make credentialed (cookie/session) cross-origin requests to this API — not just the two legitimate frontends.

**Actual g2gv0 origins**, per `hp_erp/.env.example:27-34`:
- Prod: `https://g2g.scholarclone.com`
- Local: `http://localhost:3000`

Neither `config/cors.php` nor any `.env.example` restricts `allowed_origins` to these — no allowlist exists at all. `g2gv0/.env.local.example` defines no origin/API-base vars (it only configures a standalone MCP server's DB connection), so the actual dev/prod API base URLs (`NEXT_PUBLIC_API_BASE_URL_DEV`/`NEXT_PUBLIC_API_BASE_URL_PROD`, referenced in `g2gv0/lib/api-config.ts:24-25`) aren't pinned anywhere either — can't cross-check them against a CORS allowlist that doesn't exist.

**Fix**: Set `allowed_origins` to `['https://g2g.scholarclone.com', 'http://localhost:3000']` explicitly.

## 13.3 IDOR Check — 5 `{id}` Endpoints

| Endpoint | Controller:line | Tenant check |
|---|---|---|
| `GET talent/applications/{id}/assessment` | `TalentAssessmentController.php:642-657` | `.where('a.sub_institute_id', $sid)` from `talentContext()` — OK |
| `GET/PUT/DELETE competency/library/skills/{id}` | `LibraryController.php:782-808,1206` | `baseQuery($resource, $context['sub_institute_id'])` — OK |
| `GET/PUT/DELETE competency/library/jobroles/{id}` | `LibraryController.php:1233` (same `showResource`) | Same tenant-scoped query — OK |
| `POST talent-offers/{id}/accept` | `TalentOfferController.php:498-509` | `TalentOffer::where('id',$id)->where('sub_institute_id',$tenantId)` — OK |
| `PUT talent/resume-screenings/{id}` | `ResumeScreeningController.php:145-158` | `->where('sub_institute_id', $tenant)->where('id', $id)` — OK |

**Verdict**: No IDOR found in this 5-endpoint sample — all resolve tenant from an authenticated context object/token first, then filter the query by it before touching the row. Not exhaustive; other `{id}` routes weren't sampled.

## 13.4 Committed Secrets in `.env.example` Files

- **`hp_erp/.env.example`** — all secret-shaped vars (`APP_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, `MAIL_PASSWORD`, `DB_PASSWORD`) are blank or placeholder (`null`). No real value committed.
- **`g2gv0/.env.local.example`** — `DB_PASSWORD`, `DB_USER`, `DB_NAME` are placeholder text (e.g. `your_database_password`). No real value committed.
- No other `.env.example`/`.env.local.example` found elsewhere in either repo tree (only a vendored `neo4j-php-client` example, irrelevant).

**Verdict**: No committed real secrets found in these files.
