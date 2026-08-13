<?php
/**
 * R9 — RENDER CHECK THROUGH THE REAL REQUEST PATH, all nine roles.
 *
 * gate-render.php REPLAYS displaySidebarMenu's logic. This one CALLS it, through
 * the HTTP kernel, so route middleware, the controller's own guard and its real
 * query all run. If the two disagree, the controller is right and the replay is
 * wrong (R4: the checker is the primary suspect).
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$profiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->get()->groupBy('role_key');

/* A real token is required by the controller's own guard. Seven of the nine role
 * profiles have zero users (the roles were created by D-011 and nobody is
 * assigned yet), so one real user's token is minted and profile_id is varied per
 * request - which is how the endpoint works: it reads profile_id from the
 * REQUEST, not from the token. See G-NAV-02. */
$actor = DB::table('tbluser')->whereNotNull('user_profile_id')->first();
$user  = App\Models\User::find($actor->id);
$token = $user->createToken('phase3-r9-check')->plainTextToken;
printf("acting user %d, token minted%s", $actor->id, PHP_EOL . PHP_EOL);

/** walks the rendered tree and counts what a user would actually see */
function countTree($nodes, &$modules, &$leaves, $depth = 0) {
    foreach ($nodes as $n) {
        $n = (array) $n;
        // The controller nests under 'menus' at module level and 'submenus'
        // below it. Guessing the key gave leaves == modules on every role.
        $kids = $n['menus'] ?? $n['submenus'] ?? [];
        if ($depth === 0) $modules++;
        if (!$kids) $leaves++;
        if ($kids) countTree($kids, $modules, $leaves, $depth + 1);
    }
}

printf("%-20s %6s %8s %8s  %s\n", 'role', 'HTTP', 'modules', 'leaves', 'verdict');

$fail = 0;
foreach ($profiles as $roleKey => $ps) {
    $p = $ps[0];

    $request = Request::create('/user/ajax_sidebar_menu_g2g', 'GET', [
        'profile_id'       => $p->id,
        'sub_institute_id' => $p->sub_institute_id,
        'token'            => $token,
    ]);

    $response = $kernel->handle($request);
    $status   = $response->getStatusCode();
    $body     = json_decode($response->getContent(), true);

    $tree = $body['data'] ?? $body['menu'] ?? $body ?? [];
    if (!is_array($tree)) $tree = [];

    $modules = 0; $leaves = 0;
    countTree($tree, $modules, $leaves);

    $ok = $status === 200 && $modules > 0;
    if (!$ok) $fail++;

    printf("%-20s %6d %8d %8d  %s\n", $roleKey, $status, $modules, $leaves,
        $ok ? 'renders' : '*** EMPTY OR ERROR - BLOCKER ***');
}

printf("\n%s\n", $fail === 0
    ? 'ALL NINE ROLES RENDER A NON-EMPTY SIDEBAR THROUGH THE REAL PATH.'
    : "*** {$fail} ROLE(S) FAILED ***");
