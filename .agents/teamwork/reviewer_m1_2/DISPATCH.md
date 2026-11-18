## 2026-11-06T07:36:05Z
You are teamwork_reviewer assigned to Milestone 1: Requirements, Isolation & Outreach Gate Compliance Review for hp_erp.

Working directory: .agents/teamwork/reviewer_m1_2/
Workspace root: c:\Users\MILAN\Downloads\hp_erp
Original request file: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md
Scope document: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md
Worker handoff file: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\worker_m1_1\handoff.md

You MUST read c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md and c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md before starting work.
Inspect `database/seeders/Tenant6SignalOutreachLifecycleSeeder.php` and `scripts/verify_tenant6_lifecycle.php`.

Your review focus:
1. Examine requirements coverage, Tenant 6 isolation, and Outreach Gate compliance:
   - Check that all records across `crm_signal_inbox`, `g2g_org_accounts`, `crm_organizations`, `crm_contacts`, `crm_leads`, `gtm_accounts`, `gtm_contacts`, `gtm_outreach_messages` strictly belong to Tenant 6 (`sub_institute_id = 6`) and Admin User 28. Zero cross-tenant leakage.
   - Check `gtm_outreach_messages`: verify that messages are staged under `status = 'pending_approval'` (zero unauthorized dispatches).
   - Check Outreach Gate rules: verify zero unrendered template variables (e.g. no {{...}}, ${...}, [...]), zero banned spam phrases, zero unapproved claims/superlatives/percentages, subjects <= 150 chars, bodies <= 1500 chars, links <= 1.
   - Verify lead scoring formula: confirm `industry = 'Education'` and `rating = 'Hot'` yield a score of 40 via `LeadScorer`.
2. Run build and verification commands:
   - `php scripts/verify_tenant6_lifecycle.php`
3. Deliver a verdict (APPROVE or REQUEST_CHANGES) in .agents/teamwork/reviewer_m1_2/handoff.md following the Handoff Protocol.
4. When complete, send a message to orchestrator (`dbac4f0e-56d8-4ce7-80fd-20503080d664`).
