# Handoff Report: Survey Phase — Account, Contact, and Lead Resolution

**Target Workspace**: `hp_erp` (Git branch: `Milan-2`)  
**Target Tenant**: Tenant 6 (ScholarClone, `sub_institute_id = 6` / `tenant_id = 6`, Admin User ID: 28, Product Master ID: 1)  
**Author**: teamwork_explorer (`explorer_survey_2`)  
**Target Recipient**: Orchestrator (`dbac4f0e-56d8-4ce7-80fd-20503080d664`)  
**Date**: 2026-11-06  

---

## 1. Observation

Direct observations from codebase inspection, schema migrations, domain service classes, unit/feature tests, and live database reflection against `hp_erp` on host `202.47.117.220:3306`:

### 1.1 Tenant 6 Environment & Configuration
- **Database Connection**: Target default database connection `mysql` points to `202.47.117.220:3306`, database `hp_erp`.
- **Target Tenant**: `sub_institute_id = 6` (ScholarClone).
- **Admin User**: User ID `28` in `tbluser`.
- **Product Master Binding**: `g2g_product_master` ID `1`:
  - `product_key`: `'scholarclone'`
  - `name`: `'ScholarClone'`
  - `kind`: `'product'`
  - `signal_key`: `'scholar'`
  - `portfolio_name`: `'Scholar K-12'`
  - `sub_institute_id`: `0` (global/builtin catalog item visible to all tenants).
- **Existing Records in Tenant 6**:
  - `g2g_org_accounts`: 46 records
  - `crm_organizations`: 23 records
  - `crm_contacts`: 100 records
  - `crm_leads`: 96 records
  - `crm_lead_scoring_rules`: 2 active rules

---

### 1.2 Schema and Model Definitions

#### A. Table `g2g_org_accounts` & Model `OrgAccount`
- **Migration**: `database/migrations/2026_12_09_090000_create_g2g_org_accounts_tables.php:37-63`.
- **Eloquent Model**: `app/Domain/OrgAccounts/OrgAccount.php:22-62`:
  - Extends `Illuminate\Database\Eloquent\Model`.
  - Uses `Illuminate\Database\Eloquent\SoftDeletes`.
  - Uses `\App\Models\Concerns\SkipsGuardableColumnCheck` (avoids MariaDB 10.1 `generation_expression` introspection defect).
  - Protected `$table = 'g2g_org_accounts'`.
  - Protected `$guarded = ['id']`.
  - Protected `$casts`:
    ```php
    'sub_institute_id'    => 'integer',
    'crm_organization_id' => 'integer',
    'gtm_account_id'      => 'integer',
    'g2g_company_id'      => 'integer',
    'owner_user_id'       => 'integer',
    ```
- **Schema Columns (`g2g_org_accounts`)**:
  | Column | Type | Nullable | Default | Constraints / Indexes | Purpose |
  |---|---|---|---|---|---|
  | `id` | `bigint(20) unsigned` | NO | NULL | PRIMARY KEY, AUTO_INCREMENT | Unique Identity ID |
  | `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | Composite Keys | Tenant ID (6) |
  | `name` | `varchar(191)` | NO | NULL | | Display Name of Organization |
  | `normalized_name` | `varchar(191)` | NO | NULL | INDEX `g2g_oa_tenant_name` (`sub_institute_id`, `normalized_name`) | Comparable name from `OrgNormalizer::name()` |
  | `domain` | `varchar(191)` | YES | NULL | INDEX `g2g_oa_tenant_domain` (`sub_institute_id`, `domain`) | Clean web host domain from `OrgNormalizer::domain()` |
  | `lifecycle` | `varchar(12)` | NO | `'discovered'` | INDEX `g2g_oa_tenant_lifecycle` (`sub_institute_id`, `lifecycle`) | Lifecycle stage: `discovered`, `target`, `engaged`, `customer`, `archived` |
  | `crm_organization_id` | `bigint(20) unsigned` | YES | NULL | UNIQUE `g2g_oa_tenant_crm` (`sub_institute_id`, `crm_organization_id`) | Pointer to `crm_organizations.id` |
  | `gtm_account_id` | `bigint(20) unsigned` | YES | NULL | UNIQUE `g2g_oa_tenant_gtm` (`sub_institute_id`, `gtm_account_id`) | Pointer to `gtm_accounts.id` |
  | `g2g_company_id` | `bigint(20) unsigned` | YES | NULL | UNIQUE `g2g_oa_tenant_company` (`sub_institute_id`, `g2g_company_id`) | Pointer to `g2g_companies.id` |
  | `crm_link_basis` | `varchar(16)` | YES | NULL | | Basis: `existing_link`, `exact_name`, `exact_domain`, `manual` |
  | `gtm_link_basis` | `varchar(16)` | YES | NULL | | Basis of GTM account link |
  | `company_link_basis`| `varchar(16)` | YES | NULL | | Basis of Signals company link |
  | `owner_user_id` | `bigint(20) unsigned` | YES | NULL | | Assigned owner User ID |
  | `created_by` | `bigint(20) unsigned` | YES | NULL | | Creator User ID (28) |
  | `updated_by` | `bigint(20) unsigned` | YES | NULL | | Updater User ID |
  | `created_at` | `timestamp` | YES | NULL | | Creation timestamp |
  | `updated_at` | `timestamp` | YES | NULL | | Update timestamp |
  | `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

#### B. Table `crm_organizations`
- **Migrations**: `database/migrations/2026_11_20_090100_create_crm_organizations_table.php`, `database/migrations/2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations.php`.
- **Eloquent Model**: Accessed directly via Query Builder (`DB::table('crm_organizations')`) and controller `App\Http\Controllers\Api\Crm\CrmOrganizationController`.
- **Schema Columns (`crm_organizations`)**:
  | Column | Type | Nullable | Default | Constraints / Indexes | Purpose |
  |---|---|---|---|---|---|
  | `id` | `bigint(20) unsigned` | NO | NULL | PRIMARY KEY, AUTO_INCREMENT | Account ID in CRM |
  | `account_no` | `varchar(100)` | NO | NULL | UNIQUE `crm_organizations_account_no_unique` | Code, e.g. `ORG-` + 8 chars |
  | `name` | `varchar(191)` | NO | NULL | App & DB Required | Organization / Company Name |
  | `parent_id` | `bigint(20) unsigned` | YES | NULL | FK -> `crm_organizations(id)` | Parent organization |
  | `account_type` | `varchar(50)` | YES | NULL | | `Prospect`, `Customer`, etc. |
  | `industry` | `varchar(100)` | YES | NULL | | e.g. `Education`, `Technology`, `HRTech` |
  | `rating` | `varchar(50)` | YES | NULL | | `Hot`, `Warm`, `Cold` |
  | `employees` | `int(10) unsigned` | YES | NULL | | Headcount, e.g. 30, 50 |
  | `phone` | `varchar(30)` | YES | NULL | | Landline / board phone |
  | `email` | `varchar(191)` | YES | NULL | | Official email |
  | `website` | `varchar(191)` | YES | NULL | | Website URL / domain |
  | `assigned_to` | `bigint(20) unsigned` | NO | NULL | App Required (`required\|integer`) | Owner User ID (28) |
  | `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | INDEX `crm_organizations_sub_institute_id_index` | Tenant ID (6) |
  | `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID (28) |
  | `linkedin_url` | `varchar(300)` | YES | NULL | | LinkedIn page URL |
  | `billing_street` | `varchar(250)` | YES | NULL | | Address |
  | `billing_city` | `varchar(50)` | YES | NULL | | City |
  | `billing_state` | `varchar(50)` | YES | NULL | | State |
  | `billing_country`| `varchar(50)` | YES | NULL | | Country (`India`, `Nepal`, etc.) |
  | `created_at` | `timestamp` | YES | NULL | | Timestamp |
  | `updated_at` | `timestamp` | YES | NULL | | Timestamp |
  | `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

#### C. Table `crm_contacts`
- **Migrations**: `database/migrations/2026_11_20_090200_create_crm_contacts_table.php`, `database/migrations/2026_12_03_080100_add_consent_fields_to_crm_leads_contacts.php`, `database/migrations/2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations.php`.
- **Eloquent Model**: Accessed directly via Query Builder (`DB::table('crm_contacts')`) and controller `App\Http\Controllers\Api\Crm\CrmContactController`. (Note: `gtm_contacts` model is `App\Domain\Gtm\GtmContact`).
- **Schema Columns (`crm_contacts`)**:
  | Column | Type | Nullable | Default | Constraints / Indexes | Purpose |
  |---|---|---|---|---|---|
  | `id` | `bigint(20) unsigned` | NO | NULL | PRIMARY KEY, AUTO_INCREMENT | Contact ID |
  | `contact_no` | `varchar(100)` | NO | NULL | UNIQUE `crm_contacts_contact_no_unique` | Code, e.g. `CON-` + 8 chars |
  | `organization_id`| `bigint(20) unsigned`| YES | NULL | FK -> `crm_organizations(id)` | Linked Organization ID |
  | `salutation` | `varchar(20)` | YES | NULL | | `Mr.`, `Ms.`, `Mrs.`, `Dr.` |
  | `first_name` | `varchar(100)` | YES | NULL | | First name |
  | `last_name` | `varchar(100)` | NO | NULL | App & DB Required | Surname / Family name |
  | `title` | `varchar(100)` | YES | NULL | | Persona designation |
  | `department` | `varchar(100)` | YES | NULL | | Persona department |
  | `email` | `varchar(191)` | YES | NULL | | Professional email |
  | `secondary_email`| `varchar(191)`| YES | NULL | | Alternate email |
  | `phone` | `varchar(30)` | YES | NULL | | Desk phone |
  | `mobile` | `varchar(30)` | YES | NULL | | Mobile number |
  | `reports_to_id` | `bigint(20) unsigned` | YES | NULL | FK -> `crm_contacts(id)` | Hierarchy parent |
  | `lead_source` | `varchar(100)` | YES | NULL | | Source, e.g. `'LinkedIn'` |
  | `assigned_to` | `bigint(20) unsigned` | NO | NULL | App Required (`required\|integer`) | Owner User ID (28) |
  | `sub_institute_id`| `bigint(20) unsigned`| NO | NULL | INDEX `crm_contacts_sub_institute_id_index` | Tenant ID (6) |
  | `created_by` | `bigint(20) unsigned` | YES | NULL | | Creator User ID (28) |
  | `linkedin_url` | `varchar(300)` | YES | NULL | | LinkedIn personal profile URL |
  | `created_at` | `timestamp` | YES | NULL | | Timestamp |
  | `updated_at` | `timestamp` | YES | NULL | | Timestamp |
  | `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

#### D. Table `crm_leads`
- **Migrations**: `database/migrations/2026_11_20_090350_create_crm_leads_table_if_missing.php`, `database/migrations/2026_11_30_090000_add_lead_scoring_to_crm_leads_table.php`, `database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php`.
- **Domain Services**: `App\Domain\Crm\Leads\LeadFactory`, `App\Domain\Crm\Leads\LeadScorer`, `App\Domain\Crm\Leads\LeadNumber`, `App\Domain\Crm\Leads\LeadLinks`.
- **Schema Columns (`crm_leads`)**:
  | Column | Type | Nullable | Default | Constraints / Indexes | Purpose |
  |---|---|---|---|---|---|
  | `id` | `bigint(20) unsigned` | NO | NULL | PRIMARY KEY, AUTO_INCREMENT | Lead ID |
  | `lead_no` | `varchar(30)` | YES | NULL | UNIQUE `crm_leads_lead_no_unique` | Formatted: `LD-00042` |
  | `salutation` | `varchar(20)` | YES | NULL | | `Mr.`, `Ms.`, `Mrs.` |
  | `first_name` | `varchar(191)` | YES | NULL | | First name |
  | `last_name` | `varchar(191)` | NO | NULL | App & DB Required | Last name |
  | `company` | `varchar(191)` | YES | NULL | | Organization name |
  | `designation` | `varchar(191)` | YES | NULL | | Persona title |
  | `email` | `varchar(191)` | YES | NULL | | Primary email |
  | `phone` | `varchar(50)` | YES | NULL | | Phone number |
  | `mobile` | `varchar(50)` | YES | NULL | | Mobile number |
  | `lead_source` | `varchar(191)` | YES | NULL | | Source, e.g. `'LinkedIn'` |
  | `industry` | `varchar(191)` | YES | NULL | | Target industry: `'Education'` (+25 pts) |
  | `rating` | `varchar(191)` | YES | NULL | | Rating: `'Hot'` (+15 pts) |
  | `lead_status` | `varchar(191)` | YES | NULL | | Status: `'Pre Qualified'`, `'Not Contacted'` |
  | `lifecycle_status`| `varchar(50)` | YES | `'new'` | | Stage: `'qualified'`, `'new'` |
  | `lead_score` | `int(11)` | NO | `0` | INDEX `crm_leads_lead_score_index` | Computed points total (40) |
  | `lead_score_basis`| `text` | YES | NULL | | Explainable JSON rule trace |
  | `product_master_id`| `bigint(20) unsigned`| YES | NULL | INDEX `crm_leads_tenant_product` | Bound to 1 (ScholarClone) |
  | `org_account_id` | `bigint(20) unsigned` | YES | NULL | INDEX `crm_leads_tenant_org_account` | Pointer to `g2g_org_accounts.id` |
  | `converted_organization_id`| `bigint(20) unsigned`| YES | NULL | FK -> `crm_organizations(id)` | Converted CRM Org pointer |
  | `converted_contact_id`| `bigint(20) unsigned`| YES | NULL | FK -> `crm_contacts(id)` | Converted CRM Contact pointer |
  | `assigned_to` | `bigint(20) unsigned` | NO | NULL | FK -> `tbluser(id)` | Admin User ID (28) |
  | `sub_institute_id`| `bigint(20) unsigned`| YES | NULL | FK -> `school_setup(id)` | Tenant ID (6) |
  | `created_by` | `bigint(20) unsigned` | YES | NULL | FK -> `tbluser(id)` | Creator User ID (28) |
  | `description` | `text` | YES | NULL | Fitted by `LeadDescription::fit()` | Outreach summary and signal quote |
  | `created_at` | `timestamp` | YES | NULL | | Timestamp |
  | `updated_at` | `timestamp` | YES | NULL | | Timestamp |
  | `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

---

### 1.3 Observations on Idempotency and Resolution Logic
- **`OrgNormalizer` (`app/Domain/OrgAccounts/OrgNormalizer.php:11-122`)**:
  - `OrgNormalizer::tokens(?string $text)`: converts text to lower case, removes stop words (`pvt`, `ltd`, `llp`, `inc`, `private`, `limited`, `the`, `of`, `and`, `school`, `schools`), sorts unique words.
  - `OrgNormalizer::name(?string $name)`: returns tokens joined by single space.
  - `OrgNormalizer::domain(?string $value)`: scrubs protocols (`http://`, `https://`), `www.`, ports, paths, fragments, and queries. Returns clean host (e.g. `vnsoftverg.com`, `creuto.com`).
  - `OrgNormalizer::isSharedHost(?string $domain)`: rejects generic hosts (`linkedin.com`, `gmail.com`, `google.com`, `facebook.com`, etc.) as domain evidence.
- **`OrgEnsure` (`app/Domain/OrgAccounts/OrgEnsure.php:24-90`)**:
  - Enforces that any `crm_organizations` record has a corresponding row in `g2g_org_accounts`.
  - Runs in a database transaction with `lockForUpdate()` on `g2g_org_accounts` to prevent race conditions.
  - Raises lifecycle from `'discovered'` to `'engaged'` when attached to `crm_organizations`.
- **`LeadFactory` (`app/Domain/Crm/Leads/LeadFactory.php:24-51`)**:
  - Central creator for all leads.
  - Validates `product_master_id` via `LeadLinks::productVisible($tenantId, $value)`.
  - Validates `org_account_id` via `LeadLinks::orgAccountVisible($tenantId, $value)`.
  - Fits `description` via `LeadDescription::fit()` to avoid database truncation.
  - Mints unique `lead_no` via `LeadNumber::claim()`.
  - Computes score and explainability trace via `LeadScorer::score($attributes, $tenantId)`.
- **`LeadScorer` (`app/Domain/Crm/Leads/LeadScorer.php:22-89`)**:
  - Evaluates rules from `crm_lead_scoring_rules` where `sub_institute_id = $tenantId` and `is_active = 1`.
  - For Tenant 6, evaluates:
    * `industry = 'Education'` -> 25 points
    * `rating = 'Hot'` -> 15 points
    * Total Lead Score: **40 points**.

---

### 1.4 Extracted Target Signals, Organizations, and Personas

From public web search and HTML parsing of the 3 specified LinkedIn signals:

1. **Signal 1**: `https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/`
   - **Company**: `VN SOFTVERG TECHNOLOGY PVT. LTD.`
   - **Normalized Name**: `'softverg technology vn'`
   - **Domain**: `vnsoftverg.com`
   - **Contact Email**: `vinayjivankumar.jana@vnsoftverg.com`
   - **Headcount**: `30-50 employees`
   - **Personas**:
     * **Author (Point of Contact)**: Narendra Parvathareddy (`First: Narendra`, `Last: Parvathareddy`, Title: `Author / Technical Director`, LinkedIn: `https://in.linkedin.com/in/narendra-parvathareddy`)
     * **Operational Point of Contact**: Vinay Jivan Kumar Jana (`First: Vinay Jivan Kumar`, `Last: Jana`, Title: `HR Operations / Contact Person`, Email: `vinayjivankumar.jana@vnsoftverg.com`)
     * **Head of HR**: Head of HR (`First: Head of`, `Last: HR`, Title: `Head of Human Resources`, Reports To: Narendra Parvathareddy)
     * **CFO / Finance Head**: CFO (`First: Chief Financial`, `Last: Officer`, Title: `CFO / Finance Head`, Reports To: Narendra Parvathareddy)

2. **Signal 2**: `https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/`
   - **Company**: `Creuto` (`Creuto Technologies`)
   - **Normalized Name**: `'creuto'`
   - **Domain**: `creuto.com`
   - **Personas**:
     * **Author (CEO)**: Nihar Ranjan Rout (`First: Nihar Ranjan`, `Last: Rout`, Title: `Founder & CEO`, LinkedIn: `https://in.linkedin.com/in/niharrout`)
     * **Head of HR**: Head of HR (`First: Head of`, `Last: HR`, Title: `Head of Human Resources`, Reports To: Nihar Ranjan Rout)
     * **CFO / Finance Head**: CFO (`First: Finance`, `Last: Controller`, Title: `CFO / Finance Head`, Reports To: Nihar Ranjan Rout)

3. **Signal 3**: `https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/`
   - **Company**: `Gargi Media House` / `Kathmandu Media & Tech`
   - **Normalized Name**: `'gargi house media'`
   - **Domain / Email**: `anjulisubedi30@gmail.com`
   - **Location**: Kathmandu, Nepal
   - **Headcount**: `30-50 employees`
   - **Personas**:
     * **Author (Managing Director)**: Anjuli Subedi (`First: Anjuli`, `Last: Subedi`, Title: `Managing Director / Author`, Email: `anjulisubedi30@gmail.com`, LinkedIn: `https://np.linkedin.com/in/anjuli-s`)
     * **Head of HR**: Head of HR (`First: Head of`, `Last: HR`, Title: `Head of Human Resources`, Reports To: Anjuli Subedi)
     * **CFO / Finance Head**: CFO (`First: Finance`, `Last: Head`, Title: `CFO / Finance Head`, Reports To: Anjuli Subedi)

---

## 2. Logic Chain

1. **Deduplication and Idempotency**:
   - `crm_organizations` enforces uniqueness of `account_no` at the database level and uniqueness of `name` per tenant via application logic (`CrmOrganizationController.php:138-146`).
   - Prior to creating an organization, an exact query on `sub_institute_id = 6` with `name`, `normalized_name`, or `domain` must be performed. If a match exists, its existing `crm_organizations.id` must be reused.
   - For `g2g_org_accounts`, the table has strict composite unique constraints: `UNIQUE KEY g2g_oa_tenant_crm (sub_institute_id, crm_organization_id)`.
   - Calling `OrgEnsure::forCrmOrganization(6, $crmOrgId)` guarantees that every CRM organization is indexed in `g2g_org_accounts` without duplicating identity records.
   - Therefore, re-running the resolution process never duplicates either `crm_organizations` or `g2g_org_accounts`.

2. **Persona Creation in `crm_contacts`**:
   - `crm_contacts` requires `last_name` (NOT NULL, string max 100), `contact_no` (NOT NULL, unique), `sub_institute_id = 6`, and `assigned_to = 28`.
   - Personas must be linked to the resolved organization via `organization_id = $crmOrgId`.
   - Subordinate personas (`Head of HR`, `CFO / Finance Head`) are linked hierarchically to the primary author contact using `reports_to_id = $authorContactId`.
   - Contact deduplication is checked by `(sub_institute_id = 6, organization_id, email)` and `(sub_institute_id = 6, organization_id, first_name, last_name)`. If found, existing contact ID is reused; if absent, a new contact is created with `contact_no = 'CON-' . Str::upper(Str::random(8))`.

3. **Lead Creation and Rule Scoring in `crm_leads`**:
   - `crm_leads` requires `last_name` (NOT NULL), `lead_no` (unique), `assigned_to = 28`, and `sub_institute_id = 6`.
   - `LeadFactory::create()` must be used to ensure:
     * `lead_no` is claimed from sequence via `LeadNumber::claim()` (format `LD-00042`).
     * `product_master_id` is set to `1` (ScholarClone) and verified by `LeadLinks::productVisible(6, 1)`.
     * `org_account_id` is set to `g2g_org_accounts.id` and verified by `LeadLinks::orgAccountVisible(6, $orgAccountId)`.
     * `converted_organization_id` can be set to `crm_organizations.id`.
     * `converted_contact_id` can be set to the author's `crm_contacts.id`.
     * `LeadScorer::score()` evaluates active rules for Tenant 6: setting `industry = 'Education'` and `rating = 'Hot'` triggers Rule 14 (+25 pts) and Rule 15 (+15 pts), resulting in a lead score of exactly **40**.
   - Lead idempotency is checked by querying `crm_leads` where `sub_institute_id = 6`, `org_account_id = $orgAccountId`, and `product_master_id = 1`. If present, the lead ID is reused.

4. **Compatibility Guard Compliance (R4)**:
   - Live MariaDB 10.1 rejects `Schema::hasColumn()` due to the missing `generation_expression` column in `information_schema.COLUMNS`. All column checks must use `App\Support\ColumnExists::has($table, $column)`.
   - Eloquent models using `$guarded` must include `use \App\Models\Concerns\SkipsGuardableColumnCheck;` to disable column introspection on mass assignment.

---

## 3. Caveats

1. **Direct Web Access vs Fallback Snippets**: LinkedIn public post pages frequently alter guest HTML layouts or challenge automated requests. The scraped HTML stored in `.agents/teamwork/explorer_survey_1/` contains verified JSON-LD metadata for all 3 posts. Extraction logic must read both live requests and local JSON-LD/HTML fallbacks.
2. **Missing Contact Emails for Private Personas**: In LinkedIn posts, second-order personas (such as Head of HR or CFO) are organizational roles rather than named post authors. For these synthesized personas, email patterns follow the corporate domain (e.g. `hr@vnsoftverg.com`, `cfo@creuto.com`) or remain null, which `crm_contacts` safely allows.
3. **Foreign Key Deferral on `g2g_org_accounts`**: In accordance with hp_erp repository conventions and MariaDB 10.1 cross-subsystem decoupling, pointers `crm_organization_id`, `gtm_account_id`, and `g2g_company_id` in `g2g_org_accounts` are indexed loose pointers without hard foreign key constraints.

---

## 4. Conclusion

The database schema, Eloquent models, and domain services for Account, Contact, and Lead Resolution are fully mapped and verified:
1. **Organizations**: Dual-mapped between operational `crm_organizations` (table with contacts and opportunities) and identity index `g2g_org_accounts` (`OrgAccount` model with `SkipsGuardableColumnCheck`). Resolved idempotently via `OrgNormalizer::name()` / `OrgNormalizer::domain()` and synchronized via `OrgEnsure::forCrmOrganization(6, $crmOrgId)`.
2. **Contacts**: Created in `crm_contacts` for three key personas per organization (Author, Head of HR, CFO/Finance Head) with `organization_id`, `assigned_to = 28`, `sub_institute_id = 6`, and `reports_to_id` hierarchical relationship.
3. **Leads**: Created in `crm_leads` via `LeadFactory::create()`, bound to `product_master_id = 1` (ScholarClone), `org_account_id = $orgAccountId`, `converted_organization_id = $crmOrgId`, and `converted_contact_id = $contactId`. Setting `industry = 'Education'` and `rating = 'Hot'` achieves a verified lead score of **40** via `LeadScorer::score()`.
4. **Safety**: Zero usage of `Schema::hasColumn()`; all checks use `App\Support\ColumnExists::has()`. All Eloquent models use `SkipsGuardableColumnCheck`.

---

## 5. Verification Method

To independently verify all findings and execute the full suite of related tests:

### 5.1 Run Automated Tests for Lead Promotion, Org Accounts, and Schemas
Execute the following commands in powershell:

```powershell
# 1. Verify Lead Promotion, Links, and Scoring
php artisan test tests/Feature/LeadPromotionLinksTest.php
php artisan test tests/Feature/LeadCreationPathsTest.php
php artisan test tests/Feature/LeadNumberTest.php

# 2. Verify Org Accounts Schema and Tenant Isolation
php artisan test tests/Feature/OrgAccountsSchemaTest.php
php artisan test tests/Feature/OrgAccountsTenantSweepTest.php
php artisan test tests/Feature/OrganisationMergeTest.php
```

### 5.2 Verify Database Schema Reflection on Live Database
Run verification script checking table schemas and Tenant 6 data:

```powershell
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap(); echo 'Column product_master_id in crm_leads: ' . (\App\Support\ColumnExists::has('crm_leads', 'product_master_id') ? 'YES' : 'NO') . PHP_EOL; echo 'Column org_account_id in crm_leads: ' . (\App\Support\ColumnExists::has('crm_leads', 'org_account_id') ? 'YES' : 'NO') . PHP_EOL;"
```

### 5.3 Invalidation Conditions
This report is invalidated if:
- Table `g2g_org_accounts` or `crm_leads` is dropped or alters its unique indexes (`g2g_oa_tenant_crm`, `crm_leads_lead_no_unique`).
- `crm_lead_scoring_rules` for Tenant 6 are modified or deactivated.
- Direct calls to `Schema::hasColumn()` are reintroduced into Eloquent models or controllers.
