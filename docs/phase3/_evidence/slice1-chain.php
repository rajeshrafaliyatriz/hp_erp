<?php
/**
 * SLICE 1 — THE CHAIN, end to end, through the real request path.
 *
 * define a competency → map it to a job role → rate an employee →
 * read the gap → RENAME THE ROLE and read it all again.
 *
 * Everything goes through the API. Nothing is seeded by SQL except the employee's
 * rating, which has no endpoint yet (Slice 1b), and that writes only the table
 * item 5 created.
 *
 * Cleans up after itself: shared database.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\auth\tbluserModel;

const TENANT = 1;
$admin    = 1;
$employee = 2;

$adminTok = tbluserModel::find($admin)->createToken('chain')->plainTextToken;
$empTok   = tbluserModel::find($employee)->createToken('chain')->plainTextToken;

function call($kernel, string $uri, string $method, array $params): array {
    $r = Illuminate\Http\Request::create($uri, $method, $params);
    $r->headers->set('Accept', 'application/json');
    $res = $kernel->handle($r);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$skill   = DB::table('s_users_skills')->where('sub_institute_id', TENANT)->value('id');
$jobrole = (int) DB::table('tbluser')->where('id', $employee)->value('allocated_standards');
$roleRow = DB::table('s_user_jobrole')->where('id', $jobrole)->first();

printf("employee %d, job role %d = \"%s\"\n\n", $employee, $jobrole, $roleRow->jobrole ?? '?');

/* 1 — define the competency (items 1-2) */
[$c1, $b1] = call($kernel, '/api/competency/definitions', 'POST', [
    'token' => $adminTok, 'sub_institute_id' => TENANT,
    'name' => 'Chain Test Competency', 'code' => 'CHAIN-1',
    'items' => [
        ['kasba_type' => 'skill',     'item_id' => $skill,                        'weight' => 0.5],
        ['kasba_type' => 'knowledge', 'item_label' => 'Regulatory frameworks',    'weight' => 0.3],
        ['kasba_type' => 'attitude',  'item_label' => 'Client-first mindset',     'weight' => 0.2],
    ],
]);
$cid = $b1['data']['id'] ?? null;
printf("1. define competency        HTTP %d  id=%s\n", $c1, $cid);

/* 2 — map it to the job role at required 3 (item 3) */
[$c2, $b2] = call($kernel, '/api/competency/role-map', 'POST', [
    'token' => $adminTok, 'sub_institute_id' => TENANT, 'jobrole_id' => $jobrole,
    'items' => [['competency_id' => $cid, 'required_proficiency' => 3, 'is_mandatory' => true]],
]);
printf("2. map to role (required 3) HTTP %d  written=%s removed=%s\n",
    $c2, $b2['data']['written'] ?? '?', $b2['data']['removed'] ?? '?');

/* 3 — the gap BEFORE any rating: must be UNMEASURED, not zero, not pass */
[$c3, $b3] = call($kernel, '/api/competency/gap', 'GET',
    ['token' => $empTok, 'sub_institute_id' => TENANT, 'user_id' => $employee]);
$row = $b3['data']['competencies'][0] ?? [];
printf("3. gap BEFORE rating        HTTP %d  state=%-11s level=%-6s gap=%-6s coverage=%s\n",
    $c3, $row['state'] ?? '?', var_export($row['measured_level'] ?? null, true),
    var_export($row['gap'] ?? null, true), $row['coverage'] ?? '?');
printf("   unmeasured count         %s of %s   <- feeds capability coverage\n",
    $b3['data']['coverage']['competencies_unmeasured'] ?? '?', $b3['data']['coverage']['competencies_required'] ?? '?');

/* 4 — rate the SKILL item at 1 (item 5). No endpoint yet: Slice 1b. */
$skillItem = DB::table('competency_kasba_item')->where('competency_id', $cid)->where('kasba_type', 'skill')->first();
DB::table('competency_kasba_rating')->insert([
    'sub_institute_id' => TENANT, 'user_id' => $employee, 'kasba_item_id' => $skillItem->id,
    'rating' => 1, 'assessor_id' => $admin, 'source' => 'manual',
    'rated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
printf("\n4. rate the SKILL item at 1 (the other two items stay UNRATED)\n");

/* 5 — the gap AFTER: required 3, measured 1, gap 2 */
[$c5, $b5] = call($kernel, '/api/competency/gap', 'GET',
    ['token' => $empTok, 'sub_institute_id' => TENANT, 'user_id' => $employee]);
$row = $b5['data']['competencies'][0] ?? [];
printf("5. gap AFTER rating         HTTP %d\n", $c5);
printf("   required=%s  measured=%s  GAP=%s  state=%s\n",
    $row['required_proficiency'] ?? '?', $row['measured_level'] ?? '?', $row['gap'] ?? '?', $row['state'] ?? '?');
printf("   coverage=%s  <- the level speaks for only this share of the competency\n", $row['coverage'] ?? '?');
printf("   mandatory items below required: %d\n", count($b5['data']['mandatory_below_required'] ?? []));
foreach ($b5['data']['mandatory_below_required'] ?? [] as $m) {
    printf("     - %s %s rated %s, required %s\n", $m['kasba_type'], $m['item_label'] ?? '(by key)', $m['rating'], $m['required']);
}

/* 6 — THE PROOF: rename the job role, then read everything again */
$oldName = $roleRow->jobrole;
DB::table('s_user_jobrole')->where('id', $jobrole)->update(['jobrole' => $oldName . ' — RENAMED']);
printf("\n6. RENAMED the job role to \"%s\"\n", $oldName . ' — RENAMED');

[$c6, $b6] = call($kernel, '/api/competency/gap', 'GET',
    ['token' => $empTok, 'sub_institute_id' => TENANT, 'user_id' => $employee]);
$row6 = $b6['data']['competencies'][0] ?? [];
[$c7, $b7] = call($kernel, '/api/competency/role-map', 'GET',
    ['token' => $adminTok, 'sub_institute_id' => TENANT, 'jobrole_id' => $jobrole]);

printf("   (a) mapping holds        %s (%d requirement(s))\n",
    $c7 === 200 && count($b7['data'] ?? []) ? 'YES' : '*** LOST ***', count($b7['data'] ?? []));
printf("   (b) gap still computes   %s  required=%s measured=%s gap=%s\n",
    $c6 === 200 && ($row6['gap'] ?? null) !== null ? 'YES' : '*** LOST ***',
    $row6['required_proficiency'] ?? '?', $row6['measured_level'] ?? '?', $row6['gap'] ?? '?');
printf("   (c) rating still resolves %s\n",
    ($row6['coverage'] ?? 0) > 0 ? 'YES' : '*** LOST ***');

DB::table('s_user_jobrole')->where('id', $jobrole)->update(['jobrole' => $oldName]);

/* cleanup */
$items = DB::table('competency_kasba_item')->where('competency_id', $cid)->pluck('id');
DB::table('competency_kasba_rating')->whereIn('kasba_item_id', $items)->delete();
DB::table('jobrole_competency_map')->where('competency_id', $cid)->delete();
DB::table('competency_kasba_item')->where('competency_id', $cid)->delete();
DB::table('competency')->where('id', $cid)->delete();
DB::table('personal_access_tokens')->where('name', 'chain')->delete();
printf("\ncleaned: competency=%d map=%d ratings=%d (name restored)\n",
    DB::table('competency')->count(), DB::table('jobrole_competency_map')->count(),
    DB::table('competency_kasba_rating')->count());
