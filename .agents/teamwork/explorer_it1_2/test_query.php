<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
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
        LIMIT 10
    ");

    echo "Fetched " . count($rows) . " rows.\n";
    foreach ($rows as $i => $r) {
        echo "[$i] company: {$r->company} | city: {$r->city} | lane: {$r->lane} | code: {$r->code} | rev: {$r->annualrevenue} | emp: {$r->noofemployees} | web: {$r->website}\n";
    }
} catch (\Throwable $e) {
    echo "Query error: " . $e->getMessage() . "\n";
}
