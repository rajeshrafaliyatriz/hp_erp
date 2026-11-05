# Project: Tenant 6 CRM Lifecycle Seeder Complete Population (ScholarClone)

## Architecture
- **Target Host & Database**: MariaDB 10.11.9 on `202.47.117.220` / internal `192.168.0.2` (`mysql` connection), database `hp_erp`.
- **Target Tenant**: `sub_institute_id = 6` ("ScholarClone" / `school_setup.id = 6`).
- **Primary Admin**: User ID 28 (`scholarclone@gmail.com`).
- **Product Master ID**: 1 (`g2g_product_master.id = 1`, ScholarClone suite).
- **External Data Source**: MariaDB co-located database `crm` (`crm.vtiger_leaddetails`, `crm.vtiger_leadaddress`, `crm.vtiger_leadsubdetails`) containing 5,934 authentic Gujarat school leads.
- **Embedded Fallback Sources**: `scripts/gujarat_schools.json` (93 schools), `database/seed-data/scholar_accounts.json` (389 schools across Ahmedabad, Surat, Vadodara, Rajkot, etc.), `database/fixtures/gujarat_scholarclone_signals.json` (5 authentic signals).
- **Safety Helper**: `App\Support\ColumnExists::has()` to prevent MariaDB 10.1 `generation_expression` crashes. Zero occurrences of `Schema::hasColumn()`.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| F1 | MariaDB 10.1 Column Safety | Replace `Illuminate\Support\Facades\Schema` with `App\Support\ColumnExists::has()` in `Tenant6CrmLifecycleSeeder.php` | M1 | ORIGINAL_REQUEST §R3 |
| F2 | ScholarClone Products & Services Suite | Seed full 9-item suite (ERP, Core SIS, Fees, LMS, Attendance, Exam, Bus tracking, Implementation, Training) with SKUs, categories, gl_accounts, usage_units, prices in `crm_products` | M1 | ORIGINAL_REQUEST §R2.1 |
| F3 | Price Books & Items | Seed 3 price books (CBSE, GSEB, International) in `crm_price_books` and line item pricing for all 9 SKUs in `crm_price_book_items` | M1 | ORIGINAL_REQUEST §R2.2 |
| F4 | Marketing Campaigns | Seed 2-3 Gujarat campaigns with budget, expected revenue, actual cost, type, status, description, target audience, expected ROI in `crm_campaigns` | M1 | ORIGINAL_REQUEST §R2.3 |
| F5 | Sourcing & Normalization of 35+ Gujarat Leads | Source from `crm.vtiger_*` with embedded 35+ fallback; populate all 20+ fields (names, company, designation, phone, mobile, address, city, state, postal code, website, revenue, employee count, source, Education, Hot, score 40) in `crm_leads` | M1 | ORIGINAL_REQUEST §R2.4 |
| F6 | Converted Organizations (15+ Schools) | Convert 15+ leads to `crm_organizations` and populate all fields (name, phone, email, website, billing/shipping address, annual revenue, employees, ownership 'Private Trust', industry 'Education', type 'Customer') | M1 | ORIGINAL_REQUEST §R2.5 |
| F7 | Multiple Contacts per School | Seed 4 key personas per school (Principal, Trustee, IT Head, Finance Head) with title, department, email, phone, mobile, mailing address, consent status in `crm_contacts` | M1 | ORIGINAL_REQUEST §R2.6 |
| F8 | Opportunities Across All 8 Sales Stages | Distribute deals across Prospecting, Qualification, Needs Analysis, Value Proposition, Identify Decision Makers, Proposal/Quotation, Negotiation/Review, Closed Won with amount, closing date, probability, next step, description, forecast category | M1 | ORIGINAL_REQUEST §R2.7 |
| F9 | Multi-Item CPQ Quotes | Create multi-item quotes with subtotal, discounts, 18% GST tax rate snapshot, shipping/handling, terms & conditions, billing/shipping addresses in `crm_quotes` and `crm_quote_line_items` | M1 | ORIGINAL_REQUEST §R2.8 |
| F10 | Zero-Variance Sales Orders & Invoices | Convert closed won quotes to Sales Orders and Invoices with matching line items, zero variance ($|SO - INV| == 0.00$), and `amount_paid` recorded | M1 | ORIGINAL_REQUEST §R2.9 |
| F11 | Market Signals Ingestion | Ingest authentic Gujarat educational signals from fixtures into `crm_signal_inbox` linked to tenant 6 and matching school accounts | M1 | ORIGINAL_REQUEST §R2.10 |
| F12 | Clean Seeder Execution on db3 / mysql | Run `php artisan db:seed --class=Tenant6CrmLifecycleSeeder` without database or SQL errors | M1 | ORIGINAL_REQUEST §R1 |
| F13 | Version Control Delivery | Commit and push all changes cleanly to branch `Milan-2` | M1 | ORIGINAL_REQUEST §R4 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Comprehensive Tenant 6 CRM Lifecycle Seeder Update & Execution | All features F1-F13: Update Tenant6CrmLifecycleSeeder.php, execute seeder on db3/mysql, verify data in all 10 submodules, multi-agent review, challenge, forensic audit, commit & push to Milan-2 | None | IN_PROGRESS |

## Interface Contracts
### Database Connection Contract
- Connection: `mysql` (`202.47.117.220`, MariaDB 10.11.9, database `hp_erp`).
- Fallback: `resolveConnection()` handles `db3` timeout and routes safely to `mysql`.
- Cross-database queries: `crm.vtiger_leaddetails`, `crm.vtiger_leadaddress`, `crm.vtiger_leadsubdetails` via `mysql` connection.
- Column safety: `App\Support\ColumnExists::has($table, $column, $connection)` ONLY. Zero `Schema::hasColumn()`.

### Tenant Scope Contract
- `sub_institute_id` = 6
- Admin user: `tbluser.id = 28`
- `product_master_id` = 1 (ScholarClone)

## Code Layout
- Seeder file: `database/seeders/Tenant6CrmLifecycleSeeder.php`
- Column safety helper: `app/Support/ColumnExists.php`
- Fallback data files:
  - `scripts/gujarat_schools.json`
  - `database/seed-data/scholar_accounts.json`
  - `database/fixtures/gujarat_scholarclone_signals.json`
- Agent metadata: `.agents/teamwork/`
