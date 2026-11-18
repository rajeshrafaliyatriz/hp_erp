## 2026-11-06T07:17:17Z
You are teamwork_explorer assigned to Milestone 1: Verification Script & Assertions Strategy for hp_erp.

Working directory: .agents/teamwork/explorer_m1_2/
Workspace root: c:\Users\MILAN\Downloads\hp_erp
Original request file: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Scope document: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md

You MUST read c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md and c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md before starting work.
Also inspect prior survey reports in .agents/teamwork/explorer_survey_3/handoff.md.

Your mission:
1. Design the standalone empirical verification script `scripts/verify_tenant6_lifecycle.php` that implements feature F9:
   - Zero schema errors: uses `App\Support\ColumnExists::has()` to check all columns across `crm_signal_inbox`, `g2g_org_accounts`, `crm_organizations`, `crm_contacts`, `crm_leads`, `gtm_accounts`, `gtm_contacts`, `gtm_outreach_messages`.
   - Strict Tenant 6 isolation: verifies all generated records have `sub_institute_id = 6`, `created_by = 28`, and zero records leaked to other tenants.
   - Zero template leaks: scans all outreach messages against `PlaceholderCheck` and regex patterns (`\{\{`, `\{%`, `\$\{`, `\{name\}`, etc.), banned phrases, unapproved claims, and length constraints.
   - Strict Idempotency: programmatically verifies that re-running the lifecycle process does not create duplicate accounts, contacts, leads, or signals.
2. Outline the exact CLI execution command and pass/fail semantics (exit 0 on success, exit 1 on any failure).
3. Formulate your findings and recommended implementation strategy in .agents/teamwork/explorer_m1_2/handoff.md. Update progress.md as you work.
4. When complete, send a message to the orchestrator (dbac4f0e-56d8-4ce7-80fd-20503080d664) pointing to your handoff.md.
