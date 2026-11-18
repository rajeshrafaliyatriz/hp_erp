<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$orgs = DB::table('crm_organizations')->where('sub_institute_id', 6)->limit(3)->get(['id', 'account_no', 'name', 'assigned_to']);
print_r($orgs);

$contacts = DB::table('crm_contacts')->where('sub_institute_id', 6)->limit(3)->get(['id', 'contact_no', 'first_name', 'last_name', 'organization_id', 'title', 'email']);
print_r($contacts);
