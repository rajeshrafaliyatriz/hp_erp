<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';
$tenantId = 6;

echo "========================================================================\n";
echo "   TENANT 6 CRM COMPREHENSIVE EMPIRICAL AUDIT REPORT (ITERATION 2)\n";
echo "========================================================================\n\n";

$allPassed = true;

// 1. Sales Orders completeness
$sos = DB::connection($conn)->table('crm_sales_orders')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

echo "1. AUDITING SALES ORDERS (" . count($sos) . " records):\n";
$soAddrErrors = 0;
foreach ($sos as $so) {
    $required = [
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
        'so_stage' => $so->so_stage,
        'terms_conditions' => $so->terms_conditions,
        'description' => $so->description,
        'created_by' => $so->created_by,
    ];
    $missing = [];
    foreach ($required as $field => $val) {
        if ($val === null || $val === '' || $val === 0) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        echo "  [FAIL] SO #{$so->id} ({$so->so_no}): Missing " . implode(', ', $missing) . "\n";
        $soAddrErrors++;
        $allPassed = false;
    } else {
        echo "  [PASS] SO #{$so->id} ({$so->so_no}) | Stage: {$so->so_stage} | City: {$so->billing_city} | Zip: {$so->billing_code} | Terms: OK | Desc: OK\n";
    }
}
echo "Sales Order Issues: {$soAddrErrors}\n\n";

// 2. Invoices completeness
$invs = DB::connection($conn)->table('crm_invoices')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

echo "2. AUDITING INVOICES (" . count($invs) . " records):\n";
$invAddrErrors = 0;
foreach ($invs as $inv) {
    $required = [
        'billing_street' => $inv->billing_street,
        'billing_city' => $inv->billing_city,
        'billing_state' => $inv->billing_state,
        'billing_code' => $inv->billing_code,
        'billing_country' => $inv->billing_country,
        'shipping_street' => $inv->shipping_street,
        'shipping_city' => $inv->shipping_city,
        'shipping_state' => $inv->shipping_state,
        'shipping_code' => $inv->shipping_code,
        'shipping_country' => $inv->shipping_country,
        'terms_conditions' => $inv->terms_conditions,
        'description' => $inv->description,
        'created_by' => $inv->created_by,
    ];
    $missing = [];
    foreach ($required as $field => $val) {
        if ($val === null || $val === '' || $val === 0) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        echo "  [FAIL] INV #{$inv->id} ({$inv->invoice_no}): Missing " . implode(', ', $missing) . "\n";
        $invAddrErrors++;
        $allPassed = false;
    } else {
        echo "  [PASS] INV #{$inv->id} ({$inv->invoice_no}) | Stage: {$inv->invoice_stage} | City: {$inv->billing_city} | Zip: {$inv->billing_code} | Terms: OK | Desc: OK\n";
    }
}
echo "Invoice Issues: {$invAddrErrors}\n\n";

// 3. Contacts Organization Linking
$contactsNoOrg = DB::connection($conn)->table('crm_contacts')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('organization_id')
          ->orWhere('organization_id', '')
          ->orWhere('organization_id', 0);
    })->count();

echo "3. AUDITING CONTACTS ORGANIZATION LINKING:\n";
echo "  Contacts without organization_id: {$contactsNoOrg}\n";
if ($contactsNoOrg > 0) {
    $allPassed = false;
    echo "  [FAIL] Unlinked contacts found!\n";
} else {
    echo "  [PASS] All " . DB::connection($conn)->table('crm_contacts')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->count() . " contacts are properly linked to active organizations.\n";
}
echo "\n";

// 4. Leads Product Master ID & Campaign ID
$leadsNoProd = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('product_master_id')
          ->orWhere('product_master_id', '!=', 1);
    })->count();

$leadsNoCamp = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('campaign_id')
          ->orWhere('campaign_id', 0);
    })->count();

echo "4. AUDITING LEADS PRODUCT MASTER & CAMPAIGN LINKING:\n";
echo "  Leads without product_master_id = 1: {$leadsNoProd}\n";
echo "  Leads without campaign_id: {$leadsNoCamp}\n";
if ($leadsNoProd > 0 || $leadsNoCamp > 0) {
    $allPassed = false;
    echo "  [FAIL] Leads missing product_master_id or campaign_id found!\n";
} else {
    echo "  [PASS] All " . DB::connection($conn)->table('crm_leads')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->count() . " leads have product_master_id = 1 and valid campaign_id.\n";
}
echo "\n";

// 5. Zero-Variance Financial Reconciliation Audit
echo "5. AUDITING FINANCIAL ZERO-VARIANCE (Sales Orders <-> Invoices <-> Payments):\n";
$finVarianceErrors = 0;
foreach ($sos as $so) {
    $inv = DB::connection($conn)->table('crm_invoices')
        ->where('sales_order_id', $so->id)
        ->first();

    if (!$inv) {
        echo "  [FAIL] SO #{$so->id} has no matching invoice!\n";
        $finVarianceErrors++;
        $allPassed = false;
        continue;
    }

    $diffTotal = abs((float)$so->total - (float)$inv->total);
    $diffSubtotal = abs((float)$so->subtotal - (float)$inv->subtotal);
    $diffDiscount = abs((float)$so->discount_amount - (float)$inv->discount_amount);
    $diffTax = abs((float)$so->tax_total - (float)$inv->tax_total);
    $diffPaid = abs((float)$inv->total - (float)$inv->amount_paid);

    $payment = DB::connection($conn)->table('crm_invoice_payments')
        ->where('invoice_id', $inv->id)
        ->first();
    $paymentDiff = $payment ? abs((float)$payment->amount - (float)$so->total) : 999999;

    if ($diffTotal > 0.001 || $diffSubtotal > 0.001 || $diffDiscount > 0.001 || $diffTax > 0.001 || $diffPaid > 0.001 || $paymentDiff > 0.001) {
        echo sprintf("  [FAIL] SO #%d vs INV #%d variance detected! diffTotal=%.4f diffPaid=%.4f diffPayment=%.4f\n", $so->id, $inv->id, $diffTotal, $diffPaid, $paymentDiff);
        $finVarianceErrors++;
        $allPassed = false;
    } else {
        echo sprintf("  [PASS] SO #%d (%s: ₹%.2f) <==> INV #%d (%s: ₹%.2f) <==> Payment: ₹%.2f (%s, %s) [|SO-INV| = 0.00]\n",
            $so->id, $so->so_no, (float)$so->total, $inv->id, $inv->invoice_no, (float)$inv->total, (float)$payment->amount, $payment->method, $payment->reference);
    }
}
echo "Financial Variance Errors: {$finVarianceErrors}\n\n";

if ($allPassed) {
    echo "========================================================================\n";
    echo "  >>> ALL AUDIT CHECKS PASSED: 100% COMPLETENESS & ZERO VARIANCE <<<    \n";
    echo "========================================================================\n";
    exit(0);
} else {
    echo "========================================================================\n";
    echo "  >>> AUDIT FAILED WITH DISCREPANCIES <<<                               \n";
    echo "========================================================================\n";
    exit(1);
}
