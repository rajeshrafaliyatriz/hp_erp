## 2026-11-06T07:28:26Z
You are teamwork_worker assigned to Milestone 1: Tenant 6 Signal-to-Outreach Lifecycle Implementation and Empirical Verification.

Working directory: .agents/teamwork/worker_m1_1/
Workspace root: c:\Users\MILAN\Downloads\hp_erp
Original request file: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Scope document: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md

You MUST read c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md and c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md before starting work.
Also inspect the Explorer handoff reports and proposed implementations:
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_m1_1\handoff.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_m1_1\proposed_Tenant6SignalOutreachLifecycleSeeder.php
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_m1_2\handoff.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_m1_2\proposed_verify_tenant6_lifecycle.php
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_m1_3\handoff.md

FILE WRITE OWNERSHIP:
You have exclusive write ownership of:
1. `database/seeders/Tenant6SignalOutreachLifecycleSeeder.php`
2. `scripts/verify_tenant6_lifecycle.php`
Do NOT modify other codebase files without explicit need.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A teamwork_auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Your tasks:
1. Deploy and finalize `database/seeders/Tenant6SignalOutreachLifecycleSeeder.php`:
   - Ingest all 3 specified LinkedIn signals into `crm_signal_inbox` with classification 'buyer_inquiry', scores 95, 85, 98, full metadata and raw_payload in tags, `sub_institute_id = 6`, `assigned_to = 28`.
   - Idempotently resolve organizations in `g2g_org_accounts`, `crm_organizations`, and `gtm_accounts`.
   - Create 3 key personas per org in `crm_contacts` and `gtm_contacts` (Author, Head of HR, CFO/Finance Head) with hierarchical `reports_to_id`, `sub_institute_id = 6`, `assigned_to = 28`, `consent_status = 'consented'`.
   - Create associated `crm_leads` with `product_master_id = 1`, `org_account_id`, `industry = 'Education'`, `rating = 'Hot'` yielding verified lead score = 40 via `LeadScorer`.
   - Generate tailored outreach drafts in `gtm_outreach_messages` staged under `status = 'pending_approval'` (zero unauthorized dispatches). Use "Warm regards" rather than "Best regards" to prevent tripping the superlative claim marker. Ensure zero template placeholders (e.g. no {{...}}), zero banned phrases, length compliant.
   - Enforce MariaDB 10.1 safety: `App\Support\ColumnExists::has()` exclusively, zero `Schema::hasColumn()`, `SkipsGuardableColumnCheck`.
   - Ensure strict idempotency on re-execution.
2. Deploy and finalize `scripts/verify_tenant6_lifecycle.php`:
   - Implement the 4 validation suites: (1) Zero schema errors across 8 tables using `ColumnExists::has()`, (2) Strict Tenant 6 isolation and attribution, (3) Zero template leaks & Outreach Gate compliance, (4) Strict idempotency re-run.
   - POSIX exit code 0 on pass, exit code 1 on fail.
3. Run the seeder and verification:
   - `php artisan db:seed --class=Tenant6SignalOutreachLifecycleSeeder`
   - `php scripts/verify_tenant6_lifecycle.php`
   Confirm both execute cleanly with exit code 0.
4. Run automated test suites:
   - `php artisan test tests/Feature/OrgAccountsSchemaTest.php`
   - `php artisan test tests/Unit/MariaDb101SafetyTest.php`
5. Write a comprehensive 5-component handoff report to `.agents/teamwork/worker_m1_1/handoff.md` detailing observation, logic chain, caveats, conclusion, and exact verification commands with exit codes and outputs.
6. When complete, send a message to orchestrator (`dbac4f0e-56d8-4ce7-80fd-20503080d664`).
