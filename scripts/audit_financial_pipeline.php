<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';
$tenantId = 6;

echo "=================================================================\n";
echo " EMPIRICAL ADVERSARIAL AUDIT: FINANCIAL & PIPELINE RECONCILIATION\n";
echo " Target Tenant: " . $tenantId . " | Connection: " . $conn . "\n";
echo " Database: " . DB::connection($conn)->getDatabaseName() . "\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// PART 1: SALES ORDER VS INVOICE RECONCILIATION
// -------------------------------------------------------------
echo ">>> PART 1: SALES ORDER VS INVOICE RECONCILIATION\n";

$salesOrders = DB::connection($conn)->table('crm_sales_orders')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

$invoices = DB::connection($conn)->table('crm_invoices')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

echo "Total Sales Orders for Tenant 6: " . count($salesOrders) . "\n";
echo "Total Invoices for Tenant 6:     " . count($invoices) . "\n";

$totalVariance = 0.0;
$soInvDiscrepancies = [];
$matchedInvoiceIds = [];

foreach ($salesOrders as $so) {
    // Look for matching invoice by sales_order_id
    $inv = DB::connection($conn)->table('crm_invoices')
        ->where('sales_order_id', $so->id)
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->first();

    if (!$inv) {
        $soInvDiscrepancies[] = "CRITICAL: Sales Order ID {$so->id} ({$so->so_no}) has no matching invoice!";
        continue;
    }

    $matchedInvoiceIds[] = $inv->id;

    $soTotal = (float) $so->total;
    $invTotal = (float) $inv->total;
    $soSubtotal = (float) $so->subtotal;
    $invSubtotal = (float) $inv->subtotal;
    $soDiscount = (float) $so->discount_amount;
    $invDiscount = (float) $inv->discount_amount;
    $soTax = (float) $so->tax_total;
    $invTax = (float) $inv->tax_total;
    $invPaid = (float) $inv->amount_paid;

    $diffTotal = abs($soTotal - $invTotal);
    $diffSubtotal = abs($soSubtotal - $invSubtotal);
    $diffDiscount = abs($soDiscount - $invDiscount);
    $diffTax = abs($soTax - $invTax);
    $diffPaid = abs($invTotal - $invPaid);

    if ($diffTotal > 0.001) {
        $soInvDiscrepancies[] = "FAIL: SO #{$so->id} ({$so->so_no}) total {$soTotal} != INV #{$inv->id} ({$inv->invoice_no}) total {$invTotal} (diff: {$diffTotal})";
    }
    if ($diffSubtotal > 0.001) {
        $soInvDiscrepancies[] = "FAIL: SO #{$so->id} subtotal {$soSubtotal} != INV #{$inv->id} subtotal {$invSubtotal}";
    }
    if ($diffDiscount > 0.001) {
        $soInvDiscrepancies[] = "FAIL: SO #{$so->id} discount {$soDiscount} != INV #{$inv->id} discount {$invDiscount}";
    }
    if ($diffTax > 0.001) {
        $soInvDiscrepancies[] = "FAIL: SO #{$so->id} tax {$soTax} != INV #{$inv->id} tax {$invTax}";
    }
    if ($diffPaid > 0.001) {
        $soInvDiscrepancies[] = "FAIL: INV #{$inv->id} total {$invTotal} != amount_paid {$invPaid}";
    }

    // Line items verification
    $soItems = DB::connection($conn)->table('crm_sales_order_line_items')
        ->where('sales_order_id', $so->id)
        ->orderBy('sequence_no')
        ->get();

    $invItems = DB::connection($conn)->table('crm_invoice_line_items')
        ->where('invoice_id', $inv->id)
        ->orderBy('sequence_no')
        ->get();

    if (count($soItems) !== count($invItems)) {
        $soInvDiscrepancies[] = "FAIL: SO #{$so->id} has " . count($soItems) . " line items but INV #{$inv->id} has " . count($invItems) . " line items!";
    } else {
        for ($i = 0; $i < count($soItems); $i++) {
            $si = $soItems[$i];
            $ii = $invItems[$i];
            if ($si->product_id != $ii->product_id) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} product_id {$si->product_id} != INV #{$inv->id} item product_id {$ii->product_id}";
            }
            if (abs((float)$si->quantity - (float)$ii->quantity) > 0.001) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} qty {$si->quantity} != INV #{$inv->id} item qty {$ii->quantity}";
            }
            if (abs((float)$si->unit_price - (float)$ii->unit_price) > 0.001) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} unit_price {$si->unit_price} != INV #{$inv->id} item unit_price {$ii->unit_price}";
            }
            if (abs((float)$si->discount_amount - (float)$ii->discount_amount) > 0.001) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} discount_amount {$si->discount_amount} != INV #{$inv->id} item discount_amount {$ii->discount_amount}";
            }
            if (abs((float)$si->line_total - (float)$ii->line_total) > 0.001) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} line_total {$si->line_total} != INV #{$inv->id} item line_total {$ii->line_total}";
            }
            $siTaxPct = (float)($si->tax_percent_snapshot ?? $si->tax_percent ?? 0);
            $iiTaxPct = (float)($ii->tax_percent_snapshot ?? $ii->tax_percent ?? 0);
            if (abs($siTaxPct - $iiTaxPct) > 0.001) {
                $soInvDiscrepancies[] = "FAIL: SO #{$so->id} item {$i} tax_percent {$siTaxPct} != INV #{$inv->id} item tax_percent {$iiTaxPct}";
            }
        }
    }

    echo sprintf(
        "SO #%d (%s, total: ₹%.2f, items: %d) <==> INV #%d (%s, total: ₹%.2f, items: %d, paid: ₹%.2f) | diff: ₹%.4f\n",
        $so->id, $so->so_no, $soTotal, count($soItems),
        $inv->id, $inv->invoice_no, $invTotal, count($invItems), $invPaid,
        $diffTotal
    );
}

// Check orphaned invoices
$orphanedInvoices = DB::connection($conn)->table('crm_invoices')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->whereNotIn('id', $matchedInvoiceIds)
    ->get();

if (count($orphanedInvoices) > 0) {
    foreach ($orphanedInvoices as $oi) {
        $soInvDiscrepancies[] = "WARN: Invoice #{$oi->id} ({$oi->invoice_no}) is not mapped to any processed SO (sales_order_id: {$oi->sales_order_id})";
    }
}

echo "Discrepancies found in SO vs INV: " . count($soInvDiscrepancies) . "\n";
foreach ($soInvDiscrepancies as $disc) {
    echo "  -> " . $disc . "\n";
}

// -------------------------------------------------------------
// PART 2: LINE ITEM DETAILS & 18% GST TAX SNAPSHOT AUDIT
// -------------------------------------------------------------
echo "\n>>> PART 2: LINE ITEM DETAILS & 18% GST TAX SNAPSHOT AUDIT\n";

$taxErrors = [];
$lineCount = 0;

// Audit Quotes
$quotes = DB::connection($conn)->table('crm_quotes')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

echo "Auditing " . count($quotes) . " Quotes line items & tax snapshots...\n";
foreach ($quotes as $q) {
    $qItems = DB::connection($conn)->table('crm_quote_line_items')
        ->where('quote_id', $q->id)
        ->get();

    $calcSubtotal = 0.0;
    $calcDiscount = 0.0;
    $calcTax = 0.0;

    foreach ($qItems as $item) {
        $lineCount++;
        $qty = (float) $item->quantity;
        $unitPrice = (float) $item->unit_price;
        $discPct = (float) $item->discount_percent;
        $discAmt = (float) $item->discount_amount;
        $taxPct = (float) $item->tax_percent_snapshot;
        $lineTotal = (float) $item->line_total;

        $gross = round($qty * $unitPrice, 2);
        $expectedDisc = round($gross * ($discPct / 100), 2);
        $net = $gross - $discAmt;
        $expectedTax = round($net * 0.18, 2);
        $expectedLineTotal = round($net + $expectedTax, 2);

        $calcSubtotal += $gross;
        $calcDiscount += $discAmt;
        $calcTax += $expectedTax;

        if (abs($discAmt - $expectedDisc) > 0.01) {
            $taxErrors[] = "Quote #{$q->id} Item {$item->id}: discount_amount {$discAmt} != expected {$expectedDisc}";
        }
        if (abs($taxPct - 18.0) > 0.001) {
            $taxErrors[] = "Quote #{$q->id} Item {$item->id}: tax_percent_snapshot {$taxPct} != 18.0";
        }
        if (abs($lineTotal - $expectedLineTotal) > 0.01) {
            $taxErrors[] = "Quote #{$q->id} Item {$item->id}: line_total {$lineTotal} != expected {$expectedLineTotal}";
        }
    }

    $expectedGrand = round($calcSubtotal - $calcDiscount + $calcTax + (float)$q->shipping_handling_amount + (float)$q->adjustment, 2);
    if (abs((float)$q->subtotal - $calcSubtotal) > 0.01) {
        $taxErrors[] = "Quote #{$q->id} subtotal {$q->subtotal} != sum of items {$calcSubtotal}";
    }
    if (abs((float)$q->discount_amount - $calcDiscount) > 0.01) {
        $taxErrors[] = "Quote #{$q->id} discount_amount {$q->discount_amount} != sum of items {$calcDiscount}";
    }
    if (abs((float)$q->tax_total - $calcTax) > 0.01) {
        $taxErrors[] = "Quote #{$q->id} tax_total {$q->tax_total} != sum of item taxes {$calcTax}";
    }
    if (abs((float)$q->total - $expectedGrand) > 0.01) {
        $taxErrors[] = "Quote #{$q->id} total {$q->total} != calculated grand {$expectedGrand}";
    }
}

// Audit Sales Orders
echo "Auditing " . count($salesOrders) . " Sales Orders line items & tax snapshots...\n";
foreach ($salesOrders as $so) {
    $soItems = DB::connection($conn)->table('crm_sales_order_line_items')
        ->where('sales_order_id', $so->id)
        ->get();

    $calcSubtotal = 0.0;
    $calcDiscount = 0.0;
    $calcTax = 0.0;

    foreach ($soItems as $item) {
        $lineCount++;
        $qty = (float) $item->quantity;
        $unitPrice = (float) $item->unit_price;
        $discPct = (float) $item->discount_percent;
        $discAmt = (float) $item->discount_amount;
        $taxPct = (float) $item->tax_percent_snapshot;
        $lineTotal = (float) $item->line_total;

        $gross = round($qty * $unitPrice, 2);
        $expectedDisc = round($gross * ($discPct / 100), 2);
        $net = $gross - $discAmt;
        $expectedTax = round($net * 0.18, 2);
        $expectedLineTotal = round($net + $expectedTax, 2);

        $calcSubtotal += $gross;
        $calcDiscount += $discAmt;
        $calcTax += $expectedTax;

        if (abs($discAmt - $expectedDisc) > 0.01) {
            $taxErrors[] = "SO #{$so->id} Item {$item->id}: discount_amount {$discAmt} != expected {$expectedDisc}";
        }
        if (abs($taxPct - 18.0) > 0.001) {
            $taxErrors[] = "SO #{$so->id} Item {$item->id}: tax_percent_snapshot {$taxPct} != 18.0";
        }
        if (abs($lineTotal - $expectedLineTotal) > 0.01) {
            $taxErrors[] = "SO #{$so->id} Item {$item->id}: line_total {$lineTotal} != expected {$expectedLineTotal}";
        }
    }

    $expectedGrand = round($calcSubtotal - $calcDiscount + $calcTax + (float)$so->shipping_handling_amount + (float)$so->adjustment, 2);
    if (abs((float)$so->subtotal - $calcSubtotal) > 0.01) {
        $taxErrors[] = "SO #{$so->id} subtotal {$so->subtotal} != sum of items {$calcSubtotal}";
    }
    if (abs((float)$so->discount_amount - $calcDiscount) > 0.01) {
        $taxErrors[] = "SO #{$so->id} discount_amount {$so->discount_amount} != sum of items {$calcDiscount}";
    }
    if (abs((float)$so->tax_total - $calcTax) > 0.01) {
        $taxErrors[] = "SO #{$so->id} tax_total {$so->tax_total} != sum of item taxes {$calcTax}";
    }
    if (abs((float)$so->total - $expectedGrand) > 0.01) {
        $taxErrors[] = "SO #{$so->id} total {$so->total} != calculated grand {$expectedGrand}";
    }
}

// Audit Invoices
echo "Auditing " . count($invoices) . " Invoices line items & tax snapshots...\n";
foreach ($invoices as $inv) {
    $invItems = DB::connection($conn)->table('crm_invoice_line_items')
        ->where('invoice_id', $inv->id)
        ->get();

    $calcSubtotal = 0.0;
    $calcDiscount = 0.0;
    $calcTax = 0.0;

    foreach ($invItems as $item) {
        $lineCount++;
        $qty = (float) $item->quantity;
        $unitPrice = (float) $item->unit_price;
        $discPct = (float) $item->discount_percent;
        $discAmt = (float) $item->discount_amount;
        $taxPct = (float) $item->tax_percent_snapshot;
        $lineTotal = (float) $item->line_total;

        $gross = round($qty * $unitPrice, 2);
        $expectedDisc = round($gross * ($discPct / 100), 2);
        $net = $gross - $discAmt;
        $expectedTax = round($net * 0.18, 2);
        $expectedLineTotal = round($net + $expectedTax, 2);

        $calcSubtotal += $gross;
        $calcDiscount += $discAmt;
        $calcTax += $expectedTax;

        if (abs($discAmt - $expectedDisc) > 0.01) {
            $taxErrors[] = "INV #{$inv->id} Item {$item->id}: discount_amount {$discAmt} != expected {$expectedDisc}";
        }
        if (abs($taxPct - 18.0) > 0.001) {
            $taxErrors[] = "INV #{$inv->id} Item {$item->id}: tax_percent_snapshot {$taxPct} != 18.0";
        }
        if (abs($lineTotal - $expectedLineTotal) > 0.01) {
            $taxErrors[] = "INV #{$inv->id} Item {$item->id}: line_total {$lineTotal} != expected {$expectedLineTotal}";
        }
    }

    $expectedGrand = round($calcSubtotal - $calcDiscount + $calcTax + (float)$inv->shipping_handling_amount + (float)$inv->adjustment, 2);
    if (abs((float)$inv->subtotal - $calcSubtotal) > 0.01) {
        $taxErrors[] = "INV #{$inv->id} subtotal {$inv->subtotal} != sum of items {$calcSubtotal}";
    }
    if (abs((float)$inv->discount_amount - $calcDiscount) > 0.01) {
        $taxErrors[] = "INV #{$inv->id} discount_amount {$inv->discount_amount} != sum of items {$calcDiscount}";
    }
    if (abs((float)$inv->tax_total - $calcTax) > 0.01) {
        $taxErrors[] = "INV #{$inv->id} tax_total {$inv->tax_total} != sum of item taxes {$calcTax}";
    }
    if (abs((float)$inv->total - $expectedGrand) > 0.01) {
        $taxErrors[] = "INV #{$inv->id} total {$inv->total} != calculated grand {$expectedGrand}";
    }
}

echo "Total line items audited: " . $lineCount . "\n";
echo "Tax / calculation errors found: " . count($taxErrors) . "\n";
foreach ($taxErrors as $err) {
    echo "  -> " . $err . "\n";
}

// -------------------------------------------------------------
// PART 3: INVOICE PAYMENTS AUDIT
// -------------------------------------------------------------
echo "\n>>> PART 3: INVOICE PAYMENTS AUDIT (crm_invoice_payments)\n";

$paymentErrors = [];
foreach ($invoices as $inv) {
    $payments = DB::connection($conn)->table('crm_invoice_payments')
        ->where('invoice_id', $inv->id)
        ->get();

    $sumPaid = 0.0;
    foreach ($payments as $p) {
        $sumPaid += (float) $p->amount;
    }

    $invTotal = (float) $inv->total;
    $invAmountPaid = (float) $inv->amount_paid;

    echo sprintf(
        "INV #%d (%s): Total: ₹%.2f | amount_paid: ₹%.2f | Payments Sum: ₹%.2f (Payments count: %d)\n",
        $inv->id, $inv->invoice_no, $invTotal, $invAmountPaid, $sumPaid, count($payments)
    );

    if (count($payments) === 0) {
        $paymentErrors[] = "CRITICAL: Invoice #{$inv->id} has no payment records in crm_invoice_payments!";
    }
    if (abs($sumPaid - $invTotal) > 0.001) {
        $paymentErrors[] = "FAIL: Invoice #{$inv->id} payments sum ₹{$sumPaid} != invoice total ₹{$invTotal}";
    }
    if (abs($invAmountPaid - $invTotal) > 0.001) {
        $paymentErrors[] = "FAIL: Invoice #{$inv->id} amount_paid ₹{$invAmountPaid} != invoice total ₹{$invTotal}";
    }
}

echo "Payment errors found: " . count($paymentErrors) . "\n";
foreach ($paymentErrors as $perr) {
    echo "  -> " . $perr . "\n";
}

// -------------------------------------------------------------
// PART 4: OPPORTUNITY PIPELINE COVERAGE AUDIT
// -------------------------------------------------------------
echo "\n>>> PART 4: OPPORTUNITY PIPELINE COVERAGE AUDIT\n";

$expectedStages = [
    'Prospecting',
    'Qualification',
    'Needs Analysis',
    'Value Proposition',
    'Identify Decision Makers',
    'Proposal/Quotation',
    'Negotiation/Review',
    'Closed Won'
];

$opps = DB::connection($conn)->table('crm_opportunities')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

echo "Total Opportunities for Tenant 6: " . count($opps) . "\n";

$stagesFound = [];
$oppErrors = [];

foreach ($opps as $opp) {
    $stg = $opp->sales_stage;
    if (!isset($stagesFound[$stg])) {
        $stagesFound[$stg] = [
            'count' => 0,
            'total_amount' => 0.0,
            'probabilities' => [],
            'amounts' => [],
            'forecast_categories' => [],
        ];
    }
    $stagesFound[$stg]['count']++;
    $stagesFound[$stg]['total_amount'] += (float) $opp->amount;
    $stagesFound[$stg]['probabilities'][] = (float) $opp->probability;
    $stagesFound[$stg]['amounts'][] = (float) $opp->amount;
    $stagesFound[$stg]['forecast_categories'][] = $opp->forecast_category;

    // Check probability boundaries
    $prob = (float) $opp->probability;
    if ($prob < 0.0 || $prob > 100.0) {
        $oppErrors[] = "Opp #{$opp->id}: invalid probability {$prob}";
    }
    $amt = (float) $opp->amount;
    if ($amt <= 0.0) {
        $oppErrors[] = "Opp #{$opp->id}: non-positive amount {$amt}";
    }
}

echo "\nPipeline Stage Breakdown:\n";
echo str_pad("Stage Name", 30) . " | " . str_pad("Count", 6) . " | " . str_pad("Total Amount", 15) . " | " . str_pad("Probabilities", 20) . " | " . "Forecast Categories\n";
echo str_repeat("-", 95) . "\n";

foreach ($expectedStages as $stage) {
    if (isset($stagesFound[$stage])) {
        $data = $stagesFound[$stage];
        $probStr = implode('%, ', array_unique($data['probabilities'])) . '%';
        $fcStr = implode(', ', array_unique($data['forecast_categories']));
        echo sprintf(
            "%-30s | %6d | ₹%13.2f | %-20s | %s\n",
            $stage, $data['count'], $data['total_amount'], $probStr, $fcStr
        );
    } else {
        echo sprintf("%-30s | %6d | MISSING STAGE!\n", $stage, 0);
        $oppErrors[] = "CRITICAL: Pipeline stage '{$stage}' is completely unpopulated!";
    }
}

// Check if any unexpected stages exist
foreach ($stagesFound as $stageName => $data) {
    if (!in_array($stageName, $expectedStages, true)) {
        echo sprintf("%-30s | %6d | UNEXPECTED STAGE: ₹%.2f\n", $stageName, $data['count'], $data['total_amount']);
        $oppErrors[] = "WARN: Found unexpected stage '{$stageName}'";
    }
}

echo "\nPipeline errors found: " . count($oppErrors) . "\n";
foreach ($oppErrors as $oerr) {
    echo "  -> " . $oerr . "\n";
}

// -------------------------------------------------------------
// SUMMARY & VERDICT
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo " FINAL AUDIT SUMMARY\n";
echo "=================================================================\n";
$totalErrors = count($soInvDiscrepancies) + count($taxErrors) + count($paymentErrors) + count($oppErrors);
echo "Total Errors / Discrepancies: " . $totalErrors . "\n";

if ($totalErrors === 0) {
    echo "VERDICT: APPROVE (100% verified mathematically and structurally)\n";
} else {
    echo "VERDICT: REQUEST_CHANGES (issues detected)\n";
}
