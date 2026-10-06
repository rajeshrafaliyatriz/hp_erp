<?php
/**
 * MATRIX-ENFORCED AUTHORIZATION — the acceptance test.
 *
 * **A REFUSAL THAT SURVIVES THE MATRIX ROW CHANGING HAS NOT BEEN TESTED.** A
 * guard that refuses HR because someone wrote `admin` in a route file would pass
 * a two-role check and prove nothing. So this probe does not stop at "HR is
 * refused" — it FLIPS THE ROW and requires the answer to flip with it, then puts
 * the row back.
 *
 * Route under test: POST /readiness/gates/acknowledge, guarded
 * `['profile:admin,hr', 'menu:225,edit']`. Menu 225 is Readiness Gates;
 * acknowledging is an EDIT. `hr_manager` holds can_view=1, can_edit=0.
 *
 * Restores the row in a `finally`. Shared remote database.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;

const T = 3;
const MENU = 225;

$prof = DB::table('tbluserprofilemaster')->where('sub_institute_id', T)
    ->whereIn('role_key', ['administrator', 'hr_manager'])->pluck('id', 'role_key');

$tok = [];
foreach ($prof as $role => $pid) {
    $uid = DB::table('tbluser')->where('sub_institute_id', T)->where('user_profile_id', $pid)
        ->where('email', 'like', '%@healthcare.g2g')->value('id');
    $tok[$role] = $uid ? tbluserModel::find($uid)->createToken('matrixguard')->plainTextToken : null;
}

function call($kernel, $token): array
{
    $req = Illuminate\Http\Request::create('/api/readiness/gates/acknowledge', 'POST', [
        'token' => $token, 'type' => 'API', 'gate_key' => 'capability_coverage',
    ]);
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    $b = json_decode((string) $res->getContent(), true);
    return [$res->getStatusCode(), substr((string) ($b['message'] ?? ''), 0, 62)];
}

$hrRow = DB::table('tblgroupwise_rights_g2g')
    ->where('menu_id', MENU)->where('profile_id', $prof['hr_manager'])->where('sub_institute_id', T)->first();

printf("matrix row under test: menu=%d hr_manager can_view=%d can_edit=%d\n\n", MENU, $hrRow->can_view, $hrRow->can_edit);

$fail = 0;
try {
    // ── 1. TWO ROLES, OPPOSITE OUTCOMES ─────────────────────────────────────
    echo "TWO ROLES, SAME ROUTE\n";
    [$ca, $ma] = call($kernel, $tok['administrator']);
    [$ch, $mh] = call($kernel, $tok['hr_manager']);
    // The administrator is NOT expected to succeed at acknowledging - the gate is
    // `ready`, not at_risk - but it must get PAST the guard. 409 from the
    // acknowledger is a pass; 403 from the guard is not.
    $adminPastGuard = $ca !== 403;
    $hrBlocked = $ch === 403;
    printf("  administrator -> HTTP %-4d %s\n", $ca, $adminPastGuard ? 'past the guard' : '*** BLOCKED BY GUARD ***');
    printf("  hr_manager    -> HTTP %-4d %s\n", $ch, $hrBlocked ? 'refused' : '*** NOT REFUSED ***');
    printf("      message: %s\n", $mh);
    if (!$adminPastGuard || !$hrBlocked) $fail++;

    // ── 2. THE REFUSAL MUST NAME THE MENU, NOT THE ROLE ─────────────────────
    // A message saying "admins only" describes a hardcoded rule. This one has to
    // describe a row.
    $namesMenu = str_contains($mh, 'menu ' . MENU) || str_contains($mh, 'screen');
    printf("  refusal describes a ROW, not a role: %s\n", $namesMenu ? 'yes' : '*** NO ***');
    if (!$namesMenu) $fail++;

    // ── 3. FLIP THE ROW. THE ANSWER MUST FLIP. ──────────────────────────────
    echo "\nFLIP THE ROW — grant hr_manager can_edit on menu " . MENU . "\n";
    DB::table('tblgroupwise_rights_g2g')->where('id', $hrRow->id)->update(['can_edit' => 1]);
    [$ch2, $mh2] = call($kernel, $tok['hr_manager']);
    $flipped = $ch2 !== 403;
    printf("  hr_manager    -> HTTP %-4d %s\n", $ch2, $flipped ? 'ALLOWED - the answer followed the row' : '*** STILL 403: the refusal is NOT coming from the matrix ***');
    if (!$flipped) $fail++;

    // ── 4. FLIP IT BACK. THE REFUSAL MUST RETURN. ───────────────────────────
    echo "\nFLIP BACK — revoke can_edit\n";
    DB::table('tblgroupwise_rights_g2g')->where('id', $hrRow->id)->update(['can_edit' => 0]);
    [$ch3] = call($kernel, $tok['hr_manager']);
    $back = $ch3 === 403;
    printf("  hr_manager    -> HTTP %-4d %s\n", $ch3, $back ? 'refused again' : '*** DID NOT RETURN ***');
    if (!$back) $fail++;

    // ── 5. INDIVIDUAL DENY OUTRANKS A GROUP ALLOW ───────────────────────────
    echo "\nINDIVIDUAL DENY over GROUP ALLOW (precedence level 1 beats level 4)\n";
    DB::table('tblgroupwise_rights_g2g')->where('id', $hrRow->id)
        ->update(['can_edit' => 1, 'right_edit' => 'deny']);
    [$ch4] = call($kernel, $tok['hr_manager']);
    printf("  can_edit=1 right_edit=deny -> HTTP %-4d %s\n", $ch4,
        $ch4 === 403 ? 'refused - individual DENY wins' : '*** GROUP ALLOW WON - precedence wrong ***');
    if ($ch4 !== 403) $fail++;
} finally {
    DB::table('tblgroupwise_rights_g2g')->where('id', $hrRow->id)->update([
        'can_view' => $hrRow->can_view, 'can_add' => $hrRow->can_add,
        'can_edit' => $hrRow->can_edit, 'can_delete' => $hrRow->can_delete,
        'right_edit' => $hrRow->right_edit,
    ]);
    $after = DB::table('tblgroupwise_rights_g2g')->where('id', $hrRow->id)->first();
    printf("\nRESTORED: can_view=%d can_edit=%d right_edit=%s  %s\n",
        $after->can_view, $after->can_edit, $after->right_edit ?? 'NULL',
        ($after->can_edit === $hrRow->can_edit) ? 'matches the snapshot' : '*** DID NOT RESTORE ***');
    DB::table('personal_access_tokens')->where('name', 'matrixguard')->delete();
}

printf("VERDICT: %s\n", $fail === 0 ? 'PASS' : '*** ' . $fail . ' FAILURE(S) ***');
