<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::connection('mysql')->select("
    SELECT 
        l.leadid,
        l.firstname,
        l.lastname,
        l.company,
        l.email,
        l.designation,
        l.annualrevenue,
        l.noofemployees,
        l.leadsource,
        l.leadstatus,
        a.phone,
        a.mobile,
        a.lane,
        a.city,
        a.state,
        a.code,
        a.country,
        s.website
    FROM crm.vtiger_leaddetails l
    JOIN crm.vtiger_leadaddress a ON a.leadaddressid = l.leadid
    LEFT JOIN crm.vtiger_leadsubdetails s ON s.leadsubscriptionid = l.leadid
    WHERE (LOWER(a.state) LIKE '%gujarat%' 
       OR a.city IN ('Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Anand', 'Navsari', 'Morbi', 'Bharuch'))
      AND l.company IS NOT NULL AND TRIM(l.company) != ''
    LIMIT 100
");

$uniqueSchools = [];
foreach ($rows as $r) {
    $rawCompany = trim((string)$r->company);
    $normalizedCompany = ucwords(strtolower(preg_replace('/\s+/', ' ', $rawCompany)));
    if (!isset($uniqueSchools[$normalizedCompany])) {
        $uniqueSchools[$normalizedCompany] = $r;
    }
}
echo "Total rows: " . count($rows) . ", Unique schools: " . count($uniqueSchools) . "\n";
