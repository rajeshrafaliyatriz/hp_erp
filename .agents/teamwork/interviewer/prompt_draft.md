Thoroughly update and run Tenant6CrmLifecycleSeeder.php to populate all fields across all CRM submodules for Tenant 6 (ScholarClone, Admin User ID: 28) using authentic Gujarat K-12 school data, ensuring no modules or fields remain empty.

Working directory: c:\Users\MILAN\Downloads\hp_erp
Integrity mode: development

## Requirements

### R1. Complete CRM Lifecycle Data Population
The seeder `Tenant6CrmLifecycleSeeder.php` must populate all fields across all CRM submodules properly (not leaving fields empty or modules looking empty) for Tenant 6 (ScholarClone, Admin User ID: 28, product_master_id = 1) on database db3 / mysql (host 192.168.0.2 / 202.47.117.220, database hp_erp).

### R2. Submodule Data Coverage and Authentic Data Sourcing
Populate authentic Gujarat K-12 schools data across all submodules, querying `crm.vtiger_leaddetails` / `crm.vtiger_leadaddress` directly and falling back to embedded authentic Gujarat school seed records if external tables are unreachable or incomplete:
1. Products & Services: Full ScholarClone suite (ERP, Core SIS, Fees, LMS, Attendance, Exam, Bus tracking, Implementation, Training) with proper SKU, category, gl_account, unit_price, cost_price, usage_unit, description, etc.
2. Price Books & Items: Price books for CBSE, GSEB, and International schools, fully populated with price book line items.
3. Marketing Campaigns: Full details with budget, expected revenue, actual cost, type, status, description.
4. Leads: 35+ authentic Gujarat school leads with every field filled: firstName, lastName, company, designation, email, phone, mobile, address, city, state, postal code, website, annual revenue, employee count, lead source, industry ('Education'), rating ('Hot'), lead status, scoring.
5. Organizations (Accounts): 15+ converted school organizations with all fields: name, phone, email, website, billing address, shipping address, annual revenue, employee count, ownership, industry, type ('Customer'), etc.
6. Contacts: Multiple realistic contacts per school (Principal, Trustee, IT Head, Finance Head) with title, department, email, phone, mobile, address, consent status.
7. Opportunities & Deals: Distributed across all sales stages (Prospecting, Qualification, Needs Analysis, Value Proposition, Id. Decision Makers, Proposal/Price Quote, Negotiation/Review, Closed Won) with amount, closing date, probability, next step, description, forecast category.
8. Quotes: Multi-item quotes with subtotal, discounts, 18% GST tax, shipping, terms & conditions.
9. Sales Orders & Invoices: Fully converted for closed won deals with line items and zero variance.
10. Market Signals: Real Gujarat educational signals in crm_signal_inbox.

### R3. Database Safety & Compatibility
All code must use `App\Support\ColumnExists::has()` rather than `Schema::hasColumn()` to prevent MariaDB 10.1 generation_expression crashes.

### R4. Version Control Delivery
Commit and push all changes to branch Milan-2.

## Verification Resources
- Seeder command: `php artisan db:seed --class=Tenant6CrmLifecycleSeeder`
- Target database: MySQL connection db3 / mysql (host 192.168.0.2 / 202.47.117.220, database hp_erp)

## Acceptance Criteria

### CRM Seeder Execution & Data Completeness
- [ ] `php artisan db:seed --class=Tenant6CrmLifecycleSeeder` executes cleanly without database or SQL errors on db3 / mysql.
- [ ] Tenant 6 (ScholarClone) CRM database tables contain complete, non-empty records across all 10 submodules (Products & Services, Price Books, Campaigns, Leads, Organizations, Contacts, Opportunities/Deals, Quotes, Sales Orders/Invoices, Market Signals).
- [ ] Sourced Leads contain 35+ authentic Gujarat school leads with all specified fields populated (firstName, lastName, company, designation, email, phone, mobile, address, city, state, postal code, website, annual revenue, employee count, lead source, industry, rating, lead status, scoring).
- [ ] Organizations contain 15+ converted school entities with all required and optional attributes populated (name, phone, email, website, billing/shipping address, annual revenue, employee count, ownership, industry, type).
- [ ] Opportunities cover all sales stages from Prospecting through Closed Won with stage-appropriate amounts and probabilities.
- [ ] Quotes, Sales Orders, and Invoices have matching line items with zero variance and 18% GST calculation.
- [ ] All column existence checks in `Tenant6CrmLifecycleSeeder.php` use `App\Support\ColumnExists::has()`, with zero occurrences of `Schema::hasColumn()`.
- [ ] All changes committed and pushed to branch `Milan-2`.
