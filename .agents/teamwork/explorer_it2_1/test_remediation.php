<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Support\ColumnExists;

$conn = 'mysql';
$userId = 28;
$tenantId = 6;

echo "=== DRY RUN / TEST OF REMEDIATION LOGIC ===" . PHP_EOL;

// 1. Leads enrichment check
$leads = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

$leadUpdatesCount = 0;
foreach ($leads as $l) {
    $updates = [];
    if (empty($l->product_master_id) && ColumnExists::has('crm_leads', 'product_master_id', $conn)) {
        $updates['product_master_id'] = 1;
    }
    if (empty($l->campaign_id) && ColumnExists::has('crm_leads', 'campaign_id', $conn)) {
        $firstCamp = DB::connection($conn)->table('crm_campaigns')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->value('id');
        if ($firstCamp) {
            $updates['campaign_id'] = $firstCamp;
        }
    }
    if (empty($l->description)) {
        $updates['description'] = 'Prospective Gujarat K-12 institution evaluating ScholarClone ERP suite.';
    }
    if (empty($l->salutation)) {
        $updates['salutation'] = 'Dr.';
    }
    if (!empty($updates)) {
        $leadUpdatesCount++;
        echo "Lead #{$l->id} would update: " . json_encode($updates) . PHP_EOL;
    }
}
echo "Total leads to update: {$leadUpdatesCount}" . PHP_EOL;

// 2. Org description check
$orgs = DB::connection($conn)->table('crm_organizations')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();
$orgUpdatesCount = 0;
foreach ($orgs as $org) {
    if (empty($org->description)) {
        $orgUpdatesCount++;
        echo "Org #{$org->id} ({$org->name}) would update description." . PHP_EOL;
    }
}
echo "Total orgs to update: {$orgUpdatesCount}" . PHP_EOL;

// 3. Contact #20 org linking check
$contacts = DB::connection($conn)->table('crm_contacts')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();
$contactUpdatesCount = 0;
foreach ($contacts as $c) {
    $updates = [];
    if (empty($c->organization_id)) {
        $firstOrg = DB::connection($conn)->table('crm_organizations')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->value('id');
        if ($firstOrg) {
            $updates['organization_id'] = $firstOrg;
        }
    }
    if (empty($c->salutation)) {
        $updates['salutation'] = 'Mr.';
    }
    if (empty($c->lead_source)) {
        $updates['lead_source'] = 'Gujarat EdTech Summit 2026';
    }
    if (empty($c->description)) {
        $updates['description'] = 'Authorized institutional stakeholder for ScholarClone ERP coordination.';
    }
    if (!empty($updates)) {
        $contactUpdatesCount++;
        echo "Contact #{$c->id} would update: " . json_encode($updates) . PHP_EOL;
    }
}
echo "Total contacts to update: {$contactUpdatesCount}" . PHP_EOL;

// 4. Quotes description check
$quotes = DB::connection($conn)->table('crm_quotes')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();
$quoteUpdatesCount = 0;
foreach ($quotes as $q) {
    $updates = [];
    if (empty($q->description)) {
        $updates['description'] = 'Formal CPQ Proposal for ScholarClone ERP Suite and Implementation.';
    }
    if (empty($q->created_by)) {
        $updates['created_by'] = $userId;
    }
    if (!empty($updates)) {
        $quoteUpdatesCount++;
        echo "Quote #{$q->id} would update: " . json_encode($updates) . PHP_EOL;
    }
}
echo "Total quotes to update: {$quoteUpdatesCount}" . PHP_EOL;

// 5. Sales Orders & Invoices check
$salesOrders = DB::connection($conn)->table('crm_sales_orders')
    ->where('sub_institute_id', $tenantId)
    ->whereNull('deleted_at')
    ->get();

$soUpdatesCount = 0;
$invUpdatesCount = 0;
foreach ($salesOrders as $so) {
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
        $soUpdatesCount++;
        echo "SO #{$so->id} would update: " . json_encode(array_keys($soUpdates)) . PHP_EOL;
    }

    $inv = DB::connection($conn)->table('crm_invoices')->where('sales_order_id', $so->id)->first();
    if ($inv) {
        $invUpdates = [];
        if (empty($inv->billing_street)) $invUpdates['billing_street'] = $street;
        if (empty($inv->billing_city)) $invUpdates['billing_city'] = $city;
        if (empty($inv->billing_state)) $invUpdates['billing_state'] = $state;
        if (empty($inv->billing_code)) $invUpdates['billing_code'] = $code;
        if (empty($inv->billing_country)) $invUpdates['billing_country'] = $country;

        if (empty($inv->shipping_street)) $invUpdates['shipping_street'] = $shipStreet;
        if (empty($inv->shipping_city)) $invUpdates['shipping_city'] = $shipCity;
        if (empty($inv->shipping_state)) $invUpdates['shipping_state'] = $shipState;
        if (empty($inv->shipping_code)) $invUpdates['shipping_code'] = $shipCode;
        if (empty($inv->shipping_country)) $invUpdates['shipping_country'] = $shipCountry;

        if (empty($inv->due_date)) $invUpdates['due_date'] = $so->due_date ?: now()->addDays(30)->toDateString();
        if (empty($inv->terms_conditions)) $invUpdates['terms_conditions'] = 'Bank Details for RTGS/NEFT: HDFC Bank, A/C: 50200012345678, IFSC: HDFC0000006';
        if (empty($inv->description)) $invUpdates['description'] = 'Tax Invoice for ScholarClone Software Licenses and Deployment.';
        if (empty($inv->assigned_to)) $invUpdates['assigned_to'] = $userId;
        if (empty($inv->created_by)) $invUpdates['created_by'] = $userId;

        if (!empty($invUpdates)) {
            $invUpdatesCount++;
            echo "INV #{$inv->id} would update: " . json_encode(array_keys($invUpdates)) . PHP_EOL;
        }
    }
}

echo "Total SOs to update: {$soUpdatesCount}" . PHP_EOL;
echo "Total INVs to update: {$invUpdatesCount}" . PHP_EOL;
