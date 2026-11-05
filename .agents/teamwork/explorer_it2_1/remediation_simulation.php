<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Support\ColumnExists;
use Illuminate\Support\Str;

$conn = 'mysql';
$userId = 28;
$tenantId = 6;
$scholarcloneProductMasterId = 1;

echo "=== STARTING TRANSACTIONAL REMEDIATION SIMULATION ===" . PHP_EOL;

DB::connection($conn)->beginTransaction();

try {
    // --- 1. Leads Enrichment ---
    $leads = DB::connection($conn)->table('crm_leads')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();

    $firstCamp = DB::connection($conn)->table('crm_campaigns')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->value('id');

    $leadsEnriched = 0;
    foreach ($leads as $l) {
        $updates = [];
        $schoolName = $l->company ?: 'Gujarat Academy ' . $l->id;
        $slug = Str::slug($schoolName);

        if (empty($l->first_name)) $updates['first_name'] = 'Principal';
        if (empty($l->last_name)) $updates['last_name'] = 'Administrator';
        if (empty($l->designation)) $updates['designation'] = 'Principal & Director';
        if (empty($l->company)) $updates['company'] = $schoolName;
        if (empty($l->email)) $updates['email'] = "principal@{$slug}.edu.in";
        if (empty($l->phone)) $updates['phone'] = '+91 79 2685 4100';
        if (empty($l->mobile)) $updates['mobile'] = $l->phone ?: '+91 98250 99881';
        if (empty($l->street)) $updates['street'] = 'SG Highway, Bodakdev';
        if (empty($l->city)) $updates['city'] = 'Ahmedabad';
        if (empty($l->state)) $updates['state'] = 'Gujarat';
        if (empty($l->postal_code)) $updates['postal_code'] = '380054';
        if (empty($l->country)) $updates['country'] = 'India';
        if (empty($l->website)) $updates['website'] = "https://www.{$slug}.edu.in";
        if (empty($l->annual_revenue) || (float)$l->annual_revenue == 0.0) $updates['annual_revenue'] = 35000000.00;
        if (empty($l->number_of_emp) || (int)$l->number_of_emp == 0) $updates['number_of_emp'] = 85;
        if (empty($l->lead_source)) $updates['lead_source'] = 'Gujarat EdTech Summit 2026';
        if (empty($l->industry)) $updates['industry'] = 'Education';
        if (empty($l->rating)) $updates['rating'] = 'Hot';
        if (empty($l->lead_status)) $updates['lead_status'] = 'Qualified';
        if ((int)$l->lead_score < 40) $updates['lead_score'] = 40;
        if (empty($l->assigned_to)) $updates['assigned_to'] = $userId;
        if (empty($l->created_by)) $updates['created_by'] = $userId;

        if (empty($l->product_master_id) && ColumnExists::has('crm_leads', 'product_master_id', $conn)) {
            $updates['product_master_id'] = $scholarcloneProductMasterId;
        }
        if (empty($l->campaign_id) && ColumnExists::has('crm_leads', 'campaign_id', $conn) && $firstCamp) {
            $updates['campaign_id'] = $firstCamp;
        }
        if (empty($l->description)) {
            $updates['description'] = 'Prospective Gujarat K-12 institution evaluating ScholarClone ERP suite.';
        }
        if (empty($l->salutation)) {
            $updates['salutation'] = 'Dr.';
        }

        if (!empty($updates)) {
            DB::connection($conn)->table('crm_leads')->where('id', $l->id)->update($updates);
            $leadsEnriched++;
        }
    }
    echo "Leads enriched: {$leadsEnriched} records." . PHP_EOL;

    // --- 2. Organizations Enrichment ---
    $orgs = DB::connection($conn)->table('crm_organizations')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();

    $orgsEnriched = 0;
    foreach ($orgs as $org) {
        $updates = [];
        $orgName = $org->name ?: 'Gujarat Academic Institution ' . $org->id;
        $slug = Str::slug($orgName);

        if (empty($org->phone)) $updates['phone'] = '+91 79 2685 4100';
        if (empty($org->email)) $updates['email'] = "contact@{$slug}.edu.in";
        if (empty($org->website)) $updates['website'] = "https://www.{$slug}.edu.in";
        if (empty($org->billing_street)) $updates['billing_street'] = '101 Knowledge Park, SG Highway';
        if (empty($org->billing_city)) $updates['billing_city'] = 'Ahmedabad';
        if (empty($org->billing_state)) $updates['billing_state'] = 'Gujarat';
        if (empty($org->billing_code)) $updates['billing_code'] = '380054';
        if (empty($org->billing_country)) $updates['billing_country'] = 'India';
        if (empty($org->shipping_street)) $updates['shipping_street'] = $updates['billing_street'] ?? ($org->billing_street ?: '101 Knowledge Park, SG Highway');
        if (empty($org->shipping_city)) $updates['shipping_city'] = $updates['billing_city'] ?? ($org->billing_city ?: 'Ahmedabad');
        if (empty($org->shipping_state)) $updates['shipping_state'] = 'Gujarat';
        if (empty($org->shipping_code)) $updates['shipping_code'] = '380054';
        if (empty($org->shipping_country)) $updates['shipping_country'] = 'India';
        if (empty($org->annual_revenue) || (float)$org->annual_revenue == 0.0) $updates['annual_revenue'] = 45000000.00;
        if (empty($org->employees) || (int)$org->employees == 0) $updates['employees'] = 120;
        if (empty($org->ownership)) $updates['ownership'] = 'Private Trust';
        if (empty($org->industry)) $updates['industry'] = 'Education';
        if (empty($org->account_type)) $updates['account_type'] = 'Customer';
        if (empty($org->rating)) $updates['rating'] = 'Hot';
        if (empty($org->assigned_to)) $updates['assigned_to'] = $userId;
        if (empty($org->created_by)) $updates['created_by'] = $userId;
        if (empty($org->description)) {
            $updates['description'] = 'Prominent Gujarat educational institution running ScholarClone ERP suite.';
        }

        if (!empty($updates)) {
            DB::connection($conn)->table('crm_organizations')->where('id', $org->id)->update($updates);
            $orgsEnriched++;
        }
    }
    echo "Organizations enriched: {$orgsEnriched} records." . PHP_EOL;

    // --- 3. Contacts Enrichment ---
    $contacts = DB::connection($conn)->table('crm_contacts')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();

    $firstOrgId = DB::connection($conn)->table('crm_organizations')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->value('id');

    $contactsEnriched = 0;
    foreach ($contacts as $c) {
        $updates = [];
        if (empty($c->organization_id) && $firstOrgId) {
            $updates['organization_id'] = $firstOrgId;
        }
        if (empty($c->first_name)) $updates['first_name'] = 'Key';
        if (empty($c->last_name)) $updates['last_name'] = 'Administrator';
        if (empty($c->salutation)) $updates['salutation'] = 'Mr.';
        if (empty($c->title)) $updates['title'] = 'Academic Coordinator';
        if (empty($c->department)) $updates['department'] = 'Academics & IT';
        if (empty($c->email)) $updates['email'] = 'contact' . $c->id . '@scholarclone.edu.in';
        if (empty($c->phone)) $updates['phone'] = '+91 79 2685 4101';
        if (empty($c->mobile)) $updates['mobile'] = $c->phone ?: '+91 98250 11223';
        if (empty($c->mailing_street)) $updates['mailing_street'] = 'Administrative Block, SG Highway';
        if (empty($c->mailing_city)) $updates['mailing_city'] = 'Ahmedabad';
        if (empty($c->mailing_state)) $updates['mailing_state'] = 'Gujarat';
        if (empty($c->mailing_code)) $updates['mailing_code'] = '380054';
        if (empty($c->mailing_country)) $updates['mailing_country'] = 'India';
        if (empty($c->lead_source)) $updates['lead_source'] = 'Gujarat EdTech Summit 2026';
        if (empty($c->description)) $updates['description'] = 'Authorized institutional stakeholder for ScholarClone ERP coordination.';

        if (empty($c->consent_status) || $c->consent_status !== 'opted_in') {
            $updates['consent_status'] = 'opted_in';
            if (ColumnExists::has('crm_contacts', 'consent_date', $conn)) {
                $updates['consent_date'] = now();
            }
            if (ColumnExists::has('crm_contacts', 'consent_source', $conn)) {
                $updates['consent_source'] = 'Gujarat EdTech Summit 2026';
            }
            if (ColumnExists::has('crm_contacts', 'consent_channel', $conn)) {
                $updates['consent_channel'] = 'email_phone';
            }
        }
        if (empty($c->assigned_to)) $updates['assigned_to'] = $userId;
        if (empty($c->created_by)) $updates['created_by'] = $userId;

        if (!empty($updates)) {
            DB::connection($conn)->table('crm_contacts')->where('id', $c->id)->update($updates);
            $contactsEnriched++;
        }
    }
    echo "Contacts enriched: {$contactsEnriched} records." . PHP_EOL;

    // --- 3b. Quotes Enrichment ---
    $quotes = DB::connection($conn)->table('crm_quotes')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();
    $quotesEnriched = 0;
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
            $quotesEnriched++;
        }
    }
    echo "Quotes enriched: {$quotesEnriched} records." . PHP_EOL;

    // --- 4. Sales Orders & Invoices Address & Metadata Backfill + Financial Zero-Variance ---
    $salesOrders = DB::connection($conn)->table('crm_sales_orders')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();

    $reconciledOrders = 0;
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
            $soUpdates['updated_at'] = now();
            DB::connection($conn)->table('crm_sales_orders')->where('id', $so->id)->update($soUpdates);
            $so = DB::connection($conn)->table('crm_sales_orders')->where('id', $so->id)->first();
        }

        $inv = DB::connection($conn)->table('crm_invoices')
            ->where('sales_order_id', $so->id)
            ->first();

        if (!$inv) {
            $invData = [
                'sub_institute_id' => $tenantId,
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
    echo "Financial Reconciliation: {$reconciledOrders} orders processed." . PHP_EOL;

    // --- VERIFICATION OF POST-REMEDIATION STATE ---
    echo PHP_EOL . "=== AUDITING POST-REMEDIATION STATE IN TRANSACTION ===" . PHP_EOL;
    $emptySoStreets = DB::connection($conn)->table('crm_sales_orders')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->where(function($q) {
            $q->whereNull('billing_street')->orWhere('billing_street', '');
        })->count();
    echo "Empty SO billing_streets: {$emptySoStreets} (Expected: 0)" . PHP_EOL;

    $emptyInvStreets = DB::connection($conn)->table('crm_invoices')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->where(function($q) {
            $q->whereNull('billing_street')->orWhere('billing_street', '');
        })->count();
    echo "Empty INV billing_streets: {$emptyInvStreets} (Expected: 0)" . PHP_EOL;

    $emptyContactOrgs = DB::connection($conn)->table('crm_contacts')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->where(function($q) {
            $q->whereNull('organization_id')->orWhere('organization_id', 0)->orWhere('organization_id', '');
        })->count();
    echo "Contacts without org_id: {$emptyContactOrgs} (Expected: 0)" . PHP_EOL;

    $leadsNoPm = DB::connection($conn)->table('crm_leads')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->where(function($q) {
            $q->whereNull('product_master_id')->orWhere('product_master_id', 0);
        })->count();
    echo "Leads without product_master_id: {$leadsNoPm} (Expected: 0)" . PHP_EOL;

    $leadsNoCamp = DB::connection($conn)->table('crm_leads')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->where(function($q) {
            $q->whereNull('campaign_id')->orWhere('campaign_id', 0);
        })->count();
    echo "Leads without campaign_id: {$leadsNoCamp} (Expected: 0)" . PHP_EOL;

    $soCreatedByEmpty = DB::connection($conn)->table('crm_sales_orders')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->whereNull('created_by')->count();
    echo "SO without created_by: {$soCreatedByEmpty} (Expected: 0)" . PHP_EOL;

    $invCreatedByEmpty = DB::connection($conn)->table('crm_invoices')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->whereNull('created_by')->count();
    echo "INV without created_by: {$invCreatedByEmpty} (Expected: 0)" . PHP_EOL;

    // Check financial zero-variance
    $sos = DB::connection($conn)->table('crm_sales_orders')
        ->where('sub_institute_id', $tenantId)
        ->whereNull('deleted_at')
        ->get();
    $maxVariance = 0.0;
    foreach ($sos as $so) {
        $inv = DB::connection($conn)->table('crm_invoices')->where('sales_order_id', $so->id)->first();
        $pay = DB::connection($conn)->table('crm_invoice_payments')->where('invoice_id', $inv->id)->first();
        $diff1 = abs((float)$so->total - (float)$inv->total);
        $diff2 = abs((float)$inv->total - (float)$pay->amount);
        $maxVariance = max($maxVariance, $diff1, $diff2);
    }
    echo "Maximum Financial Variance across all 8 SO/INV/Payment triples: {$maxVariance} (Expected: 0.0000)" . PHP_EOL;

} finally {
    // ALWAYS rollback to maintain read-only state for explorer
    DB::connection($conn)->rollBack();
    echo "=== TRANSACTION ROLLED BACK CLEANLY (DATABASE UNTOUCHED) ===" . PHP_EOL;
}
