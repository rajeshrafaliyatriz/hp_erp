<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

echo ">>> DETAILED INSPECTION OF SALES ORDERS AND INVOICES <<<\n\n";

$sos = DB::connection($conn)->table('crm_sales_orders')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->get();

foreach ($sos as $so) {
    $inv = DB::connection($conn)->table('crm_invoices')
        ->where('sales_order_id', $so->id)
        ->first();

    echo "========================================================================================\n";
    echo sprintf("SALES ORDER #%d (%s)  <===>  INVOICE #%d (%s)\n", $so->id, $so->so_no, $inv?->id, $inv?->invoice_no);
    echo "========================================================================================\n";
    echo sprintf("SO  Subtotal: ₹%10.2f | Disc: ₹%8.2f | Tax: ₹%8.2f | Total: ₹%10.2f | Stage: %s\n",
        (float)$so->subtotal, (float)$so->discount_amount, (float)$so->tax_total, (float)$so->total, $so->so_stage);
    echo sprintf("INV Subtotal: ₹%10.2f | Disc: ₹%8.2f | Tax: ₹%8.2f | Total: ₹%10.2f | Stage: %s | Paid: ₹%10.2f\n",
        (float)$inv->subtotal, (float)$inv->discount_amount, (float)$inv->tax_total, (float)$inv->total, $inv->invoice_stage, (float)$inv->amount_paid);

    // Payments
    $payments = DB::connection($conn)->table('crm_invoice_payments')->where('invoice_id', $inv->id)->get();
    foreach ($payments as $p) {
        echo sprintf("  PAYMENT: Amount: ₹%10.2f | Method: %s | Ref: %s | Paid On: %s\n",
            (float)$p->amount, $p->method, $p->reference, $p->paid_on);
    }

    echo "  LINE ITEMS COMPARISON:\n";
    $soLines = DB::connection($conn)->table('crm_sales_order_line_items')->where('sales_order_id', $so->id)->orderBy('sequence_no')->get();
    $invLines = DB::connection($conn)->table('crm_invoice_line_items')->where('invoice_id', $inv->id)->orderBy('sequence_no')->get();

    for ($i = 0; $i < max(count($soLines), count($invLines)); $i++) {
        $sl = $soLines[$i] ?? null;
        $il = $invLines[$i] ?? null;

        if ($sl) {
            echo sprintf("    SO  Line %d: Prod: %-3d | Qty: %4.1f | Unit: ₹%9.2f | Disc: ₹%7.2f (%4.1f%%) | TaxPct: %4.1f%% | Total: ₹%9.2f | Desc: %s\n",
                $sl->sequence_no, $sl->product_id, (float)$sl->quantity, (float)$sl->unit_price,
                (float)$sl->discount_amount, (float)$sl->discount_percent, (float)($sl->tax_percent_snapshot ?? $sl->tax_percent),
                (float)$sl->line_total, substr($sl->description ?? $sl->item_description ?? '', 0, 30));
        }
        if ($il) {
            echo sprintf("    INV Line %d: Prod: %-3d | Qty: %4.1f | Unit: ₹%9.2f | Disc: ₹%7.2f (%4.1f%%) | TaxPct: %4.1f%% | Total: ₹%9.2f | Desc: %s\n",
                $il->sequence_no, $il->product_id, (float)$il->quantity, (float)$il->unit_price,
                (float)$il->discount_amount, (float)$il->discount_percent, (float)($il->tax_percent_snapshot ?? $il->tax_percent),
                (float)$il->line_total, substr($il->description ?? $il->item_description ?? '', 0, 30));
        }
    }
    echo "\n";
}
