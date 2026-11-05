# Remediation Blueprint Handoff Report — Tenant 6 CRM Lifecycle Seeder Address & Metadata Backfill

## 1. Observation

### A. Direct Database Audit of `crm_sales_orders` and `crm_invoices`
Direct query on MariaDB connection `mysql` (`hp_erp` on `202.47.117.220:3306`, Tenant 6 `sub_institute_id = 6`):
1. **Sales Orders #40–43**:
   - `SO #40` (SO-KESQQKTW): `quote_id = 70`, `organization_id = 161`, `contact_id = 113`
     `billing_street`: `NULL`, `billing_city`: `NULL`, `billing_state`: `NULL`, `billing_code`: `NULL`, `billing_country`: `NULL`
     `shipping_street`: `NULL`, `shipping_city`: `NULL`, `shipping_state`: `NULL`, `shipping_code`: `NULL`, `shipping_country`: `NULL`
     `so_stage`: `NULL`, `carrier`: `NULL`, `due_date`: `NULL`, `terms_conditions`: `NULL`, `description`: `NULL`
   - `SO #41` (SO-EYS5BTGB): `quote_id = 71`, `organization_id = 162`, `contact_id = 114` (Identical NULL address & stage fields)
   - `SO #42` (SO-EPIAFOS8): `quote_id = 72`, `organization_id = 163`, `contact_id = 115` (Identical NULL address & stage fields)
   - `SO #43` (SO-V0VTSPQF): `quote_id = 73`, `organization_id = 164`, `contact_id = 116` (Identical NULL address & stage fields)
2. **Sales Orders #44–47**:
   - Have billing/shipping addresses and stage, but have `created_by = NULL` because line 2604 in `seedClosedWonOrdersAndInvoices()` omitted `'created_by' => $userId`.
3. **Invoices #90–93**:
   - `INV #90` (INV-ZGDMPSXI): `sales_order_id = 40`, `organization_id = 161`
     `billing_street`: `NULL`, `billing_city`: `NULL`, `billing_state`: `NULL`, `billing_code`: `NULL`, `billing_country`: `NULL`
     `shipping_street`: `NULL`, `shipping_city`: `NULL`, `shipping_state`: `NULL`, `shipping_code`: `NULL`, `shipping_country`: `NULL`
     `due_date`: `NULL`, `terms_conditions`: `NULL`, `description`: `NULL`
   - `INV #91` (INV-UBTXE4MC): `sales_order_id = 41`, `organization_id = 162` (Identical NULL address & terms fields)
   - `INV #92` (INV-EF8SRURK): `sales_order_id = 42`, `organization_id = 163` (Identical NULL address & terms fields)
   - `INV #93` (INV-BMCJGCP4): `sales_order_id = 43`, `organization_id = 164` (Identical NULL address & terms fields)
4. **Invoices #94–97**:
   - Have addresses and terms, but have `created_by = NULL` because line 2694 in `seedClosedWonOrdersAndInvoices()` omitted `'created_by' => $userId`.

### B. Upstream Linked Authentic Gujarat Data Present in DB
1. **Quotes #70–73**:
   - `Quote #70`: `billing_street` = `'2, NEAR SOMESHWARA ENCLAVE, U. M. ROAD, VESU, SURAT'`, `city` = `'Surat'`, `terms_conditions` = populated.
   - `Quote #71`: `billing_street` = `'OPP. APPLEWOODS TOWNSHIP,SHANTIPURA CROSS ROAD, SARDAR PATEL RING ROAD, SARKHEJ, AHMEDABAD'`, `city` = `'Ahmedabad'`, `terms_conditions` = populated.
   - `Quote #72`: `billing_street` = `'OPPOSITE CHAMUNDA NAGAR PRIMARY SCHOOL,KANT ROAD, VAGHROL ROAD,'`, `city` = `'Banaskantha'`, `terms_conditions` = populated.
   - `Quote #73`: `billing_street` = `'SHILAJ-RANCHARDA ROAD,] NEAR NANDOLI BUS STAND, NANDOLI, AHMEDABAD, GUJARAT -382115'`, `city` = `'Gandhinagar'`, `terms_conditions` = populated.
2. **Organizations #161–164**:
   - `Org #161` (L. P. Savani Academy): Complete authentic Surat address, pincode `395007`.
   - `Org #162` (Lakshaya International School): Complete authentic Ahmedabad address, pincode `380058`.
   - `Org #163` (Kesar Ideal Skill School): Complete authentic Banaskantha address, pincode `385535`.
   - `Org #164` (Khyati World School): Complete authentic Gandhinagar address, pincode `382115`.

### C. Other Submodule Audit Findings
1. `crm_contacts`: Contact #20 has `organization_id = NULL`. 84 contacts lack `description`. 6 contacts lack `salutation`. 4 contacts lack `lead_source`.
2. `crm_leads`: Leads #504–507 have `product_master_id = NULL` and `campaign_id = NULL`. Leads #78 and #548 have `campaign_id = NULL`. 4 leads lack `description`. 6 leads lack `salutation`.
3. `crm_organizations`: Org #135 has `description = NULL`.
4. `crm_quotes`: Quotes #70–73 have `description = NULL` and `created_by = NULL`.
5. `Tenant6CrmLifecycleSeeder.php:3145`: In the invoice generation fallback block, the code attempts to map `'item_description' => $sl->item_description`. However, `crm_sales_order_line_items` and `crm_invoice_line_items` schema have column name `description`, not `item_description`.

### D. Financial Zero Variance Baseline
All 8 sales order <-> invoice pairs currently have line items and zero variance:
- SO #40 (₹127,440.00) == INV #90 (₹127,440.00) == Payment (₹127,440.00) [Diff: 0.0000]
- SO #41 (₹127,440.00) == INV #91 (₹127,440.00) == Payment (₹127,440.00) [Diff: 0.0000]
- SO #42 (₹127,440.00) == INV #92 (₹127,440.00) == Payment (₹127,440.00) [Diff: 0.0000]
- SO #43 (₹127,440.00) == INV #93 (₹127,440.00) == Payment (₹127,440.00) [Diff: 0.0000]
- SO #44 (₹182,310.00) == INV #94 (₹182,310.00) == Payment (₹182,310.00) [Diff: 0.0000]
- SO #45 (₹182,310.00) == INV #95 (₹182,310.00) == Payment (₹182,310.00) [Diff: 0.0000]
- SO #46 (₹182,310.00) == INV #96 (₹182,310.00) == Payment (₹182,310.00) [Diff: 0.0000]
- SO #47 (₹182,310.00) == INV #97 (₹182,310.00) == Payment (₹182,310.00) [Diff: 0.0000]

---

## 2. Logic Chain

1. **Root Cause**:
   In `Tenant6CrmLifecycleSeeder.php`, method `enrichAndReconcileAllRecords()` (lines 3095–3207):
   - The reconciliation loop iterates through `$salesOrders = DB::table('crm_sales_orders')->where('sub_institute_id', 6)->whereNull('deleted_at')->get()`.
   - It reconciles financial amounts (`subtotal`, `discount_amount`, `tax_total`, `total`, `amount_paid`), sets invoice stage to `Paid`, and verifies the `crm_invoice_payments` row.
   - However, it **never checks or populates** address or metadata fields on `$so` or existing `$inv`.
   - Specifically, if `$so->billing_street` is empty, no update is dispatched to `crm_sales_orders`.
   - In `crm_invoices`, the existing update array (lines 3159–3175) only updates financial fields and stages, completely omitting `billing_street`, `billing_city`, `billing_state`, `billing_code`, `billing_country`, `shipping_street`, `shipping_city`, `shipping_state`, `shipping_code`, `shipping_country`, `terms_conditions`, `description`, `created_by`, `due_date`.
2. **Remediation Mechanism**:
   Inside the `$salesOrders` loop in `enrichAndReconcileAllRecords()`:
   a. Query the linked Organization (`crm_organizations.id = $so->organization_id`) and linked Quote (`crm_quotes.id = $so->quote_id`).
   b. Fallback hierarchy for billing & shipping fields:
      - Billing address: `$so->billing_* ?: ($quote->billing_* ?? ($org->billing_* ?? default_gujarat_address))`
      - Shipping address: `$so->shipping_* ?: ($quote->shipping_* ?? ($org->shipping_* ?? $billing_*))`
   c. If any address, stage (`so_stage`), carrier, due date, terms, or audit fields (`created_by`, `assigned_to`) are empty on `$so`, issue an `update` to `crm_sales_orders` and refresh the `$so` model.
   d. In `crm_invoices`:
      - If creating a new invoice, include all address and metadata fields from the enriched `$so`.
      - If updating an existing invoice, merge all missing address and metadata fields from the enriched `$so` alongside the existing zero-variance financial updates.
3. **Safety & Zero Variance Preservation**:
   - The proposed fix operates purely on address strings, stage/status strings, and metadata strings (`terms_conditions`, `description`, `created_by`, `due_date`, `carrier`).
   - The financial reconciliation formulas (`subtotal`, `total`, `amount_paid`, line item totals) are 100% untouched.
   - All column existence checks use `App\Support\ColumnExists::has()` (zero occurrences of `Schema::hasColumn()`), strictly complying with MariaDB 10.1 `generation_expression` safety.

---

## 3. Caveats

- No caveats. The root cause, exact line numbers, upstream sources, and downstream impacts were empirically verified against MariaDB 10.11 on the live connection.

---

## 4. Conclusion & Actionable Code Blueprint

The worker must apply the following targeted modifications in `database/seeders/Tenant6CrmLifecycleSeeder.php`:

### Change 1: Add `'created_by' => $userId` to `seedClosedWonOrdersAndInvoices()`

**Target 1A**: Around line 2604 in `database/seeders/Tenant6CrmLifecycleSeeder.php`
```diff
                     'terms_conditions' => $quote->terms_conditions,
                     'description' => 'Confirmed Sales Order for ScholarClone deployment.',
                     'assigned_to' => $userId,
+                    'created_by' => $userId,
                     'created_at' => now(),
                     'updated_at' => now(),
```

**Target 1B**: Around line 2694 in `database/seeders/Tenant6CrmLifecycleSeeder.php`
```diff
                     'terms_conditions' => 'Bank Details for RTGS/NEFT: HDFC Bank, A/C: 50200012345678, IFSC: HDFC0000006',
                     'description' => 'Tax Invoice for ScholarClone Software Licenses and Deployment.',
                     'assigned_to' => $userId,
+                    'created_by' => $userId,
                     'created_at' => now(),
                     'updated_at' => now(),
```

---

### Change 2: Enrich Leads, Organizations, Contacts, and Quotes in `enrichAndReconcileAllRecords()`

**Target 2A (Leads)**: Around lines 2981–2984 in `Tenant6CrmLifecycleSeeder.php`
```php
            if (empty($l->product_master_id) && ColumnExists::has('crm_leads', 'product_master_id', $conn)) {
                $updates['product_master_id'] = self::SCHOLARCLONE_PRODUCT_MASTER_ID;
            }
            if (empty($l->campaign_id) && ColumnExists::has('crm_leads', 'campaign_id', $conn)) {
                $firstCamp = DB::connection($conn)->table('crm_campaigns')
                    ->where('sub_institute_id', self::TENANT_ID)
                    ->whereNull('deleted_at')
                    ->value('id');
                if ($firstCamp) {
                    $updates['campaign_id'] = $firstCamp;
                }
            }
            if (empty($l->description)) $updates['description'] = 'Prospective Gujarat K-12 institution evaluating ScholarClone ERP suite.';
            if (empty($l->salutation)) $updates['salutation'] = 'Dr.';
```

**Target 2B (Organizations)**: Around line 3020 in `Tenant6CrmLifecycleSeeder.php`
```php
            if (empty($org->description)) $updates['description'] = 'Prominent Gujarat educational institution running ScholarClone ERP suite.';
```

**Target 2C (Contacts)**: Around lines 3040–3054 in `Tenant6CrmLifecycleSeeder.php`
```php
            if (empty($c->organization_id)) {
                $firstOrg = DB::connection($conn)->table('crm_organizations')
                    ->where('sub_institute_id', self::TENANT_ID)
                    ->whereNull('deleted_at')
                    ->value('id');
                if ($firstOrg) {
                    $updates['organization_id'] = $firstOrg;
                }
            }
            if (empty($c->salutation)) $updates['salutation'] = 'Mr.';
            if (empty($c->lead_source)) $updates['lead_source'] = 'Gujarat EdTech Summit 2026';
            if (empty($c->description)) $updates['description'] = 'Authorized institutional stakeholder for ScholarClone ERP coordination.';
```

**Target 2D (Quotes Sweep)**: Insert right after Contacts enrichment (around line 3094):
```php
        // 3b. Enrich any incomplete Quotes for Tenant 6
        $quotes = DB::connection($conn)->table('crm_quotes')
            ->where('sub_institute_id', self::TENANT_ID)
            ->whereNull('deleted_at')
            ->get();
        foreach ($quotes as $q) {
            $qUpdates = [];
            if (empty($q->description)) {
                $qUpdates['description'] = 'Formal CPQ Proposal for ScholarClone ERP Suite and Implementation.';
            }
            if (empty($q->created_by)) {
                $qUpdates['created_by'] = $userId;
            }
            if (!empty($qUpdates)) {
                DB::connection($conn)->table('crm_quotes')->where('id', $q->id)->update($qUpdates);
            }
        }
```

---

### Change 3: Comprehensive Address & Metadata Backfill in Financial Reconciliation Loop

**Target 3**: Replace lines 3095–3207 in `database/seeders/Tenant6CrmLifecycleSeeder.php` with:

```php
        // 4. Financial Reconciliation: Strict Zero-Variance between Sales Orders, Invoices, and Payments
        $salesOrders = DB::connection($conn)->table('crm_sales_orders')
            ->where('sub_institute_id', self::TENANT_ID)
            ->whereNull('deleted_at')
            ->get();

        $reconciledOrders = 0;
        foreach ($salesOrders as $so) {
            // Backfill missing address and metadata from linked Organization or Quote
            $org = $so->organization_id ? DB::connection($conn)->table('crm_organizations')->where('id', $so->organization_id)->first() : null;
            $quote = $so->quote_id ? DB::connection($conn)->table('crm_quotes')->where('id', $so->quote_id)->first() : null;

            $street = $so->billing_street ?: ($quote->billing_street ?? ($org->billing_street ?? '101 Knowledge Park, SG Highway'));
            $city = $so->billing_city ?: ($quote->billing_city ?? ($org->billing_city ?? 'Ahmedabad'));
            $state = $so->billing_state ?: ($quote->billing_state ?? ($org->billing_state ?? 'Gujarat'));
            $code = $so->billing_code ?: ($quote->billing_code ?? ($org->billing_code ?? '380054'));
            $country = $so->billing_country ?: ($quote->billing_country ?? ($org->billing_country ?? 'India'));

            $shipStreet = $so->shipping_street ?: ($quote->shipping_street ?? ($org->shipping_street ?? $street));
            $shipCity = $so->shipping_city ?: ($quote->shipping_city ?? ($org->shipping_city ?? $city));
            $shipState = $so->shipping_state ?: ($quote->shipping_state ?? ($org->shipping_state ?? $state));
            $shipCode = $so->shipping_code ?: ($quote->shipping_code ?? ($org->shipping_code ?? $code));
            $shipCountry = $so->shipping_country ?: ($quote->shipping_country ?? ($org->shipping_country ?? $country));

            $terms = $so->terms_conditions ?: ($quote->terms_conditions ?? "1. Payment Terms: 50% advance on Order Confirmation, 40% on UAT deployment, 10% on Go-Live.\n2. Prices are inclusive of 18% GST as per statutory rates.\n3. Subscription includes cloud hosting on Tier-4 datacenter in India, daily automated backups, and 99.9% uptime SLA.\n4. Annual Maintenance Contract (AMC) for Year 2 onwards will be 18% of license value.\n5. Implementation timeline: 4 weeks from sign-off and student data handover.");

            $soUpdates = [];
            if (empty($so->billing_street)) $soUpdates['billing_street'] = $street;
            if (empty($so->billing_city)) $soUpdates['billing_city'] = $city;
            if (empty($so->billing_state)) $soUpdates['billing_state'] = $state;
            if (empty($so->billing_code)) $soUpdates['billing_code'] = $code;
            if (empty($so->billing_country)) $soUpdates['billing_country'] = $country;

            if (empty($so->shipping_street)) $soUpdates['shipping_street'] = $shipStreet;
            if (empty($so->shipping_city)) $soUpdates['shipping_city'] = $shipCity;
            if (empty($so->shipping_state)) $soUpdates['shipping_state'] = $shipState;
            if (empty($so->shipping_code)) $soUpdates['shipping_code'] = $shipCode;
            if (empty($so->shipping_country)) $soUpdates['shipping_country'] = $shipCountry;

            if (empty($so->so_stage)) $soUpdates['so_stage'] = 'Confirmed';
            if (empty($so->carrier)) $soUpdates['carrier'] = 'Cloud Electronic Delivery';
            if (empty($so->due_date)) $soUpdates['due_date'] = now()->addDays(30)->toDateString();
            if (empty($so->terms_conditions)) $soUpdates['terms_conditions'] = $terms;
            if (empty($so->description)) $soUpdates['description'] = 'Confirmed Sales Order for ScholarClone deployment.';
            if (empty($so->assigned_to)) $soUpdates['assigned_to'] = $userId;
            if (empty($so->created_by)) $soUpdates['created_by'] = $userId;

            if (!empty($soUpdates)) {
                $soUpdates['updated_at'] = now();
                DB::connection($conn)->table('crm_sales_orders')->where('id', $so->id)->update($soUpdates);
                $so = DB::connection($conn)->table('crm_sales_orders')->where('id', $so->id)->first();
            }

            $inv = DB::connection($conn)->table('crm_invoices')
                ->where('sales_order_id', $so->id)
                ->first();

            if (!$inv) {
                // Generate matching invoice for orphaned sales order
                $invData = [
                    'sub_institute_id' => self::TENANT_ID,
                    'sales_order_id' => $so->id,
                    'invoice_no' => 'INV-GUJ-' . strtoupper(Str::random(8)),
                    'subject' => $so->subject ?? 'ScholarClone ERP License & Deployment',
                    'invoice_date' => now()->toDateString(),
                    'due_date' => $so->due_date ?: now()->addDays(30)->toDateString(),
                    'organization_id' => $so->organization_id,
                    'contact_id' => $so->contact_id,
                    'subtotal' => $so->subtotal,
                    'discount_amount' => $so->discount_amount,
                    'shipping_handling_amount' => $so->shipping_handling_amount ?? 0.00,
                    'adjustment' => $so->adjustment ?? 0.00,
                    'tax_total' => $so->tax_total ?? 0.00,
                    'total' => $so->total,
                    'amount_paid' => $so->total,
                    'currency' => 'INR',
                    'billing_street' => $so->billing_street,
                    'billing_city' => $so->billing_city,
                    'billing_state' => $so->billing_state,
                    'billing_code' => $so->billing_code,
                    'billing_country' => $so->billing_country,
                    'shipping_street' => $so->shipping_street,
                    'shipping_city' => $so->shipping_city,
                    'shipping_state' => $so->shipping_state,
                    'shipping_code' => $so->shipping_code,
                    'shipping_country' => $so->shipping_country,
                    'terms_conditions' => 'Bank Details for RTGS/NEFT: HDFC Bank, A/C: 50200012345678, IFSC: HDFC0000006',
                    'description' => 'Tax Invoice for ScholarClone Software Licenses and Deployment.',
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (ColumnExists::has('crm_invoices', 'invoice_stage', $conn)) {
                    $invData['invoice_stage'] = 'Paid';
                }
                if (ColumnExists::has('crm_invoices', 'status', $conn)) {
                    $invData['status'] = 'Paid';
                }
                $invId = DB::connection($conn)->table('crm_invoices')->insertGetId($invData);

                // Clone line items from sales order
                $soLines = DB::connection($conn)->table('crm_sales_order_line_items')
                    ->where('sales_order_id', $so->id)
                    ->get();
                foreach ($soLines as $sl) {
                    DB::connection($conn)->table('crm_invoice_line_items')->insert([
                        'invoice_id' => $invId,
                        'product_id' => $sl->product_id,
                        'description' => $sl->description,
                        'quantity' => $sl->quantity,
                        'unit_price' => $sl->unit_price,
                        'discount_percent' => $sl->discount_percent,
                        'discount_amount' => $sl->discount_amount ?? 0.00,
                        'tax_rate_id' => $sl->tax_rate_id ?? null,
                        'tax_name_snapshot' => $sl->tax_name_snapshot ?? null,
                        'tax_percent_snapshot' => $sl->tax_percent_snapshot ?? null,
                        'line_total' => $sl->line_total,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $inv = DB::connection($conn)->table('crm_invoices')->where('id', $invId)->first();
            } else {
                // Ensure invoice financial amounts match sales order amounts with zero variance, and backfill missing addresses/metadata
                $invUpdates = [
                    'subtotal' => $so->subtotal,
                    'discount_amount' => $so->discount_amount,
                    'shipping_handling_amount' => $so->shipping_handling_amount ?? 0.00,
                    'adjustment' => $so->adjustment ?? 0.00,
                    'tax_total' => $so->tax_total ?? 0.00,
                    'total' => $so->total,
                    'amount_paid' => $so->total,
                    'updated_at' => now(),
                ];
                if (empty($inv->billing_street)) $invUpdates['billing_street'] = $so->billing_street;
                if (empty($inv->billing_city)) $invUpdates['billing_city'] = $so->billing_city;
                if (empty($inv->billing_state)) $invUpdates['billing_state'] = $so->billing_state;
                if (empty($inv->billing_code)) $invUpdates['billing_code'] = $so->billing_code;
                if (empty($inv->billing_country)) $invUpdates['billing_country'] = $so->billing_country;

                if (empty($inv->shipping_street)) $invUpdates['shipping_street'] = $so->shipping_street;
                if (empty($inv->shipping_city)) $invUpdates['shipping_city'] = $so->shipping_city;
                if (empty($inv->shipping_state)) $invUpdates['shipping_state'] = $so->shipping_state;
                if (empty($inv->shipping_code)) $invUpdates['shipping_code'] = $so->shipping_code;
                if (empty($inv->shipping_country)) $invUpdates['shipping_country'] = $so->shipping_country;

                if (empty($inv->due_date)) $invUpdates['due_date'] = $so->due_date ?: now()->addDays(30)->toDateString();
                if (empty($inv->terms_conditions)) $invUpdates['terms_conditions'] = 'Bank Details for RTGS/NEFT: HDFC Bank, A/C: 50200012345678, IFSC: HDFC0000006';
                if (empty($inv->description)) $invUpdates['description'] = 'Tax Invoice for ScholarClone Software Licenses and Deployment.';
                if (empty($inv->assigned_to)) $invUpdates['assigned_to'] = $userId;
                if (empty($inv->created_by)) $invUpdates['created_by'] = $userId;

                if (ColumnExists::has('crm_invoices', 'invoice_stage', $conn)) {
                    $invUpdates['invoice_stage'] = 'Paid';
                }
                if (ColumnExists::has('crm_invoices', 'status', $conn)) {
                    $invUpdates['status'] = 'Paid';
                }
                DB::connection($conn)->table('crm_invoices')
                    ->where('id', $inv->id)
                    ->update($invUpdates);
            }

            // Ensure invoice payment record exists and matches exact total
            $payment = DB::connection($conn)->table('crm_invoice_payments')
                ->where('invoice_id', $inv->id)
                ->first();

            if (!$payment) {
                DB::connection($conn)->table('crm_invoice_payments')->insert([
                    'invoice_id' => $inv->id,
                    'amount' => $so->total,
                    'method' => 'RTGS',
                    'reference' => 'HDFC-RTGS-GJ-' . strtoupper(Str::random(10)),
                    'paid_on' => now()->toDateString(),
                    'note' => 'Full settlement received via RTGS from School Management Trust account.',
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            } elseif (abs((float)$payment->amount - (float)$so->total) > 0.001) {
                DB::connection($conn)->table('crm_invoice_payments')
                    ->where('id', $payment->id)
                    ->update([
                        'amount' => $so->total,
                        'method' => 'RTGS',
                    ]);
            }

            $reconciledOrders++;
        }

        $this->command?->info("Financial Reconciliation: {$reconciledOrders} sales orders and invoices verified with zero variance and full RTGS payments.");
```

---

## 5. Verification Method

Once the worker agent applies the blueprint:

1. **Verify Seeder Syntax**:
   ```powershell
   php -l database/seeders/Tenant6CrmLifecycleSeeder.php
   ```
   **Expected**: `No syntax errors detected in database/seeders/Tenant6CrmLifecycleSeeder.php`

2. **Verify Zero `Schema::hasColumn()`**:
   ```powershell
   powershell -Command "Select-String -Path 'database/seeders/Tenant6CrmLifecycleSeeder.php' -Pattern 'use.*Facades\\\\Schema'"
   ```
   **Expected**: 0 matches.

3. **Execute the Seeder**:
   ```powershell
   php artisan db:seed --class=Tenant6CrmLifecycleSeeder
   ```
   **Expected**: Exit code 0, 0 database errors.

4. **Independent Post-Seeding Completeness Check**:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); use Illuminate\Support\Facades\DB;
   \$soEmpty = DB::connection('mysql')->table('crm_sales_orders')->where('sub_institute_id', 6)->whereNull('deleted_at')->where(function(\$q){\$q->whereNull('billing_street')->orWhere('billing_street', '');})->count();
   \$invEmpty = DB::connection('mysql')->table('crm_invoices')->where('sub_institute_id', 6)->whereNull('deleted_at')->where(function(\$q){\$q->whereNull('billing_street')->orWhere('billing_street', '');})->count();
   \$cNoOrg = DB::connection('mysql')->table('crm_contacts')->where('sub_institute_id', 6)->whereNull('deleted_at')->where(function(\$q){\$q->whereNull('organization_id')->orWhere('organization_id', 0)->orWhere('organization_id', '');})->count();
   \$lNoPm = DB::connection('mysql')->table('crm_leads')->where('sub_institute_id', 6)->whereNull('deleted_at')->whereNull('product_master_id')->count();
   echo \"Empty SO addresses: {\$soEmpty}\nEmpty INV addresses: {\$invEmpty}\nContacts without org: {\$cNoOrg}\nLeads without PM: {\$lNoPm}\n\";"
   ```
   **Expected**: All 4 counts must be `0`.

5. **Financial Zero-Variance Check**:
   ```powershell
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); use Illuminate\Support\Facades\DB;
   \$sos = DB::connection('mysql')->table('crm_sales_orders')->where('sub_institute_id', 6)->whereNull('deleted_at')->get();
   \$maxVar = 0.0;
   foreach (\$sos as \$so) {
       \$inv = DB::connection('mysql')->table('crm_invoices')->where('sales_order_id', \$so->id)->first();
       \$pay = DB::connection('mysql')->table('crm_invoice_payments')->where('invoice_id', \$inv->id)->first();
       \$maxVar = max(\$maxVar, abs((float)\$so->total - (float)\$inv->total), abs((float)\$inv->total - (float)\$pay->amount));
   }
   echo \"Maximum variance across all pairs: {\$maxVar}\n\";"
   ```
   **Expected**: `Maximum variance across all pairs: 0`
