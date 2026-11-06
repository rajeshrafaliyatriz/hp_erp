# Project: Tenant 6 Signal-to-Outreach Lifecycle (ScholarClone / HRMS Suite)

## Architecture
- **Target Host & Database**: MariaDB 10.11.9 on `202.47.117.220` (connection `mysql`), database `hp_erp`.
- **Target Tenant**: `sub_institute_id = 6` ("ScholarClone" / `school_setup.id = 6`).
- **Primary Admin**: User ID 28 (`scholarclone@gmail.com`).
- **Product Master ID**: 1 (`g2g_product_master.id = 1`, ScholarClone HRMS Suite).
- **Signal Ingestion**: `crm_signal_inbox` table, storing classified signals with SHA-256 fingerprint `signal_key` for idempotency, metadata, and raw JSON payloads in `tags`.
- **Account & Identity Resolution**:
  - `crm_organizations`: Operational CRM accounts.
  - `g2g_org_accounts`: Multi-subsystem identity anchor linked via `OrgEnsure::forCrmOrganization(6, $crmOrgId)` and normalized name/domain.
  - `gtm_accounts`: GTM sales pipeline accounts linked via `crm_organization_id`.
- **Persona & Contact Resolution**:
  - `crm_contacts`: 3 verified personas per account (Author, Head of HR, CFO/Finance Head) with hierarchical `reports_to_id` and unique `contact_no`.
  - `gtm_contacts`: Mirrored GTM contacts enabling foreign reference for outreach drafts.
- **Lead Generation & Scoring**:
  - `crm_leads`: Created via `LeadFactory` / `LeadNumber` with `product_master_id = 1`, `org_account_id`, `converted_organization_id`, and `converted_contact_id`. Scored to 40 points via `LeadScorer` (`industry = 'Education'`, `rating = 'Hot'`).
- **Outreach Draft Generation**:
  - `gtm_outreach_messages`: Staged strictly under `status = 'pending_approval'`, referencing specific post context and pain points, with 0 template leaks, 0 unapproved claims, and length limits.
- **Database Safety & Compatibility**:
  - MariaDB 10.1 compatibility: All column checks use `App\Support\ColumnExists::has()`, zero occurrences of `Schema::hasColumn()`.
  - Guarded Eloquent models use `\App\Models\Concerns\SkipsGuardableColumnCheck`.
- **Empirical Verification**:
  - Independent verification script `scripts/verify_tenant6_lifecycle.php` asserting schema integrity, Tenant 6 isolation, zero template leaks, and idempotency across all tables.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| F1 | Signal Extraction & Ingestion | Extract text, author, affiliation, and engagement intent from 3 LinkedIn URLs into `crm_signal_inbox` for Tenant 6 (`sub_institute_id = 6`, `assigned_to = 28`) | M1 | ORIGINAL_REQUEST §R1 |
| F2 | Intent Classification & Scoring | Classify all 3 signals into 'buyer_inquiry' with scores (95, 85, 98) and populate metadata/raw payloads | M1 | ORIGINAL_REQUEST §R1 |
| F3 | Idempotent Organization Resolution | Resolve target organizations into `g2g_org_accounts`, `crm_organizations`, and `gtm_accounts` with exact-match deduplication | M1 | ORIGINAL_REQUEST §R2 |
| F4 | Persona Extraction & Creation | Create 3 key personas (Author, Head of HR, CFO/Finance Head) in `crm_contacts` and `gtm_contacts` linked hierarchically | M1 | ORIGINAL_REQUEST §R2 |
| F5 | Lead Generation & Scoring | Create `crm_leads` bound to `product_master_id = 1` and `org_account_id` with verified lead score of 40 | M1 | ORIGINAL_REQUEST §R2 |
| F6 | Playbook Mapping & Outreach Drafts | Generate pain-point tailored outreach drafts in `gtm_outreach_messages` staged under `status = 'pending_approval'` | M1 | ORIGINAL_REQUEST §R3 |
| F7 | Outreach Gate Compliance | Zero template placeholders ({{...}}), zero banned phrases, zero unapproved superlatives/% claims, subject <=150 chars, body <=1500 chars | M1 | ORIGINAL_REQUEST §R3 |
| F8 | MariaDB 10.1 Safety & Compatibility | Enforce `App\Support\ColumnExists::has()` (zero `Schema::hasColumn()`) and `SkipsGuardableColumnCheck` | M1 | ORIGINAL_REQUEST §R4 |
| F9 | Empirical Verification Test Harness | Implement and execute `scripts/verify_tenant6_lifecycle.php` proving zero schema errors, strict Tenant 6 isolation, zero template leaks, and idempotency | M1 | ORIGINAL_REQUEST §R5 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Tenant 6 Signal-to-Outreach Lifecycle Implementation & Empirical Verification | All features F1-F9: Ingestion of 3 LinkedIn signals, organization/contact/lead resolution, staged outreach generation, MariaDB 10.1 safety, and independent verification script execution | None | DONE |

## Interface Contracts
### Signal Ingestion Contract (`crm_signal_inbox`)
- `signal_key`: `hash('sha256', $sourceUrl)`
- `source_name`: `'LinkedIn'`
- `source_url`: exact LinkedIn post URL
- `classification`: `'buyer_inquiry'`
- `classification_confidence`: `0.95`, `0.85`, `0.98`
- `relevance_score`: `0.95`, `0.85`, `0.98`
- `tags`: JSON string containing `intent_score`, `intent_classification`, `metadata`, `raw_payload`
- `sub_institute_id`: `6`
- `assigned_to`: `28`

### Account Resolution Contract
- Exact match on `(sub_institute_id = 6, name = $orgName)` in `crm_organizations`
- Synchronized with `g2g_org_accounts` via `OrgEnsure::forCrmOrganization(6, $crmOrgId)` and `(sub_institute_id = 6, normalized_name = OrgNormalizer::name($orgName))`
- Synchronized with `gtm_accounts` on `(sub_institute_id = 6, crm_organization_id = $crmOrgId)`

### Persona Resolution Contract (`crm_contacts` & `gtm_contacts`)
- Personas: Author (Primary), Head of HR (`reports_to_id = $authorId`), CFO/Finance Head (`reports_to_id = $authorId`)
- `organization_id`: `$crmOrgId`
- `sub_institute_id`: `6`
- `assigned_to`: `28`
- Mirrored to `gtm_contacts` (`account_id = $gtmAccountId`, `sub_institute_id = 6`)

### Lead Creation Contract (`crm_leads`)
- `lead_no`: generated via `LeadNumber::claim()` or format `LD-XXXXX`
- `product_master_id`: `1` (ScholarClone HRMS)
- `org_account_id`: `$orgAccountId` (`g2g_org_accounts.id`)
- `converted_organization_id`: `$crmOrgId`
- `converted_contact_id`: `$authorContactId`
- `industry`: `'Education'`, `rating`: `'Hot'`, `lead_score`: `40`
- `sub_institute_id`: `6`, `assigned_to`: `28`

### Outreach Draft Contract (`gtm_outreach_messages`)
- Foreign pointers: `account_id = $gtmAccountId`, `contact_id = $gtmContactId`, `product_master_id = 1`, `sub_institute_id = 6`
- Status: `'pending_approval'` (strictly)
- Subject: <= 150 chars, no merge fields
- Body: <= 1500 chars, <= 1 link, 0 banned phrases, 0 unapproved claims, 0 merge fields

### Safety Contract
- Column existence: `App\Support\ColumnExists::has($table, $column)` ONLY.
- Model mass assignment: `\App\Models\Concerns\SkipsGuardableColumnCheck`.

## Code Layout
- Seeder/Runner: `database/seeders/Tenant6SignalOutreachLifecycleSeeder.php`
- Verification script: `scripts/verify_tenant6_lifecycle.php`
- Domain models:
  - `app/Domain/OrgAccounts/OrgAccount.php`
  - `app/Domain/Gtm/GtmOutreachMessage.php`
  - `app/Domain/Gtm/GtmAccount.php`
  - `app/Domain/Gtm/GtmContact.php`
- Safety helper: `app/Support/ColumnExists.php`
- Agent metadata: `.agents/teamwork/`
