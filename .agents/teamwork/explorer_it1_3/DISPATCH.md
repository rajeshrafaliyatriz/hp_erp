## 2026-11-05T14:24:16Z
Your working directory is c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_it1_3\
Read c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\ORIGINAL_REQUEST.md and c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\orchestrator\PROJECT.md before starting work.
Refer also to:
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_survey_2\handoff.md
- c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_survey_3\handoff.md
- c:\Users\MILAN\Downloads\hp_erp\database\seeders\Tenant6CrmLifecycleSeeder.php
- c:\Users\MILAN\Downloads\hp_erp\database\fixtures\gujarat_scholarclone_signals.json

Your task:
1. Develop exact implementation code strategy for `Tenant6CrmLifecycleSeeder.php` covering:
   - Contacts: ensure multiple realistic contacts per school (4 personas: Principal, Trustee, IT Head, Finance Head) with title, department, email, phone, mobile, mailing address, consent status in `crm_contacts`.
   - Opportunities: distribute deals across all 8 stages (Prospecting, Qualification, Needs Analysis, Value Proposition, Identify Decision Makers, Proposal/Quotation, Negotiation/Review, Closed Won) with amount, closing date, probability, next step, description, forecast category, `product_master_id = 1`.
   - Quotes: multi-item CPQ quotes with subtotal, discounts, 18% GST tax, shipping, terms & conditions.
   - Sales Orders & Invoices: zero-variance conversion for Closed Won deals with line items and `amount_paid` recorded.
   - Market Signals: ingest authentic Gujarat educational signals from `database/fixtures/gujarat_scholarclone_signals.json` into `crm_signal_inbox` linked to tenant 6 and matching school accounts.
2. Provide precise code snippets and recommendations for Worker. DO NOT edit any source code yourself (read-only).
3. Write your report to `c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_it1_3\handoff.md` and send a completion message with the path when finished.
