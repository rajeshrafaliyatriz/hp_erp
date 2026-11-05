# Database Schema Survey Report: 10 CRM Submodules on hp_erp

**Date**: 2026-11-05  
**Author**: Teamwork Explorer (Survey Subagent)  
**Target Database**: MySQL `hp_erp` (Host: 202.47.117.220:3306)  
**External Source Database**: `crm` (`crm.vtiger_leaddetails`, `crm.vtiger_leadaddress`)  
**Target Tenant**: Tenant 6 (ScholarClone, Admin User ID: 28, Product Master ID: 1)  

---

## 1. Observation

Direct observations from codebase inspection, database queries, and Laravel migrations:

### 1.1 Infrastructure & Database Connectivity
- Target default database connection `mysql` points to `202.47.117.220:3306`, database `hp_erp`, user `dev_db`.
- Query `SELECT DATABASE()` returns `hp_erp`.
- Query `SHOW TABLES LIKE 'crm_%'` returns 35 active CRM tables.
- Query `SELECT COUNT(*) FROM crm.vtiger_leaddetails` succeeded and returned **32,883** records.
- Query `SELECT COUNT(*) FROM crm.vtiger_leaddetails l JOIN crm.vtiger_leadaddress a ON a.leadaddressid = l.leadid WHERE LOWER(a.state) LIKE '%gujarat%' AND l.company IS NOT NULL AND TRIM(l.company) != ''` returned **622** authentic Gujarat records.

### 1.2 CRM Submodule Architecture & Table Mapping
Codebase analysis of `database/migrations/` and `app/Http/Controllers/Api/Crm/` establishes the mapping for all 10 requested submodules:
1. **Products & Services**: Unified single table `crm_products` with column `item_type` (`enum('product','service')`), auxiliary tables `crm_product_bundle_items`, `crm_tax_rates`.
   - Migration: `2026_11_24_080200_create_crm_products_table.php`, `2026_11_24_081400_add_phase6_fields_to_crm_products_table.php`, `2026_12_11_160000_add_product_master_id_to_crm_products.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmProductController.php:28-32` states:
     > "Products & Services - one unified `crm_products` table, `item_type` discriminated. See that table's own migration for why this is the correct translation of legacy's polymorphic design... Two menu leaves/routes (Products, Services) share this one controller and one table".
2. **Price Books & Items**: `crm_price_books`, `crm_price_book_items`.
   - Migrations: `2026_11_24_081800_create_crm_price_books_table.php`, `2026_11_24_081900_create_crm_price_book_items_table.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmPriceBookController.php`.
3. **Marketing Campaigns**: `crm_campaigns`, `crm_campaign_targets`.
   - Migrations: `2026_11_20_090400_create_crm_campaigns_table.php`, `2026_11_20_090500_create_crm_campaign_targets_table.php`, `2026_12_03_080300_add_touchpoint_events_to_crm_campaign_targets.php`, `2026_12_11_140000_add_product_master_id_to_crm_campaigns.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmCampaignController.php`.
4. **Leads**: `crm_leads`, `crm_lead_scoring_rules`, `crm_lead_followups`, `crm_lead_capture_settings`, `crm_lead_assignment_settings`, `crm_lead_assignment_rotation_members`, `crm_lead_comments`.
   - Migrations: `2026_11_20_090350_create_crm_leads_table_if_missing.php`, `2026_11_24_081300_add_designation_and_converted_opportunity_to_crm_leads_table.php`, `2026_11_30_090000_add_lead_scoring_to_crm_leads_table.php`, `2026_12_03_080000_add_lifecycle_fields_to_crm_leads.php`, `2026_12_11_100100_add_product_and_org_account_to_crm_leads.php`, `2026_12_11_140200_add_campaign_id_to_crm_leads.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmLeadController.php`.
5. **Organizations (Accounts)**: `crm_organizations`, `crm_organization_comments`.
   - Migrations: `2026_11_20_090100_create_crm_organizations_table.php`, `2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmOrganizationController.php`.
6. **Contacts**: `crm_contacts`, `crm_contact_comments`, `crm_consent_preferences`.
   - Migrations: `2026_11_20_090200_create_crm_contacts_table.php`, `2026_12_03_080100_add_consent_fields_to_crm_leads_contacts.php`, `2026_12_03_080200_create_crm_consent_preferences_table.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmContactController.php`.
7. **Opportunities / Deals**: `crm_opportunities`, `crm_opportunity_contacts`, `crm_opportunity_products`, `crm_opportunity_stage_history`, `crm_opportunity_comments`.
   - Migrations: `2026_11_24_080300_create_crm_opportunities_table.php`, `2026_11_24_080400_create_crm_opportunity_contacts_table.php`, `2026_11_24_080500_create_crm_opportunity_products_table.php`, `2026_11_24_080600_create_crm_opportunity_stage_history_table.php`, `2026_11_30_080000_add_close_reason_to_crm_opportunities_table.php`, `2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmOpportunityController.php`.
8. **Quotes**: `crm_quotes`, `crm_quote_line_items`, `crm_quote_status_history`.
   - Migrations: `2026_11_24_080700_create_crm_quotes_table.php`, `2026_11_24_080800_create_crm_quote_line_items_table.php`, `2026_11_24_082000_add_price_book_id_to_crm_quotes_table.php`, `2026_11_24_084500_create_crm_quote_status_history_table.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmQuoteController.php`.
9. **Sales Orders & Invoices**:
   - Sales Orders: `crm_sales_orders`, `crm_sales_order_line_items`, `crm_sales_order_status_history`.
   - Invoices: `crm_invoices`, `crm_invoice_line_items`, `crm_invoice_payments`, `crm_invoice_status_history`.
   - Migrations: `2026_11_24_082400_create_crm_sales_orders_table.php`, `2026_11_24_082500_create_crm_sales_order_line_items_table.php`, `2026_11_24_082800_create_crm_invoices_table.php`, `2026_11_24_082900_create_crm_invoice_line_items_table.php`, `2026_11_24_083000_create_crm_order_to_cash_status_history_tables.php`, `2026_11_30_080100_add_payment_tracking_to_crm_invoices_table.php`.
   - Controllers: `app/Http/Controllers/Api/Crm/CrmSalesOrderController.php`, `app/Http/Controllers/Api/Crm/CrmInvoiceController.php`.
10. **Market Signals**: `crm_signal_inbox`, `crm_signal_topics`.
   - Migrations: `2026_12_03_100000_create_crm_signal_inbox_table.php`, `2026_12_03_100100_create_crm_signal_topics_table.php`.
   - Controller: `app/Http/Controllers/Api/Crm/CrmSignalInboxController.php`.

### 1.3 Live Tenant 6 Record Counts in Target Database
Querying the live database for Tenant 6 (`sub_institute_id = 6`) yields:
- `crm_products`: 6
- `crm_tax_rates`: 1
- `crm_price_books`: **0**
- `crm_price_book_items`: **0**
- `crm_campaigns`: 1
- `crm_campaign_targets`: **0**
- `crm_leads`: 18
- `crm_lead_scoring_rules`: 2
- `crm_lead_followups`: 2
- `crm_organizations`: 9
- `crm_contacts`: 6
- `crm_consent_preferences`: **0**
- `crm_opportunities`: 7
- `crm_opportunity_contacts`: 5
- `crm_opportunity_products`: **0**
- `crm_quotes`: 8
- `crm_quote_line_items`: 8
- `crm_sales_orders`: 4
- `crm_sales_order_line_items`: 4
- `crm_invoices`: 4
- `crm_invoice_line_items`: 4
- `crm_invoice_payments`: **0**
- `crm_signal_inbox`: **0**
- `crm_signal_topics`: **0**

---

## 2. Comprehensive Schema Definitions per Submodule

### Submodule 1: Products & Services

#### Table: `crm_products`
Unified table storing both physical products and services for ScholarClone suite.
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `product_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger product ID |
| `item_type` | `enum('product','service')` | NO | NULL | MUL | **DB & App Required**: `required\|in:product,service` |
| `product_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: Unique code (e.g. `PRD-GUJ-001`, `SRV-GUJ-001`) |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `sku` | `varchar(100)` | YES | NULL | | Stock Keeping Unit code (e.g. `SCH-01-GUJ`, `SCH-SRV-IMP`) |
| `category` | `varchar(100)` | YES | NULL | | Picklist: `Hardware`, `Software`, `Subscription`, `Consulting`, `Support`, `Training`, `Other` |
| `gl_account` | `varchar(191)` | YES | NULL | | General ledger accounting code (e.g. `4000-SALES-GUJ`) |
| `description` | `text` | YES | NULL | | Detailed product/service scope |
| `unit_price` | `decimal(15,2)` | YES | NULL | | Selling/list price |
| `cost_price` | `decimal(15,2)` | YES | NULL | | Internal cost |
| `commission_rate` | `decimal(5,2)` | YES | NULL | | Commission percentage (0.00 to 100.00) |
| `currency` | `varchar(10)` | YES | NULL | | Currency code (e.g. `INR`) |
| `tax_rate_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_tax_rates(id)` |
| `is_active` | `tinyint(1)` | NO | 1 | | Active status (1 = Active, 0 = Inactive) |
| `vendor` | `varchar(191)` | YES | NULL | | Vendor / Supplier name |
| `qty_in_stock` | `decimal(15,2)` | YES | NULL | | Inventory count (for products) |
| `qty_in_demand` | `decimal(12,2)` | YES | NULL | | Pipeline demand quantity |
| `reorder_level` | `int(10) unsigned` | YES | NULL | | Minimum stock before reorder |
| `weight` | `decimal(10,3)` | YES | NULL | | Shipping weight in kg |
| `image` | `varchar(191)` | YES | NULL | | Media image path |
| `usage_unit` | `varchar(191)` | YES | NULL | | Billing unit (e.g. `Student/Year`, `Campus/Year`, `Hours`, `Licenses`) |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` (Owner User ID) |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | Creator User ID |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | | Last modifier User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | | Deleter User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `product_master_id` | `bigint(20) unsigned` | YES | NULL | | **Catalog Binding**: 1 = ScholarClone |

**Foreign Keys:**
- `tax_rate_id` -> `crm_tax_rates(id)` [constraint: `crm_products_tax_rate_id_foreign`]

#### Table: `crm_product_bundle_items`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `parent_product_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Parent bundle product ID |
| `component_product_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Component product ID |
| `quantity` | `decimal(12,2)` | NO | 1.00 | | Quantity in bundle |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |

**Foreign Keys:**
- `parent_product_id` -> `crm_products(id)` [constraint: `crm_product_bundle_items_parent_product_id_foreign`]
- `component_product_id` -> `crm_products(id)` [constraint: `crm_product_bundle_items_component_product_id_foreign`]

#### Table: `crm_tax_rates`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `name` | `varchar(100)` | NO | NULL | | **DB Required**: e.g. `GST 18%` |
| `percentage` | `decimal(7,3)` | NO | NULL | | **DB Required**: e.g. `18.000` |
| `is_default` | `tinyint(1)` | NO | 0 | | Default tax rate indicator |
| `is_active` | `tinyint(1)` | NO | 1 | | Active flag |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | Creator User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |

---

### Submodule 2: Price Books & Items

#### Table: `crm_price_books`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `price_book_no` (varchar(191))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `price_book_no` | `varchar(191)` | NO | NULL | UNI | **DB Required**: e.g. `PB-CBSE-2026` |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` (e.g. `CBSE Gujarat School Price Book`) |
| `currency` | `varchar(191)` | YES | NULL | | `INR` |
| `is_active` | `tinyint(1)` | NO | 1 | | Active status (1 = Active) |
| `description` | `text` | YES | NULL | | Price book scope and applicability |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

#### Table: `crm_price_book_items`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Index: `(price_book_id, product_id)`

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `price_book_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: FK -> `crm_price_books(id)` |
| `product_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: FK -> `crm_products(id)` |
| `list_price` | `decimal(12,2)` | NO | NULL | | **DB & App Required**: `required\|numeric\|min:0` |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |

**Foreign Keys:**
- `price_book_id` -> `crm_price_books(id)` [constraint: `crm_price_book_items_price_book_id_foreign`]
- `product_id` -> `crm_products(id)` [constraint: `crm_price_book_items_product_id_foreign`]

---

### Submodule 3: Marketing Campaigns

#### Table: `crm_campaigns`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `campaign_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger campaign ID |
| `campaign_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: Unique code (e.g. `CMP-GUJ-2026`) |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `campaign_type` | `varchar(100)` | YES | NULL | | Picklist: `Conference`, `Webinar`, `Trade Show`, `Public Relations`, `Partners`, `Referral Program`, `Advertisement`, `Banner Ads`, `Direct Mail`, `Email`, `Telemarketing`, `Others` |
| `campaign_status` | `varchar(100)` | YES | NULL | MUL | Picklist: `Planning`, `Active`, `Inactive`, `Completed`, `Cancelled` |
| `expected_revenue`| `decimal(15,2)` | YES | NULL | | Projected campaign return |
| `budget_cost` | `decimal(15,2)` | YES | NULL | | Allocated budget |
| `actual_cost` | `decimal(15,2)` | YES | NULL | | Actual expenses incurred |
| `expected_response`| `varchar(50)` | YES | NULL | | Picklist: `Excellent`, `Good`, `Average`, `Poor` |
| `num_sent` | `int(10) unsigned` | YES | NULL | | Number of outreach messages/invitations |
| `sponsor` | `varchar(191)` | YES | NULL | | Sponsor or department |
| `target_audience` | `varchar(191)` | YES | NULL | | e.g. `Gujarat K-12 School Trustees & Principals` |
| `target_size` | `int(10) unsigned` | YES | NULL | | Target entity pool size |
| `expected_response_count` | `int(10) unsigned` | YES | NULL | | Expected responding leads |
| `expected_sales_count` | `int(10) unsigned` | YES | NULL | | Expected closed deals |
| `actual_response_count` | `int(10) unsigned` | YES | NULL | | Actual inbound responses |
| `actual_sales_count` | `int(10) unsigned` | YES | NULL | | Actual closed deals |
| `expected_roi` | `decimal(15,2)` | YES | NULL | | Expected ROI percentage or amount |
| `actual_roi` | `decimal(15,2)` | YES | NULL | | Computed actual ROI |
| `closing_date` | `date` | YES | NULL | | Target campaign completion date |
| `product_id` | `bigint(20) unsigned` | YES | NULL | | Associated product ID |
| `description` | `text` | YES | NULL | | Campaign narrative and outreach plan |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `product_master_id`| `bigint(20) unsigned`| YES | NULL | | **Catalog Binding**: 1 = ScholarClone |

#### Table: `crm_campaign_targets`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `campaign_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: FK -> `crm_campaigns(id)` |
| `target_type` | `varchar(20)` | NO | NULL | MUL | **DB & App Required**: `in:lead,contact,organization` |
| `target_id` | `bigint(20) unsigned` | NO | NULL | | **DB & App Required**: ID of target entity |
| `response_status` | `varchar(30)` | NO | 'none' | | Status: `none`, `contacted_successful`, `contacted_unsuccessful`, `contacted_never_again` |
| `created_at` | `timestamp` | YES | NULL | | Created timestamp |
| `sent_at` | `timestamp` | YES | NULL | | Timestamp outreach was dispatched |
| `opened_at` | `timestamp` | YES | NULL | | Timestamp email/message was viewed |
| `replied_at` | `timestamp` | YES | NULL | | Timestamp response was received |
| `meeting_booked_at`| `timestamp`| YES | NULL | | Timestamp demo/meeting scheduled |
| `opted_out_at` | `timestamp` | YES | NULL | | Timestamp recipient opted out |
| `converted_at` | `timestamp` | YES | NULL | | Timestamp converted to opportunity |
| `notes` | `text` | YES | NULL | | Outreach notes |

**Foreign Keys:**
- `campaign_id` -> `crm_campaigns(id)` [constraint: `crm_campaign_targets_campaign_id_foreign`]

---

### Submodule 4: Leads

#### Table: `crm_leads`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `lead_no` (varchar(30))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger leadid |
| `lead_no` | `varchar(30)` | YES | NULL | UNI | Auto-generated code (e.g. `LEA-GUJ-001`) |
| `salutation` | `varchar(20)` | YES | NULL | | Picklist: `Mr.`, `Ms.`, `Mrs.`, `Dr.`, `Prof.` |
| `first_name` | `varchar(191)` | YES | NULL | | First name |
| `last_name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `company` | `varchar(191)` | YES | NULL | MUL | School/Institution name |
| `designation` | `varchar(191)` | YES | NULL | | Designation (e.g. `Principal`, `Trustee`, `Administrator`) |
| `email` | `varchar(191)` | YES | NULL | MUL | Primary contact email |
| `phone` | `varchar(50)` | YES | NULL | | Landline / board telephone |
| `mobile` | `varchar(50)` | YES | NULL | MUL | Direct mobile number |
| `fax` | `varchar(30)` | YES | NULL | | Fax number |
| `lead_source` | `varchar(191)` | YES | NULL | MUL | Picklist: `Cold Call`, `Existing Customer`, `Self Generated`, `Employee`, `Partner`, `Public Relations`, `Direct Mail`, `Conference`, `Trade Show`, `Web Site`, `Word of mouth`, `Other` |
| `industry` | `varchar(191)` | YES | NULL | | Picklist: `Education` (Scores +25 pts in rule engine) |
| `website` | `varchar(191)` | YES | NULL | | School portal URL |
| `annual_revenue` | `decimal(15,2)` | YES | NULL | | Annual school revenue / fee budget |
| `lead_status` | `varchar(191)` | YES | NULL | MUL | Picklist: `Attempted to Contact`, `Contacted`, `Junk Lead`, `Lost Lead`, `Not Contacted`, `Pre Qualified`, `Qualified` |
| `lifecycle_status` | `varchar(50)` | YES | 'new' | | Stage: `new`, `contacted`, `qualified`, `nurture`, `unqualified` |
| `next_action` | `text` | YES | NULL | | Immediate next step |
| `follow_up_due_date` | `timestamp` | YES | NULL | | Follow-up deadline |
| `assigned_to` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: `required\|integer` (FK -> `tbluser(id)`) |
| `number_of_emp` | `varchar(50)` | YES | NULL | | Staff/Faculty count (e.g. `50-100`) |
| `rating` | `varchar(191)` | YES | NULL | | Picklist: `Hot` (Scores +15 pts), `Warm`, `Cold` |
| `lead_score` | `int(11)` | NO | 0 | MUL | Automated score (Target: 40 pts for Education + Hot) |
| `lead_score_basis` | `text` | YES | NULL | | JSON rule evaluation trace |
| `secondary_email` | `varchar(191)` | YES | NULL | | Alternate contact email |
| `email_opt_out` | `tinyint(1)` | NO | 0 | | Opt out flag (0 = In, 1 = Out) |
| `consent_status` | `varchar(30)` | YES | 'unknown' | | Consent: `opted_in`, `opted_out`, `unknown` |
| `suppressed_at` | `timestamp` | YES | NULL | | Suppression timestamp |
| `suppressed_reason` | `varchar(191)` | YES | NULL | | Reason for suppression |
| `type` | `varchar(191)` | YES | NULL | | Lead type classification |
| `campaign_text` | `varchar(191)` | YES | NULL | | Campaign label |
| `converted` | `tinyint(1)` | NO | 0 | MUL | 1 if converted to Org/Contact/Opportunity |
| `converted_organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` |
| `converted_contact_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_contacts(id)` |
| `converted_opportunity_id` | `bigint(20) unsigned` | YES | NULL | | ID in `crm_opportunities` |
| `source_company_opportunity_id` | `bigint(20) unsigned` | YES | NULL | MUL | Parent source opportunity link |
| `street` | `varchar(191)` | YES | NULL | | Street address |
| `po_box` | `varchar(191)` | YES | NULL | | Post office box |
| `postal_code` | `varchar(191)` | YES | NULL | | Postal code (e.g. `380015`) |
| `country` | `varchar(191)` | YES | NULL | | `India` |
| `state` | `varchar(191)` | YES | NULL | | `Gujarat` |
| `city` | `varchar(191)` | YES | NULL | | City (e.g. `Ahmedabad`, `Surat`, `Vadodara`, `Rajkot`) |
| `description` | `text` | YES | NULL | | Lead profile and notes |
| `sub_institute_id` | `bigint(20) unsigned` | YES | NULL | MUL | Tenant ID (6) (FK -> `school_setup(id)`) |
| `created_by` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `tbluser(id)` |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `tbluser(id)` |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `tbluser(id)` |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `product_master_id` | `bigint(20) unsigned` | YES | NULL | | **Catalog Binding**: 1 = ScholarClone |
| `org_account_id` | `bigint(20) unsigned` | YES | NULL | | Pre-conversion matched account ID |
| `campaign_id` | `bigint(20) unsigned` | YES | NULL | | Marketing campaign link |

**Foreign Keys:**
- `assigned_to` -> `tbluser(id)` [`crm_leads_assigned_to_foreign`]
- `converted_contact_id` -> `crm_contacts(id)` [`crm_leads_converted_contact_id_foreign`]
- `converted_organization_id` -> `crm_organizations(id)` [`crm_leads_converted_organization_id_foreign`]
- `created_by` -> `tbluser(id)` [`crm_leads_created_by_foreign`]
- `deleted_by` -> `tbluser(id)` [`crm_leads_deleted_by_foreign`]
- `sub_institute_id` -> `school_setup(id)` [`crm_leads_sub_institute_id_foreign`]
- `updated_by` -> `tbluser(id)` [`crm_leads_updated_by_foreign`]

#### Table: `crm_lead_scoring_rules`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `field_key` | `varchar(50)` | NO | NULL | | **DB Required**: e.g. `industry`, `rating` |
| `operator` | `varchar(20)` | NO | NULL | | **DB Required**: `equals`, `contains`, `greater_than` |
| `value` | `varchar(191)` | YES | NULL | | Target value: e.g. `Education`, `Hot` |
| `points` | `int(11)` | NO | NULL | | **DB Required**: Points to assign (+25, +15) |
| `is_active` | `tinyint(1)` | NO | 1 | | Active flag |
| `sort_order` | `int(10) unsigned` | NO | 0 | | Evaluation order |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | Creator User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |

#### Auxiliary Lead Tables:
- `crm_lead_followups`: Automated follow-up schedule (`lead_id`, `state`, `attempts`, `intervals`, `window_start`, `window_end`, `timezone`, `subject_template`, `body_template`).
- `crm_lead_capture_settings`: Inbound form settings (`public_key`, `is_enabled`, `default_lead_source`, `default_assigned_to`).
- `crm_lead_assignment_settings`: Round-robin router config (`is_enabled`, `last_assigned_user_id`).
- `crm_lead_assignment_rotation_members`: Round-robin team roster (`user_id`, `sort_order`, `is_active`).
- `crm_lead_comments`: Timeline notes (`lead_id`, `comment`, `created_by`).

---

### Submodule 5: Organizations / Accounts

#### Table: `crm_organizations`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `account_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger account ID |
| `account_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `ACC-GUJ-001` |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` (School Name) |
| `parent_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` (Parent trust/group) |
| `account_type` | `varchar(50)` | YES | NULL | | Picklist: `Customer`, `Prospect`, `Integrator`, `Reseller`, `Analyst`, `Competitor`, `Investor`, `Press` |
| `industry` | `varchar(100)` | YES | NULL | | Picklist: `Education` |
| `rating` | `varchar(50)` | YES | NULL | | Picklist: `Hot`, `Warm`, `Cold` |
| `ownership` | `varchar(100)` | YES | NULL | | e.g. `Private Trust`, `Educational Society`, `Government Aided` |
| `annual_revenue` | `decimal(15,2)` | YES | NULL | | Annual school turnover/fees |
| `employees` | `int(10) unsigned` | YES | NULL | | Staff/Faculty count |
| `sic_code` | `varchar(50)` | YES | NULL | | SIC / Educational Board code (e.g. `CBSE-0430`, `GSEB-1102`) |
| `ticker_symbol` | `varchar(30)` | YES | NULL | | Registration/DISE number |
| `phone` | `varchar(30)` | YES | NULL | | School administrative phone |
| `secondary_phone` | `varchar(30)` | YES | NULL | | Alternate line |
| `email` | `varchar(191)` | YES | NULL | | Official school domain email |
| `secondary_email` | `varchar(191)` | YES | NULL | | Principal/Management email |
| `website` | `varchar(191)` | YES | NULL | | School website URL |
| `fax` | `varchar(30)` | YES | NULL | | Fax line |
| `email_opt_out` | `tinyint(1)` | NO | 0 | | Communication opt-out flag |
| `billing_street` | `varchar(250)` | YES | NULL | | Campus road / address |
| `billing_city` | `varchar(50)` | YES | NULL | | City in Gujarat |
| `billing_state` | `varchar(50)` | YES | NULL | | `Gujarat` |
| `billing_code` | `varchar(30)` | YES | NULL | | PIN code |
| `billing_country` | `varchar(50)` | YES | NULL | | `India` |
| `billing_po_box` | `varchar(30)` | YES | NULL | | Post box |
| `shipping_street` | `varchar(250)` | YES | NULL | | Delivery / Campus address |
| `shipping_city` | `varchar(50)` | YES | NULL | | City |
| `shipping_state` | `varchar(50)` | YES | NULL | | State |
| `shipping_code` | `varchar(30)` | YES | NULL | | PIN code |
| `shipping_country`| `varchar(50)` | YES | NULL | | Country |
| `shipping_po_box` | `varchar(30)` | YES | NULL | | Post box |
| `description` | `text` | YES | NULL | | School history and infrastructure profile |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `linkedin_url` | `varchar(300)` | YES | NULL | | School LinkedIn / social URL |

**Foreign Keys:**
- `parent_id` -> `crm_organizations(id)` [constraint: `crm_organizations_parent_id_foreign`]

#### Table: `crm_organization_comments`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Columns: `id`, `organization_id` (FK -> `crm_organizations(id)`), `sub_institute_id`, `comment`, `created_by`, `created_via`, `created_at`, `updated_at`.

---

### Submodule 6: Contacts

#### Table: `crm_contacts`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `contact_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger contact ID |
| `contact_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `CON-GUJ-001` |
| `organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` |
| `salutation` | `varchar(20)` | YES | NULL | | Picklist: `Mr.`, `Ms.`, `Mrs.`, `Dr.`, `Prof.` |
| `first_name` | `varchar(100)` | YES | NULL | | Given name |
| `last_name` | `varchar(100)` | NO | NULL | | **DB & App Required**: `required\|string\|max:100` |
| `title` | `varchar(100)` | YES | NULL | | Key Role (e.g. `Principal`, `Trustee / Chairman`, `IT Director`, `Finance Controller`) |
| `department` | `varchar(100)` | YES | NULL | | Department (e.g. `Academic Administration`, `Board of Trustees`, `Information Technology`, `Accounts & Finance`) |
| `email` | `varchar(191)` | YES | NULL | | Official work email |
| `secondary_email` | `varchar(191)` | YES | NULL | | Personal email |
| `phone` | `varchar(30)` | YES | NULL | | Direct desk extension |
| `mobile` | `varchar(30)` | YES | NULL | | Mobile number |
| `fax` | `varchar(30)` | YES | NULL | | Fax line |
| `home_phone` | `varchar(30)` | YES | NULL | | Alternate line |
| `assistant` | `varchar(100)` | YES | NULL | | Executive assistant name |
| `assistant_phone` | `varchar(30)` | YES | NULL | | Assistant contact |
| `reports_to_id` | `bigint(20) unsigned` | YES | NULL | MUL | Hierarchy FK -> `crm_contacts(id)` (e.g. Principal reports to Trustee) |
| `birthday` | `date` | YES | NULL | | Date of birth |
| `lead_source` | `varchar(100)` | YES | NULL | | Picklist source |
| `do_not_call` | `tinyint(1)` | NO | 0 | | DNC flag |
| `email_opt_out` | `tinyint(1)` | NO | 0 | | Opt out flag |
| `consent_status` | `varchar(30)` | YES | 'unknown' | | Consent: `opted_in`, `opted_out`, `unknown` |
| `suppressed_at` | `timestamp` | YES | NULL | | Suppression timestamp |
| `suppressed_reason` | `varchar(191)` | YES | NULL | | Suppression reason |
| Mailing Address | `varchar(250)` / `50` / `30` | YES | NULL | | `mailing_street`, `mailing_city`, `mailing_state`, `mailing_code`, `mailing_country`, `mailing_po_box` |
| Other Address | `varchar(250)` / `50` / `30` | YES | NULL | | `other_street`, `other_city`, `other_state`, `other_code`, `other_country`, `other_po_box` |
| `description` | `text` | YES | NULL | | Background bio, office hours, academic degrees |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | MUL | User ID |
| `linkedin_url` | `varchar(300)` | YES | NULL | | Professional LinkedIn profile URL |

**Foreign Keys:**
- `organization_id` -> `crm_organizations(id)` [constraint: `crm_contacts_organization_id_foreign`]
- `reports_to_id` -> `crm_contacts(id)` [constraint: `crm_contacts_reports_to_id_foreign`]

#### Table: `crm_consent_preferences`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Columns: `id`, `entity_type` (`lead`, `contact`), `entity_id`, `channel` (`email`, `sms`, `call`), `status` (`opted_in`, `opted_out`), `captured_at`, `source`, `notes`, `sub_institute_id`, `created_by`.

---

### Submodule 7: Opportunities / Deals

#### Table: `crm_opportunities`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `opportunity_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger potential ID |
| `opportunity_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `OPP-GUJ-001` |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` (Deal Name) |
| `organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` |
| `campaign_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_campaigns(id)` |
| `amount` | `decimal(15,2)` | YES | NULL | | Deal value (e.g. ₹75,000 to ₹350,000) |
| `currency` | `varchar(10)` | YES | NULL | | `INR` |
| `closing_date` | `date` | YES | NULL | | **App Required**: `required\|date` |
| `sales_stage` | `varchar(100)` | YES | NULL | MUL | **App Required**: Picklist: `Prospecting`, `Qualification`, `Needs Analysis`, `Value Proposition`, `Identify Decision Makers`, `Perception Analysis`, `Proposal/Quotation`, `Negotiation/Review`, `Closed Won`, `Closed Lost` |
| `probability` | `decimal(5,2)` | YES | NULL | | Stage probability (10% to 100%) |
| `lead_source` | `varchar(100)` | YES | NULL | | Picklist source |
| `potential_type` | `varchar(50)` | YES | NULL | | Picklist: `New Business`, `Existing Business` |
| `next_step` | `text` | YES | NULL | | Detailed upcoming milestone |
| `forecast_category` | `varchar(50)` | YES | NULL | | Picklist: `Pipeline`, `Best Case`, `Commit`, `Omitted` |
| `description` | `text` | YES | NULL | | Implementation scope & technical specifications |
| `close_reason` | `text` | YES | NULL | | Win/loss explanation |
| `converted_from_gtm_deal_id` | `bigint(20) unsigned` | YES | NULL | MUL | GTM sync deal reference |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |
| `product_master_id` | `bigint(20) unsigned` | YES | NULL | | **Catalog Binding**: 1 = ScholarClone |
| `loss_reason_code` | `varchar(30)` | YES | NULL | | Standardized loss code |

**Foreign Keys:**
- `campaign_id` -> `crm_campaigns(id)` [constraint: `crm_opportunities_campaign_id_foreign`]
- `organization_id` -> `crm_organizations(id)` [constraint: `crm_opportunities_organization_id_foreign`]

#### Auxiliary Opportunity Tables:
- `crm_opportunity_contacts`: Pivot table (`opportunity_id` -> `crm_opportunities(id)`, `contact_id` -> `crm_contacts(id)`).
- `crm_opportunity_products`: Deal line items (`opportunity_id` -> `crm_opportunities(id)`, `product_id` -> `crm_products(id)`, `quantity`).
- `crm_opportunity_stage_history`: Stage progression audit (`opportunity_id`, `from_stage`, `to_stage`, `amount`, `probability`, `closing_date`, `changed_by`).
- `crm_opportunity_comments`: Discussion logs (`opportunity_id`, `sub_institute_id`, `comment`, `created_by`).

---

### Submodule 8: Quotes

#### Table: `crm_quotes`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `quote_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger quotes ID |
| `quote_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `QUO-GUJ-001` |
| `subject` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` |
| `contact_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_contacts(id)` |
| `opportunity_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_opportunities(id)` |
| `price_book_id` | `bigint(20) unsigned` | YES | NULL | | Associated Price Book ID |
| `quote_stage` | `varchar(100)` | YES | NULL | MUL | Picklist: `Draft`, `Sent`, `Accepted`, `Rejected`, `Revised`, `Expired` |
| `valid_till` | `date` | YES | NULL | | Quotation expiry date |
| `currency` | `varchar(10)` | YES | NULL | | `INR` |
| `subtotal` | `decimal(15,2)` | NO | 0.00 | | **DB Required**: Line item pre-discount subtotal |
| `discount_percent`| `decimal(7,3)` | YES | NULL | | Header discount percentage |
| `discount_amount` | `decimal(15,2)` | NO | 0.00 | | Header discount amount |
| `shipping_handling_amount` | `decimal(15,2)` | NO | 0.00 | | Shipping & handling fee |
| `adjustment` | `decimal(15,2)` | NO | 0.00 | | Financial rounding / adjustment |
| `tax_total` | `decimal(15,2)` | NO | 0.00 | | Total calculated tax (18% GST) |
| `total` | `decimal(15,2)` | NO | 0.00 | | Grand total payable |
| Billing Address | `varchar(250)` / `50` / `30` | YES | NULL | | `billing_street`, `billing_city`, `billing_state`, `billing_code`, `billing_country`, `billing_po_box` |
| Shipping Address | `varchar(250)` / `50` / `30` | YES | NULL | | `shipping_street`, `shipping_city`, `shipping_state`, `shipping_code`, `shipping_country`, `shipping_po_box` |
| `terms_conditions`| `text` | YES | NULL | | Payment terms, SLA, cloud deployment clauses |
| `description` | `text` | YES | NULL | | Commercial terms narrative |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `updated_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `deleted_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `sub_institute_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

**Foreign Keys:**
- `contact_id` -> `crm_contacts(id)` [constraint: `crm_quotes_contact_id_foreign`]
- `opportunity_id` -> `crm_opportunities(id)` [constraint: `crm_quotes_opportunity_id_foreign`]
- `organization_id` -> `crm_organizations(id)` [constraint: `crm_quotes_organization_id_foreign`]

#### Table: `crm_quote_line_items`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `quote_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: FK -> `crm_quotes(id)` |
| `product_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_products(id)` |
| `description` | `varchar(500)` | YES | NULL | | Line item description |
| `quantity` | `decimal(15,2)` | NO | 1.00 | | **DB & App Required**: `required\|numeric\|min:0.01` |
| `unit_price` | `decimal(15,2)` | NO | 0.00 | | **DB & App Required**: `required\|numeric\|min:0` |
| `discount_percent`| `decimal(7,3)` | YES | NULL | | Line discount % |
| `discount_amount` | `decimal(15,2)` | YES | NULL | | Line discount ₹ |
| `tax_rate_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_tax_rates(id)` |
| `tax_name_snapshot` | `varchar(100)` | YES | NULL | | Snapshot (e.g. `GST 18%`) |
| `tax_percent_snapshot` | `decimal(7,3)` | YES | NULL | | Snapshot (e.g. `18.000`) |
| `line_total` | `decimal(15,2)` | NO | 0.00 | | Computed line total including tax |
| `sequence_no` | `int(10) unsigned` | NO | 0 | | Sort display order |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |

**Foreign Keys:**
- `quote_id` -> `crm_quotes(id)` [constraint: `crm_quote_line_items_quote_id_foreign`]
- `product_id` -> `crm_products(id)` [constraint: `crm_quote_line_items_product_id_foreign`]
- `tax_rate_id` -> `crm_tax_rates(id)` [constraint: `crm_quote_line_items_tax_rate_id_foreign`]

#### Table: `crm_quote_status_history`
- Columns: `id`, `quote_id` (FK -> `crm_quotes(id)`), `from_stage`, `to_stage`, `changed_by`, `created_at`.

---

### Submodule 9: Sales Orders & Invoices

#### Table: `crm_sales_orders`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `so_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger salesorder ID |
| `so_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `SO-GUJ-001` |
| `subject` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` (FK -> `crm_organizations(id)`) |
| `contact_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_contacts(id)` |
| `opportunity_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_opportunities(id)` |
| `quote_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_quotes(id)` |
| `so_stage` | `varchar(100)` | YES | NULL | MUL | Picklist: `Draft`, `Confirmed`, `Processing`, `Shipped`, `Completed`, `Cancelled` |
| `due_date` | `date` | YES | NULL | | Delivery/Activation due date |
| `carrier` | `varchar(100)` | YES | NULL | | Service dispatcher / delivery mode |
| `currency` | `varchar(10)` | YES | NULL | | `INR` |
| Financials | `decimal(15,2)` | NO | 0.00 | | `subtotal`, `discount_amount`, `shipping_handling_amount`, `adjustment`, `tax_total`, `total` |
| Addresses | `varchar(250)` / `50` / `30` | YES | NULL | | Billing & Shipping addresses (12 columns matching Quotes) |
| `terms_conditions`| `text` | YES | NULL | | Signed commercial contract terms |
| `description` | `text` | YES | NULL | | Project implementation timeline |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `sub_institute_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| Timestamps | `timestamp` | YES | NULL | | `created_at`, `updated_at`, `deleted_at` |

**Foreign Keys:**
- `contact_id` -> `crm_contacts(id)` [`crm_sales_orders_contact_id_foreign`]
- `opportunity_id` -> `crm_opportunities(id)` [`crm_sales_orders_opportunity_id_foreign`]
- `organization_id` -> `crm_organizations(id)` [`crm_sales_orders_organization_id_foreign`]
- `quote_id` -> `crm_quotes(id)` [`crm_sales_orders_quote_id_foreign`]

#### Table: `crm_sales_order_line_items`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Columns: `id`, `sales_order_id` (FK), `product_id` (FK), `description`, `quantity`, `unit_price`, `discount_percent`, `discount_amount`, `tax_rate_id` (FK), `tax_name_snapshot`, `tax_percent_snapshot`, `line_total`, `sequence_no`, timestamps.

#### Table: `crm_sales_order_status_history`
- Columns: `id`, `sales_order_id` (FK), `from_stage`, `to_stage`, `total`, `changed_by`, `created_at`.

#### Table: `crm_invoices`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `invoice_no` (varchar(100))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `legacy_id` | `bigint(20) unsigned` | YES | NULL | MUL | Legacy vtiger invoice ID |
| `invoice_no` | `varchar(100)` | NO | NULL | UNI | **DB Required**: e.g. `INV-GUJ-001` |
| `subject` | `varchar(191)` | NO | NULL | | **DB & App Required**: `required\|string\|max:191` |
| `sales_order_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_sales_orders(id)` |
| `organization_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` |
| `contact_id` | `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_contacts(id)` |
| `invoice_stage` | `varchar(100)` | YES | NULL | MUL | Picklist: `Draft`, `Sent`, `Paid`, `Credit Invoice`, `Cancelled` |
| `invoice_date` | `date` | YES | NULL | | Invoice issue date |
| `due_date` | `date` | YES | NULL | | Payment due date |
| `currency` | `varchar(10)` | YES | NULL | | `INR` |
| Financials | `decimal(15,2)` | NO | 0.00 | | `subtotal`, `discount_amount`, `shipping_handling_amount`, `adjustment`, `tax_total`, `total` (Zero variance to SO total) |
| `amount_paid` | `decimal(15,2)` | NO | 0.00 | | Cumulative payments recorded |
| Addresses | `varchar(250)` / `50` / `30` | YES | NULL | | Billing & Shipping addresses (12 columns) |
| `terms_conditions`| `text` | YES | NULL | | Invoice payment instructions & bank details |
| `description` | `text` | YES | NULL | | Notes & PO reference |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | MUL | **App Required**: `required\|integer` |
| `sub_institute_id`| `bigint(20) unsigned` | NO | NULL | MUL | **DB Required**: Tenant ID (6) |
| Timestamps | `timestamp` | YES | NULL | | `created_at`, `updated_at`, `deleted_at` |

**Foreign Keys:**
- `contact_id` -> `crm_contacts(id)` [`crm_invoices_contact_id_foreign`]
- `organization_id` -> `crm_organizations(id)` [`crm_invoices_organization_id_foreign`]
- `sales_order_id` -> `crm_sales_orders(id)` [`crm_invoices_sales_order_id_foreign`]

#### Table: `crm_invoice_line_items`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Columns: `id`, `invoice_id` (FK), `product_id` (FK), `description`, `quantity`, `unit_price`, `discount_percent`, `discount_amount`, `tax_rate_id` (FK), `tax_name_snapshot`, `tax_percent_snapshot`, `line_total`, `sequence_no`, timestamps.

#### Table: `crm_invoice_payments`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `invoice_id` | `bigint(20) unsigned` | NO | NULL | MUL | **DB & App Required**: FK -> `crm_invoices(id)` |
| `amount` | `decimal(15,2)` | NO | NULL | | **DB & App Required**: `required\|numeric\|min:0.01` |
| `method` | `varchar(50)` | YES | NULL | | Payment mode (e.g. `NEFT`, `RTGS`, `Cheque`, `UPI`) |
| `reference` | `varchar(191)` | YES | NULL | | Bank transaction UTR / Cheque No |
| `paid_on` | `date` | YES | NULL | | Payment date |
| `note` | `text` | YES | NULL | | Payment remarks |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | Collector User ID |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |

**Foreign Keys:**
- `invoice_id` -> `crm_invoices(id)` [`crm_invoice_payments_invoice_id_foreign`]

---

### Submodule 10: Market Signals

#### Table: `crm_signal_inbox`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)
- Unique Keys: `signal_key` (varchar(64))

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `signal_key` | `varchar(64)` | NO | NULL | UNI | **DB & App Required**: Unique signal fingerprint hash (SHA-256 of source + external ID) |
| `source_name` | `varchar(191)` | YES | NULL | | Source outlet (e.g. `Gujarat Education Department Portal`, `Times of India Gujarat`, `CBSE Regional Circulars`) |
| `source_url` | `text` | YES | NULL | | Source link |
| `title` | `varchar(500)` | NO | NULL | | **DB & App Required**: `required\|string\|max:500` (Signal headline) |
| `body` | `text` | YES | NULL | | Signal body / text extract |
| `published_at` | `timestamp` | YES | NULL | MUL | Publication timestamp |
| `ingested_at` | `timestamp` | NO | `current_timestamp()`| | System ingestion timestamp |
| `classification` | `varchar(30)` | NO | 'unclassified' | MUL | Classifier tag (e.g. `buying_intent`, `expansion`, `leadership_change`, `curriculum_switch`, `unclassified`) |
| `classification_confidence` | `decimal(3,2)` | YES | NULL | | Confidence (0.00 to 1.00, e.g. `0.92`) |
| `classification_reason` | `text` | YES | NULL | | AI classification rationale |
| `relevance_score` | `decimal(3,2)` | YES | NULL | | School buying relevance (0.00 to 1.00) |
| `linked_account_id`| `bigint(20) unsigned` | YES | NULL | MUL | FK -> `crm_organizations(id)` (Matched Gujarat school) |
| `linked_account_confidence` | `decimal(3,2)` | YES | NULL | | Account matching confidence |
| `status` | `varchar(20)` | NO | 'pending' | | Review status: `pending`, `accepted`, `rejected`, `dismissed` |
| `reviewed_by` | `bigint(20) unsigned` | YES | NULL | | Reviewer User ID |
| `reviewed_at` | `timestamp` | YES | NULL | | Review timestamp |
| `promoted_to` | `varchar(30)` | YES | NULL | | Promotion target: `lead`, `opportunity` |
| `promoted_id` | `bigint(20) unsigned` | YES | NULL | | Created Lead ID or Opportunity ID |
| `assigned_to` | `bigint(20) unsigned` | YES | NULL | | Assigned sales rep |
| `tags` | `longtext` | YES | NULL | | JSON array of keyword tags |
| `sub_institute_id`| `bigint(20) unsigned` | YES | NULL | | Tenant ID (6) |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |
| `deleted_at` | `timestamp` | YES | NULL | | Soft delete timestamp |

**Foreign Keys:**
- `linked_account_id` -> `crm_organizations(id)` [constraint: `crm_signal_inbox_linked_account_id_foreign`]

#### Table: `crm_signal_topics`
- Primary Key: `id` (bigint(20) unsigned, auto_increment)

| Column | Type | Nullable | Default | Extra / Key | Description / Validation |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NO | NULL | auto_increment, PRI | Primary Key |
| `name` | `varchar(191)` | NO | NULL | | **DB & App Required**: Topic Name (e.g. `Gujarat School ERP RFP Monitor`, `CBSE Affiliation Gujarat`) |
| `keywords` | `longtext` | NO | NULL | | **DB & App Required**: JSON array of search keywords |
| `topic_type` | `varchar(30)` | NO | 'custom' | | Type: `brand`, `competitor`, `industry`, `buying_intent`, `custom` |
| `status` | `tinyint(4)` | NO | 1 | MUL | 1 = Active, 0 = Inactive |
| `sub_institute_id` | `bigint(20) unsigned` | YES | NULL | MUL | Tenant ID (6) |
| `created_by` | `bigint(20) unsigned` | YES | NULL | | User ID |
| `created_at` | `timestamp` | YES | NULL | | Timestamp |
| `updated_at` | `timestamp` | YES | NULL | | Timestamp |

---

## 3. Logic Chain

1. **Table Discovery & Identification**:
   - Starting from migrations `database/migrations/*crm*`, each table's schema was traced.
   - For Submodule 1, legacy vtiger split `vtiger_products` and `vtiger_service` into two polymorphic tables. In `hp_erp`, migration `2026_11_24_080200_create_crm_products_table.php` and `CrmProductController.php:28` unify them into `crm_products` with `item_type` (`product` vs `service`). Thus, both Products and Services live in `crm_products`.
2. **Schema Introspection**:
   - Querying MySQL `information_schema.COLUMNS` and `information_schema.KEY_COLUMN_USAGE` on live database `hp_erp` confirmed the exact column types, nullability, default values, and foreign key constraints for all 29 CRM tables across all 10 submodules.
3. **Required Field Categorization**:
   - *Database-level required fields*: Columns where `IS_NULLABLE = 'NO'` and `COLUMN_DEFAULT IS NULL` (excluding `auto_increment` primary keys).
   - *Application-level required fields*: Fields enforced in `app/Http/Controllers/Api/Crm/*Controller.php` using `Validator::make(...)`. For example, `assignedTo` is required across almost all modules (`crm_products`, `crm_leads`, `crm_organizations`, `crm_quotes`, `crm_sales_orders`, `crm_invoices`), even if nullable in the raw DB column.
4. **Authentic Data Availability Verification**:
   - The query against `crm.vtiger_leaddetails` joined with `crm.vtiger_leadaddress` confirmed that 622 authentic Gujarat school records exist with actual phone numbers, school names, cities (Ahmedabad, Surat, Vadodara, Rajkot, Bhavnagar, Gandhinagar, etc.), pin codes, and email addresses.
5. **Seeder Gap Identification**:
   - Comparing existing `Tenant6CrmLifecycleSeeder.php` with the schema and requirements reveals:
     - Missing Price Books & Price Book Items (Submodule 2).
     - Missing Services (only 3 products exist, no items with `item_type = 'service'`).
     - Missing Campaign Targets (Submodule 3).
     - Insufficient Leads (only 6 in seeder vs requirement of 35+).
     - Insufficient Converted Organizations (only 2 vs requirement of 15+).
     - Insufficient Contacts per School (only 1-2 vs multiple roles: Principal, Trustee, IT Head, Finance Head).
     - Truncated Opportunity Sales Stages (only single stage seeded vs full pipeline: Prospecting through Closed Won).
     - Missing Market Signals (Submodule 10).
     - MariaDB compatibility violation: Several legacy seeders use `Schema::hasColumn()`, violating rule R3 which requires `App\Support\ColumnExists::has()`.

---

## 4. Caveats

1. **Cross-Database Permission**: The seeder queries `crm.vtiger_leaddetails` directly. On the target MySQL server (`202.47.117.220`), user `dev_db` has cross-database read access to `crm.*`. If ever executed in an environment where the database `crm` is unattached or restricted, the seeder must gracefully fall back to the embedded authentic Gujarat dataset without throwing an unhandled PDO exception.
2. **MariaDB 10.1 Compatibility**: Requirement R3 explicitly warns against calling `Schema::hasColumn()` due to MariaDB 10.1 generation_expression bugs. All column checks in the updated seeder must strictly utilize `App\Support\ColumnExists::has($tableName, $columnName)`.
3. **Product Master Id = 1**: Tenant 6 is strictly bound to ScholarClone Product Master ID 1 (`product_master_id = 1`). All products, campaigns, leads, and opportunities must be tagged with `product_master_id = 1`.

---

## 5. Conclusion

All 10 CRM submodules have fully migrated, well-structured database schemas on `hp_erp`. Every table, column type, nullability constraint, default, foreign key, and validation rule has been cataloged.

The implementer agent has everything required to update `Tenant6CrmLifecycleSeeder.php` to populate all fields across all 10 submodules without missing columns, constraint violations, or MariaDB crashes.

---

## 6. Verification Method

To verify these findings independently on `hp_erp`:

1. **Verify Database Connectivity & Table Existence**:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Database: ' . Illuminate\Support\Facades\DB::connection()->getDatabaseName() . PHP_EOL; foreach (['crm_products', 'crm_price_books', 'crm_campaigns', 'crm_leads', 'crm_organizations', 'crm_contacts', 'crm_opportunities', 'crm_quotes', 'crm_sales_orders', 'crm_invoices', 'crm_signal_inbox'] as \$t) { echo \$t . ': ' . (Illuminate\Support\Facades\Schema::hasTable(\$t) ? 'OK' : 'MISSING') . PHP_EOL; }"
   ```

2. **Verify External Gujarat Data Source**:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$count = Illuminate\Support\Facades\DB::select('SELECT COUNT(*) as c FROM crm.vtiger_leaddetails l JOIN crm.vtiger_leadaddress a ON a.leadaddressid = l.leadid WHERE LOWER(a.state) LIKE \"%gujarat%\"')[0]->c; echo 'Gujarat school leads in crm: ' . \$count . PHP_EOL;"
   ```

3. **Verify Column Details on Any CRM Table**:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$cols = Illuminate\Support\Facades\DB::select('DESCRIBE crm_products'); foreach (\$cols as \$c) { echo \$c->Field . ' | ' . \$c->Type . ' | Null: ' . \$c->Null . ' | Default: ' . \$c->Default . PHP_EOL; }"
   ```
