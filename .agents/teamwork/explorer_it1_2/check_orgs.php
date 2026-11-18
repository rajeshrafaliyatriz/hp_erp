<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$orgs = DB::connection('mysql')->table('crm_organizations')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->get();

echo "Tenant 6 has " . count($orgs) . " organizations:\n";
foreach ($orgs as $org) {
    echo "ID: {$org->id} | Name: {$org->name} | Email: {$org->email} | Type: {$org->account_type} | Ownership: {$org->ownership} | ShipCity: {$org->shipping_city} | Rev: {$org->annual_revenue}\n";
}
