<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $rows = DB::connection('mysql')->select("
        SELECT l.firstname, l.lastname, l.company, l.email, a.phone, a.mobile, a.city, a.state
        FROM crm.vtiger_leaddetails l
        JOIN crm.vtiger_leadaddress a ON a.leadaddressid = l.leadid
        WHERE a.state LIKE '%Gujarat%' 
           OR a.city IN ('Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Anand', 'Navsari', 'Morbi', 'Bharuch')
        LIMIT 100
    ");
    echo "Found " . count($rows) . " leads in db5\n";
    $schools = [];
    foreach ($rows as $r) {
        $name = trim((string)$r->company);
        if ($name && !isset($schools[$name])) {
            $schools[$name] = [
                'company' => $name,
                'firstName' => trim((string)$r->firstname) ?: 'Principal',
                'lastName' => trim((string)$r->lastname) ?: 'Office',
                'email' => trim((string)$r->email),
                'phone' => trim((string)$r->phone) ?: trim((string)$r->mobile),
                'city' => trim((string)$r->city) ?: 'Gujarat',
                'state' => 'Gujarat',
            ];
        }
    }
    echo "Unique schools: " . count($schools) . "\n";
    file_put_contents(__DIR__ . '/gujarat_schools.json', json_encode(array_values($schools), JSON_PRETTY_PRINT));
    echo "Saved to scripts/gujarat_schools.json\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
