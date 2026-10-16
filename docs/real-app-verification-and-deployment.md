# Signals: real-app verification and deployment checklist

Two parts. **Part A** is a repeatable procedure for a person with an *authorised development login*: nothing in it can be done without one, and nothing here creates credentials or bypasses login. **Part B** is the ordered production checklist. Nothing in this document was executed against production.

Automated tests (`php vendor/bin/phpunit`, `npm test`) prove behaviour with fixtures and an in-memory database. They are not a substitute for Part A.

---

## Part A: verify the real application (development only)

**Before you start.** Open the app on `http://localhost:3000` (never on a LAN hostname: the frontend treats every host that is not `localhost`, `127.0.0.1`, `192.168.*`, `10.0.*`, `172.16-31.*`, `*.local` or `*.test` as *production* and uses `NEXT_PUBLIC_API_BASE_URL_PROD`; see `lib/api-config.ts`). Confirm in the browser's network tab that requests go to `http://127.0.0.1:8000`.

### A0. Machine checks (no login needed)

```
cd g2gb
php artisan signals:preflight --tenant=<dev id> --origin=http://localhost:3000     # read-only: no provider is called
cd ../g2gf
node scripts/check-api-contract.mjs                                                # every frontend call exists on the backend
```
Expect: no `FAIL` lines; `scheduled research: off`; `0 missing`.

### A1. Sign in and set up two accounts

Use development accounts you are authorised to use: one **administrator** and one **non-administrator** (employee). Optionally a second organisation's administrator for isolation. Record the dev tenant id.

### A2. Company Opportunities (administrator)

| # | Do | Expect |
|---|---|---|
| 1 | Open `/signals` | Product dropdown shows ScholarClone, Enterprise Brain, G2G (no "All"). Research-date dropdown shows dates only. |
| 2 | Pick ScholarClone, pick the newest date | Real saved signals for that date; each shows its source link and a publication date or "No publication date on the source". |
| 3 | Open the network tab, change date and product several times | Only `GET` requests. **No** `POST /signals/research/run`. |
| 4 | Switch to Enterprise Brain, then G2G | No ScholarClone records. "ICP setup required" with what is missing. Generate Signals refuses without a request. |
| 5 | On ScholarClone, "Approach now" / "Watching" / status / search | Counts and lists change; empty filter shows the filter empty state. |
| 6 | Mark a signal reviewed, reload | Status persists. |
| 7 | Compare: `php artisan tinker` → count of `g2g_company_opportunities` per `business_fit` for the tenant | Matches what each product shows (unassigned records appear in none). |
| 8 | (Only if you accept the cost) press **Generate Signals** once | Button shows Generating, cannot be double-clicked, finishes with a message that matches the run row; the new date appears and is selected. |

### A3. Product Portfolio (administrator)

1. Select Enterprise Brain. The ICP form is empty (nothing copied from ScholarClone). Press **Check** with it empty: problems listed, nothing saved.
2. Fill the ICP name, ideal customer, one signal, one search. **Save**: "Saved. No research was started." Still "Needs setup". Reload: the values are still there.
3. **Save and activate**: Ready; "Go to Company Opportunities" becomes a live link for that product. Check the Enterprise Brain product in Company Opportunities now offers Generate Signals.
4. Select G2G and ScholarClone: their own forms, unchanged.
5. Edit and save the ScholarClone ICP once; confirm version +1 and a single active profile (`select id,key,status,version from g2g_icp_profiles where sub_institute_id=<id>`).

### A4. Ingestion

1. Open Ingestion: nothing is processed on load (network tab: only `GET`).
2. Choose Enterprise Brain (not configured) and add a link: refused with the missing items, no request stored (`select count(*) from g2g_ingestion_sources` unchanged).
3. Choose ScholarClone. Add a **public** URL, then a small `.txt` and `.pdf` file. Each appears in history with a status; the product is stored (`product_key`).
4. Double-click Add / Retry: one row / one analysis only.
5. With the queue worker stopped: the analysis stays "generating" and is closed as stale after 15 minutes with a "retry" message; start the worker, press Retry, it completes.
6. A file over 15 MB, or `.exe`: refused with a clear message.

Requires a queue worker (see B3) or the on-demand worker (`exec()` allowed).

### A5. Partner Network (administrator)

Create (blank form; type is required; bad email → field message; duplicate ID → clear message), edit, search, delete (confirm; a failure shows inside the dialog). Triple-click Create: one row in `g2g_partners`.

### A6. Roles and isolation

* **Employee**: the API returns 403 for `/api/signals/*` and for every write (this is covered by an automated role-matrix test). In the browser, write down what `/signals` and `/ingestion` show: a clear message is the expected behaviour; an empty or endlessly loading screen is a defect to report (it has not been checked with a real login). Portfolio and Partner Network should be readable with no Add / Edit / Delete buttons.
* **Second organisation's admin**: sees none of the first organisation's opportunities, partners, ICPs, documents or runs; a direct `PATCH /api/signals/opportunities/<id>/review` on the other tenant's id returns 404.

### A7. Failure messages

Stop the backend: screens show an error with Retry, not an empty list. Remove the search key in `.env` on dev: Generate Signals says search is not configured and starts nothing.

### A8. Record the result

For each row note PASS / FAIL, the account used and the date. A row that could not be run is **not** a pass.

---

## Part B: production deployment checklist (do not run without approval)

Every step marked **[APPROVAL]** needs your explicit go-ahead. Run `php artisan signals:preflight` afterwards (read-only) to confirm.

### B0. Prerequisites
1. **[APPROVAL]** A tested database backup (`php artisan signals:backup-tables` backs up the Signals tables; take a full dump as well).
2. Which environment and database are you on: `php artisan env` and the preflight's `environment` line. Production host is expected; confirm it is the intended one.
3. The deployed code includes: `app/Support/ColumnExists.php`, the `LegacyMariaDbSchemaGrammar` commit (`app/Database/Schema/`, registered in `AppServiceProvider`), the four migrations below, `QueueWorkerLauncher`, and `signals:preflight`.

### B1. Backend code
Deploy the backend commit. `composer install --no-dev --optimize-autoloader`, then `php artisan config:clear && php artisan route:clear` (and re-cache if you cache).

### B2. Migrations: **[APPROVAL]**
```
php artisan migrate:status                       # confirm exactly these are pending, among any others you expect
php artisan migrate --force --path=database/migrations/2026_11_29_090000_add_assessment_to_g2g_company_opportunities.php
php artisan migrate --force --path=database/migrations/2026_11_29_100000_add_date_provenance_to_g2g_research_sources.php
php artisan migrate --force --path=database/migrations/2026_11_29_110000_add_modified_date_to_g2g_research_sources.php
php artisan migrate --force --path=database/migrations/2026_12_01_090000_add_product_key_to_signals_runs_and_sources.php
```
Also required if absent (check `signals:preflight`): `2026_09_30_150000_…`, `2026_10_13_100000_create_g2g_target_accounts_table`, `2026_10_14_100000_create_g2g_icp_profiles_tables`. All are additive; none drops or rewrites data; none uses a `json` column (MariaDB 10.1 safe). If a migration fails, stop and send the message; it is safe to re-run (each checks before adding).

### B3. Environment (`.env`, never committed; do not paste keys anywhere)
| Setting | Value |
|---|---|
| `QUEUE_CONNECTION` | `database` |
| `DB_QUEUE_RETRY_AFTER` | `960` or unset (default 960; must exceed the 900 s job timeout) |
| `SIGNALS_SEARCH_DRIVER` + its key (`TAVILY_API_KEY`) | set |
| AI provider and key (`AI_PROVIDER`, `DEEPSEEK_API_KEY` / `GEMINI_API_KEY`) | set |
| `SIGNALS_SCHEDULED_RESEARCH` | **unset or `false`** (manual only) |
| CORS | the production frontend origin must be in `config/cors.php` (`signals:preflight --origin=https://<frontend>`) |

### B4. Queue worker
Generate Signals and Ingestion queue a job on queue `signals`. Either keep a standing worker (recommended):
```
php artisan queue:work --queue=signals,default --tries=1 --timeout=900
```
(under Supervisor / systemd / a Windows service), or rely on the on-demand worker the API starts (needs `exec()` enabled and the CLI PHP found automatically). Preflight reports `on-demand worker start`. Restart the worker after each deploy (`php artisan queue:restart`).

### B5. Storage and uploads
`storage/app/private` writable by the web and worker users; ingestion disk is private (preflight: `ingestion storage ... (private)`). Check the web server's own upload limit (nginx `client_max_body_size`, Apache `LimitRequestBody`) is at least 16 MB.

### B6. Frontend
Set `NEXT_PUBLIC_API_BASE_URL_PROD` to the production API and build (`npm run build`). Deploy. Do not open the dev frontend on a non-local hostname while pointed at production credentials.

### B7. Data: **[APPROVAL]**, one at a time
1. Target accounts (ScholarClone schools): `php artisan signals:load-accounts <file> --tenant=<live id>` (dry run), review the count, then `--apply`. The file comes from `signals:export-accounts` on development.
2. Enterprise Brain / G2G: an administrator creates and activates each ICP in Product Portfolio (nothing is copied).
3. Optional, after review: `php artisan signals:backfill-product` (dry run; `--apply` only if its counts are right).

### B8. First production run: **[APPROVAL]**
Only after A-style checks on the production URL with a real administrator. One **Generate Signals** for ScholarClone. This spends search and AI quota. Watch the run in Report history and `php artisan queue:failed`.

### B9. Rollback
Code: redeploy the previous commit; `php artisan queue:restart`. Data: migrations are additive and can stay. Nothing in this release deletes records.

---

## Known limits and risks
* The signed-in application has been exercised only through automated tests, a mock-API browser harness and unauthenticated checks. Part A has not been run by the person who wrote this.
* A signal that a later run updates with new evidence is listed under that later run's date.
* The frontend picks the production API purely from the browser hostname (see Part A, before you start).
* `.env` defines `NEXT_PUBLIC_SUPABASE_SERVICE_ROLE_KEY`. A `NEXT_PUBLIC_*` value is shipped to browsers if any code reads it. Nothing reads it today; rename it (drop `NEXT_PUBLIC_`) or remove it, and rotate the key if it was ever deployed.
