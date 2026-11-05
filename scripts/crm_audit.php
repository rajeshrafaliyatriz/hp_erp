<?php
// Final comprehensive data quality audit for Tenant 6
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$t = 6;
$pass = 0; $fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) {
        echo "  ✓ PASS  {$label}\n";
        $pass++;
    } else {
        echo "  ✗ FAIL  {$label}" . ($detail ? " ({$detail})" : '') . "\n";
        $fail++;
    }
}

echo "\n====================================================\n";
echo "   TENANT 6 CRM DATA QUALITY AUDIT\n";
echo "====================================================\n\n";

// --- COUNTS ---
echo "[ MODULE MINIMUMS ]\n";
$leads   = DB::table('crm_leads')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$orgs    = DB::table('crm_organizations')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$conts   = DB::table('crm_contacts')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$opps    = DB::table('crm_opportunities')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$quotes  = DB::table('crm_quotes')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$sos     = DB::table('crm_sales_orders')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$invs    = DB::table('crm_invoices')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$prods   = DB::table('crm_products')->where('sub_institute_id',$t)->whereNull('deleted_at')->count();
$pbs     = DB::table('crm_price_books')->where('sub_institute_id',$t)->count();
$cmpns   = DB::table('crm_campaigns')->where('sub_institute_id',$t)->count();
$sigs    = DB::table('crm_signal_inbox')->where('sub_institute_id',$t)->count();

check("Leads >= 35 (actual: {$leads})",       $leads >= 35);
check("Organizations >= 15 (actual: {$orgs})", $orgs >= 15);
check("Contacts >= 60 (actual: {$conts})",     $conts >= 60);
check("Opportunities >= 15 (actual: {$opps})", $opps >= 15);
check("Quotes >= 8 (actual: {$quotes})",        $quotes >= 8);
check("Sales Orders >= 4 (actual: {$sos})",     $sos >= 4);
check("Invoices >= 4 (actual: {$invs})",         $invs >= 4);
check("Products >= 7 (actual: {$prods})",         $prods >= 7);
check("Price Books == 3 (actual: {$pbs})",        $pbs == 3);
check("Campaigns >= 2 (actual: {$cmpns})",         $cmpns >= 2);
check("Market Signals >= 3 (actual: {$sigs})",     $sigs >= 3);

// --- PIPELINE STAGES ---
echo "\n[ OPPORTUNITY PIPELINE COVERAGE ]\n";
$stages = DB::table('crm_opportunities')
    ->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->selectRaw('sales_stage, COUNT(*) as cnt')->groupBy('sales_stage')->pluck('cnt','sales_stage');
$required = ['Prospecting','Qualification','Needs Analysis','Value Proposition',
             'Identify Decision Makers','Proposal/Quotation','Negotiation/Review','Closed Won'];
foreach ($required as $s) {
    check("Stage '{$s}' has records", ($stages[$s] ?? 0) > 0);
}

// --- ADDRESS COMPLETENESS ---
echo "\n[ ADDRESS COMPLETENESS ]\n";
$soBad = DB::table('crm_sales_orders')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->where(fn($q)=>$q->whereNull('billing_street')->orWhere('billing_street',''))->count();
check("SOs: zero empty billing_street (bad: {$soBad})", $soBad === 0);

$soShipBad = DB::table('crm_sales_orders')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->where(fn($q)=>$q->whereNull('shipping_street')->orWhere('shipping_street',''))->count();
check("SOs: zero empty shipping_street (bad: {$soShipBad})", $soShipBad === 0);

$invBilBad = DB::table('crm_invoices')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->where(fn($q)=>$q->whereNull('billing_street')->orWhere('billing_street',''))->count();
check("INVs: zero empty billing_street (bad: {$invBilBad})", $invBilBad === 0);

$invShipBad = DB::table('crm_invoices')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->where(fn($q)=>$q->whereNull('shipping_street')->orWhere('shipping_street',''))->count();
check("INVs: zero empty shipping_street (bad: {$invShipBad})", $invShipBad === 0);

// --- RELATIONSHIP INTEGRITY ---
echo "\n[ RELATIONSHIP INTEGRITY ]\n";
$contNoOrg = DB::table('crm_contacts')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->whereNull('organization_id')->count();
check("Contacts: all have organization_id (orphans: {$contNoOrg})", $contNoOrg === 0);

$oppNoOrg = DB::table('crm_opportunities')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->whereNull('organization_id')->count();
check("Opportunities: all have organization_id (orphans: {$oppNoOrg})", $oppNoOrg === 0);

$soNoOrg = DB::table('crm_sales_orders')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->whereNull('organization_id')->count();
check("Sales Orders: all have organization_id (orphans: {$soNoOrg})", $soNoOrg === 0);

// --- PRODUCT MASTER BINDING ---
echo "\n[ PRODUCT MASTER BINDING ]\n";
$leadsNoPM = DB::table('crm_leads')->where('sub_institute_id',$t)->whereNull('deleted_at')
    ->whereNull('product_master_id')->count();
check("Leads: all bound to product_master_id (unbound: {$leadsNoPM})", $leadsNoPM === 0);

$campsNoPM = DB::table('crm_campaigns')->where('sub_institute_id',$t)
    ->whereNull('product_master_id')->count();
check("Campaigns: all bound to product_master_id (unbound: {$campsNoPM})", $campsNoPM === 0);

// --- FINANCIAL INTEGRITY ---
echo "\n[ FINANCIAL INTEGRITY ]\n";
$payments = DB::table('crm_invoice_payments')
    ->join('crm_invoices','crm_invoices.id','=','crm_invoice_payments.invoice_id')
    ->where('crm_invoices.sub_institute_id',$t)
    ->whereNull('crm_invoices.deleted_at')
    ->count();
check("Invoice payments recorded (count: {$payments})", $payments >= $invs);

// --- SUMMARY ---
$total = $pass + $fail;
echo "\n====================================================\n";
echo "   RESULT: {$pass}/{$total} PASSED" . ($fail === 0 ? " — ALL CLEAR ✓" : " — {$fail} ISSUES FOUND ✗") . "\n";
echo "====================================================\n\n";
