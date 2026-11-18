<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\SalesDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

class SalesOrderInvoiceReconciliationTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const INVOICE_LINK = '/module/crm/sales/invoices';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();

        foreach ([
            '2026_11_24_080100_create_crm_tax_rates_table',
            '2026_11_24_080200_create_crm_products_table',
            '2026_11_24_080700_create_crm_quotes_table',
            '2026_11_24_082400_create_crm_sales_orders_table',
            '2026_11_24_082500_create_crm_sales_order_line_items_table',
            '2026_11_24_082800_create_crm_invoices_table',
            '2026_11_24_082900_create_crm_invoice_line_items_table',
            '2026_11_30_080100_add_payment_tracking_to_crm_invoices_table',
            '2026_11_24_083000_create_crm_order_to_cash_status_history_tables',
            '2026_08_10_150000_create_g2g_audit_log',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id' => 475,
            'menu_name' => 'Invoices',
            'access_link' => self::INVOICE_LINK,
        ]);
    }

    public function test_sales_order_conversion_to_invoice_has_zero_financial_variance(): void
    {
        $user = $this->user([self::INVOICE_LINK => ['view', 'add', 'edit', 'delete']], self::T);

        // 1. Create a sales order with line items, shipping handling and adjustment
        $subtotal = 2500.00;
        $discountAmount = 150.00;
        $taxTotal = 423.00;
        $shipping = 120.00;
        $adjustment = -45.00;
        $expectedTotal = round($subtotal - $discountAmount + $taxTotal + $shipping + $adjustment, 2); // 2848.00

        $soId = DB::table('crm_sales_orders')->insertGetId([
            'so_no' => 'SO-TEST-001',
            'subject' => 'School Infrastructure Expansion',
            'currency' => 'INR',
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_total' => $taxTotal,
            'shipping_handling_amount' => $shipping,
            'adjustment' => $adjustment,
            'total' => $expectedTotal,
            'so_stage' => 'Approved',
            'assigned_to' => $user['id'],
            'sub_institute_id' => self::T,
            'created_by' => $user['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('crm_products')->insertGetId([
            'name' => 'ERP License Tier 1',
            'product_no' => 'PRD-001',
            'item_type' => 'product',
            'sub_institute_id' => self::T,
            'unit_price' => 250.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('crm_sales_order_line_items')->insert([
            [
                'sales_order_id' => $soId,
                'product_id' => $productId,
                'description' => 'ERP License Tier 1',
                'quantity' => 10,
                'unit_price' => 250.00,
                'discount_percent' => 6.0,
                'discount_amount' => 150.00,
                'tax_rate_id' => null,
                'tax_name_snapshot' => 'GST',
                'tax_percent_snapshot' => 18.0,
                'line_total' => 2773.00,
                'sequence_no' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 2. Call conversion endpoint
        $res = $this->postJson("/api/crm/invoices/from-sales-order/{$soId}", [], $user['h'])->assertCreated();

        $invoiceId = (int) $res->json('data.id');
        $this->assertGreaterThan(0, $invoiceId);

        // 3. Verify invoice header and reconciliation
        $invoice = DB::table('crm_invoices')->where('id', $invoiceId)->first();
        $this->assertNotNull($invoice);

        $this->assertSame($soId, (int) $invoice->sales_order_id);
        $this->assertSame((float) $shipping, (float) $invoice->shipping_handling_amount, 'shipping_handling_amount must match sales order exactly');
        $this->assertSame((float) $adjustment, (float) $invoice->adjustment, 'adjustment must match sales order exactly');
        $this->assertSame((float) $expectedTotal, (float) $invoice->total, 'invoice total must mathematically match sales order total');

        // Assert ZERO financial variance
        $salesOrder = DB::table('crm_sales_orders')->where('id', $soId)->first();
        $variance = round((float) $invoice->total - (float) $salesOrder->total, 2);
        $this->assertSame(0.0, $variance, 'There must be zero financial variance between Sales Order and Invoice');
    }
}
