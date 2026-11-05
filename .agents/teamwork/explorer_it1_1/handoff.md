# Technical Strategy & Implementation Report: Safe Column Safety, ScholarClone Suite, Price Books, and Campaigns in Tenant6CrmLifecycleSeeder

**Date**: 2026-11-05  
**Author**: Teamwork Explorer (Explorer It1 Subagent)  
**Target File**: `database/seeders/Tenant6CrmLifecycleSeeder.php`  
**Tenant Target**: Tenant 6 (ScholarClone, Admin User ID: 28, Product Master ID: 1)  
**Database**: MySQL `hp_erp` / MariaDB 10.11 (`mysql` connection)

---

## 1. Observation

Direct observations from inspecting the codebase, schema, and live MariaDB instance:

### 1.1 Column Safety & Schema Usage in `Tenant6CrmLifecycleSeeder.php`
- In `database/seeders/Tenant6CrmLifecycleSeeder.php` line 21:
  ```php
  use Illuminate\Support\Facades\Schema;
  ```
  `Schema` is imported on line 21, but `App\Support\ColumnExists` is not imported.
- Searching for `Schema` across `Tenant6CrmLifecycleSeeder.php`:
  `Select-String -Path "database\seeders\Tenant6CrmLifecycleSeeder.php" -Pattern "Schema"` yielded exactly 1 match (line 21).
  No calls to `Schema::hasColumn()` or `Schema::hasTable()` currently exist in `Tenant6CrmLifecycleSeeder.php`.
- In `app/Support/ColumnExists.php` lines 16–38:
  ```php
  final class ColumnExists
  {
      public static function has(string $table, string $column, string|Connection|null $connection = null): bool
      {
          $conn = $connection instanceof Connection ? $connection : DB::connection($connection);
          $driver = $conn->getDriverName();
          if (! in_array($driver, ['mysql', 'mariadb'], true)) {
              $connName = $connection instanceof Connection ? $connection->getName() : $connection;
              return Schema::connection($connName)->hasColumn($table, $column);
          }

          $pdo = $conn->getPdo();
          $rows = $conn->select(self::showColumnsLikeSql($table, $column, fn (string $v): string => (string) $pdo->quote($v)));

          foreach ($rows as $row) {
              if (strcasecmp((string) ($row->Field ?? ''), $column) === 0) {
                  return true;
              }
          }

          return false;
      }
  ```
  `ColumnExists::has()` executes `SHOW COLUMNS FROM <table_name> LIKE '<column_name>'`, bypassing `information_schema.columns.generation_expression` and avoiding MariaDB 10.1 crash `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'generation_expression'`.

### 1.2 Current State of Products in `crm_products`
- Querying `crm_products` for Tenant 6 (`sub_institute_id = 6`) returned 6 rows:
  - Rows 6, 7, 8: Generic demo items (`Enterprise License`, `Onboarding Support`, `ZZ UI Widget`) with `product_master_id = null`.
  - Rows 63, 64, 65: Currently seeded ScholarClone items:
    - ID 63: `Scholar One ERP - Gujarat Edition` (`SCH-01-GUJ`, `product_master_id = 1`)
    - ID 64: `Scholar Core School Management` (`SCH-CORE-GUJ`, `product_master_id = 1`)
    - ID 65: `Scholar CCB Fee Collection System` (`SCH-CCB-GUJ`, `product_master_id = 1`)
- **Inspection of Rows 63, 64, 65**:
  - `description`: `NULL`
  - `usage_unit`: `NULL`
  - `qty_in_stock`: `NULL`
  - `reorder_level`: `NULL`
- In `Tenant6CrmLifecycleSeeder.php` lines 231–248:
  ```php
  $existing = DB::connection($conn)->table('crm_products')
      ->where('sub_institute_id', self::TENANT_ID)
      ->where('sku', $skuData['sku'])
      ->whereNull('deleted_at')
      ->first();

  if ($existing) {
      if ((int) ($existing->product_master_id ?? 0) !== self::SCHOLARCLONE_PRODUCT_MASTER_ID) {
          DB::connection($conn)->table('crm_products')
              ->where('id', $existing->id)
              ->update(['product_master_id' => self::SCHOLARCLONE_PRODUCT_MASTER_ID]);
          ProductMasterSync::catalogueItem(self::TENANT_ID, (int) $existing->id, self::SCHOLARCLONE_PRODUCT_MASTER_ID);
      }
      $productIds[$skuData['sku']] = (int) $existing->id;
      continue;
  }
  ```
  Because of `continue;`, if a product exists, its empty/null fields (`description`, `usage_unit`, `category`, `gl_account`, `unit_price`, `cost_price`) are never updated.
- Line 243 invokes `ProductMasterSync::catalogueItem(self::TENANT_ID, (int) $existing->id, self::SCHOLARCLONE_PRODUCT_MASTER_ID);`. In `app/Domain/ProductMaster/ProductMasterSync.php` line 148, `catalogueItem` is an **instance method** with signature `catalogueItem(int $tenant, int $crmProductId, string $event = self::SAVED): string`. The canonical static entry point is `ProductMasterSync::throughCatalogueItems($tenant, [$id], ProductMasterSync::SAVED, $userId)` (line 71).
- `CrmProductController::store` (lines 233–241) sets `$data['product_master_id'] = null;` when `itemType === 'service'`. Therefore, for services that belong to ScholarClone, setting `product_master_id = 1` requires updating the table or setting it explicitly if desired.
- `CrmProductController::payload` (lines 952–960) accepts camelCase inputs:
  - `name` -> `name`
  - `sku` -> `sku`
  - `category` -> `category`
  - `glAccount` -> `gl_account`
  - `description` -> `description`
  - `unitPrice` -> `unit_price`
  - `costPrice` -> `cost_price`
  - `usageUnit` -> `usage_unit`
  - `qtyInStock` -> `qty_in_stock`
  - `reorderLevel` -> `reorder_level`
  - `taxRateId` -> `tax_rate_id`
  - `productMasterId` -> `product_master_id`

### 1.3 Current State of Price Books in `crm_price_books` and `crm_price_book_items`
- Querying `crm_price_books` and `crm_price_book_items` for Tenant 6 (`sub_institute_id = 6`):
  - `SELECT COUNT(*) FROM crm_price_books WHERE sub_institute_id = 6` returned **0**.
  - `SELECT COUNT(*) FROM crm_price_book_items` returned **0**.
- `Tenant6CrmLifecycleSeeder.php` currently has **zero references** to `crm_price_books` or `crm_price_book_items`.
- Table `crm_price_books` schema:
  - `id` (bigint unsigned PK)
  - `price_book_no` (varchar(191) UNIQUE, required)
  - `name` (varchar(191), required)
  - `currency` (varchar(191), nullable)
  - `is_active` (tinyint(1), default 1)
  - `description` (text, nullable)
  - `assigned_to` (bigint unsigned, nullable)
  - `created_by` (bigint unsigned, nullable)
  - `updated_by` (bigint unsigned, nullable)
  - `deleted_by` (bigint unsigned, nullable)
  - `sub_institute_id` (bigint unsigned, required)
  - `created_at`, `updated_at`, `deleted_at`
- Table `crm_price_book_items` schema:
  - `id` (bigint unsigned PK)
  - `price_book_id` (bigint unsigned FK -> `crm_price_books.id`, required)
  - `product_id` (bigint unsigned FK -> `crm_products.id`, required)
  - `list_price` (decimal(12,2), required)
  - `created_at`, `updated_at`
  - Unique index: `(price_book_id, product_id)`
- In `CrmQuoteController::store` (line 139):
  - Accepts `'priceBookId' => 'nullable|integer'`
  - Validates `'priceBookId'` against `crm_price_books` where `sub_institute_id = 6`.

### 1.4 Current State of Marketing Campaigns in `crm_campaigns`
- Querying `crm_campaigns` for Tenant 6 (`sub_institute_id = 6`) returned **1 row**:
  - ID 12: `Gujarat K-12 Digital Transformation 2026` (`CMP-GUJ-C8D25`)
  - `campaign_type`: `'Conference'`
  - `campaign_status`: `'Active'`
  - `expected_revenue`: `1500000.00`
  - `budget_cost`: `100000.00`
  - `actual_cost`: `75000.00`
  - `expected_response`: `'High'`
  - `num_sent`: `NULL`
  - `sponsor`: `NULL`
  - `target_audience`: `NULL`
  - `target_size`: `NULL`
  - `expected_response_count`: `NULL`
  - `expected_sales_count`: `NULL`
  - `actual_response_count`: `NULL`
  - `actual_sales_count`: `NULL`
  - `expected_roi`: `NULL`
  - `actual_roi`: `NULL`
  - `closing_date`: `NULL`
  - `product_id`: `NULL`
  - `description`: `NULL`
- In `Tenant6CrmLifecycleSeeder.php` lines 277–310:
  - `ensureCampaign()` creates only 1 campaign.
  - If existing campaign ID 12 is found, it does not update the NULL fields.
  - Returns a single `int $id`.

---

## 2. Logic Chain

1. **Safe Column Checking (`ColumnExists::has`)**:
   - `Schema::hasColumn()` selects `generation_expression` from `information_schema.columns`.
   - On MariaDB 10.1 (such as secondary replica servers or local instances), this throws `SQLSTATE[42S22]: Unknown column 'generation_expression'`.
   - Replacing `use Illuminate\Support\Facades\Schema;` with `use App\Support\ColumnExists;` and calling `ColumnExists::has($table, $column, $conn)` guarantees 100% MariaDB 10.1 and MariaDB 10.11 compatibility.
   - Any column check (such as verifying `product_master_id` on `crm_products`, `crm_campaigns`, `crm_opportunities`, or `crm_leads`) must pass `$conn` to `ColumnExists::has()`.

2. **ScholarClone 9-Item Suite Completeness**:
   - Requirement R2.1 mandates 9 items: ERP, Core SIS, Fees, LMS, Attendance, Exam, Bus tracking, Implementation, and Training.
   - 7 items are software products (`itemType => 'product'`) and 2 items are professional services (`itemType => 'service'`: Implementation and Training).
   - Currently, only 3 products exist in `crm_products` (rows 63, 64, 65), and all 3 have `description = null` and `usage_unit = null`.
   - To satisfy R1 ("not leaving fields empty or modules looking empty"), the seeder must:
     a) Update rows 63, 64, 65 with authentic descriptions, usage units, categories, GL accounts, stock quantities, and reorder levels.
     b) Seed the remaining 6 items (LMS, Attendance, Exam, Bus tracking, Implementation, Training) with full attributes.
     c) Bind all items to ScholarClone (`product_master_id = 1`) and invoke `ProductMasterSync::throughCatalogueItems(self::TENANT_ID, [$id], ProductMasterSync::SAVED, $userId)`.

3. **Price Books & Items Implementation (`ensurePriceBooks`)**:
   - Requirement R2.2 mandates 3 price books: CBSE, GSEB, and International schools, fully populated with price book line items.
   - Currently, 0 rows exist in `crm_price_books` and `crm_price_book_items`.
   - Creating 3 price books (`CBSE Affiliated Schools Price Book`, `GSEB Gujarat State Board Price Book`, `International & Cambridge Board Price Book`) with segment-specific pricing overrides across all 9 SKUs creates exactly 27 line items in `crm_price_book_items`.
   - Idempotency is preserved by using `updateOrInsert` on `(price_book_id, product_id)` and checking `price_book_no` / `name` on `crm_price_books`.
   - Returning `[$pbKey => $pbId]` enables Quotes in subsequent lifecycle steps to bind to `$priceBookIds['CBSE']`.

4. **Marketing Campaigns Completeness (`ensureCampaigns`)**:
   - Requirement R2.3 mandates 2-3 Gujarat marketing campaigns with budget, expected revenue, actual cost, type, status, description, target audience, expected ROI.
   - Currently, only 1 campaign exists (ID 12), and all metric fields (`description`, `target_audience`, `sponsor`, `closing_date`, `expected_roi`, `actual_roi`, `response counts`) are `NULL`.
   - The seeder should define 3 distinct campaigns:
     1. Flagship Conference: `Gujarat K-12 Digital Transformation 2026` (tied to ERP)
     2. EdTech Summit / Webinar: `Mission Vidya Gujarat EdTech Summit 2026` (tied to LMS)
     3. Safety Roadshow / Direct Outreach: `Gujarat Safe Campus & GPS Transit Initiative` (tied to Bus Tracking)
   - If ID 12 exists, update its NULL fields so it is fully populated; insert campaigns 2 and 3 if missing.
   - Returning `['flagship' => $c1, 'summit' => $c2, 'transit' => $c3]` preserves compatibility with downstream lead assignments.

---

## 3. Caveats

1. **Read-Only Scope**:
   In strict accordance with the explorer role guidelines, no source code files (`Tenant6CrmLifecycleSeeder.php`, etc.) have been edited or modified during this investigation.
2. **CrmProductController Service Handling**:
   `CrmProductController::store` automatically zeroes out `vendor`, `qty_in_stock`, `reorder_level`, and sets `product_master_id = null` when `itemType === 'service'`. Therefore, for services (Implementation and Training), the seeder should pass `qtyInStock => null` and `reorderLevel => null` to the controller, and then update `product_master_id = 1` in `crm_products` via direct DB query if catalog binding is desired.
3. **Price Book Currency Validation**:
   In `crm_price_books`, the `currency` column is `varchar(191)` (nullable), and in `crm_price_book_items`, `list_price` is `decimal(12,2)`. Both adhere cleanly to INR monetary values.
4. **Existing Campaign ID 12 Preservation**:
   Campaign ID 12 is already referenced by existing leads in `crm_leads`. The seeder must update ID 12 in place rather than deleting or creating a duplicate record, preserving foreign key integrity with existing leads.

---

## 4. Conclusion & Precise Code Implementation Strategy

Below are the exact code snippets and architectural specifications recommended for the Worker agent to implement in `database/seeders/Tenant6CrmLifecycleSeeder.php`.

### 4.1 Step 1: Replace Schema Import with ColumnExists

In `database/seeders/Tenant6CrmLifecycleSeeder.php`, lines 19–22:

**Before:**
```php
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
```

**After:**
```php
use App\Support\ColumnExists;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
```

Ensure zero occurrences of `Schema::hasColumn()` or `use Illuminate\Support\Facades\Schema;` across the entire file. When checking column existence, use:
```php
ColumnExists::has($tableName, $columnName, $connectionName)
```

---

### 4.2 Step 2: Implement Complete 9-Item ScholarClone Suite in `ensureScholarCloneProducts()`

Replace lines 198–275 of `database/seeders/Tenant6CrmLifecycleSeeder.php` with the following comprehensive method:

```php
    /**
     * Seeds and fully enriches the complete 9-item ScholarClone suite (7 products + 2 services)
     * bound to Product Master ID 1 (ScholarClone) for Tenant 6.
     *
     * Enriches existing records (rows 63, 64, 65) that have null descriptions/usage units,
     * and seeds any missing items.
     *
     * @return array<string, int> SKU => Product ID map
     */
    private function ensureScholarCloneProducts(string $bearer, string $conn, int $userId, int $taxRateId): array
    {
        $skus = [
            [
                'name' => 'Scholar One ERP - Gujarat Edition',
                'sku' => 'SCH-01-GUJ',
                'itemType' => 'product',
                'unitPrice' => 120000.00,
                'costPrice' => 60000.00,
                'category' => 'School ERP',
                'glAccount' => '4000-SALES-GUJ',
                'usageUnit' => 'Campus/Year',
                'description' => 'Comprehensive K-12 School ERP suite including student lifecycle, academics, administration, fee collection, staff payroll, and compliance reporting tailored for Gujarat educational standards.',
                'qtyInStock' => 50.00,
                'reorderLevel' => 10,
            ],
            [
                'name' => 'Scholar Core School Management',
                'sku' => 'SCH-CORE-GUJ',
                'itemType' => 'product',
                'unitPrice' => 75000.00,
                'costPrice' => 35000.00,
                'category' => 'School ERP',
                'glAccount' => '4000-SALES-CORE',
                'usageUnit' => 'Campus/Year',
                'description' => 'Core Student Information System (SIS) managing admissions, student demographics, enrollment, section allocations, and parent portal communications.',
                'qtyInStock' => 100.00,
                'reorderLevel' => 20,
            ],
            [
                'name' => 'Scholar CCB Fee Collection System',
                'sku' => 'SCH-CCB-GUJ',
                'itemType' => 'product',
                'unitPrice' => 35000.00,
                'costPrice' => 15000.00,
                'category' => 'Fees Intelligence',
                'glAccount' => '4000-SALES-FEES',
                'usageUnit' => 'Campus/Year',
                'description' => 'End-to-end fee management, fee structure customization, online payment gateway reconciliation, automated overdue SMS reminders, and real-time defaulter tracking.',
                'qtyInStock' => 100.00,
                'reorderLevel' => 20,
            ],
            [
                'name' => 'Scholar LMS & Digital Classroom',
                'sku' => 'SCH-LMS-GUJ',
                'itemType' => 'product',
                'unitPrice' => 45000.00,
                'costPrice' => 20000.00,
                'category' => 'Digital Learning',
                'glAccount' => '4000-SALES-LMS',
                'usageUnit' => 'Student/Year',
                'description' => 'Interactive Learning Management System supporting digital content distribution, assignment submissions, recorded video lectures, and live interactive classes.',
                'qtyInStock' => 200.00,
                'reorderLevel' => 50,
            ],
            [
                'name' => 'Scholar Smart Attendance & Biometric Access',
                'sku' => 'SCH-ATT-GUJ',
                'itemType' => 'product',
                'unitPrice' => 30000.00,
                'costPrice' => 12000.00,
                'category' => 'Student Safety',
                'glAccount' => '4000-SALES-ATT',
                'usageUnit' => 'Campus/Year',
                'description' => 'Automated RFID and biometric student and staff attendance system with real-time push alerts, leave workflows, and Gujarat Shiksha Setu compliance integration.',
                'qtyInStock' => 75.00,
                'reorderLevel' => 15,
            ],
            [
                'name' => 'Scholar Examination & CCE Report Engine',
                'sku' => 'SCH-EXAM-GUJ',
                'itemType' => 'product',
                'unitPrice' => 40000.00,
                'costPrice' => 18000.00,
                'category' => 'Academic Evaluation',
                'glAccount' => '4000-SALES-EXAM',
                'usageUnit' => 'Campus/Year',
                'description' => 'Comprehensive examination scheduler, hall ticket generator, continuous comprehensive evaluation (CCE) grading, mark entry, and dynamic report card publishing.',
                'qtyInStock' => 100.00,
                'reorderLevel' => 20,
            ],
            [
                'name' => 'Scholar Fleet GPS Bus Tracking System',
                'sku' => 'SCH-BUS-GUJ',
                'itemType' => 'product',
                'unitPrice' => 50000.00,
                'costPrice' => 25000.00,
                'category' => 'Transport Management',
                'glAccount' => '4000-SALES-TRANS',
                'usageUnit' => 'Vehicle/Year',
                'description' => 'Real-time IoT bus tracking, route planning, speed alerts, geo-fenced safe pickup/drop notifications, and emergency SOS alerts for parents and transport managers.',
                'qtyInStock' => 40.00,
                'reorderLevel' => 10,
            ],
            [
                'name' => 'ScholarClone On-Premise & Cloud Implementation',
                'sku' => 'SCH-SRV-IMP',
                'itemType' => 'service',
                'unitPrice' => 25000.00,
                'costPrice' => 10000.00,
                'category' => 'Implementation',
                'glAccount' => '4000-SERV-IMP',
                'usageUnit' => 'One-Time Deployment',
                'description' => 'Professional turnkey onboarding service: database provisioning, legacy data cleaning & migration, institutional hierarchy configuration, and administrator hand-holding.',
                'qtyInStock' => null,
                'reorderLevel' => null,
            ],
            [
                'name' => 'ScholarClone Faculty & Staff Certification Training',
                'sku' => 'SCH-SRV-TRN',
                'itemType' => 'service',
                'unitPrice' => 15000.00,
                'costPrice' => 5000.00,
                'category' => 'Training',
                'glAccount' => '4000-SERV-TRN',
                'usageUnit' => 'Session/Year',
                'description' => 'Dedicated on-site and remote training workshops for teaching staff, accountants, and administrators with official ScholarClone system proficiency certification.',
                'qtyInStock' => null,
                'reorderLevel' => null,
            ],
        ];

        $prodController = app(CrmProductController::class);
        $productIds = [];

        foreach ($skus as $skuData) {
            $existing = DB::connection($conn)->table('crm_products')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('sku', $skuData['sku'])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                // Enrich existing records to ensure zero empty fields
                $updateData = [
                    'name' => $skuData['name'],
                    'category' => $skuData['category'],
                    'gl_account' => $skuData['glAccount'],
                    'description' => $skuData['description'],
                    'unit_price' => $skuData['unitPrice'],
                    'cost_price' => $skuData['costPrice'],
                    'usage_unit' => $skuData['usageUnit'],
                    'currency' => 'INR',
                    'tax_rate_id' => $taxRateId,
                    'is_active' => 1,
                    'assigned_to' => $userId,
                    'updated_at' => now(),
                ];

                if ($skuData['itemType'] === 'product') {
                    $updateData['qty_in_stock'] = $skuData['qtyInStock'];
                    $updateData['reorder_level'] = $skuData['reorderLevel'];
                }

                if (ColumnExists::has('crm_products', 'product_master_id', $conn)) {
                    $updateData['product_master_id'] = self::SCHOLARCLONE_PRODUCT_MASTER_ID;
                }

                DB::connection($conn)->table('crm_products')
                    ->where('id', $existing->id)
                    ->update($updateData);

                ProductMasterSync::throughCatalogueItems(self::TENANT_ID, [(int) $existing->id], ProductMasterSync::SAVED, $userId);

                $productIds[$skuData['sku']] = (int) $existing->id;
                $this->command?->info("Updated and verified SKU '{$skuData['sku']}' (ID: {$existing->id}).");
                continue;
            }

            // Create via CrmProductController::store
            $payload = [
                'itemType' => $skuData['itemType'],
                'name' => $skuData['name'],
                'sku' => $skuData['sku'],
                'unitPrice' => $skuData['unitPrice'],
                'costPrice' => $skuData['costPrice'],
                'category' => $skuData['category'],
                'glAccount' => $skuData['glAccount'],
                'usageUnit' => $skuData['usageUnit'],
                'description' => $skuData['description'],
                'currency' => 'INR',
                'taxRateId' => $taxRateId,
                'assignedTo' => $userId,
            ];

            if ($skuData['itemType'] === 'product') {
                $payload['productMasterId'] = self::SCHOLARCLONE_PRODUCT_MASTER_ID;
                $payload['qtyInStock'] = $skuData['qtyInStock'];
                $payload['reorderLevel'] = $skuData['reorderLevel'];
            }

            $res = $prodController->store($this->request($bearer, 'POST', $payload));

            if ($res->getStatusCode() === 201) {
                $id = (int) json_decode($res->getContent(), true)['data']['id'];

                // Ensure product_master_id is bound for services as well
                if (ColumnExists::has('crm_products', 'product_master_id', $conn)) {
                    DB::connection($conn)->table('crm_products')
                        ->where('id', $id)
                        ->update(['product_master_id' => self::SCHOLARCLONE_PRODUCT_MASTER_ID]);
                }

                ProductMasterSync::throughCatalogueItems(self::TENANT_ID, [$id], ProductMasterSync::SAVED, $userId);

                $productIds[$skuData['sku']] = $id;
                $this->command?->info("Created SKU '{$skuData['sku']}' via CrmProductController (ID: {$id}).");
            } else {
                $this->command?->warn("Failed creating SKU '{$skuData['sku']}': " . $res->getContent());
            }
        }

        return $productIds;
    }
```

---

### 4.3 Step 3: Implement `ensurePriceBooks()` with 3 Price Books and 27 Line Item Overrides

Add this new method to `database/seeders/Tenant6CrmLifecycleSeeder.php`:

```php
    /**
     * Seeds 3 price books (CBSE, GSEB, International) and establishes line item
     * pricing overrides for all 9 ScholarClone SKUs in crm_price_book_items.
     *
     * @param  array<string, int>  $productIds  SKU => Product ID mapping
     * @return array<string, int>  Book Key => Price Book ID mapping
     */
    private function ensurePriceBooks(string $conn, int $userId, array $productIds): array
    {
        $books = [
            'CBSE' => [
                'price_book_no' => 'PB-CBSE-GUJ',
                'name' => 'CBSE Affiliated Schools Price Book',
                'currency' => 'INR',
                'description' => 'Standard institutional pricing matrix for CBSE affiliated K-12 private and trust schools across Gujarat with integrated CCE reporting.',
                'items' => [
                    'SCH-01-GUJ'   => 120000.00, // ERP Gujarat Edition
                    'SCH-CORE-GUJ' => 75000.00,  // Core SIS
                    'SCH-CCB-GUJ'  => 35000.00,  // CCB Fee Collection
                    'SCH-LMS-GUJ'  => 45000.00,  // LMS & Digital Classroom
                    'SCH-ATT-GUJ'  => 30000.00,  // Smart Attendance
                    'SCH-EXAM-GUJ' => 40000.00,  // Exam & CCE Engine
                    'SCH-BUS-GUJ'  => 50000.00,  // Fleet GPS Bus Tracking
                    'SCH-SRV-IMP'  => 25000.00,  // Implementation Service
                    'SCH-SRV-TRN'  => 15000.00,  // Training Service
                ],
            ],
            'GSEB' => [
                'price_book_no' => 'PB-GSEB-GUJ',
                'name' => 'GSEB Gujarat State Board Price Book',
                'currency' => 'INR',
                'description' => 'Subsidized and volume pricing matrix for Gujarat State Board (GSEB) Gujarati & English medium schools with Shiksha Setu compliance.',
                'items' => [
                    'SCH-01-GUJ'   => 95000.00,  // ~20% concession
                    'SCH-CORE-GUJ' => 60000.00,
                    'SCH-CCB-GUJ'  => 28000.00,
                    'SCH-LMS-GUJ'  => 36000.00,
                    'SCH-ATT-GUJ'  => 24000.00,
                    'SCH-EXAM-GUJ' => 32000.00,
                    'SCH-BUS-GUJ'  => 42000.00,
                    'SCH-SRV-IMP'  => 20000.00,
                    'SCH-SRV-TRN'  => 12000.00,
                ],
            ],
            'International' => [
                'price_book_no' => 'PB-INTL-GUJ',
                'name' => 'International & Cambridge Board Price Book',
                'currency' => 'INR',
                'description' => 'Premium institutional pricing tier for International Baccalaureate (IB) and Cambridge (CIE) schools in Ahmedabad, Surat, and Vadodara.',
                'items' => [
                    'SCH-01-GUJ'   => 160000.00, // Premium tier
                    'SCH-CORE-GUJ' => 95000.00,
                    'SCH-CCB-GUJ'  => 45000.00,
                    'SCH-LMS-GUJ'  => 60000.00,
                    'SCH-ATT-GUJ'  => 40000.00,
                    'SCH-EXAM-GUJ' => 52000.00,
                    'SCH-BUS-GUJ'  => 65000.00,
                    'SCH-SRV-IMP'  => 35000.00,
                    'SCH-SRV-TRN'  => 22000.00,
                ],
            ],
        ];

        $priceBookIds = [];

        foreach ($books as $key => $def) {
            $pb = DB::connection($conn)->table('crm_price_books')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where(function ($q) use ($def) {
                    $q->where('price_book_no', $def['price_book_no'])
                      ->orWhere('name', $def['name']);
                })
                ->whereNull('deleted_at')
                ->first();

            if ($pb) {
                $pbId = (int) $pb->id;
                DB::connection($conn)->table('crm_price_books')
                    ->where('id', $pbId)
                    ->update([
                        'name' => $def['name'],
                        'price_book_no' => $def['price_book_no'],
                        'currency' => $def['currency'],
                        'description' => $def['description'],
                        'is_active' => 1,
                        'assigned_to' => $userId,
                        'updated_at' => now(),
                    ]);
                $this->command?->info("Updated Price Book '{$def['name']}' (ID: {$pbId}).");
            } else {
                $pbId = DB::connection($conn)->table('crm_price_books')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'price_book_no' => $def['price_book_no'],
                    'name' => $def['name'],
                    'currency' => $def['currency'],
                    'is_active' => 1,
                    'description' => $def['description'],
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->command?->info("Created Price Book '{$def['name']}' (ID: {$pbId}).");
            }

            $priceBookIds[$key] = $pbId;

            // Seed price book line items for all products
            $itemCount = 0;
            foreach ($def['items'] as $sku => $overridePrice) {
                if (!isset($productIds[$sku])) {
                    continue;
                }

                $productId = $productIds[$sku];
                DB::connection($conn)->table('crm_price_book_items')->updateOrInsert(
                    [
                        'price_book_id' => $pbId,
                        'product_id' => $productId,
                    ],
                    [
                        'list_price' => $overridePrice,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $itemCount++;
            }

            $this->command?->info("Populated {$itemCount} price book item overrides for '{$def['name']}'.");
        }

        return $priceBookIds;
    }
```

---

### 4.4 Step 4: Implement `ensureCampaigns()` with 3 Authentic Gujarat Campaigns

Replace lines 277–310 of `database/seeders/Tenant6CrmLifecycleSeeder.php` with the following comprehensive method:

```php
    /**
     * Seeds 3 targeted Gujarat marketing campaigns with complete budget, expected revenue,
     * actual cost, ROI, and audience metrics, binding them to ScholarClone (Product Master ID 1).
     *
     * Enriches existing campaign #12 in place to populate all previously null fields.
     *
     * @param  array<string, int>  $productIds  SKU => Product ID mapping
     * @return array<string, int>  Campaign Key => Campaign ID mapping
     */
    private function ensureCampaigns(string $conn, int $userId, array $productIds): array
    {
        $campaigns = [
            'flagship' => [
                'campaign_no' => 'CMP-GUJ-DIGI26',
                'name' => 'Gujarat K-12 Digital Transformation 2026',
                'campaign_type' => 'Conference',
                'campaign_status' => 'Active',
                'expected_revenue' => 2500000.00,
                'budget_cost' => 150000.00,
                'actual_cost' => 112500.00,
                'expected_response' => 'Excellent',
                'num_sent' => 500,
                'sponsor' => 'Gujarat Secondary & Higher Secondary Education Board (GSEB) Partner Network',
                'target_audience' => 'Trustees, Principals, and IT Directors of CBSE & GSEB Private Schools across Ahmedabad, Surat, Vadodara, and Rajkot',
                'target_size' => 1200,
                'expected_response_count' => 120,
                'expected_sales_count' => 25,
                'actual_response_count' => 98,
                'actual_sales_count' => 18,
                'expected_roi' => 1566.67,
                'actual_roi' => 1240.00,
                'closing_date' => '2026-12-31',
                'sku' => 'SCH-01-GUJ',
                'description' => 'Statewide conference and roadshow demonstrating Scholar One ERP and Core SIS to K-12 school managements, focusing on automated fee collection, biometric attendance, and state compliance.',
            ],
            'summit' => [
                'campaign_no' => 'CMP-GUJ-VIDYA26',
                'name' => 'Mission Vidya Gujarat EdTech Summit 2026',
                'campaign_type' => 'Webinar',
                'campaign_status' => 'Active',
                'expected_revenue' => 1200000.00,
                'budget_cost' => 80000.00,
                'actual_cost' => 54000.00,
                'expected_response' => 'Good',
                'num_sent' => 850,
                'sponsor' => 'ScholarClone Academic Innovations Desk',
                'target_audience' => 'Academic Directors, Exam Controllers, and Tech Coordinators of Gujarat English & Gujarati Medium Higher Secondary Schools',
                'target_size' => 2000,
                'expected_response_count' => 210,
                'expected_sales_count' => 15,
                'actual_response_count' => 165,
                'actual_sales_count' => 12,
                'expected_roi' => 1400.00,
                'actual_roi' => 1100.00,
                'closing_date' => '2026-11-30',
                'sku' => 'SCH-LMS-GUJ',
                'description' => 'Virtual summit presenting Scholar LMS and Smart Attendance solutions aligned with National Education Policy (NEP 2020) and Gujarat Vidya Samiksha Kendra analytics.',
            ],
            'transit' => [
                'campaign_no' => 'CMP-GUJ-TRANS26',
                'name' => 'Gujarat Safe Campus & GPS Transit Initiative',
                'campaign_type' => 'Direct Mail',
                'campaign_status' => 'Planning',
                'expected_revenue' => 900000.00,
                'budget_cost' => 60000.00,
                'actual_cost' => 38000.00,
                'expected_response' => 'Good',
                'num_sent' => 400,
                'sponsor' => 'Gujarat School Safety and Student Welfare Forum',
                'target_audience' => 'School Transport Incharges, Facility Managers, and School Trust Boards managing multi-bus fleets',
                'target_size' => 800,
                'expected_response_count' => 85,
                'expected_sales_count' => 10,
                'actual_response_count' => 62,
                'actual_sales_count' => 8,
                'expected_roi' => 1400.00,
                'actual_roi' => 950.00,
                'closing_date' => '2027-01-31',
                'sku' => 'SCH-BUS-GUJ',
                'description' => 'Targeted outreach campaign focused on child safety, automated RFID attendance, and real-time GPS fleet tracking compliance under RTO Gujarat school bus guidelines.',
            ],
        ];

        $campaignIds = [];

        foreach ($campaigns as $key => $camp) {
            $existing = DB::connection($conn)->table('crm_campaigns')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where(function ($q) use ($camp) {
                    $q->where('name', $camp['name'])
                      ->orWhere('campaign_no', $camp['campaign_no']);
                })
                ->whereNull('deleted_at')
                ->first();

            $linkedProductId = $productIds[$camp['sku']] ?? null;

            if ($existing) {
                // Enrich existing campaign to populate all null fields
                $updateData = [
                    'name' => $camp['name'],
                    'campaign_type' => $camp['campaign_type'],
                    'campaign_status' => $camp['campaign_status'],
                    'expected_revenue' => $camp['expected_revenue'],
                    'budget_cost' => $camp['budget_cost'],
                    'actual_cost' => $camp['actual_cost'],
                    'expected_response' => $camp['expected_response'],
                    'num_sent' => $camp['num_sent'],
                    'sponsor' => $camp['sponsor'],
                    'target_audience' => $camp['target_audience'],
                    'target_size' => $camp['target_size'],
                    'expected_response_count' => $camp['expected_response_count'],
                    'expected_sales_count' => $camp['expected_sales_count'],
                    'actual_response_count' => $camp['actual_response_count'],
                    'actual_sales_count' => $camp['actual_sales_count'],
                    'expected_roi' => $camp['expected_roi'],
                    'actual_roi' => $camp['actual_roi'],
                    'closing_date' => $camp['closing_date'],
                    'product_id' => $linkedProductId,
                    'description' => $camp['description'],
                    'updated_at' => now(),
                ];

                if (ColumnExists::has('crm_campaigns', 'product_master_id', $conn)) {
                    $updateData['product_master_id'] = self::SCHOLARCLONE_PRODUCT_MASTER_ID;
                }

                DB::connection($conn)->table('crm_campaigns')
                    ->where('id', $existing->id)
                    ->update($updateData);

                $campaignIds[$key] = (int) $existing->id;
                $this->command?->info("Updated and enriched Campaign '{$camp['name']}' (ID: {$existing->id}).");
            } else {
                $insertData = [
                    'sub_institute_id' => self::TENANT_ID,
                    'campaign_no' => $camp['campaign_no'],
                    'name' => $camp['name'],
                    'campaign_type' => $camp['campaign_type'],
                    'campaign_status' => $camp['campaign_status'],
                    'expected_revenue' => $camp['expected_revenue'],
                    'budget_cost' => $camp['budget_cost'],
                    'actual_cost' => $camp['actual_cost'],
                    'expected_response' => $camp['expected_response'],
                    'num_sent' => $camp['num_sent'],
                    'sponsor' => $camp['sponsor'],
                    'target_audience' => $camp['target_audience'],
                    'target_size' => $camp['target_size'],
                    'expected_response_count' => $camp['expected_response_count'],
                    'expected_sales_count' => $camp['expected_sales_count'],
                    'actual_response_count' => $camp['actual_response_count'],
                    'actual_sales_count' => $camp['actual_sales_count'],
                    'expected_roi' => $camp['expected_roi'],
                    'actual_roi' => $camp['actual_roi'],
                    'closing_date' => $camp['closing_date'],
                    'product_id' => $linkedProductId,
                    'description' => $camp['description'],
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (ColumnExists::has('crm_campaigns', 'product_master_id', $conn)) {
                    $insertData['product_master_id'] = self::SCHOLARCLONE_PRODUCT_MASTER_ID;
                }

                $id = DB::connection($conn)->table('crm_campaigns')->insertGetId($insertData);
                $campaignIds[$key] = (int) $id;
                $this->command?->info("Created Campaign '{$camp['name']}' (ID: {$id}).");
            }
        }

        return $campaignIds;
    }
```

---

### 4.5 Step 5: Wire Orchestration in `run()`

In `database/seeders/Tenant6CrmLifecycleSeeder.php` lines 81–97, update the orchestration flow:

```php
            // 1. Setup Tenant Prerequisites (Tax Rate + Lead Scoring Rules)
            $taxRateId = $this->ensureTaxRate($connectionName);
            $this->ensureLeadScoringRules($connectionName);

            // 2. Setup ScholarClone Product Line SKUs (9 Items)
            $productIds = $this->ensureScholarCloneProducts($bearer, $connectionName, $userId, $taxRateId);

            // 3. Setup Price Books & Items (3 Price Books + 27 Line Item Overrides)
            $priceBookIds = $this->ensurePriceBooks($connectionName, $userId, $productIds);

            // 4. Setup Gujarat School Campaigns (3 Targeted Campaigns)
            $campaignIds = $this->ensureCampaigns($connectionName, $userId, $productIds);
            $primaryCampaignId = $campaignIds['flagship'] ?? array_values($campaignIds)[0];

            // 5. Source Gujarat Schools Data
            $schools = $this->sourceGujaratSchools($connectionName);
            $this->command?->info(sprintf('Loaded %d Gujarat schools for seeding.', count($schools)));

            // 6. Seed Leads & Drive Golden-Path Lifecycle for Gujarat Schools
            $this->seedGujaratSchoolsLifecycle(
                $bearer, $connectionName, $userId, $taxRateId, $productIds,
                $primaryCampaignId, $schools, $priceBookIds, $campaignIds
            );
```

And in `driveFullConversionAndBilling(...)`:
Pass `$priceBookIds['CBSE'] ?? null` as `'priceBookId'` when creating quotes in `CrmQuoteController::store`:
```php
'priceBookId' => $priceBookIds['CBSE'] ?? null,
```
This guarantees that generated Quotes and Price Books are fully connected!

---

## 5. Verification Method

To verify these implementations independently after the Worker applies them:

### 5.1 Syntax and Schema Check
Run PHP linter on `Tenant6CrmLifecycleSeeder.php`:
```powershell
php -l database/seeders/Tenant6CrmLifecycleSeeder.php
```
*Expected*: `No syntax errors detected in database/seeders/Tenant6CrmLifecycleSeeder.php`

Check for zero occurrences of `Schema` or `Schema::hasColumn`:
```powershell
Select-String -Path "database/seeders/Tenant6CrmLifecycleSeeder.php" -Pattern "Schema"
```
*Expected*: Zero occurrences.

### 5.2 Seeder Execution Check
Run the seeder directly:
```powershell
php artisan db:seed --class=Tenant6CrmLifecycleSeeder
```
*Expected*: Clean execution without PDOException, MariaDB column errors, or controller validation failures.

### 5.3 Database Data Integrity Checks
Verify 9 ScholarClone products in `crm_products`:
```powershell
php artisan tinker --execute="echo json_encode(DB::table('crm_products')->where('sub_institute_id', 6)->whereNotNull('sku')->select('id', 'name', 'sku', 'item_type', 'unit_price', 'usage_unit', 'description', 'product_master_id')->get(), JSON_PRETTY_PRINT);"
```
*Expected*: Exactly 9 rows with valid non-null `name`, `sku`, `unit_price`, `usage_unit`, `description`, and `product_master_id = 1`.

Verify 3 price books and 27 items in `crm_price_books` and `crm_price_book_items`:
```powershell
php artisan tinker --execute="echo 'Price Books: ' . DB::table('crm_price_books')->where('sub_institute_id', 6)->count() . ', Price Book Items: ' . DB::table('crm_price_book_items')->join('crm_price_books', 'crm_price_books.id', '=', 'crm_price_book_items.price_book_id')->where('crm_price_books.sub_institute_id', 6)->count();"
```
*Expected*: `Price Books: 3, Price Book Items: 27`.

Verify 3 campaigns in `crm_campaigns`:
```powershell
php artisan tinker --execute="echo json_encode(DB::table('crm_campaigns')->where('sub_institute_id', 6)->select('id', 'name', 'campaign_type', 'budget_cost', 'expected_revenue', 'actual_cost', 'expected_roi', 'target_audience', 'product_master_id')->get(), JSON_PRETTY_PRINT);"
```
*Expected*: Exactly 3 rows with complete, non-null values for all columns.
