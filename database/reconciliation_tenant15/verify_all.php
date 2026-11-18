<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use App\Models\auth\tbluserModel;
use App\Services\Auth\InviteService;
use App\Support\MailGate;

// Switch default connection to db3
config(['database.default' => 'db3']);
DB::purge('mysql');

echo "======================================================================\n";
echo "E2E VERIFICATION SUITE: TENANT 15 RECONCILIATION & DELIVERY ACTIVATION\n";
echo "Target Host: db3 (128.199.17.97, database hp_erp, MariaDB 10.1)\n";
echo "======================================================================\n\n";

$passes = 0;
$total = 0;

function assertCondition(string $name, bool $condition, string $detail = '') {
    global $passes, $total;
    $total++;
    if ($condition) {
        $passes++;
        echo "  [PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        echo "  [FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
        throw new \RuntimeException("Assertion failed: {$name} - {$detail}");
    }
}

// -----------------------------------------------------------------------------
// SECTION 1: MASTER CATALOGUE & ADOPTION PROVENANCE (R3)
// -----------------------------------------------------------------------------
echo "--- SECTION 1: MASTER CATALOGUE & ADOPTION PROVENANCE (R3) ---\n";

$masterCount = DB::table('s_jobrole')
    ->where('sector', 'like', '%Management Studies%')
    ->orWhere('track', 'like', '%Management Studies%')
    ->orWhere('jobrole', 'like', '%Management Studies%')
    ->count();
assertCondition("F1: Master catalogue check for 'Institute of Management Studies & Research'", $masterCount === 0, "count = {$masterCount}");

$adoptedRoles = DB::table('s_user_jobrole')
    ->where('sub_institute_id', 15)
    ->whereNotIn('id', [7283, 7284])
    ->get();
assertCondition("F2: Exactly 12 NAEMD designations adopted into s_user_jobrole", $adoptedRoles->count() === 12, "count = {$adoptedRoles->count()}");

$provenanceMissing = $adoptedRoles->whereNull('catalogue_jobrole_id')->count();
assertCondition("F2.1: 100% provenance on adopted roles (catalogue_jobrole_id)", $provenanceMissing === 0, "missing = {$provenanceMissing}");

$clonedTasks = DB::table('s_user_jobrole_task')->where('sub_institute_id', 15)->get();
assertCondition("F3: Tasks cloned into s_user_jobrole_task with provenance", $clonedTasks->count() === 385, "count = {$clonedTasks->count()}");

$competencies = DB::table('competency')->where('sub_institute_id', 15)->count();
$kasbaItems = DB::table('competency_kasba_item')->where('sub_institute_id', 15)->count();
$mappedRoles = DB::table('jobrole_competency_map')->where('sub_institute_id', 15)->distinct()->count('jobrole_id');
assertCondition("F4: Competencies established", $competencies === 8, "count = {$competencies}");
assertCondition("F4.1: KASBA items populated", $kasbaItems === 11, "count = {$kasbaItems}");
assertCondition("F4.2: All 12 adopted roles mapped in jobrole_competency_map", $mappedRoles === 12, "roles mapped = {$mappedRoles}");

// -----------------------------------------------------------------------------
// SECTION 2: EMPLOYEE SCOPE REDUCTION & DATA CLEANLINESS (R1)
// -----------------------------------------------------------------------------
echo "\n--- SECTION 2: EMPLOYEE SCOPE REDUCTION & DATA CLEANLINESS (R1) ---\n";

$backupCount = DB::table('tbluser_backup_tenant15_purge_20261104')->count();
assertCondition("F5: Pre-purge backup table exists and contains 145 users", $backupCount === 145, "count = {$backupCount}");

$tenantUsers = DB::table('tbluser')->where('sub_institute_id', 15)->get();
assertCondition("F6: Exactly 17 users remain in tbluser for tenant 15", $tenantUsers->count() === 17, "count = {$tenantUsers->count()}");

$adminUser = $tenantUsers->firstWhere('id', 479);
assertCondition("F6.1: Primary Admin 479 preserved", $adminUser !== null && $adminUser->email === 'lsi.mkes@futurevarsity.edu.in');

$staffIds = $tenantUsers->where('id', '!=', 479)->pluck('id')->sort()->values()->toArray();
$expectedIds = range(564, 579);
assertCondition("F6.2: Exactly 16 NAEMD staff preserved (IDs 564..579)", $staffIds === $expectedIds, "ids = " . implode(',', $staffIds));

// Orphan checks
$skillOrphans = DB::table('s_skill_matrix as m')
    ->leftJoin('tbluser as u', 'm.user_id', '=', 'u.id')
    ->where('m.sub_institute_id', 15)
    ->whereNull('u.id')
    ->count();
$attendanceOrphans = DB::table('hrms_attendances as a')
    ->leftJoin('tbluser as u', 'a.user_id', '=', 'u.id')
    ->where('a.sub_institute_id', 15)
    ->whereNull('u.id')
    ->count();
$eventOrphans = DB::table('calendar_events as e')
    ->leftJoin('tbluser as u', 'e.created_by', '=', 'u.id')
    ->where('e.sub_institute_id', 15)
    ->whereNull('u.id')
    ->count();
$docOrphans = DB::table('staff_document as d')
    ->leftJoin('tbluser as u', 'd.user_id', '=', 'u.id')
    ->where('d.sub_institute_id', 15)
    ->whereNull('u.id')
    ->count();

$totalOrphans = $skillOrphans + $attendanceOrphans + $eventOrphans + $docOrphans;
assertCondition("F7: Zero orphan records across dependent tables", $totalOrphans === 0, "orphans = {$totalOrphans}");

$reversalScript = __DIR__ . '/reversal_all.sql';
assertCondition("F8: Reversal rollback SQL script exists and is non-empty", file_exists($reversalScript) && filesize($reversalScript) > 1000);

// -----------------------------------------------------------------------------
// SECTION 3: DIRECTORY DUAL-KEY SYNC & ROLE ADMINISTRATION (R2 & R5)
// -----------------------------------------------------------------------------
echo "\n--- SECTION 3: DIRECTORY DUAL-KEY SYNC & ROLE ADMINISTRATION (R2 & R5) ---\n";

$staffUsers = DB::table('tbluser')
    ->where('sub_institute_id', 15)
    ->where('id', '!=', 479)
    ->get();

$mismatchCount = 0;
foreach ($staffUsers as $su) {
    if ((string)$su->allocated_standards !== (string)$su->jobtitle_id) {
        $mismatchCount++;
    }
}
assertCondition("F9: Zero mismatches between jobtitle_id and allocated_standards", $mismatchCount === 0, "mismatches = {$mismatchCount}");

$obsoleteRoles = DB::table('s_user_jobrole')
    ->where('sub_institute_id', 15)
    ->whereBetween('id', [7300, 7315])
    ->count();
assertCondition("F12: Obsolete department roles 7300..7315 removed", $obsoleteRoles === 0, "remaining = {$obsoleteRoles}");

// Check RBAC profiles
$p564 = DB::table('tbluser')->where('id', 564)->value('user_profile_id');
$p573 = DB::table('tbluser')->where('id', 573)->value('user_profile_id');
$execProfiles = DB::table('tbluser')->whereIn('id', [567, 569, 570, 572])->pluck('user_profile_id')->unique()->toArray();
$empProfiles = DB::table('tbluser')->whereIn('id', [565, 566, 568, 571, 574, 575, 576, 577, 578, 579])->pluck('user_profile_id')->unique()->toArray();

assertCondition("F11: Incharge 564 has Admin profile (63)", $p564 === 63);
assertCondition("F11.1: Faculty Coordinator 573 has Department Head profile (60)", $p573 === 60);
assertCondition("F11.2: Executives 567, 569, 570, 572 have Executive profile (64)", $execProfiles === [64]);
assertCondition("F11.3: Counsellors & Support have Employee profile (58)", $empProfiles === [58]);

// -----------------------------------------------------------------------------
// SECTION 4: OUTBOUND DELIVERY ACTIVATION & CREDENTIALS (R4)
// -----------------------------------------------------------------------------
echo "\n--- SECTION 4: OUTBOUND DELIVERY ACTIVATION & CREDENTIALS (R4) ---\n";

$outboundEnabled = (int) DB::table('school_setup')->where('id', 15)->value('outbound_email_enabled');
assertCondition("F13: school_setup.outbound_email_enabled = 1 for tenant 15", $outboundEnabled === 1);

$mailGateAllowed = MailGate::allowedForTenant(15);
assertCondition("F13.1: MailGate::allowedForTenant(15) returns true", $mailGateAllowed === true);

$smtp = DB::table('smtp_details')->where('sub_institute_id', 15)->first();
assertCondition("F14: smtp_details configured for tenant 15", $smtp !== null && $smtp->gmail === 'trizinnovation2018@gmail.com');

$sms = DB::table('sms_api_details')->where('sub_institute_id', 15)->first();
assertCondition("F14.1: sms_api_details configured for tenant 15", $sms !== null && (int)$sms->is_active === 1);

// Test Invitation Flow with a designated test email address (WITHOUT emailing real employees)
echo "\n  Testing credential invitation issuance flow (safe diverted recipient)...\n";
$targetEmployee = 'vasudha.surve@naemd.edu.in';
$testRecipient = 'test.tenant15.verify@example.com';

// Mock transport to avoid live SMTP network dispatch
Mail::fake();

$inviteService = app(InviteService::class);
$inviteResult = $inviteService->issue(
    $targetEmployee,
    15,
    'invite',
    $testRecipient
);

assertCondition("F15: InviteService generates valid invitation result", !empty($inviteResult['link']));
assertCondition("F15.1: InviteService delivery mode is email", $inviteResult['delivered'] === 'email');

// Verify token was stored in password_reset_tokens
$tokenRow = DB::table('password_reset_tokens')->where('email', $targetEmployee)->first();
assertCondition("F15.2: Token created in password_reset_tokens", $tokenRow !== null);

if ($tokenRow) {
    $tokenCheck = $inviteService->check($tokenRow->token);
    assertCondition("F15.3: InviteService::check validates token as valid", $tokenCheck['valid'] === true);
    // Cleanup test token
    DB::table('password_reset_tokens')->where('email', $targetEmployee)->delete();
}

// -----------------------------------------------------------------------------
// SECTION 5: HTTP DIRECTORY & PROFILE ENDPOINTS (R2 & R6)
// -----------------------------------------------------------------------------
echo "\n--- SECTION 5: HTTP DIRECTORY & PROFILE ENDPOINTS (R2 & R6) ---\n";

$admin = tbluserModel::find(479);
$token = $admin->createToken('reconciliation_e2e_verify')->plainTextToken;

// 1. Directory Index
$req1 = Request::create('/api/employees-management', 'GET');
$req1->headers->set('Accept', 'application/json');
$req1->headers->set('Authorization', 'Bearer ' . $token);
$res1 = app()->handle($req1);
assertCondition("F17: GET /api/employees-management returns HTTP 200", $res1->getStatusCode() === 200, "status = " . $res1->getStatusCode());

$dirData = json_decode($res1->getContent(), true);
$hasEmployees = isset($dirData['data']) && count($dirData['data']) >= 16;
assertCondition("F17.1: Directory returns populated staff collection", $hasEmployees, "count = " . (isset($dirData['data']) ? count($dirData['data']) : 0));

// Check job role data is populated in directory list
$staffWithJobRole = collect($dirData['data'])->where('id', '!=', 479)->whereNotNull('jobrole')->count();
assertCondition("F17.2: Directory items contain populated job role data (all 16 staff)", $staffWithJobRole === 16, "populated count = {$staffWithJobRole}/16");

// 2. Individual Employee Show Endpoints (IDs 564..579)
echo "  Verifying individual profile endpoints for all 16 NAEMD staff (zero 500 errors)...\n";
$profile200Count = 0;
for ($id = 564; $id <= 579; $id++) {
    $req = Request::create("/api/employees-management/{$id}", 'GET');
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = app()->handle($req);
    if ($res->getStatusCode() === 200) {
        $profile200Count++;
    } else {
        echo "    [WARN] User {$id} returned {$res->getStatusCode()}\n";
    }
}
assertCondition("F17.3: All 16 individual employee show endpoints return HTTP 200", $profile200Count === 16, "200 count = {$profile200Count}/16");

// 3. Delivery Options and Profiles Endpoints
$reqOpt = Request::create('/api/organization/delivery/options', 'GET');
$reqOpt->headers->set('Accept', 'application/json');
$reqOpt->headers->set('Authorization', 'Bearer ' . $token);
$resOpt = app()->handle($reqOpt);
assertCondition("F17.4: GET /api/organization/delivery/options returns HTTP 200", $resOpt->getStatusCode() === 200);

$reqProf = Request::create('/api/organization/delivery/profiles', 'GET');
$reqProf->headers->set('Accept', 'application/json');
$reqProf->headers->set('Authorization', 'Bearer ' . $token);
$resProf = app()->handle($reqProf);
assertCondition("F17.5: GET /api/organization/delivery/profiles returns HTTP 200", $resProf->getStatusCode() === 200);

$profData = json_decode($resProf->getContent(), true);
$hasSmtpProfile = !empty($profData['data']['profiles']) || !empty($profData['profiles']);
assertCondition("F17.6: Delivery profiles endpoint returns configured SMTP profile", $hasSmtpProfile);

// 4. Test legacy Blade Controller edit logic (eliminating "jobrole on null" crash)
echo "  Testing legacy Blade edit controller logic for user 564...\n";
$tbluserCtrl = app(\App\Http\Controllers\user\tbluserController::class);
$legacyReq = Request::create("/user/add_user/564/edit", 'GET', [
    'type' => 'API',
    'sub_institute_id' => 15,
    'syear' => 2026
]);

$crashed = false;
$crashError = '';
try {
    $editView = $tbluserCtrl->edit($legacyReq, 564);
    assertCondition("F10: Legacy tbluserController@edit executes with zero crashes", $editView !== null);
} catch (\Throwable $e) {
    $crashed = true;
    $crashError = $e->getMessage();
    assertCondition("F10: Legacy tbluserController@edit executes with zero crashes", false, $crashError);
}

// Clean up verification token
DB::table('personal_access_tokens')->where('name', 'reconciliation_e2e_verify')->delete();

echo "\n" . str_repeat('=', 70) . "\n";
echo "SUMMARY: {$passes} / {$total} VERIFICATION ASSERTIONS PASSED (100% SUCCESS)\n";
echo str_repeat('=', 70) . "\n";
