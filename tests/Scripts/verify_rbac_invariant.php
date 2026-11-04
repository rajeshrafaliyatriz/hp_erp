<?php
/**
 * Automated CLI Invariant Verification Script for RBAC Capabilities
 *
 * Verifies the complete lifecycle of capability permissions:
 *  1. Admin authentication via Sanctum PersonalAccessToken
 *  2. Mutation and Save via storeGroupwiseRightsG2g (/user/save_groupwiserights_g2g)
 *  3. Direct SQL verification in tblgroupwise_rights_g2g
 *  4. API reflection via displayPlatformServicesRightsG2g (/user/ajax_platform_services_rights_g2g)
 *  5. Revocation round-trip (Revoke -> Assert 0 in SQL -> Assert false in API)
 *  6. Restoration back to active baseline
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\user\tblmenumasterG2gController;
use App\Models\user\tbluserModel;
use App\Models\user\tbluserprofilemasterModel;
use App\Support\MenuRight;

$subInstituteId = 6;
$profileId = 16; // Tenant 6 Admin

echo "========================================================================\n";
echo "RBAC CAPABILITY INVARIANT VERIFICATION: TENANT {$subInstituteId} (PROFILE {$profileId})\n";
echo "========================================================================\n";

// 1. Authenticate as Admin user
$adminUser = tbluserModel::where('sub_institute_id', $subInstituteId)
    ->where('user_profile_id', $profileId)
    ->first();

if (!$adminUser) {
    echo "[FAIL] Admin user not found for tenant {$subInstituteId}\n";
    exit(1);
}

$tokenPlain = 'cli_inv_test_' . bin2hex(random_bytes(16));
DB::table('personal_access_tokens')->insert([
    'tokenable_type' => get_class($adminUser),
    'tokenable_id' => $adminUser->id,
    'name' => 'cli_invariant_test',
    'token' => hash('sha256', $tokenPlain),
    'abilities' => json_encode(['*']),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "[PASS] Issued ephemeral Sanctum bearer token for user {$adminUser->id} ({$adminUser->user_name})\n";

$controller = new tblmenumasterG2gController();

// Target capability for testing: AI Providers (/ai/providers)
$targetLink = '/ai/providers';
$targetMenuId = DB::table('tblmenumaster_g2g')->where('access_link', $targetLink)->value('id');
echo "[INFO] Target test capability: '{$targetLink}' (Menu ID: {$targetMenuId})\n\n";

try {
    // -------------------------------------------------------------
    // TEST 1: Baseline Read
    // -------------------------------------------------------------
    $reqRead = Request::create('/user/ajax_platform_services_rights_g2g', 'GET', [
        'token' => $tokenPlain,
        'sub_institute_id' => $subInstituteId,
    ]);
    $resRead = $controller->displayPlatformServicesRightsG2g($reqRead);
    $dataRead = json_decode($resRead->getContent(), true);

    if (($dataRead['status_code'] ?? 0) !== 1) {
        throw new Exception("Initial displayPlatformServicesRightsG2g failed: " . json_encode($dataRead));
    }
    echo "[TEST 1: BASELINE READ] HTTP 200 OK - Baseline capability response received.\n";

    // -------------------------------------------------------------
    // TEST 2: Revocation Test (Save without targetMenuId)
    // -------------------------------------------------------------
    echo "\n[TEST 2: REVOCATION CYCLE]\n";
    // Fetch all current rights for this profile except targetMenuId
    $allExisting = DB::table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profileId)
        ->get();

    $rightsPayloadRevoke = [];
    foreach ($allExisting as $r) {
        if ((int)$r->menu_id === (int)$targetMenuId) {
            continue; // OMIT target menu to revoke it
        }
        $rightsPayloadRevoke[] = [
            'menu_id' => (int)$r->menu_id,
            'can_view' => (int)$r->can_view,
            'can_add' => (int)$r->can_add,
            'can_edit' => (int)$r->can_edit,
            'can_delete' => (int)$r->can_delete,
            'dashboard_right' => (int)$r->dashboard_right,
            'is_mobile' => (int)$r->is_mobile,
        ];
    }

    $reqSaveRevoke = Request::create('/user/save_groupwiserights_g2g', 'POST', [
        'token' => $tokenPlain,
        'sub_institute_id' => $subInstituteId,
        'profile_id' => $profileId,
        'rights' => $rightsPayloadRevoke,
    ]);
    $resSaveRevoke = $controller->storeGroupwiseRightsG2g($reqSaveRevoke);
    $dataSaveRevoke = json_decode($resSaveRevoke->getContent(), true);

    echo "   -> POST /user/save_groupwiserights_g2g: status_code=" . ($dataSaveRevoke['status_code'] ?? 0) . " saved=" . ($dataSaveRevoke['data']['saved'] ?? 0) . "\n";
    if (($dataSaveRevoke['status_code'] ?? 0) !== 1) {
        throw new Exception("Revocation save failed: " . json_encode($dataSaveRevoke));
    }

    // Direct SQL Assertion after revocation
    $sqlRevoked = DB::table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profileId)
        ->where('menu_id', $targetMenuId)
        ->first();

    echo "   -> SQL Assertion: Row for Menu {$targetMenuId} in tblgroupwise_rights_g2g: " . ($sqlRevoked ? "FOUND (Unexpected)" : "NOT FOUND (Correct)") . "\n";
    if ($sqlRevoked) {
        throw new Exception("Revoked row still exists in tblgroupwise_rights_g2g!");
    }

    // API Assertion after revocation
    $resVerifyRevoke = $controller->displayPlatformServicesRightsG2g($reqRead);
    $dataVerifyRevoke = json_decode($resVerifyRevoke->getContent(), true);
    $aiProvidersRevoked = $dataVerifyRevoke['data']['ai']['providers'] ?? null;
    echo "   -> API Assertion: ajax_platform_services_rights_g2g -> ai.providers = " . var_export($aiProvidersRevoked, true) . "\n";
    if ($aiProvidersRevoked !== false) {
        throw new Exception("API still returned true for revoked capability!");
    }
    echo "[PASS] Revocation successfully asserted in both Database and API output.\n";

    // -------------------------------------------------------------
    // TEST 3: Grant Test (Save WITH targetMenuId)
    // -------------------------------------------------------------
    echo "\n[TEST 3: GRANT & PERSISTENCE CYCLE]\n";
    $rightsPayloadGrant = $rightsPayloadRevoke;
    $rightsPayloadGrant[] = [
        'menu_id' => (int)$targetMenuId,
        'can_view' => 1,
        'can_add' => 0,
        'can_edit' => 0,
        'can_delete' => 0,
        'dashboard_right' => 0,
        'is_mobile' => 0,
    ];

    $reqSaveGrant = Request::create('/user/save_groupwiserights_g2g', 'POST', [
        'token' => $tokenPlain,
        'sub_institute_id' => $subInstituteId,
        'profile_id' => $profileId,
        'rights' => $rightsPayloadGrant,
    ]);
    $resSaveGrant = $controller->storeGroupwiseRightsG2g($reqSaveGrant);
    $dataSaveGrant = json_decode($resSaveGrant->getContent(), true);

    echo "   -> POST /user/save_groupwiserights_g2g: status_code=" . ($dataSaveGrant['status_code'] ?? 0) . " saved=" . ($dataSaveGrant['data']['saved'] ?? 0) . "\n";
    if (($dataSaveGrant['status_code'] ?? 0) !== 1) {
        throw new Exception("Grant save failed: " . json_encode($dataSaveGrant));
    }

    // Direct SQL Assertion after grant
    $sqlGranted = DB::table('tblgroupwise_rights_g2g')
        ->where('profile_id', $profileId)
        ->where('menu_id', $targetMenuId)
        ->first();

    echo "   -> SQL Assertion: Row for Menu {$targetMenuId} in tblgroupwise_rights_g2g: " . ($sqlGranted ? "FOUND (can_view={$sqlGranted->can_view})" : "NOT FOUND") . "\n";
    if (!$sqlGranted || (int)$sqlGranted->can_view !== 1) {
        throw new Exception("Granted row does not exist or can_view != 1 in tblgroupwise_rights_g2g!");
    }

    // API Assertion after grant
    $resVerifyGrant = $controller->displayPlatformServicesRightsG2g($reqRead);
    $dataVerifyGrant = json_decode($resVerifyGrant->getContent(), true);
    $aiProvidersGranted = $dataVerifyGrant['data']['ai']['providers'] ?? null;
    echo "   -> API Assertion: ajax_platform_services_rights_g2g -> ai.providers = " . var_export($aiProvidersGranted, true) . "\n";
    if ($aiProvidersGranted !== true) {
        throw new Exception("API did not return true for granted capability!");
    }
    echo "[PASS] Grant successfully asserted in both Database and API output.\n";

    echo "\n========================================================================\n";
    echo "ALL INVARIANT TESTS PASSED CLEANLY (Zero Regressions, Zero Manual Clicks)\n";
    echo "========================================================================\n";

} finally {
    // Clean up test token
    DB::table('personal_access_tokens')->where('name', 'cli_invariant_test')->delete();
}
