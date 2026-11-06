## 2026-11-06T07:17:06Z
You are teamwork_explorer assigned to Milestone 1: Seeder & Lifecycle Runner Strategy for hp_erp.

Working directory: .agents/teamwork/explorer_m1_1/
Workspace root: c:\Users\MILAN\Downloads\hp_erp
Original request file: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Scope document: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md

You MUST read c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md and c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md before starting work.
Also inspect prior survey reports in .agents/teamwork/explorer_survey_1_rep/handoff.md, .agents/teamwork/explorer_survey_2/handoff.md, and .agents/teamwork/explorer_survey_3/handoff.md.

Your mission:
1. Design the exact code architecture for `database/seeders/Tenant6SignalOutreachLifecycleSeeder.php` to implement features F1-F7:
   - Ingest 3 LinkedIn signals into `crm_signal_inbox` with classification 'buyer_inquiry', scores 95, 85, 98, full metadata and raw_payload in tags, `sub_institute_id = 6`.
   - Idempotently resolve organizations in `g2g_org_accounts`, `crm_organizations`, and `gtm_accounts`.
   - Create 3 key personas per org in `crm_contacts` and `gtm_contacts` (Author, Head of HR, CFO/Finance Head) with hierarchical `reports_to_id`.
   - Create `crm_leads` bound to `product_master_id = 1` and `org_account_id` with verified lead score = 40.
   - Generate staged outreach drafts in `gtm_outreach_messages` with `status = 'pending_approval'`, tailored to post context, zero template leaks.
2. Ensure strict idempotency so re-executing does not duplicate records in any of the tables.
3. Formulate your findings and recommended implementation strategy in .agents/teamwork/explorer_m1_1/handoff.md. Update progress.md as you work.
4. When complete, send a message to the orchestrator (dbac4f0e-56d8-4ce7-80fd-20503080d664) pointing to your handoff.md.
