<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

echo "=== AUDIT: HOST & DATABASE ===\n";
$dbHost = config('database.connections.mysql.host');
$dbName = config('database.connections.mysql.database');
echo "Host: {$dbHost}\n";
echo "Database: {$dbName}\n";

$targetUrls = [
    'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/',
    'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/',
    'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/',
];

echo "\n=== 1. CRM_SIGNAL_INBOX AUDIT ===\n";
$signals = DB::connection($conn)->table('crm_signal_inbox')
    ->whereIn('source_url', $targetUrls)
    ->get();

echo "Found signals count: " . count($signals) . "\n";
$leakSignals = DB::connection($conn)->table('crm_signal_inbox')
    ->whereIn('source_url', $targetUrls)
    ->where('sub_institute_id', '!=', 6)
    ->get();
echo "Cross-tenant leak count: " . count($leakSignals) . "\n";

$linkedOrgIds = [];
$promotedLeadIds = [];
foreach ($signals as $s) {
    echo "Signal ID: {$s->id} | Tenant: {$s->sub_institute_id} | Assigned: {$s->assigned_to} | Status: {$s->status} | Class: {$s->classification} | Score: {$s->relevance_score}\n";
    echo "  Key: {$s->signal_key}\n";
    echo "  Linked Org ID: {$s->linked_account_id} | Promoted Lead ID: {$s->promoted_id}\n";
    $linkedOrgIds[] = $s->linked_account_id;
    $promotedLeadIds[] = $s->promoted_id;
}

echo "\n=== 2. CRM_ORGANIZATIONS AUDIT ===\n";
$crmOrgs = DB::connection($conn)->table('crm_organizations')
    ->whereIn('id', $linkedOrgIds)
    ->get();
echo "Found crm_organizations count: " . count($crmOrgs) . "\n";
foreach ($crmOrgs as $org) {
    echo "Org ID: {$org->id} | Name: {$org->name} | Tenant: {$org->sub_institute_id} | Assigned: {$org->assigned_to} | Created By: {$org->created_by}\n";
}

echo "\n=== 3. G2G_ORG_ACCOUNTS AUDIT ===\n";
$g2gAccounts = DB::connection($conn)->table('g2g_org_accounts')
    ->whereIn('crm_organization_id', $linkedOrgIds)
    ->get();
echo "Found g2g_org_accounts count: " . count($g2gAccounts) . "\n";
$g2gIds = [];
$gtmAccIds = [];
foreach ($g2gAccounts as $g2g) {
    echo "G2G ID: {$g2g->id} | Name: {$g2g->name} | Tenant: {$g2g->sub_institute_id} | CRM Org ID: {$g2g->crm_organization_id} | GTM Acc ID: {$g2g->gtm_account_id} | Basis: {$g2g->crm_link_basis}\n";
    $g2gIds[] = $g2g->id;
    $gtmAccIds[] = $g2g->gtm_account_id;
}

echo "\n=== 4. GTM_ACCOUNTS AUDIT ===\n";
$gtmAccounts = DB::connection($conn)->table('gtm_accounts')
    ->whereIn('id', $gtmAccIds)
    ->get();
echo "Found gtm_accounts count: " . count($gtmAccounts) . "\n";
foreach ($gtmAccounts as $gtm) {
    echo "GTM Acc ID: {$gtm->id} | Name: {$gtm->name} | Tenant: {$gtm->sub_institute_id} | CRM Org ID: {$gtm->crm_organization_id} | Owner: {$gtm->owner_user_id} | Created By: {$gtm->created_by}\n";
}

echo "\n=== 5. CRM_CONTACTS AUDIT ===\n";
$contacts = DB::connection($conn)->table('crm_contacts')
    ->whereIn('organization_id', $linkedOrgIds)
    ->get();
echo "Found crm_contacts count: " . count($contacts) . "\n";
$crmContactIds = [];
foreach ($contacts as $c) {
    echo "Contact ID: {$c->id} | Name: {$c->first_name} {$c->last_name} | Title: {$c->title} | ReportsTo: {$c->reports_to_id} | Tenant: {$c->sub_institute_id} | Consent: {$c->consent_status} | Assigned: {$c->assigned_to}\n";
    $crmContactIds[] = $c->id;
}

echo "\n=== 6. GTM_CONTACTS AUDIT ===\n";
$gtmContacts = DB::connection($conn)->table('gtm_contacts')
    ->whereIn('account_id', $gtmAccIds)
    ->get();
echo "Found gtm_contacts count: " . count($gtmContacts) . "\n";
$gtmContactIds = [];
foreach ($gtmContacts as $gc) {
    echo "GTM Contact ID: {$gc->id} | Name: {$gc->full_name} | RoleInDeal: {$gc->role_in_deal} | Tenant: {$gc->sub_institute_id} | Acc ID: {$gc->account_id} | Created By: {$gc->created_by}\n";
    $gtmContactIds[] = $gc->id;
}

echo "\n=== 7. CRM_LEADS AUDIT ===\n";
$leads = DB::connection($conn)->table('crm_leads')
    ->whereIn('id', $promotedLeadIds)
    ->get();
echo "Found crm_leads count: " . count($leads) . "\n";
foreach ($leads as $l) {
    echo "Lead ID: {$l->id} | No: {$l->lead_no} | Company: {$l->company} | Product ID: {$l->product_master_id} | Score: {$l->lead_score} | Score Basis: {$l->lead_score_basis} | Tenant: {$l->sub_institute_id} | OrgAccount ID: {$l->org_account_id} | Converted Org: {$l->converted_organization_id} | Converted Contact: {$l->converted_contact_id} | Assigned: {$l->assigned_to}\n";
}

echo "\n=== 8. GTM_OUTREACH_MESSAGES AUDIT ===\n";
$outreaches = DB::connection($conn)->table('gtm_outreach_messages')
    ->whereIn('account_id', $gtmAccIds)
    ->get();
echo "Found gtm_outreach_messages count: " . count($outreaches) . "\n";
foreach ($outreaches as $o) {
    echo "Outreach ID: {$o->id} | Acc ID: {$o->account_id} | Contact ID: {$o->contact_id} | Product ID: {$o->product_master_id} | Tenant: {$o->sub_institute_id} | Status: {$o->status} | Step: {$o->step} | Created By: {$o->created_by}\n";
    echo "  To: {$o->to_email} | Subject: {$o->subject}\n";
    echo "  Body Preview: " . substr(str_replace("\n", " ", $o->body), 0, 100) . "...\n";
}

echo "\n=== 9. CROSS-TENANT LEAKAGE CHECK ACROSS ALL 8 TABLES ===\n";
$tablesAndConditions = [
    'crm_signal_inbox' => DB::connection($conn)->table('crm_signal_inbox')->whereIn('source_url', $targetUrls)->where('sub_institute_id', '!=', 6)->count(),
    'crm_organizations' => DB::connection($conn)->table('crm_organizations')->whereIn('id', $linkedOrgIds)->where('sub_institute_id', '!=', 6)->count(),
    'g2g_org_accounts' => DB::connection($conn)->table('g2g_org_accounts')->whereIn('id', $g2gIds)->where('sub_institute_id', '!=', 6)->count(),
    'gtm_accounts' => DB::connection($conn)->table('gtm_accounts')->whereIn('id', $gtmAccIds)->where('sub_institute_id', '!=', 6)->count(),
    'crm_contacts' => DB::connection($conn)->table('crm_contacts')->whereIn('id', $crmContactIds)->where('sub_institute_id', '!=', 6)->count(),
    'gtm_contacts' => DB::connection($conn)->table('gtm_contacts')->whereIn('id', $gtmContactIds)->where('sub_institute_id', '!=', 6)->count(),
    'crm_leads' => DB::connection($conn)->table('crm_leads')->whereIn('id', $promotedLeadIds)->where('sub_institute_id', '!=', 6)->count(),
    'gtm_outreach_messages' => DB::connection($conn)->table('gtm_outreach_messages')->whereIn('account_id', $gtmAccIds)->where('sub_institute_id', '!=', 6)->count(),
];

foreach ($tablesAndConditions as $tbl => $leakCount) {
    echo "Leak check {$tbl}: " . ($leakCount === 0 ? "PASSED (0 leaks)" : "FAILED ({$leakCount} leaks)") . "\n";
}
