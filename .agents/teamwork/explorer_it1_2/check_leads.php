<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$leads = DB::connection('mysql')->table('crm_leads')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->get();

echo "Tenant 6 has " . count($leads) . " leads:\n";
foreach ($leads as $lead) {
    echo "ID: {$lead->id} | Company: {$lead->company} | Street: {$lead->street} | Postal: {$lead->postal_code} | Rev: {$lead->annual_revenue} | Emp: {$lead->number_of_emp} | Score: {$lead->lead_score} | Converted: {$lead->converted}\n";
}
