<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

function cleanPhone(?string $phone, ?string $mobile, string $city): array
{
    $cityCodes = [
        'Ahmedabad' => '079', 'Gandhinagar' => '079', 'Surat' => '261',
        'Vadodara' => '265', 'Rajkot' => '281', 'Bhavnagar' => '278',
        'Jamnagar' => '288', 'Bharuch' => '2642', 'Anand' => '2692',
    ];
    $std = $cityCodes[$city] ?? '261';

    $clean = function (?string $raw) use ($std) {
        $raw = trim((string)$raw);
        if ($raw === '' || str_contains($raw, 'E+') || strlen(preg_replace('/\D/', '', $raw)) < 6) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            return '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5);
        }
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $sub = substr($digits, 2);
            return '+91 ' . substr($sub, 0, 5) . ' ' . substr($sub, 5);
        }
        return '+91 ' . $std . ' ' . substr($digits, -7);
    };

    $p = $clean($phone);
    $m = $clean($mobile);

    if (!$p && !$m) {
        $randDigits = rand(20000, 99999);
        $p = "+91 {$std} 2{$randDigits}";
        $m = "+91 9825" . rand(1, 9) . " {$randDigits}";
    } elseif (!$p) {
        $p = "+91 {$std} 2" . rand(20000, 99999);
    } elseif (!$m) {
        $m = "+91 9825" . rand(1, 9) . " " . rand(10000, 99999);
    }

    return [$p, $m];
}

function cleanPostalCode(?string $code, string $city): string
{
    $code = trim((string)$code);
    if (preg_match('/^[1-9][0-9]{5}$/', $code)) {
        return $code;
    }
    $pincodes = [
        'Ahmedabad' => '380015',
        'Gandhinagar' => '382010',
        'Surat' => '395007',
        'Vadodara' => '390007',
        'Rajkot' => '360005',
        'Bhavnagar' => '364001',
        'Jamnagar' => '361006',
        'Bharuch' => '392001',
        'Anand' => '388001',
        'Valsad' => '396001',
        'Junagadh' => '362001',
        'Banaskantha' => '385001',
    ];
    return $pincodes[$city] ?? '380001';
}

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
    LIMIT 60
");

$schools = [];
foreach ($rows as $i => $r) {
    $rawCompany = trim((string)$r->company);
    $company = ucwords(strtolower(preg_replace('/\s+/', ' ', $rawCompany)));
    if (isset($schools[$company])) {
        continue;
    }

    $city = ucwords(strtolower(trim((string)$r->city))) ?: 'Ahmedabad';
    [$phone, $mobile] = cleanPhone($r->phone, $r->mobile, $city);
    $postalCode = cleanPostalCode($r->code, $city);
    $slug = strtolower(preg_replace('/[^a-z0-9]/', '', $company));

    $website = trim((string)$r->website);
    if (!$website || !str_starts_with($website, 'http')) {
        $website = "https://www.{$slug}.edu.in";
    }

    $email = strtolower(trim((string)$r->email));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = "principal@{$slug}.edu.in";
    }

    $street = trim((string)$r->lane);
    if (!$street || strlen($street) < 5) {
        $street = "Campus Road, Near Education Hub, {$city}";
    }

    $rev = (float)$r->annualrevenue;
    if ($rev <= 0) {
        $rev = (float)(rand(25, 85) * 1000000); // 2.5 Cr to 8.5 Cr
    }

    $emp = (int)$r->noofemployees;
    if ($emp <= 0) {
        $emp = rand(65, 180);
    }

    $schools[$company] = [
        'company' => $company,
        'salutation' => 'Dr.',
        'firstName' => trim((string)$r->firstname) ?: 'Principal',
        'lastName' => trim((string)$r->lastname) ?: 'School Head',
        'designation' => trim((string)$r->designation) ?: 'Principal',
        'email' => $email,
        'phone' => $phone,
        'mobile' => $mobile,
        'street' => $street,
        'city' => $city,
        'state' => 'Gujarat',
        'postalCode' => $postalCode,
        'country' => 'India',
        'website' => $website,
        'annualRevenue' => $rev,
        'numberOfEmp' => "{$emp}-" . ($emp + 30),
        'employees' => $emp,
        'leadSource' => trim((string)$r->leadsource) ?: 'Conference',
        'industry' => 'Education',
        'rating' => 'Hot',
        'leadStatus' => 'Qualified',
        'description' => "Premier K-12 educational institution in {$city}, Gujarat evaluating ScholarClone ERP suite.",
    ];
}

echo "Normalized " . count($schools) . " schools from vtiger.\n";
$first = array_values($schools)[0];
echo "Sample school record:\n";
print_r($first);
