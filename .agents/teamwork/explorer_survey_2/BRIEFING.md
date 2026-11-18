# BRIEFING — 2026-11-06T07:07:00Z

## Mission
Survey Phase: Account, Contact, and Lead Resolution for hp_erp (Tenant 6, ScholarClone). Investigate schema, Eloquent models, idempotency matching, dual table mapping (g2g_org_accounts & crm_organizations), persona creation in crm_contacts, and lead creation/scoring in crm_leads.

## 🔒 My Identity
- Archetype: explorer
- Roles: investigation, schema analysis, documentation
- Working directory: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_survey_2\
- Original parent: 8d265f3e-4940-4f48-8635-ac8c37bbdbd2
- Milestone: CRM Database Schema Investigation
- Current milestone: Account, Contact, and Lead Resolution Survey for Tenant 6

## 🔒 Key Constraints
- Read-only investigation — do NOT implement or modify any code
- Strictly write only to c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\explorer_survey_2\
- Self-contained 5-component handoff report in handoff.md
- All database checks must use App\Support\ColumnExists::has() (never Schema::hasColumn() directly)
- Models with $guarded must use \App\Models\Concerns\SkipsGuardableColumnCheck

## Current Parent
- Conversation ID: dbac4f0e-56d8-4ce7-80fd-20503080d664
- Updated: 2026-11-06T07:07:00Z

## Investigation State
- **Explored paths**:
  - `database/migrations/2026_12_09_090000_create_g2g_org_accounts_tables.php`
  - `database/migrations/2026_11_20_090100_create_crm_organizations_table.php`
  - `database/migrations/2026_11_20_090200_create_crm_contacts_table.php`
  - `database/migrations/2026_11_20_090350_create_crm_leads_table_if_missing.php`
  - `database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php`
  - `database/migrations/2026_12_07_090000_create_g2g_product_master_table.php`
  - `app/Domain/OrgAccounts/OrgAccount.php`
  - `app/Domain/OrgAccounts/OrgEnsure.php`
  - `app/Domain/OrgAccounts/OrgNormalizer.php`
  - `app/Domain/OrgAccounts/OrgSources.php`
  - `app/Domain/OrgAccounts/OrgBackfill.php`
  - `app/Domain/Crm/Leads/LeadFactory.php`
  - `app/Domain/Crm/Leads/LeadScorer.php`
  - `app/Domain/Crm/Leads/LeadNumber.php`
  - `app/Domain/Crm/Leads/LeadLinks.php`
  - `app/Http/Controllers/Api/Crm/CrmOrganizationController.php`
  - `app/Http/Controllers/Api/Crm/CrmContactController.php`
  - `app/Http/Controllers/Api/Crm/CrmLeadController.php`
  - Live MySQL database `hp_erp`
- **Key findings**:
  - `g2g_org_accounts` uses Eloquent model `App\Domain\OrgAccounts\OrgAccount` with `SoftDeletes` and `SkipsGuardableColumnCheck`. Holds loose pointer `crm_organization_id` with `UNIQUE KEY g2g_oa_tenant_crm (sub_institute_id, crm_organization_id)`.
  - `crm_organizations`, `crm_contacts`, and `crm_leads` do not use dedicated Eloquent models; they are accessed directly via Query Builder and controller/domain services.
  - Idempotency resolution for organizations uses `OrgNormalizer::name()`, `OrgNormalizer::domain()`, and `OrgEnsure::forCrmOrganization()`.
  - Persona creation in `crm_contacts` extracts Author, Head of HR, and CFO/Finance Head with `reports_to_id` hierarchical linking to the Author.
  - `crm_leads` creation uses `LeadFactory::create()`, which claims race-free `lead_no` via `LeadNumber::claim()` (format `LD-00042`) and computes lead score using `LeadScorer::score()`. Tenant 6 active rules assign 25 pts for `industry=Education` and 15 pts for `rating=Hot`, totaling 40 pts.
- **Unexplored areas**: None.

## Key Decisions Made
- All findings validated directly against live MariaDB/MySQL database reflection and codebase implementations.
- Handoff report structured strictly under the 5-component Teamwork Handoff Protocol.

## Artifact Index
- DISPATCH.md — Received dispatch messages
- BRIEFING.md — Persistent context & identity
- progress.md — Liveness heartbeat
- handoff.md — Comprehensive Survey Report for Account, Contact, and Lead Resolution
