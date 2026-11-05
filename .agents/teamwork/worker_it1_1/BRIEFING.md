# BRIEFING — 2026-11-05T14:40:00Z

## Mission
Thoroughly update and execute `Tenant6CrmLifecycleSeeder.php` to populate all CRM submodules for Tenant 6 (ScholarClone, Admin User ID 28, product_master_id = 1) using authentic Gujarat school data, ensuring zero empty fields and zero MariaDB 10.1 Schema crashes.

## 🔒 My Identity
- Archetype: worker
- Roles: implementer, qa, specialist
- Working directory: c:\Users\MILAN\Downloads\hp_erp\.agents\teamwork\worker_it1_1\
- Original parent: 8d265f3e-4940-4f48-8635-ac8c37bbdbd2
- Milestone: M1

## 🔒 Key Constraints
- Exclusive write ownership: `database/seeders/Tenant6CrmLifecycleSeeder.php`. Do NOT edit other source files.
- Replace `use Illuminate\Support\Facades\Schema;` with `use App\Support\ColumnExists;`.
- ZERO occurrences of `Schema::hasColumn()` or `use Illuminate\Support\Facades\Schema;`.
- Genuine implementation — no cheating, no hardcoded test results, maintain real state.
- Target connection: `mysql` (`hp_erp` on `202.47.117.220`), sub_institute_id = 6, user ID = 28, product_master_id = 1.
- Populate all 10 CRM submodules cleanly.

## Current Parent
- Conversation ID: 8d265f3e-4940-4f48-8635-ac8c37bbdbd2
- Updated: not yet

## Task Summary
- **What to build**: Full update to `database/seeders/Tenant6CrmLifecycleSeeder.php` incorporating blueprints from all 3 explorers:
  1. Safe Column Safety via `ColumnExists::has()`, zero `Schema` usage.
  2. 9 ScholarClone SKUs (7 products + 2 services), enriching existing rows 63, 64, 65 and binding `product_master_id = 1` via `ProductMasterSync::throughCatalogueItems()`.
  3. 3 Price Books (CBSE, GSEB, International) with 27 item overrides in `crm_price_book_items`.
  4. 3 Gujarat Marketing Campaigns (Flagship Conference, EdTech Summit, Transit Initiative) with complete metrics.
  5. Sourcing Gujarat schools with expanded column list (`annualrevenue`, `noofemployees`, `a.lane`, `a.code`, `s.website`) and embedded 38-school authentic Gujarat fallback array.
  6. 35+ leads with all 20+ fields populated, `lead_score = 40`.
  7. 18+ converted organizations with all attributes (`billing_*`, `shipping_*`, `annual_revenue`, `employees`, `ownership` 'Private Trust', `industry` 'Education', `account_type` 'Customer').
  8. 4 contact personas per school (Principal, Trustee, IT Head, Finance Head) with hierarchical `reports_to_id`, full details, and `consent_status = 'opted_in'`.
  9. Opportunities across all 8 pipeline stages with matching amounts, probabilities, next steps, descriptions.
  10. Multi-item CPQ quotes with 10% discount, 18% GST snapshot, and terms.
  11. Closed won conversion to Sales Orders and Invoices with zero variance and RTGS payment recorded in `crm_invoice_payments`.
  12. Ingestion of 5 authentic Gujarat market signals from `database/fixtures/gujarat_scholarclone_signals.json` into `crm_signal_inbox` and 3 topics into `crm_signal_topics`.
- **Success criteria**:
  - `php artisan db:seed --class=Tenant6CrmLifecycleSeeder` executes cleanly without errors.
  - Zero `Schema::hasColumn()` or `use Illuminate\Support\Facades\Schema;`.
  - Database queries show full row counts and 0 empty fields across all 10 submodules.
- **Interface contracts**: PROJECT.md
- **Code layout**: PROJECT.md

## Change Tracker
- **Files modified**: `database/seeders/Tenant6CrmLifecycleSeeder.php`
- **Build status**: pending implementation
- **Pending issues**: none

## Quality Status
- **Build/test result**: pending
- **Lint status**: clean
- **Tests added/modified**: validation queries

## Key Decisions Made
- Combine and harmonize blueprints from explorer 1, 2, and 3 into a clean, cohesive seeder.
- Ensure idempotent execution: every record creation checks existence by unique key or updates in place.
