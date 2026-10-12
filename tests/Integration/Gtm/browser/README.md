# GTM browser end-to-end scripts (Playwright)

Dev-only. They log in with short-lived Sanctum tokens injected into the browser session (no passwords).

1. `php tests/Integration/Gtm/browser/mint.php > sessions.json` (from the repo root; creates `gtm-e2e` tokens and a
   temporary Executive user `verify_e2e_exec_*`) - copy `sessions.json` next to the scripts.
2. `npm i playwright-core`, point `lib.js` at a Chromium executable, start both dev servers (Laravel :8000, Next :3000).
3. `node smoke.js admin|executive|employee` loads every GTM page; `flow1..flow8` create/edit/approve real "Verify E2E ..."
   records through the UI (run in order; the dev backend is slow, so waits are long).
4. Clean up: `php tests/Integration/Gtm/http.php cleanup` and `php tests/Integration/Gtm/browser/mint.php cleanup`.

The flows write to the real dev database (tenant 6). Never run them against production.
