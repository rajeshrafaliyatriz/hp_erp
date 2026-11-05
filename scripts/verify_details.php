<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

$incompleteLeads = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('first_name')
          ->orWhereNull('last_name')
          ->orWhereNull('company')
          ->orWhereNull('email')
          ->orWhereNull('phone')
          ->orWhereNull('mobile')
          ->orWhereNull('street')
          ->orWhereNull('city')
          ->orWhereNull('state')
          ->orWhereNull('postal_code')
          ->orWhereNull('website')
          ->orWhereNull('annual_revenue')
          ->orWhereNull('number_of_emp')
          ->orWhereNull('lead_source')
          ->orWhereNull('industry')
          ->orWhereNull('rating');
    })
    ->get(['id', 'company', 'email', 'street', 'city', 'lead_score']);

echo "INCOMPLETE LEADS:\n";
print_r($incompleteLeads->toArray());

$incompleteOrgs = DB::connection($conn)->table('crm_organizations')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('name')
          ->orWhereNull('phone')
          ->orWhereNull('email')
          ->orWhereNull('website')
          ->orWhereNull('billing_street')
          ->orWhereNull('billing_city')
          ->orWhereNull('billing_state')
          ->orWhereNull('billing_code')
          ->orWhereNull('shipping_street')
          ->orWhereNull('shipping_city')
          ->orWhereNull('annual_revenue')
          ->orWhereNull('employees')
          ->orWhereNull('ownership')
          ->orWhereNull('industry')
          ->orWhereNull('account_type');
    })
    ->get(['id', 'name', 'phone', 'email', 'website', 'billing_street', 'annual_revenue', 'employees']);

echo "INCOMPLETE ORGS:\n";
print_r($incompleteOrgs->toArray());

$incompleteContacts = DB::connection($conn)->table('crm_contacts')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('first_name')
          ->orWhereNull('last_name')
          ->orWhereNull('title')
          ->orWhereNull('department')
          ->orWhereNull('email')
          ->orWhereNull('phone')
          ->orWhereNull('mobile')
          ->orWhereNull('mailing_street')
          ->orWhereNull('mailing_city')
          ->orWhereNull('consent_status');
    })
    ->get(['id', 'first_name', 'last_name', 'organization_id', 'title', 'email', 'phone', 'mobile', 'mailing_street']);

echo "INCOMPLETE CONTACTS (first 5):\n";
print_r($incompleteContacts->take(5)->toArray());
