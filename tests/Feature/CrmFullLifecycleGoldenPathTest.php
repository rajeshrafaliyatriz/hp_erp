<?php

namespace Tests\Feature;

use App\Domain\Gtm\OutreachMailer;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Full Lifecycle Persona Golden-Path Simulation (Phase 2).
 *
 * Programmatically executes the 9 lifecycle stages via actual application controllers and endpoints:
 * 1. Stage 1 (Signals/CPQ): Unified Product Line ("ScholarClone") and sellable SKU ("Scholar One ERP").
 * 2. Stage 2 (Lead Acquisition): Lead associated with Product Line; lead scoring fires.
 * 3. Stage 3 (Conversion): Convert Lead to Organization, Contact, and Opportunity (surviving product association).
 * 4. Stage 4 & 5 (CPQ & Pipeline): Quote with Opportunity & Product SKU; discount and tax calculation.
 * 5. Stage 6 (Fulfillment & Billing): Convert Quote to Sales Order and Invoice; assert mathematical zero variance.
 * 6. Stage 7 & 8 (Outreach & Mailbox): Verify OutreachMailer::readiness reports can_send = TRUE using tenant mailbox.
 */
class CrmFullLifecycleGoldenPathTest extends TestCase
{
    private const TENANT_ID = 6;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });

        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });

        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamps();
        });

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamps();
        });

        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('school_setup', function ($t) {
            $t->id();
            $t->boolean('outbound_email_enabled')->default(false);
        });

        Schema::create('smtp_details', function ($t) {
            $t->id();
            $t->string('gmail')->nullable();
            $t->string('password')->nullable();
            $t->string('server_address')->nullable();
            $t->string('port')->nullable();
            $t->string('module_key')->nullable();
            $t->string('from_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // Run required migrations
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
            '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind',
            '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields',
            '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_12_05_090000_create_g2g_signal_products_table',
            '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_11_100000_create_g2g_number_sequences_table',
            '2026_11_20_090100_create_crm_organizations_table',
            '2026_11_20_090200_create_crm_contacts_table',
            '2026_11_20_090400_create_crm_campaigns_table',
            '2026_10_10_500000_create_gtm_outreach_messages',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        // Build crm_leads directly for SQLite in-memory compatibility
        Schema::create('crm_leads', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
            $t->string('lead_no', 30)->nullable()->unique('crm_leads_lead_no_unique');
            $t->string('salutation', 20)->nullable();
            $t->string('first_name', 191)->nullable();
            $t->string('last_name', 191);
            $t->string('company', 191)->nullable()->index();
            $t->string('email', 191)->nullable()->index();
            $t->string('phone', 50)->nullable();
            $t->string('mobile', 50)->nullable()->index();
            $t->string('fax', 30)->nullable();
            $t->string('lead_source', 191)->nullable()->index();
            $t->string('industry', 191)->nullable();
            $t->string('website', 191)->nullable();
            $t->decimal('annual_revenue', 15, 2)->nullable();
            $t->string('lead_status', 191)->nullable()->index();
            $t->string('lifecycle_status', 50)->nullable()->default('new');
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->string('number_of_emp', 50)->nullable();
            $t->string('rating', 191)->nullable();
            $t->integer('lead_score')->default(0)->index();
            $t->text('lead_score_basis')->nullable();
            $t->string('secondary_email', 191)->nullable();
            $t->boolean('email_opt_out')->default(false);
            $t->string('type', 191)->nullable();
            $t->string('campaign_text', 191)->nullable();
            $t->boolean('converted')->default(false)->index();
            $t->unsignedBigInteger('converted_organization_id')->nullable()->index();
            $t->unsignedBigInteger('converted_contact_id')->nullable()->index();
            $t->unsignedBigInteger('converted_opportunity_id')->nullable()->index();
            $t->string('designation')->nullable();
            $t->string('street', 191)->nullable();
            $t->string('po_box', 191)->nullable();
            $t->string('postal_code', 191)->nullable();
            $t->string('country', 191)->nullable();
            $t->string('state', 191)->nullable();
            $t->string('city', 191)->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('product_master_id')->nullable()->index();
            $t->unsignedBigInteger('org_account_id')->nullable()->index();
            $t->unsignedBigInteger('source_company_opportunity_id')->nullable()->index();
            $t->unsignedBigInteger('campaign_id')->nullable()->index();
            $t->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('updated_by')->nullable()->index();
            $t->unsignedBigInteger('deleted_by')->nullable()->index();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        foreach ([
            '2026_11_30_090100_create_crm_lead_routing_tables',
            '2026_11_24_080100_create_crm_tax_rates_table',
            '2026_11_24_080200_create_crm_products_table',
            '2026_11_24_081400_add_phase6_fields_to_crm_products_table',
            '2026_11_24_080300_create_crm_opportunities_table',
            '2026_11_24_080400_create_crm_opportunity_contacts_table',
            '2026_11_24_080600_create_crm_opportunity_stage_history_table',
            '2026_11_24_081800_create_crm_price_books_table',
            '2026_11_24_080700_create_crm_quotes_table',
            '2026_11_24_082000_add_price_book_id_to_crm_quotes_table',
            '2026_11_24_080800_create_crm_quote_line_items_table',
            '2026_11_24_082400_create_crm_sales_orders_table',
            '2026_11_24_082500_create_crm_sales_order_line_items_table',
            '2026_11_24_082800_create_crm_invoices_table',
            '2026_11_30_080100_add_payment_tracking_to_crm_invoices_table',
            '2026_11_24_082900_create_crm_invoice_line_items_table',
            '2026_11_24_083000_create_crm_order_to_cash_status_history_tables',
            '2026_11_24_084500_create_crm_quote_status_history_table',
            '2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities',
            '2026_12_11_140000_add_product_master_id_to_crm_campaigns',
            '2026_12_11_160000_add_product_master_id_to_crm_products',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);
        }

        // Mock event recorder
        $this->app->instance(EventRecorder::class, new class extends EventRecorder {
            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                return 1;
            }
        });

        RoleKey::flushCache();
        Cache::flush();

        // Seed menu structure
        $menus = [
            [199, 'Sales & Marketing', 0, '/module/crm'],
            [468, 'Products', 199, '/module/crm/sales/products'],
            [201, 'Leads', 199, '/module/crm/marketing/leads'],
            [452, 'Organizations', 199, '/module/crm/marketing/organizations'],
            [451, 'Contacts', 199, '/module/crm/marketing/contacts'],
            [466, 'Opportunities', 199, '/module/crm/sales/opportunities'],
            [467, 'Quotes', 199, '/module/crm/sales/quotes'],
            [473, 'Sales Orders', 199, '/module/crm/sales/sales-orders'],
            [475, 'Invoices', 199, '/module/crm/sales/invoices'],
        ];

        foreach ($menus as [$id, $name, $parent, $link]) {
            DB::table('tblmenumaster_g2g')->insert([
                'id' => $id, 'menu_name' => $name, 'parent_id' => $parent,
                'level' => $parent === 0 ? 1 : 2, 'access_link' => $link, 'sort_order' => $id,
                'status' => 1,
            ]);
        }

        // Setup Admin profile and grant all rights for Tenant 6
        DB::table('tbluserprofilemaster')->insert([
            'id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator', 'sub_institute_id' => self::TENANT_ID,
        ]);

        foreach ($menus as [$id, $name, $parent, $link]) {
            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => self::TENANT_ID,
                'menu_id' => $id,
                'profile_id' => 1,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
            ]);
        }

        // Backfill built-in products
        (new ProductMasterBackfill)->run(self::TENANT_ID, true);
    }

    private function h(int $tenant = self::TENANT_ID, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId([
            'user_name' => 'admin_t6_' . uniqid(),
            'first_name' => 'Admin',
            'last_name' => 'ScholarClone',
            'sub_institute_id' => $tenant,
            'user_profile_id' => $profile,
            'status' => 1,
        ]);

        return [
            'Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t6_golden_path')->plainTextToken,
            '_user' => $id,
        ];
    }

    private function headers(array $h): array
    {
        unset($h['_user']);

        return $h;
    }

    public function test_crm_full_lifecycle_golden_path_simulation_tenant_6(): void
    {
        $auth = $this->h(self::TENANT_ID);
        $userId = $auth['_user'];
        $headers = $this->headers($auth);

        // Setup Tax Rate (GST 18%)
        $taxRateId = DB::table('crm_tax_rates')->insertGetId([
            'sub_institute_id' => self::TENANT_ID,
            'name' => 'GST 18%',
            'percentage' => 18.000,
            'is_active' => true,
        ]);

        // Setup Lead Scoring Rules (Education industry + Hot rating = 40 pts)
        DB::table('crm_lead_scoring_rules')->insert([
            [
                'sub_institute_id' => self::TENANT_ID,
                'field_key' => 'industry',
                'operator' => 'equals',
                'value' => 'Education',
                'points' => 25,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sub_institute_id' => self::TENANT_ID,
                'field_key' => 'rating',
                'operator' => 'equals',
                'value' => 'Hot',
                'points' => 15,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // =====================================================================
        // Stage 1 (Signals/CPQ): Unified Product Line & Sellable Catalogue SKU
        // =====================================================================
        $createProdRes = $this->postJson('/api/crm/products', [
            'itemType' => 'product',
            'name' => 'Scholar One ERP',
            'sku' => 'SCH-01-GUJ',
            'unitPrice' => 120000.00,
            'costPrice' => 60000.00,
            'productLineName' => 'ScholarClone',
            'category' => 'School ERP',
            'glAccount' => '4000-SALES',
            'currency' => 'INR',
            'taxRateId' => $taxRateId,
            'assignedTo' => $userId,
        ], $headers);

        $createProdRes->assertStatus(201);
        $this->assertSame(1, $createProdRes->json('status'));
        $productId = (int) $createProdRes->json('data.id');
        $productMasterId = (int) $createProdRes->json('data.productMasterId');
        $this->assertGreaterThan(0, $productId);
        $this->assertGreaterThan(0, $productMasterId);

        // Verify parent line in g2g_product_master
        $productLine = DB::table('g2g_product_master')->where('id', $productMasterId)->first();
        $this->assertNotNull($productLine);
        $this->assertSame('ScholarClone', $productLine->name);
        $this->assertSame(ProductMaster::KIND_PRODUCT, $productLine->kind);

        // Verify SKU row in g2g_product_master binds to parent product line
        $skuMaster = DB::table('g2g_product_master')->where('crm_product_id', $productId)->first();
        $this->assertNotNull($skuMaster);
        $this->assertSame($productMasterId, (int) $skuMaster->parent_id);

        // =====================================================================
        // Stage 2 (Lead Acquisition): Create Gujarat School Lead with Scoring
        // =====================================================================
        $campaignId = DB::table('crm_campaigns')->insertGetId([
            'sub_institute_id' => self::TENANT_ID,
            'campaign_no' => 'CMP-GUJ-TEST',
            'name' => 'Gujarat K-12 Outreach Campaign',
            'product_master_id' => $productMasterId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createLeadRes = $this->postJson('/api/crm/leads', [
            'firstName' => 'Hasmukh',
            'lastName' => 'Patel',
            'company' => 'Gujarat International Public School',
            'designation' => 'Principal',
            'email' => 'principal@gips-ahmedabad.edu.in',
            'phone' => '+91 98250 11223',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'country' => 'India',
            'industry' => 'Education',
            'rating' => 'Hot',
            'productMasterId' => $productMasterId,
            'campaignId' => $campaignId,
            'assignedTo' => $userId,
        ], $headers);

        $createLeadRes->assertStatus(201);
        $leadId = (int) $createLeadRes->json('data.id');
        $this->assertGreaterThan(0, $leadId);
        $this->assertSame('Gujarat', $createLeadRes->json('data.state'));
        $this->assertSame($productMasterId, (int) $createLeadRes->json('data.productMasterId'));

        // Verify rule-based lead scoring fired (25 + 15 = 40)
        $leadScore = (int) $createLeadRes->json('data.leadScore');
        $this->assertSame(40, $leadScore, 'Lead score must calculate correctly based on Education + Hot rules');
        $this->assertNotEmpty($createLeadRes->json('data.leadScoreBasis'));

        // =====================================================================
        // Stage 3 (Conversion): Convert Lead -> Organization, Contact, Opportunity
        // =====================================================================
        $convertRes = $this->postJson("/api/crm/leads/{$leadId}/convert", [
            'createOrganization' => true,
            'createContact' => true,
            'createOpportunity' => true,
            'assignedTo' => $userId,
        ], $headers);

        $convertRes->assertStatus(200);
        $this->assertSame(1, $convertRes->json('status'));
        $orgId = (int) $convertRes->json('data.organizationId');
        $contactId = (int) $convertRes->json('data.contactId');
        $oppId = (int) $convertRes->json('data.opportunityId');

        $this->assertGreaterThan(0, $orgId);
        $this->assertGreaterThan(0, $contactId);
        $this->assertGreaterThan(0, $oppId);

        // Verify Organization details & Gujarat state scoping
        $org = DB::table('crm_organizations')->where('id', $orgId)->first();
        $this->assertNotNull($org);
        $this->assertSame('Gujarat International Public School', $org->name);
        $this->assertSame('Gujarat', $org->billing_state);
        $this->assertSame('Ahmedabad', $org->billing_city);

        // Verify Contact details
        $contact = DB::table('crm_contacts')->where('id', $contactId)->first();
        $this->assertNotNull($contact);
        $this->assertSame('Hasmukh', $contact->first_name);
        $this->assertSame('Patel', $contact->last_name);
        $this->assertSame($orgId, (int) $contact->organization_id);

        // Verify Opportunity product and campaign association survives conversion
        $opp = DB::table('crm_opportunities')->where('id', $oppId)->first();
        $this->assertNotNull($opp);
        $this->assertSame($productMasterId, (int) $opp->product_master_id, 'Opportunity must inherit product_master_id from Lead');
        $this->assertSame($campaignId, (int) $opp->campaign_id, 'Opportunity must inherit campaign_id from Lead');

        // Verify no duplicate contacts created
        $contactCount = DB::table('crm_contacts')
            ->where('sub_institute_id', self::TENANT_ID)
            ->where('organization_id', $orgId)
            ->count();
        $this->assertSame(1, $contactCount);

        // =====================================================================
        // Stage 4 & 5 (CPQ & Pipeline): Create Quote, Configure Discount & Tax
        // =====================================================================
        $createQuoteRes = $this->postJson('/api/crm/quotes', [
            'subject' => 'Quote - Gujarat International Public School (ScholarClone)',
            'organizationId' => $orgId,
            'contactId' => $contactId,
            'opportunityId' => $oppId,
            'quoteStage' => 'Created',
            'currency' => 'INR',
            'assignedTo' => $userId,
        ], $headers);

        $createQuoteRes->assertStatus(201);
        $quoteId = (int) $createQuoteRes->json('data.id');
        $this->assertGreaterThan(0, $quoteId);

        // Add line item: 1x Scholar One ERP @ 120,000 INR, 10% discount, 18% tax
        // Subtotal: 120,000
        // Discount 10%: 12,000
        // Net: 108,000
        // Tax 18%: 19,440
        // Total: 127,440
        $lineItemsRes = $this->putJson("/api/crm/quotes/{$quoteId}/line-items", [
            'lineItems' => [
                [
                    'productId' => $productId,
                    'quantity' => 1,
                    'unitPrice' => 120000.00,
                    'discountPercent' => 10.00,
                    'taxRateId' => $taxRateId,
                ],
            ],
        ], $headers);

        $lineItemsRes->assertStatus(200);

        // Check computed values on Quote
        $quote = DB::table('crm_quotes')->where('id', $quoteId)->first();
        $this->assertEquals(120000.00, (float) $quote->subtotal);
        $this->assertEquals(12000.00, (float) $quote->discount_amount);
        $this->assertEquals(19440.00, (float) $quote->tax_total);
        $this->assertEquals(127440.00, (float) $quote->total);

        // =====================================================================
        // Stage 6 (Fulfillment & Billing): Quote -> Sales Order -> Invoice
        // =====================================================================
        // 6a. Convert Quote -> Sales Order
        $toSoRes = $this->postJson("/api/crm/sales-orders/from-quote/{$quoteId}", [], $headers);
        $toSoRes->assertStatus(201);
        $salesOrderId = (int) $toSoRes->json('data.id');
        $this->assertGreaterThan(0, $salesOrderId);

        $salesOrder = DB::table('crm_sales_orders')->where('id', $salesOrderId)->first();
        $this->assertNotNull($salesOrder);
        $this->assertEquals(127440.00, (float) $salesOrder->total);

        // 6b. Convert Sales Order -> Invoice
        $toInvoiceRes = $this->postJson("/api/crm/invoices/from-sales-order/{$salesOrderId}", [], $headers);
        $toInvoiceRes->assertStatus(201);
        $invoiceId = (int) $toInvoiceRes->json('data.id');
        $this->assertGreaterThan(0, $invoiceId);

        $invoice = DB::table('crm_invoices')->where('id', $invoiceId)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(127440.00, (float) $invoice->total);

        // 6c. Assert mathematical ZERO VARIANCE between Sales Order and Invoice
        $this->assertSame(
            (float) $salesOrder->total,
            (float) $invoice->total,
            'Zero variance financial reconciliation invariant: sales_orders.total == invoices.total'
        );
        $this->assertSame(
            (float) $salesOrder->subtotal,
            (float) $invoice->subtotal,
            'Zero variance subtotal reconciliation invariant'
        );
        $this->assertSame(
            (float) $salesOrder->tax_total,
            (float) $invoice->tax_total,
            'Zero variance tax reconciliation invariant'
        );

        // =====================================================================
        // Stage 7 & 8 (Outreach & Mailbox): Verify Tenant 6 Outreach Readiness
        // =====================================================================
        config(['gtm.outreach.sending_enabled' => false]);
        DB::table('school_setup')->updateOrInsert(['id' => self::TENANT_ID], ['outbound_email_enabled' => 1]);

        DB::table('smtp_details')->insert([
            'sub_institute_id' => self::TENANT_ID,
            'module_key' => 'gtm_outreach',
            'gmail' => 'scholarclone-outreach@scholarclone.edu.in',
            'password' => Crypt::encryptString('secure_pass_123'),
            'server_address' => 'smtp.scholarclone.edu.in',
            'port' => '587',
            'from_name' => 'ScholarClone Gujarat Outreach Desk',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mailer = app(OutreachMailer::class);
        $readiness = $mailer->readiness(self::TENANT_ID);

        $this->assertTrue($readiness['gtm_sending_enabled'], 'Tenant SMTP mailbox must enable sending');
        $this->assertTrue($readiness['can_send'], 'Outreach readiness must report can_send = TRUE');
        $this->assertSame('smtp.scholarclone.edu.in', $readiness['host']);
        $this->assertSame('scholarclone-outreach@scholarclone.edu.in', $readiness['from']);
        $this->assertSame('ScholarClone Gujarat Outreach Desk', $readiness['from_name']);
        $this->assertEmpty($readiness['blockers']);
    }
}
