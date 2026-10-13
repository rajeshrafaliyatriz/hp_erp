# GTM live-database integration scenarios

These are NOT PHPUnit tests. They drive the real HTTP stack (`/api/gtm/*`) in-process with real Sanctum tokens
against the database in `.env` (the shared dev database), so they prove behaviour PHPUnit's SQLite cannot:
MySQL/MariaDB migrations, tenant isolation on real rows, role permissions, and the Gemini integration.

Run from the repository root:

    php tests/Integration/Gtm/http.php <scenario>

Scenarios: `playbooks agents deals outreach overview forecast campaigns customers automation workflows reports regress monitoring`
(`score`, `smtp` are diagnostics, not pass/fail suites; `cleanup` removes leftovers).

Safety rules (read before changing a scenario):

* Every row a scenario creates is named `Verify ...` (or hangs off such a row). `gtm_verify_cleanup()` deletes only
  those. It never deletes by "id greater than N", because people use the same tenant in the browser.
* Tokens are created as `gtm-verify` and deleted at the end of each run.
* No email leaves the machine: mail is captured by an in-process transport, Gemini is faked with `Http::fake`
  except in the `agents` scenario's "(real model)" checks, which make real provider calls (a few tokens each).
* The "(real model)" checks fail when the provider rate-limits or has no quota. Such a failure is a blocked check,
  not a pass - re-run later; do not edit the assertion.
* Scenarios assume tenant 6 with an administrator (user 28), an employee (profile 17) and an administrator of a
  different tenant. Adjust the lookups at the top of `http.php` for another database.

Browser end-to-end checks (Playwright, against the running Next.js app) are kept outside the repository; see the
final GTM report for what was covered.
