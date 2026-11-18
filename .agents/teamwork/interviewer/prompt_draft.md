Execute the end-to-end Signal-to-Outreach Lifecycle for Tenant 6 (ScholarClone / HRMS Suite) on the 3 specified LinkedIn signals in hp_erp for production execution on Tenant 6.

Working directory: c:\Users\MILAN\Downloads\hp_erp
Integrity mode: development

## Requirements

### R1. Signal Ingestion and Intent Classification
Extract raw text, author metadata, company affiliation, and engagement intent from the 3 specified LinkedIn post URLs:
1. https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/
2. https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/
3. https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/
Attempt direct live fetching from the URLs, falling back to public web search or extracted snippets if blocked by LinkedIn login or rate limits. Classify each signal into 'buyer_inquiry', 'vendor_promotion', or 'industry_discussion' with an intent score (0-100), and ingest into `crm_signal_inbox` for Tenant 6 with full metadata, source URL, and raw payload.

### R2. Account and Contact Resolution
Resolve target organizations into `g2g_org_accounts` and `crm_organizations` using idempotent exact-match logic. Extract and create verified key personas in `crm_contacts` (e.g., Author, Head of HR, CFO/Finance Head) linked to the organization. Create associated `crm_leads` with `product_master_id` bound to the appropriate HRMS SKU (Product Master ID 1) and calculate the lead score.

### R3. Playbook Mapping and Outreach Generation
Generate tailored, pain-point-specific outreach drafts in `gtm_outreach_messages` referencing the specific context of each post. Stage messages under `status = 'pending_approval'` (gated for review; zero unauthorized dispatches).

### R4. Database Safety and Compatibility Constraints
Target Tenant ID is 6 (ScholarClone, Admin User ID 28, Product Master ID 1) on active Git branch `Milan-2` of `hp_erp`. All database checks must use `App\Support\ColumnExists::has()` to guarantee MariaDB 10.1 compatibility; never call `Schema::hasColumn()` directly. Eloquent models using `$guarded` must use `\App\Models\Concerns\SkipsGuardableColumnCheck`.

### R5. Empirical Verification
Provide and run an independent verification script proving:
a) Zero schema errors.
b) All generated records are strictly isolated to Tenant 6.
c) Zero template leaks (e.g., no unrendered `{{variable}}`).
d) Idempotency (re-running does not duplicate accounts or signals).

## Acceptance Criteria

### Ingestion & Intent Classification
- [ ] Raw text, author metadata, company affiliation, and engagement intent are extracted for all 3 LinkedIn signals (using live fetch or search/snippet fallback).
- [ ] Each signal is classified into 'buyer_inquiry', 'vendor_promotion', or 'industry_discussion' with an intent score between 0 and 100.
- [ ] Ingested records in `crm_signal_inbox` contain full metadata, source URL, raw payload, and Tenant 6 association (`tenant_id = 6` / `sub_institute_id`).

### Account, Contact & Lead Resolution
- [ ] Target organizations are resolved idempotently in `g2g_org_accounts` and `crm_organizations`.
- [ ] Key personas (Author, Head of HR, CFO/Finance Head) are created in `crm_contacts` and linked to their resolved organizations.
- [ ] Associated `crm_leads` are created with `product_master_id = 1` and computed lead scores.

### Outreach Generation & Staging
- [ ] Tailored outreach drafts referencing post context are generated in `gtm_outreach_messages`.
- [ ] All generated outreach messages are staged with `status = 'pending_approval'` (zero unauthorized dispatches).

### Database Compatibility & Safety
- [ ] All database column existence checks use `App\Support\ColumnExists::has()` with zero occurrences of `Schema::hasColumn()`.
- [ ] Models with `$guarded` use `\App\Models\Concerns\SkipsGuardableColumnCheck`.

### Empirical Verification
- [ ] The independent verification script runs without database or schema errors.
- [ ] Verification confirms all records across `crm_signal_inbox`, `g2g_org_accounts`, `crm_organizations`, `crm_contacts`, `crm_leads`, and `gtm_outreach_messages` belong exclusively to Tenant 6.
- [ ] Staged outreach messages contain zero unrendered template variables or raw placeholders.
- [ ] Re-executing the lifecycle process does not create duplicate accounts, contacts, leads, or signals.