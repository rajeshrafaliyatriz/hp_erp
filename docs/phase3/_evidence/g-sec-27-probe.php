<?php
/**
 * G-SEC-27 PROBE — can an authenticated caller from tenant A write a job
 * description into tenant B?
 *
 * ASSERTING A LEAK FROM SOURCE HAS BITTEN THREE TIMES THIS PHASE. This sends the
 * request and reads what landed.
 *
 * Everything it creates, it deletes. The database is shared and remote.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n================ G-SEC-27 PROBE ================\n\n";

// An attacker in one tenant, a victim organisation in another.
$attacker = DB::table('tbluser')->where('sub_institute_id', 3)
    ->whereNotNull('email')->orderBy('id')->first(['id', 'sub_institute_id']);
$victimTenant = (int) DB::table('tbluser')->where('sub_institute_id', '!=', 3)
    ->where('sub_institute_id', '>', 0)->value('sub_institute_id');

printf("attacker: user %d in tenant %d\n", $attacker->id, $attacker->sub_institute_id);
printf("victim  : tenant %d\n\n", $victimTenant);

$token = tbluserModel::find($attacker->id)->createToken('gsec27')->plainTextToken;

// Which tables does save() write? Lines 75, 120, 153 of SaveJDController.
$candidates = array_values(array_filter(
    ['s_jd_master', 'jd_master', 's_user_jobrole', 'jd_details', 's_jobrole_jd'],
    fn ($t) => Schema::hasTable($t)
));
$before = [];
foreach ($candidates as $t) {
    $before[$t] = DB::table($t)->where('sub_institute_id', $victimTenant)->count();
}

$marker = 'GSEC27-PROBE-' . substr(md5((string) $attacker->id), 0, 6);

$req = Illuminate\Http\Request::create('/api/gemini/save-jd', 'POST', [
    'token'            => $token,
    'type'             => 'API',
    // THE PAYLOAD NAMES ANOTHER ORGANISATION.
    'sub_institute_id' => $victimTenant,
    'jobrole'          => $marker,
    'job_role_name'    => $marker,
    'user_id'          => $attacker->id,
    'syear'            => '2025',
    // ADDED AFTER THE FIRST RUN WAS REFUSED AT VALIDATION (422 on department and
    // industry). A probe rejected before the code under test proves nothing -
    // R16 applies to probes exactly as it applies to sweeps: it must be able to
    // SUCCEED before a negative result means anything.
    'department'       => 'Probe Department',
    'industry'         => 'Probe Industry',
    'behaviour'        => [],
    'knowledge'        => [],
    'skills'           => [],
]);
$req->headers->set('Accept', 'application/json');
$res = $kernel->handle($req);

printf("HTTP %d\n%s\n\n", $res->getStatusCode(), substr($res->getContent(), 0, 220));

$landed = [];
foreach ($candidates as $t) {
    $after = DB::table($t)->where('sub_institute_id', $victimTenant)->count();
    if ($after !== $before[$t]) {
        $landed[$t] = $after - $before[$t];
    }
}

// And a direct search for the marker, in case the table list is wrong.
$markerHits = [];
foreach ($candidates as $t) {
    foreach (['jobrole', 'job_role_name', 'name', 'title'] as $col) {
        if (!Schema::hasColumn($t, $col)) continue;
        // TENANT-SCOPED. The first version searched EVERY tenant, so after the
        // fix it still found the marker - in the ATTACKER'S OWN tenant, where the
        // row now correctly lands. An unscoped search cannot tell "written to the
        // victim" from "written to yourself", which is the entire question.
        $n = DB::table($t)->where($col, 'like', "%$marker%")
            ->where('sub_institute_id', $victimTenant)->count();
        if ($n > 0) $markerHits[] = "$t.$col ($n) IN TENANT $victimTenant";
    }
}

echo "VERDICT\n";
if ($landed === [] && $markerHits === []) {
    echo "  NO CROSS-TENANT ROW CREATED. G-SEC-27 is NOT confirmed as a write leak\n";
    echo "  by this probe. Either the route refused, or the write did not reach a\n";
    echo "  tenant-scoped table with this payload.\n";
} else {
    echo "  *** CROSS-TENANT WRITE CONFIRMED ***\n";
    foreach ($landed as $t => $d) printf("     %s: +%d rows in tenant %d\n", $t, $d, $victimTenant);
    foreach ($markerHits as $h) echo "     marker found in $h\n";
}

// ── CLEANUP ─────────────────────────────────────────────────────────────────
$removed = 0;
foreach ($candidates as $t) {
    foreach (['jobrole', 'job_role_name', 'name', 'title'] as $col) {
        if (!Schema::hasColumn($t, $col)) continue;
        $removed += DB::table($t)->where($col, 'like', "%$marker%")->delete();
    }
}
DB::table('personal_access_tokens')->where('name', 'gsec27')->delete();
printf("\ncleanup: %d probe row(s) removed, token deleted\n", $removed);
