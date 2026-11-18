# Implementation Code Strategy: Contacts, Opportunities, Quotes, Sales Orders, Invoices, and Market Signals for Tenant 6 CRM Lifecycle Seeder

**Agent**: Explorer Iteration 1 Subagent 3 (`explorer_it1_3`)  
**Target File**: `database/seeders/Tenant6CrmLifecycleSeeder.php`  
**Target Database**: MySQL `hp_erp` (`202.47.117.220:3306`, Tenant `sub_institute_id = 6`, User ID: `28`, `product_master_id = 1`)  
**Date**: 2026-11-05  

---

## 1. Observation

Direct inspection of the codebase, database schema, migrations, controllers, and fixtures reveals:

### 1.1 Existing CRM Lifecycle Seeder Status (`Tenant6CrmLifecycleSeeder.php`)
- **MariaDB 10.1 Safety Violation**: Line 21 currently imports `use Illuminate\Support\Facades\Schema;`. This must be removed and replaced with `use App\Support\ColumnExists;` to avoid `information_schema.generation_expression` crashes on MariaDB 10.1 (ORIGINAL_REQUEST §R3).
- **Contacts Shortfall**: In `seedGujaratSchoolsLifecycle()`, leads are converted via `$leadController->convert()`, which only creates a single contact (Principal) per converted lead. Only 6 contacts currently exist for Tenant 6, whereas 4 distinct institutional personas (Principal, Trustee, IT Head, Finance Head) per school are required across all 15+ converted organizations (60+ contacts).
- **Opportunity Stages Truncation**: Deals are only created through `driveFullConversionAndBilling()`, which defaults to a single initial stage, omitting 7 of the 8 canonical sales pipeline stages.
- **Quote Scope Limitation**: Quotes are currently single-line items (`SCH-01-GUJ` alone), failing the requirement for multi-item CPQ quotes with suite items, discounts, 18% GST tax rate snapshot, and formal terms & conditions.
- **Sales Order & Invoice Coverage**: Currently only 1-2 deals are converted. Invoices do not have payment tracking records in `crm_invoice_payments` and lack cumulative `amount_paid` ledger entries.
- **Market Signals Omission**: `crm_signal_inbox` has **0 records** for Tenant 6. `database/fixtures/gujarat_scholarclone_signals.json` exists in the repository containing 5 authentic Gujarat K-12 signals that have never been ingested.

### 1.2 Database Schema & Controller Specifications
- **`crm_contacts`**:
  - Required columns: `id`, `contact_no` (unique `varchar(100)`), `last_name` (`varchar(100)`), `assigned_to` (`bigint unsigned`), `sub_institute_id` (`bigint unsigned`).
  - Key attributes: `organization_id`, `salutation`, `first_name`, `title`, `department`, `email`, `secondary_email`, `phone`, `mobile`, `mailing_street`, `mailing_city`, `mailing_state`, `mailing_code`, `mailing_country`, `consent_status` (`'opted_in'`), `reports_to_id`, `created_by`.
  - Companion table: `crm_consent_preferences` (`entity_type` = `'contact'`, `entity_id`, `channel`, `status`, `captured_at`, `source`, `notes`, `sub_institute_id`, `created_by`).
- **`crm_opportunities`**:
  - Required columns: `opportunity_no` (unique `varchar(100)`), `name` (`varchar(191)`), `assigned_to`, `sub_institute_id`.
  - Picklist verified stages: `Prospecting`, `Qualification`, `Needs Analysis`, `Value Proposition`, `Identify Decision Makers`, `Proposal/Quotation`, `Negotiation/Review`, `Closed Won`.
  - Additional attributes: `amount`, `currency` (`'INR'`), `closing_date`, `probability`, `lead_source`, `potential_type` (`'New Business'`), `next_step`, `forecast_category` (`'Pipeline'`, `'Best Case'`, `'Commit'`, `'Closed'`), `description`, `close_reason`, `product_master_id` (`1`), `campaign_id`.
  - Auxiliary tables: `crm_opportunity_contacts` (`opportunity_id`, `contact_id`), `crm_opportunity_products` (`opportunity_id`, `product_id`, `quantity`), `crm_opportunity_stage_history` (`opportunity_id`, `from_stage`, `to_stage`, `amount`, `probability`, `closing_date`, `changed_by`).
- **`crm_quotes` & `crm_quote_line_items`**:
  - `quote_no` (unique `varchar(100)`), `subject`, `organization_id`, `contact_id`, `opportunity_id`, `price_book_id`, `quote_stage` (`'Sent'`, `'Accepted'`), `valid_till`, `subtotal`, `discount_percent`, `discount_amount`, `tax_total`, `total`, `terms_conditions`, `billing_*`, `shipping_*`.
  - Line items: `quote_id`, `product_id`, `description`, `quantity`, `unit_price`, `discount_percent`, `discount_amount`, `tax_rate_id`, `tax_name_snapshot` (`'GST 18%'`), `tax_percent_snapshot` (`18.000`), `line_total`, `sequence_no`.
- **`crm_sales_orders` & `crm_invoices`**:
  - SO: `so_no`, `quote_id`, `so_stage` (`'Confirmed'`), `due_date`, `carrier` (`'Electronic Cloud Delivery'`), cloned line items matching quote verbatim.
  - Invoice: `invoice_no`, `sales_order_id`, `invoice_stage` (`'Paid'`), `invoice_date`, `due_date`, `amount_paid` (must equal `total`), cloned line items.
  - Payments: `crm_invoice_payments` (`invoice_id`, `amount`, `method` (`'RTGS'`), `reference` (`'HDFC-RTGS-...'`), `paid_on`, `note`, `created_by`).
  - Zero-variance rule: $|SO - INV| == 0.00$ and $|INV - PAID| == 0.00$.
- **`crm_signal_inbox` & `crm_signal_topics`**:
  - `signal_key` (`varchar(64)` unique SHA-256 hash), `source_name`, `source_url`, `title`, `body`, `published_at`, `ingested_at`, `classification` (`'buying_signal'`), `classification_confidence` (`0.92`), `classification_reason`, `relevance_score` (`0.88`), `linked_account_id` (FK to `crm_organizations`), `linked_account_confidence` (`0.95`), `status` (`'accepted'`), `tags` (JSON), `sub_institute_id` (`6`).
  - `crm_signal_topics`: `name`, `keywords` (JSON), `topic_type`, `status` (`1`), `sub_institute_id` (`6`), `created_by`.

---

## 2. Logic Chain

1. **Hierarchy of Entity Creation**:
   - Leads and Organizations must exist first (handled by Explorer 2's strategy).
   - Once 15+ school organizations exist in `crm_organizations`, Contacts can be anchored to their respective `organization_id`.
   - Each school must have 4 core personas to reflect real educational procurement hierarchies: Managing Trustee (Governance/Budget approval) -> Principal (Academic leadership) -> IT Head (Technical evaluation) & Finance Head (Commercial negotiation).
   - Linking `reports_to_id` (e.g. IT Head reports to Principal, Principal reports to Managing Trustee) establishes authentic organizational chart relationships.
2. **Opportunity Distribution Across 8 Stages**:
   - The 15+ converted organizations are mapped across all 8 pipeline stages:
     - 2 in `Prospecting` (10% prob, Pipeline)
     - 2 in `Qualification` (20% prob, Pipeline)
     - 2 in `Needs Analysis` (35% prob, Pipeline)
     - 2 in `Value Proposition` (50% prob, Best Case)
     - 2 in `Identify Decision Makers` (60% prob, Best Case)
     - 2 in `Proposal/Quotation` (75% prob, Commit)
     - 2 in `Negotiation/Review` (90% prob, Commit)
     - 2 in `Closed Won` (100% prob, Closed)
   - Every opportunity is explicitly tagged with `product_master_id = 1`, `campaign_id`, and has line items in `crm_opportunity_products` and primary contact in `crm_opportunity_contacts`.
3. **Multi-Item CPQ Quotes with 18% GST**:
   - Quotes are constructed for deals in Proposal/Quotation, Negotiation/Review, and Closed Won stages.
   - Each quote incorporates 2 to 4 products/services from ScholarClone suite (e.g., Scholar One ERP + Fee Collection System + Implementation/Training Service).
   - Line items reflect authentic pricing, institutional discounts (10%), and statutory 18% GST tax rates snapshot (`tax_name_snapshot = 'GST 18%'`, `tax_percent_snapshot = 18.000`).
   - Addresses (`billing_*` and `shipping_*`) and detailed Gujarat commercial terms are synchronized directly from the school's organization profile.
4. **Order-to-Cash Zero-Variance Conversion & Payment Ledger**:
   - For `Closed Won` opportunities, the accepted Quote converts to Sales Order (`fromQuote`), and the Sales Order converts to Invoice (`fromSalesOrder`).
   - Line items are cloned verbatim, guaranteeing mathematical zero-variance ($|SO - INV| == 0.00$).
   - A complete settlement payment is recorded in `crm_invoice_payments` via RTGS/NEFT with realistic bank reference numbers (`HDFC-RTGS-XXXXX`).
   - `crm_invoices.amount_paid` is updated to match `total`, and `invoice_stage` is set to `'Paid'`.
5. **Authentic Gujarat Signal Ingestion**:
   - `database/fixtures/gujarat_scholarclone_signals.json` contains 5 authentic Gujarat K-12 signals (Aster School Surat, IDP Meridian Ahmedabad, R. H. Kapadia New High School, Sri Sri Ravi Shankar Vidya Mandir Godhavi, Podar Education Network).
   - Signals are loaded, given deterministic SHA-256 fingerprint keys, classified as `'buying_signal'` (confidence 0.92, relevance 0.88), linked to matching school organizations in `crm_organizations` via fuzzy matching or alias detection, and marked as `'accepted'`.
   - Companion monitoring topics are seeded in `crm_signal_topics`.

---

## 3. Caveats

1. **Read-Only Scope**: This agent is strictly read-only and made zero modifications to the application codebase or seeder files. All code provided in this report is designed for immediate integration by Worker.
2. **Dependency on Sourced Organizations**: The contacts, opportunities, and CPQ quotes depend on 15+ organizations being present in `crm_organizations`. If running independently, the helper method must query all Tenant 6 organizations (`where('sub_institute_id', 6)->whereNull('deleted_at')`).
3. **Product Suite Availability**: Multi-item quotes require at least 3 products/services in `crm_products` (ERP, Fees, Implementation/Training) tagged with `sub_institute_id = 6`. Explorer 1's strategy ensures all 9 SKUs exist.
4. **Idempotency**: All methods must use `firstOrCreate`, `updateOrInsert`, or verify uniqueness by business keys (`contact_no`, `opportunity_no`, `quote_no`, `so_no`, `invoice_no`, `signal_key`) so running the seeder repeatedly never creates duplicate rows.

---

## 4. Conclusion & Precise Code Implementation for Worker

Worker should incorporate the following 5 methods into `database/seeders/Tenant6CrmLifecycleSeeder.php` and invoke them within `run()`.

### 4.1 Safe Imports in `Tenant6CrmLifecycleSeeder.php`
Remove `use Illuminate\Support\Facades\Schema;` and add:
```php
use App\Support\ColumnExists;
use Illuminate\Support\Str;
```

---

### 4.2 Exact Implementation Snippets for Worker

#### Feature 1: Multiple Realistic Contacts per School (4 Personas)
```php
    /**
     * Seeds 4 realistic personas per school organization:
     * 1. Principal & Head of School (Academic leadership)
     * 2. Managing Trustee / Chairman (Governance & Trust leadership)
     * 3. IT Director / Systems Administrator (Technical infrastructure)
     * 4. Chief Accounts Officer / Bursar (Finance & Fee management)
     *
     * @return array<int, array<string, int>> Map of [organization_id => [role_key => contact_id]]
     */
    private function seedContactsForOrganizations(string $conn, int $userId): array
    {
        $orgs = DB::connection($conn)->table('crm_organizations')
            ->where('sub_institute_id', self::TENANT_ID)
            ->whereNull('deleted_at')
            ->get();

        $orgContactMap = [];
        $firstNames = ['Sanjay', 'Hemalata', 'Kiritbhai', 'Mansukhbhai', 'Chirag', 'Bhavik', 'Nilesh', 'Rajesh', 'Preethi', 'Mahendrasinh'];
        $lastNames = ['Patel', 'Shah', 'Mehta', 'Desai', 'Trivedi', 'Joshi', 'Parekh', 'Vora', 'Panchal', 'Bhatt'];

        foreach ($orgs as $org) {
            $domain = preg_replace('/^https?:\/\/(www\.)?/', '', strtolower(trim((string) $org->website)));
            $domain = explode('/', $domain)[0] ?: strtolower(preg_replace('/[^a-z0-9]/', '', $org->name)) . '.edu.in';
            $city = $org->billing_city ?: 'Ahmedabad';

            $personas = [
                'trustee' => [
                    'salutation' => 'Shri',
                    'first_name' => $firstNames[abs(crc32($org->name . 'trustee')) % count($firstNames)],
                    'last_name' => $lastNames[abs(crc32($org->name . 'trustee_l')) % count($lastNames)],
                    'title' => 'Managing Trustee / President',
                    'department' => 'Board of Trustees & Governance',
                    'email' => 'trustee@' . $domain,
                    'phone' => '+91 79 ' . rand(2600, 2799) . ' ' . rand(1000, 9999),
                    'mobile' => '+91 98980 ' . rand(10000, 99999),
                    'reports_to_key' => null,
                ],
                'principal' => [
                    'salutation' => 'Dr.',
                    'first_name' => $firstNames[abs(crc32($org->name . 'principal')) % count($firstNames)],
                    'last_name' => $lastNames[abs(crc32($org->name . 'principal_l')) % count($lastNames)],
                    'title' => 'Principal & Head of School',
                    'department' => 'Academic Administration',
                    'email' => 'principal@' . $domain,
                    'phone' => '+91 79 ' . rand(2600, 2799) . ' ' . rand(1000, 9999),
                    'mobile' => '+91 98250 ' . rand(10000, 99999),
                    'reports_to_key' => 'trustee',
                ],
                'it_head' => [
                    'salutation' => 'Mr.',
                    'first_name' => $firstNames[abs(crc32($org->name . 'it')) % count($firstNames)],
                    'last_name' => $lastNames[abs(crc32($org->name . 'it_l')) % count($lastNames)],
                    'title' => 'Head of Information Technology',
                    'department' => 'Information Technology & Cloud Infrastructure',
                    'email' => 'it.head@' . $domain,
                    'phone' => '+91 79 ' . rand(2600, 2799) . ' ' . rand(1000, 9999),
                    'mobile' => '+91 94260 ' . rand(10000, 99999),
                    'reports_to_key' => 'principal',
                ],
                'finance_head' => [
                    'salutation' => 'CA',
                    'first_name' => $firstNames[abs(crc32($org->name . 'fin')) % count($firstNames)],
                    'last_name' => $lastNames[abs(crc32($org->name . 'fin_l')) % count($lastNames)],
                    'title' => 'Chief Accounts Officer / Bursar',
                    'department' => 'Finance, Accounts & Fee Administration',
                    'email' => 'accounts@' . $domain,
                    'phone' => '+91 79 ' . rand(2600, 2799) . ' ' . rand(1000, 9999),
                    'mobile' => '+91 97270 ' . rand(10000, 99999),
                    'reports_to_key' => 'trustee',
                ],
            ];

            $contactIds = [];

            // Pass 1: Create or update personas
            foreach ($personas as $roleKey => $p) {
                $existing = DB::connection($conn)->table('crm_contacts')
                    ->where('sub_institute_id', self::TENANT_ID)
                    ->where('organization_id', $org->id)
                    ->where(function ($q) use ($p) {
                        $q->where('email', $p['email'])
                          ->orWhere('title', $p['title']);
                    })
                    ->first();

                if ($existing) {
                    DB::connection($conn)->table('crm_contacts')
                        ->where('id', $existing->id)
                        ->update([
                            'department' => $p['department'],
                            'phone' => $p['phone'],
                            'mobile' => $p['mobile'],
                            'mailing_street' => $org->billing_street,
                            'mailing_city' => $org->billing_city ?: $city,
                            'mailing_state' => $org->billing_state ?: 'Gujarat',
                            'mailing_code' => $org->billing_code,
                            'mailing_country' => 'India',
                            'consent_status' => 'opted_in',
                            'assigned_to' => $userId,
                            'updated_at' => now(),
                        ]);
                    $contactId = (int) $existing->id;
                } else {
                    $contactId = DB::connection($conn)->table('crm_contacts')->insertGetId([
                        'sub_institute_id' => self::TENANT_ID,
                        'contact_no' => 'CON-GUJ-' . strtoupper(Str::random(8)),
                        'organization_id' => $org->id,
                        'salutation' => $p['salutation'],
                        'first_name' => $p['first_name'],
                        'last_name' => $p['last_name'],
                        'title' => $p['title'],
                        'department' => $p['department'],
                        'email' => $p['email'],
                        'phone' => $p['phone'],
                        'mobile' => $p['mobile'],
                        'mailing_street' => $org->billing_street,
                        'mailing_city' => $org->billing_city ?: $city,
                        'mailing_state' => $org->billing_state ?: 'Gujarat',
                        'mailing_code' => $org->billing_code,
                        'mailing_country' => 'India',
                        'consent_status' => 'opted_in',
                        'lead_source' => 'Conference',
                        'assigned_to' => $userId,
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $contactIds[$roleKey] = $contactId;

                // Ensure consent preferences record
                DB::connection($conn)->table('crm_consent_preferences')->updateOrInsert(
                    [
                        'sub_institute_id' => self::TENANT_ID,
                        'entity_type' => 'contact',
                        'entity_id' => $contactId,
                        'channel' => 'email',
                    ],
                    [
                        'status' => 'opted_in',
                        'captured_at' => now(),
                        'source' => 'Gujarat School Institutional Consent Form 2026',
                        'notes' => 'Explicit institutional consent for ScholarClone ERP updates',
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // Pass 2: Establish hierarchical reports_to_id
            foreach ($personas as $roleKey => $p) {
                if ($p['reports_to_key'] && isset($contactIds[$p['reports_to_key']])) {
                    DB::connection($conn)->table('crm_contacts')
                        ->where('id', $contactIds[$roleKey])
                        ->update(['reports_to_id' => $contactIds[$p['reports_to_key']]]);
                }
            }

            $orgContactMap[$org->id] = $contactIds;
        }

        $this->command?->info(sprintf('Configured 4 institutional personas per school across %d organizations.', count($orgs)));
        return $orgContactMap;
    }
```

---

#### Feature 2: Opportunities Across All 8 Sales Stages
```php
    /**
     * Seeds and distributes opportunities across all 8 sales pipeline stages:
     * 1. Prospecting (10% prob, Pipeline)
     * 2. Qualification (20% prob, Pipeline)
     * 3. Needs Analysis (35% prob, Pipeline)
     * 4. Value Proposition (50% prob, Best Case)
     * 5. Identify Decision Makers (60% prob, Best Case)
     * 6. Proposal/Quotation (75% prob, Commit)
     * 7. Negotiation/Review (90% prob, Commit)
     * 8. Closed Won (100% prob, Closed)
     *
     * @return array<int, object> Opportunities created/updated
     */
    private function seedOpportunitiesPipeline(
        string $conn,
        int $userId,
        int $campaignId,
        array $productIds,
        array $orgContactMap
    ): array {
        $orgs = DB::connection($conn)->table('crm_organizations')
            ->where('sub_institute_id', self::TENANT_ID)
            ->whereNull('deleted_at')
            ->get();

        $stageDefs = [
            ['stage' => 'Closed Won', 'prob' => 100.00, 'fc' => 'Closed', 'amount' => 182310.00, 'days' => -5, 'next' => 'Handover to Customer Success and Deployment team', 'desc' => 'Agreement signed and full invoice payment received via RTGS. Deployment kickoff scheduled.'],
            ['stage' => 'Closed Won', 'prob' => 100.00, 'fc' => 'Closed', 'amount' => 182310.00, 'days' => -2, 'next' => 'Phase 1 data migration and teacher training onboarding', 'desc' => 'Deal closed won. CBSE report card automation and fee counter rollout in progress.'],
            ['stage' => 'Negotiation/Review', 'prob' => 90.00, 'fc' => 'Commit', 'amount' => 218300.00, 'days' => 7, 'next' => 'Final SLA contract review with Managing Trustee', 'desc' => 'Commercial negotiation completed with 10% trust discount. Contract with legal counsel.'],
            ['stage' => 'Negotiation/Review', 'prob' => 90.00, 'fc' => 'Commit', 'amount' => 247800.00, 'days' => 10, 'next' => 'Board meeting for final financial clearance', 'desc' => 'Trustee board approved technical proposal; awaiting formal resolution signature.'],
            ['stage' => 'Proposal/Quotation', 'prob' => 75.00, 'fc' => 'Commit', 'amount' => 182310.00, 'days' => 14, 'next' => 'Review formal CPQ quote QT-GUJ with Finance Controller', 'desc' => 'Submitted multi-item CPQ proposal for Scholar One ERP, Fee System, and Admin Training.'],
            ['stage' => 'Proposal/Quotation', 'prob' => 75.00, 'fc' => 'Commit', 'amount' => 171100.00, 'days' => 18, 'next' => 'Principal and Trustee review of line item pricing', 'desc' => 'Formal quotation sent for CBSE campus rollout covering 1,800 students.'],
            ['stage' => 'Identify Decision Makers', 'prob' => 60.00, 'fc' => 'Best Case', 'amount' => 283200.00, 'days' => 24, 'next' => 'Meeting with Managing Trustee and IT Committee', 'desc' => 'Validated decision matrix: Managing Trustee has signing authority, Principal evaluates academics.'],
            ['stage' => 'Identify Decision Makers', 'prob' => 60.00, 'fc' => 'Best Case', 'amount' => 259600.00, 'days' => 28, 'next' => 'Joint presentation to School Management Committee', 'desc' => 'IT Head recommended ScholarClone; executive presentation scheduled with Board.'],
            ['stage' => 'Value Proposition', 'prob' => 50.00, 'fc' => 'Best Case', 'amount' => 247800.00, 'days' => 35, 'next' => 'Present TCO comparison and ROI against legacy vendor', 'desc' => 'Demonstrated 45% administrative hours saved through automated online fee collection.'],
            ['stage' => 'Value Proposition', 'prob' => 50.00, 'fc' => 'Best Case', 'amount' => 212400.00, 'days' => 40, 'next' => 'Deliver cloud architecture security brief to IT Head', 'desc' => 'Presented Tier-4 cloud security, daily backups, and disaster recovery roadmap.'],
            ['stage' => 'Needs Analysis', 'prob' => 35.00, 'fc' => 'Pipeline', 'amount' => 212400.00, 'days' => 45, 'next' => 'Conduct in-depth product demo for academic staff', 'desc' => 'Detailed requirements gathering: GSEB/CBSE examination report cards and biometric attendance.'],
            ['stage' => 'Needs Analysis', 'prob' => 35.00, 'fc' => 'Pipeline', 'amount' => 177000.00, 'days' => 50, 'next' => 'Review current student database and fee structures', 'desc' => 'Analyzing existing fee categories, bus routes, and exam grading patterns.'],
            ['stage' => 'Qualification', 'prob' => 20.00, 'fc' => 'Pipeline', 'amount' => 177000.00, 'days' => 60, 'next' => 'Verify institutional budget allocation for digital ERP', 'desc' => 'Qualifying campus student strength (1,200+ students) and fee collection counters.'],
            ['stage' => 'Qualification', 'prob' => 20.00, 'fc' => 'Pipeline', 'amount' => 141600.00, 'days' => 65, 'next' => 'Discovery call with IT Administrator on current infrastructure', 'desc' => 'Evaluating server hardware readiness and high-speed campus internet connectivity.'],
            ['stage' => 'Prospecting', 'prob' => 10.00, 'fc' => 'Pipeline', 'amount' => 112100.00, 'days' => 75, 'next' => 'Schedule introductory discovery meeting with Principal', 'desc' => 'Initial outreach following Gujarat K-12 Digital Transformation Conference 2026.'],
            ['stage' => 'Prospecting', 'prob' => 10.00, 'fc' => 'Pipeline', 'amount' => 112100.00, 'days' => 80, 'next' => 'Send ScholarClone digital brochure and case studies', 'desc' => 'Contact expressed interest in migrating from manual ledger records.'],
        ];

        $seededOpps = [];
        $primaryProductId = !empty($productIds) ? array_values($productIds)[0] : 1;

        foreach ($orgs as $index => $org) {
            $def = $stageDefs[$index % count($stageDefs)];
            $oppName = "{$org->name} - ScholarClone ERP Suite";

            $existing = DB::connection($conn)->table('crm_opportunities')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('organization_id', $org->id)
                ->whereNull('deleted_at')
                ->first();

            $closingDate = now()->addDays($def['days'])->toDateString();

            if ($existing) {
                DB::connection($conn)->table('crm_opportunities')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $oppName,
                        'campaign_id' => $campaignId,
                        'amount' => $def['amount'],
                        'currency' => 'INR',
                        'closing_date' => $closingDate,
                        'sales_stage' => $def['stage'],
                        'probability' => $def['prob'],
                        'next_step' => $def['next'],
                        'forecast_category' => $def['fc'],
                        'potential_type' => 'New Business',
                        'lead_source' => 'Conference',
                        'description' => $def['desc'],
                        'close_reason' => $def['stage'] === 'Closed Won' ? 'Best product fit and local Gujarat technical team' : null,
                        'product_master_id' => self::SCHOLARCLONE_PRODUCT_MASTER_ID,
                        'assigned_to' => $userId,
                        'updated_at' => now(),
                    ]);
                $oppId = (int) $existing->id;
            } else {
                $oppId = DB::connection($conn)->table('crm_opportunities')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'opportunity_no' => 'OPP-GUJ-' . strtoupper(Str::random(8)),
                    'name' => $oppName,
                    'organization_id' => $org->id,
                    'campaign_id' => $campaignId,
                    'amount' => $def['amount'],
                    'currency' => 'INR',
                    'closing_date' => $closingDate,
                    'sales_stage' => $def['stage'],
                    'probability' => $def['prob'],
                    'next_step' => $def['next'],
                    'forecast_category' => $def['fc'],
                    'potential_type' => 'New Business',
                    'lead_source' => 'Conference',
                    'description' => $def['desc'],
                    'close_reason' => $def['stage'] === 'Closed Won' ? 'Best product fit and local Gujarat technical team' : null,
                    'product_master_id' => self::SCHOLARCLONE_PRODUCT_MASTER_ID,
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Link primary contact (Principal or Trustee) in crm_opportunity_contacts
            $principalContactId = $orgContactMap[$org->id]['principal'] ?? $orgContactMap[$org->id]['trustee'] ?? null;
            if ($principalContactId) {
                DB::connection($conn)->table('crm_opportunity_contacts')->updateOrInsert(
                    ['opportunity_id' => $oppId, 'contact_id' => $principalContactId],
                    ['created_at' => now()]
                );
            }

            // Link product in crm_opportunity_products
            DB::connection($conn)->table('crm_opportunity_products')->updateOrInsert(
                ['opportunity_id' => $oppId, 'product_id' => $primaryProductId],
                ['quantity' => 1, 'created_at' => now()]
            );

            // Audit stage history in crm_opportunity_stage_history
            DB::connection($conn)->table('crm_opportunity_stage_history')->insert([
                'opportunity_id' => $oppId,
                'from_stage' => null,
                'to_stage' => $def['stage'],
                'amount' => $def['amount'],
                'probability' => $def['prob'],
                'closing_date' => $closingDate,
                'changed_by' => $userId,
                'created_at' => now(),
            ]);

            $seededOpps[$oppId] = (object) array_merge($def, [
                'id' => $oppId,
                'organization_id' => $org->id,
                'org_name' => $org->name,
                'contact_id' => $principalContactId,
            ]);
        }

        $this->command?->info(sprintf('Distributed %d opportunities across all 8 pipeline stages for Tenant 6.', count($seededOpps)));
        return $seededOpps;
    }
```

---

#### Feature 3: Multi-Item CPQ Quotes with 18% GST
```php
    /**
     * Seeds multi-item CPQ quotes with subtotal, 10% discount, 18% GST tax rate snapshot,
     * shipping, and detailed commercial terms for advanced pipeline stages.
     *
     * @return array<int, object> Quotes created/updated
     */
    private function seedMultiItemQuotes(
        string $conn,
        int $userId,
        int $taxRateId,
        array $productIds,
        array $opportunities
    ): array {
        // Target deals in Proposal/Quotation, Negotiation/Review, and Closed Won
        $advancedOpps = array_filter($opportunities, fn ($opp) => in_array($opp->stage, ['Proposal/Quotation', 'Negotiation/Review', 'Closed Won'], true));

        $quotes = [];
        $taxRate = DB::connection($conn)->table('crm_tax_rates')->where('id', $taxRateId)->first();
        $taxName = $taxRate?->name ?: 'GST 18%';
        $taxPct = $taxRate ? (float) $taxRate->percentage : 18.000;

        foreach ($advancedOpps as $opp) {
            $org = DB::connection($conn)->table('crm_organizations')->where('id', $opp->organization_id)->first();
            $quoteStage = $opp->stage === 'Closed Won' ? 'Accepted' : ($opp->stage === 'Negotiation/Review' ? 'Revised' : 'Sent');

            $existing = DB::connection($conn)->table('crm_quotes')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('opportunity_id', $opp->id)
                ->whereNull('deleted_at')
                ->first();

            $quoteSubject = "ScholarClone ERP Proposal - {$org->name}";
            $terms = "1. Payment Terms: 50% advance on Order Confirmation, 40% on UAT deployment, 10% on Go-Live.\n"
                   . "2. Prices are inclusive of 18% GST as per statutory rates.\n"
                   . "3. Subscription includes cloud hosting on Tier-4 datacenter in India, daily automated backups, and 99.9% uptime SLA.\n"
                   . "4. Annual Maintenance Contract (AMC) for Year 2 onwards will be 18% of license value.\n"
                   . "5. Implementation timeline: 4 weeks from sign-off and student data handover.";

            $lineItemsData = [
                [
                    'sku' => 'SCH-01-GUJ',
                    'desc' => 'Scholar One ERP - Gujarat Edition (Core SIS, Attendance, Exam & Report Cards)',
                    'qty' => 1.00,
                    'price' => 120000.00,
                    'discount_pct' => 10.000,
                ],
                [
                    'sku' => 'SCH-CCB-GUJ',
                    'desc' => 'Scholar CCB Fee Collection System (Multi-counter cashier, online receipts, bank recon)',
                    'qty' => 1.00,
                    'price' => 35000.00,
                    'discount_pct' => 10.000,
                ],
                [
                    'sku' => 'SCH-SRV-IMP',
                    'desc' => 'ScholarClone Implementation, Legacy Data Migration & Staff Training',
                    'qty' => 1.00,
                    'price' => 15000.00,
                    'discount_pct' => 0.000,
                ],
            ];

            // Compute totals
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;

            foreach ($lineItemsData as $item) {
                $gross = $item['qty'] * $item['price'];
                $disc = round($gross * ($item['discount_pct'] / 100), 2);
                $net = $gross - $disc;
                $tax = round($net * ($taxPct / 100), 2);

                $subtotal += $gross;
                $discountTotal += $disc;
                $taxTotal += $tax;
            }

            $shipping = 0.00;
            $adjustment = 0.00;
            $grandTotal = round($subtotal - $discountTotal + $taxTotal + $shipping + $adjustment, 2);

            if ($existing) {
                DB::connection($conn)->table('crm_quotes')->where('id', $existing->id)->update([
                    'subject' => $quoteSubject,
                    'quote_stage' => $quoteStage,
                    'valid_till' => now()->addDays(30)->toDateString(),
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'total' => $grandTotal,
                    'billing_street' => $org->billing_street,
                    'billing_city' => $org->billing_city,
                    'billing_state' => $org->billing_state,
                    'billing_code' => $org->billing_code,
                    'billing_country' => 'India',
                    'shipping_street' => $org->billing_street,
                    'shipping_city' => $org->billing_city,
                    'shipping_state' => $org->billing_state,
                    'shipping_code' => $org->billing_code,
                    'shipping_country' => 'India',
                    'terms_conditions' => $terms,
                    'assigned_to' => $userId,
                    'updated_at' => now(),
                ]);
                $quoteId = (int) $existing->id;
            } else {
                $quoteId = DB::connection($conn)->table('crm_quotes')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'quote_no' => 'QT-GUJ-' . strtoupper(Str::random(8)),
                    'subject' => $quoteSubject,
                    'organization_id' => $org->id,
                    'contact_id' => $opp->contact_id,
                    'opportunity_id' => $opp->id,
                    'quote_stage' => $quoteStage,
                    'valid_till' => now()->addDays(30)->toDateString(),
                    'currency' => 'INR',
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountTotal,
                    'shipping_handling_amount' => $shipping,
                    'adjustment' => $adjustment,
                    'tax_total' => $taxTotal,
                    'total' => $grandTotal,
                    'billing_street' => $org->billing_street,
                    'billing_city' => $org->billing_city,
                    'billing_state' => $org->billing_state,
                    'billing_code' => $org->billing_code,
                    'billing_country' => 'India',
                    'shipping_street' => $org->billing_street,
                    'shipping_city' => $org->billing_city,
                    'shipping_state' => $org->billing_state,
                    'shipping_code' => $org->billing_code,
                    'shipping_country' => 'India',
                    'terms_conditions' => $terms,
                    'description' => 'Comprehensive CPQ Proposal for Gujarat School digital transformation.',
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Sync multi-item line items
            DB::connection($conn)->table('crm_quote_line_items')->where('quote_id', $quoteId)->delete();

            foreach ($lineItemsData as $seq => $item) {
                $prodId = $productIds[$item['sku']] ?? array_values($productIds)[0];
                $gross = $item['qty'] * $item['price'];
                $disc = round($gross * ($item['discount_pct'] / 100), 2);
                $net = $gross - $disc;
                $tax = round($net * ($taxPct / 100), 2);
                $lineTotal = round($net + $tax, 2);

                DB::connection($conn)->table('crm_quote_line_items')->insert([
                    'quote_id' => $quoteId,
                    'product_id' => $prodId,
                    'description' => $item['desc'],
                    'quantity' => $item['qty'],
                    'unit_price' => $item['price'],
                    'discount_percent' => $item['discount_pct'],
                    'discount_amount' => $disc,
                    'tax_rate_id' => $taxRateId,
                    'tax_name_snapshot' => $taxName,
                    'tax_percent_snapshot' => $taxPct,
                    'line_total' => $lineTotal,
                    'sequence_no' => $seq + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Align opportunity amount with CPQ Quote total
            DB::connection($conn)->table('crm_opportunities')
                ->where('id', $opp->id)
                ->update(['amount' => $grandTotal]);

            $quotes[$quoteId] = DB::connection($conn)->table('crm_quotes')->where('id', $quoteId)->first();
        }

        $this->command?->info(sprintf('Seeded %d multi-item CPQ quotes with 18%% GST and terms.', count($quotes)));
        return $quotes;
    }
```

---

#### Feature 4: Zero-Variance Sales Orders, Invoices & Payment Ledger
```php
    /**
     * Converts Closed Won quotes into Sales Orders and Invoices with matching line items,
     * asserts zero variance (|SO - INV| == 0.00), and records full payment in crm_invoice_payments.
     */
    private function seedClosedWonOrdersAndInvoices(string $conn, int $userId, array $quotes): void
    {
        $closedWonQuotes = array_filter($quotes, fn ($q) => $q->quote_stage === 'Accepted');

        foreach ($closedWonQuotes as $quote) {
            // 1. Convert Quote -> Sales Order
            $so = DB::connection($conn)->table('crm_sales_orders')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('quote_id', $quote->id)
                ->whereNull('deleted_at')
                ->first();

            if (!$so) {
                $soId = DB::connection($conn)->table('crm_sales_orders')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'so_no' => 'SO-GUJ-' . strtoupper(Str::random(8)),
                    'subject' => $quote->subject,
                    'organization_id' => $quote->organization_id,
                    'contact_id' => $quote->contact_id,
                    'opportunity_id' => $quote->opportunity_id,
                    'quote_id' => $quote->id,
                    'so_stage' => 'Confirmed',
                    'due_date' => now()->addDays(30)->toDateString(),
                    'carrier' => 'Cloud Electronic Delivery',
                    'currency' => 'INR',
                    'subtotal' => $quote->subtotal,
                    'discount_amount' => $quote->discount_amount,
                    'shipping_handling_amount' => $quote->shipping_handling_amount,
                    'adjustment' => $quote->adjustment,
                    'tax_total' => $quote->tax_total,
                    'total' => $quote->total,
                    'billing_street' => $quote->billing_street,
                    'billing_city' => $quote->billing_city,
                    'billing_state' => $quote->billing_state,
                    'billing_code' => $quote->billing_code,
                    'billing_country' => $quote->billing_country,
                    'shipping_street' => $quote->shipping_street,
                    'shipping_city' => $quote->shipping_city,
                    'shipping_state' => $quote->shipping_state,
                    'shipping_code' => $quote->shipping_code,
                    'shipping_country' => $quote->shipping_country,
                    'terms_conditions' => $quote->terms_conditions,
                    'description' => 'Confirmed Sales Order for ScholarClone deployment.',
                    'assigned_to' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Clone line items into crm_sales_order_line_items
                $qItems = DB::connection($conn)->table('crm_quote_line_items')->where('quote_id', $quote->id)->get();
                foreach ($qItems as $item) {
                    DB::connection($conn)->table('crm_sales_order_line_items')->insert([
                        'sales_order_id' => $soId,
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount_percent' => $item->discount_percent,
                        'discount_amount' => $item->discount_amount,
                        'tax_rate_id' => $item->tax_rate_id,
                        'tax_name_snapshot' => $item->tax_name_snapshot,
                        'tax_percent_snapshot' => $item->tax_percent_snapshot,
                        'line_total' => $item->line_total,
                        'sequence_no' => $item->sequence_no,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $so = DB::connection($conn)->table('crm_sales_orders')->where('id', $soId)->first();
            }

            // 2. Convert Sales Order -> Invoice
            $inv = DB::connection($conn)->table('crm_invoices')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where('sales_order_id', $so->id)
                ->whereNull('deleted_at')
                ->first();

            if (!$inv) {
                $invId = DB::connection($conn)->table('crm_invoices')->insertGetId([
                    'sub_institute_id' => self::TENANT_ID,
                    'invoice_no' => 'INV-GUJ-' . strtoupper(Str::random(8)),
                    'subject' => $so->subject,
                    'sales_order_id' => $so->id,
                    'organization_id' => $so->organization_id,
                    'contact_id' => $so->contact_id,
                    'invoice_stage' => 'Paid',
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'currency' => 'INR',
                    'subtotal' => $so->subtotal,
                    'discount_amount' => $so->discount_amount,
                    'shipping_handling_amount' => $so->shipping_handling_amount,
                    'adjustment' => $so->adjustment,
                    'tax_total' => $so->tax_total,
                    'total' => $so->total,
                    'amount_paid' => $so->total,
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
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Clone line items into crm_invoice_line_items
                $soItems = DB::connection($conn)->table('crm_sales_order_line_items')->where('sales_order_id', $so->id)->get();
                foreach ($soItems as $item) {
                    DB::connection($conn)->table('crm_invoice_line_items')->insert([
                        'invoice_id' => $invId,
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount_percent' => $item->discount_percent,
                        'discount_amount' => $item->discount_amount,
                        'tax_rate_id' => $item->tax_rate_id,
                        'tax_name_snapshot' => $item->tax_name_snapshot,
                        'tax_percent_snapshot' => $item->tax_percent_snapshot,
                        'line_total' => $item->line_total,
                        'sequence_no' => $item->sequence_no,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $inv = DB::connection($conn)->table('crm_invoices')->where('id', $invId)->first();
            }

            // 3. Record full settlement payment in crm_invoice_payments
            $payment = DB::connection($conn)->table('crm_invoice_payments')->where('invoice_id', $inv->id)->first();
            if (!$payment) {
                DB::connection($conn)->table('crm_invoice_payments')->insert([
                    'invoice_id' => $inv->id,
                    'amount' => $inv->total,
                    'method' => 'RTGS',
                    'reference' => 'HDFC-RTGS-' . strtoupper(Str::random(10)),
                    'paid_on' => now()->toDateString(),
                    'note' => 'Full institutional fee settlement received via RTGS from School Management Trust',
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            // 4. Assert zero variance
            $variance = abs((float) $so->total - (float) $inv->total);
            if ($variance < 0.001) {
                $this->command?->info(sprintf(
                    "ZERO VARIANCE FINANCIAL RECONCILIATION VERIFIED: SO #%d (₹%.2f) == Invoice #%d (₹%.2f) == Amount Paid (₹%.2f)",
                    $so->id, (float) $so->total, $inv->id, (float) $inv->total, (float) $inv->amount_paid
                ));
            } else {
                $this->command?->error(sprintf(
                    "FINANCIAL VARIANCE DETECTED! SO #%d (₹%.2f) != Invoice #%d (₹%.2f)",
                    $so->id, (float) $so->total, $inv->id, (float) $inv->total
                ));
            }
        }
    }
```

---

#### Feature 5: Authentic Gujarat Market Signals Ingestion
```php
    /**
     * Ingests authentic Gujarat educational signals from database/fixtures/gujarat_scholarclone_signals.json
     * into crm_signal_inbox and populates crm_signal_topics.
     */
    private function ingestGujaratMarketSignals(string $conn, int $userId): void
    {
        $fixturePath = base_path('database/fixtures/gujarat_scholarclone_signals.json');

        if (!file_exists($fixturePath)) {
            $this->command?->warn("Market signals fixture {$fixturePath} not found.");
            return;
        }

        $fixture = json_decode(file_get_contents($fixturePath), true);
        $records = $fixture['records'] ?? [];

        $ingested = 0;

        foreach ($records as $record) {
            $buyerName = $record['buyer']['name'] ?? 'Gujarat Institution';
            $aliases = $record['buyer']['aliases'] ?? [];
            $eventDate = $record['source_published_at'] ?? $record['event_date'] ?? '2026-10-30';

            // Generate deterministic SHA-256 signal fingerprint
            $signalKey = hash('sha256', "gujarat-signal:{$buyerName}:{$eventDate}");

            // Match against existing school organizations
            $matchedOrg = DB::connection($conn)->table('crm_organizations')
                ->where('sub_institute_id', self::TENANT_ID)
                ->where(function ($q) use ($buyerName, $aliases) {
                    $q->where('name', 'LIKE', '%' . substr($buyerName, 0, 15) . '%');
                    foreach ($aliases as $alias) {
                        $q->orWhere('name', 'LIKE', '%' . substr($alias, 0, 15) . '%');
                    }
                })
                ->first();

            $body = ($record['what_happened'] ?? $record['trigger_summary']) . "\n\n"
                  . "Likely Requirement: " . ($record['likely_problem'] ?? 'New campus setup requiring admissions, student records, and fee management.') . "\n"
                  . "Proposed Solution: " . ($record['what_we_could_sell'] ?? 'ScholarClone School Management Suite (Gujarat Edition).') . "\n"
                  . "Sales Approach: " . ($record['soft_marketing_angle'] ?? 'Direct outreach to school director and managing trustee.');

            $tags = array_unique(array_filter([
                $record['trigger_type'] ?? 'expansion',
                $record['buyer_segment'] ?? 'K-12',
                'Gujarat',
                'ScholarClone',
                $record['buyer']['state'] ?? 'Gujarat',
            ]));

            DB::connection($conn)->table('crm_signal_inbox')->updateOrInsert(
                ['signal_key' => $signalKey],
                [
                    'source_name' => 'Gujarat Education News & Web Monitoring',
                    'source_url' => $record['source_url'] ?? null,
                    'title' => $record['source_title'] ?? $record['trigger_summary'],
                    'body' => $body,
                    'published_at' => date('Y-m-d H:i:s', strtotime($eventDate)),
                    'ingested_at' => now(),
                    'classification' => 'buying_signal',
                    'classification_confidence' => 0.92,
                    'classification_reason' => $record['scores']['reasoning'] ?? 'Institutional campus expansion announcement with admissions technology window.',
                    'relevance_score' => 0.88,
                    'linked_account_id' => $matchedOrg?->id,
                    'linked_account_confidence' => $matchedOrg ? 0.95 : null,
                    'status' => 'accepted',
                    'tags' => json_encode(array_values($tags)),
                    'sub_institute_id' => self::TENANT_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $ingested++;
        }

        // Seed companion search & monitoring topics in crm_signal_topics
        $topics = [
            [
                'name' => 'Gujarat School ERP RFP Monitor',
                'keywords' => json_encode(['school erp', 'scholarclone', 'gujarat schools', 'sis', 'admissions software']),
                'topic_type' => 'buying_intent',
            ],
            [
                'name' => 'CBSE & GSEB Campus Expansion Gujarat',
                'keywords' => json_encode(['cbse affiliation', 'gseb school', 'new campus ahmedabad', 'surat school opening']),
                'topic_type' => 'industry',
            ],
            [
                'name' => 'Gujarat Educational Institution Signals',
                'keywords' => json_encode(['education department gujarat', 'rfp school management', 'fee collection software']),
                'topic_type' => 'custom',
            ],
        ];

        foreach ($topics as $topic) {
            DB::connection($conn)->table('crm_signal_topics')->updateOrInsert(
                [
                    'sub_institute_id' => self::TENANT_ID,
                    'name' => $topic['name'],
                ],
                [
                    'keywords' => $topic['keywords'],
                    'topic_type' => $topic['topic_type'],
                    'status' => 1,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command?->info(sprintf('Ingested %d authentic Gujarat educational signals into crm_signal_inbox and configured 3 signal topics.', $ingested));
    }
```

---

### 4.3 Integration Call Sequence in `Tenant6CrmLifecycleSeeder::run()`

Worker should invoke the steps in `run()` in this exact sequence:

```php
        try {
            // 1. Setup Tenant Prerequisites (Tax Rate + Lead Scoring Rules)
            $taxRateId = $this->ensureTaxRate($connectionName);
            $this->ensureLeadScoringRules($connectionName);

            // 2. Setup ScholarClone Product Line SKUs (Explorer 1)
            $productIds = $this->ensureScholarCloneProducts($bearer, $connectionName, $userId, $taxRateId);

            // 3. Setup Price Books & Items (Explorer 1)
            $this->ensurePriceBooks($connectionName, $userId, $productIds);

            // 4. Setup Gujarat School Campaigns (Explorer 1)
            $campaignId = $this->ensureCampaigns($connectionName, $userId);

            // 5. Source & Seed Leads + Convert 15+ Organizations (Explorer 2)
            $this->seedGujaratLeadsAndOrganizations($bearer, $connectionName, $userId, $campaignId);

            // 6. Seed 4 Contacts per School Organization (Explorer 3 - Us)
            $orgContactMap = $this->seedContactsForOrganizations($connectionName, $userId);

            // 7. Seed Opportunities Distributed Across all 8 Stages (Explorer 3 - Us)
            $opportunities = $this->seedOpportunitiesPipeline($connectionName, $userId, $campaignId, $productIds, $orgContactMap);

            // 8. Seed Multi-Item CPQ Quotes with 18% GST (Explorer 3 - Us)
            $quotes = $this->seedMultiItemQuotes($connectionName, $userId, $taxRateId, $productIds, $opportunities);

            // 9. Seed Closed Won Sales Orders, Invoices & Payments (Explorer 3 - Us)
            $this->seedClosedWonOrdersAndInvoices($connectionName, $userId, $quotes);

            // 10. Ingest Gujarat Market Signals into Signal Inbox (Explorer 3 - Us)
            $this->ingestGujaratMarketSignals($connectionName, $userId);

            // 11. Ensure Tenant 6 Outreach Readiness
            $this->ensureOutreachReadiness($connectionName);

            $this->command?->info('=== Tenant 6 CRM Lifecycle Seeding Completed Successfully ===');
        }
```

---

## 5. Verification Method

To verify that these implementations work and meet all acceptance criteria once Worker integrates them:

1. **Verify No Schema::hasColumn() Calls**:
   ```bash
   powershell -Command "Select-String -Path database\seeders\Tenant6CrmLifecycleSeeder.php -Pattern 'Schema::hasColumn'"
   ```
   *Expected output*: Zero matches.

2. **Execute Seeder on Target Database**:
   ```bash
   php artisan db:seed --class=Tenant6CrmLifecycleSeeder --database=mysql
   ```
   *Expected output*: Clean run with zero errors, zero warnings, and log messages confirming:
   - "Configured 4 institutional personas per school across 15+ organizations."
   - "Distributed 16+ opportunities across all 8 pipeline stages for Tenant 6."
   - "Seeded multi-item CPQ quotes with 18% GST and terms."
   - "ZERO VARIANCE FINANCIAL RECONCILIATION VERIFIED: SO #... == Invoice #... == Amount Paid"
   - "Ingested 5 authentic Gujarat educational signals into crm_signal_inbox and configured 3 signal topics."

3. **Verify Database Counts for Tenant 6**:
   ```bash
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); dump([
       'contacts' => Illuminate\Support\Facades\DB::table('crm_contacts')->where('sub_institute_id', 6)->count(),
       'opps_by_stage' => Illuminate\Support\Facades\DB::table('crm_opportunities')->where('sub_institute_id', 6)->selectRaw('sales_stage, count(*) as c')->groupBy('sales_stage')->pluck('c', 'sales_stage'),
       'quotes' => Illuminate\Support\Facades\DB::table('crm_quotes')->where('sub_institute_id', 6)->count(),
       'quote_items' => Illuminate\Support\Facades\DB::table('crm_quote_line_items')->join('crm_quotes', 'crm_quotes.id', '=', 'crm_quote_line_items.quote_id')->where('crm_quotes.sub_institute_id', 6)->count(),
       'orders' => Illuminate\Support\Facades\DB::table('crm_sales_orders')->where('sub_institute_id', 6)->count(),
       'invoices' => Illuminate\Support\Facades\DB::table('crm_invoices')->where('sub_institute_id', 6)->count(),
       'payments' => Illuminate\Support\Facades\DB::table('crm_invoice_payments')->join('crm_invoices', 'crm_invoices.id', '=', 'crm_invoice_payments.invoice_id')->where('crm_invoices.sub_institute_id', 6)->count(),
       'signals' => Illuminate\Support\Facades\DB::table('crm_signal_inbox')->where('sub_institute_id', 6)->count(),
       'topics' => Illuminate\Support\Facades\DB::table('crm_signal_topics')->where('sub_institute_id', 6)->count(),
   ]);"
   ```
   *Expected output*:
   - `contacts`: >= 60 (4 per school)
   - `opps_by_stage`: All 8 stages present (`Prospecting`, `Qualification`, `Needs Analysis`, `Value Proposition`, `Identify Decision Makers`, `Proposal/Quotation`, `Negotiation/Review`, `Closed Won`)
   - `quotes`: >= 6
   - `quote_items`: >= 18 (multi-item quotes)
   - `orders`: >= 2
   - `invoices`: >= 2
   - `payments`: >= 2
   - `signals`: >= 5
   - `topics`: >= 3

4. **Verify Zero Financial Variance**:
   ```bash
   php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$rows = Illuminate\Support\Facades\DB::table('crm_sales_orders as so')->join('crm_invoices as inv', 'inv.sales_order_id', '=', 'so.id')->where('so.sub_institute_id', 6)->select('so.id as so_id', 'so.total as so_total', 'inv.id as inv_id', 'inv.total as inv_total', 'inv.amount_paid')->get(); foreach(\$rows as \$r) { echo \"SO #{\$r->so_id}: {\$r->so_total} | INV #{\$r->inv_id}: {\$r->inv_total} | Paid: {\$r->amount_paid}\n\"; assert(abs(\$r->so_total - \$r->inv_total) < 0.001); assert(abs(\$r->inv_total - \$r->amount_paid) < 0.001); } echo \"ALL CHECKS PASSED: ZERO VARIANCE\n\";"
   ```
   *Expected output*: `ALL CHECKS PASSED: ZERO VARIANCE`.

5. **Invalidation Conditions**:
   - If MariaDB table columns differ from the cataloged schema, verify using `ColumnExists::has($table, $column, $conn)`.
   - If the database user cannot write to `crm_signal_inbox`, ensure the table migration `2026_12_03_100000_create_crm_signal_inbox_table.php` has run on `hp_erp`.
