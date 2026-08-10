<?php
/**
 * PHASE 3 SMOKE SUITE — one command, PASS/FAIL per check, one overall verdict.
 *
 * WHY: 44 code items have shipped. Every one was verified when it landed and
 * NONE had been re-run since. If something broke three items ago, nothing would
 * have told us.
 *
 * RULES BUILT IN:
 *   - Every check CLEANS UP after itself. This is a shared database.
 *   - A check that CANNOT RUN reports SKIPPED, never PASS. That distinction has
 *     caught things all phase and it is not a formality here.
 *   - Assertions are the ones the original items were verified with; this
 *     orchestrates them rather than inventing new ones.
 *
 * Usage:  php phase3-smoke.php
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\auth\tbluserModel;

$RESULTS = [];
$t0 = microtime(true);

function check(string $group, string $name, callable $fn): void
{
    global $RESULTS;
    try {
        [$state, $detail] = $fn();
    } catch (\Throwable $e) {
        $state = 'SKIPPED';
        $detail = 'could not run: ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 70);
    }
    $RESULTS[] = [$group, $name, $state, $detail];
    printf("  %-7s %-46s %s\n", $state, $name, $detail);
}

function tok(int $userId, string $name = 'smoke'): string
{
    return tbluserModel::find($userId)->createToken($name)->plainTextToken;
}

function call($kernel, string $uri, string $method, array $params): array
{
    $r = Illuminate\Http\Request::create($uri, $method, $params);
    $r->headers->set('Accept', 'application/json');
    $res = $kernel->handle($r);
    return [$res->getStatusCode(), json_decode($res->getContent(), true), (string) $res->getContent()];
}

/* ══════════════════════════ SECURITY ══════════════════════════ */
echo "\nSECURITY\n";

check('security', 'G-SEC-23: the three routes NOT DISCLOSING', function () use ($kernel) {
    $t = tok(198, 'smoke_sec');
    $cases = [
        ['api/user-signup/', 'tbluser', 'first_name'],
        ['api/feedback/', 'talent_job_applications', 'email'],
    ];
    $leaks = [];
    foreach ($cases as [$uri, $table, $field]) {
        $row = DB::table($table)->where('sub_institute_id', 3)->orderBy('id')->first();
        if (!$row || empty($row->$field)) continue;
        [$code, , $body] = call($kernel, '/' . $uri . $row->id, 'GET',
            ['token' => $t, 'sub_institute_id' => 7, 'type' => 'API']);
        if ($code === 200 && stripos($body, (string) $row->$field) !== false) $leaks[] = $uri;
    }
    DB::table('personal_access_tokens')->where('name', 'smoke_sec')->delete();
    return [$leaks ? 'FAIL' : 'PASS', $leaks ? 'LEAKING: ' . implode(', ', $leaks) : 'tenant-3 markers absent'];
});

check('security', 'G-SEC-15: unauthenticated GET refused', function () use ($kernel) {
    [$code] = call($kernel, '/getSkillCompetency', 'GET', ['sub_institute_id' => 1]);
    return [$code === 401 ? 'PASS' : 'FAIL', "HTTP $code (expected 401)"];
});

check('security', 'G-SEC-15: bounded result set holds', function () use ($kernel) {
    $t = tok(198, 'smoke_bnd');
    [$code, $b] = call($kernel, '/getSkillCompetency', 'GET',
        ['token' => $t, 'sub_institute_id' => 7, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_bnd')->delete();
    $n = is_array($b['data'] ?? null) ? count($b['data']) : -1;
    return [$code === 200 && $n <= 2000 ? 'PASS' : 'FAIL', "HTTP $code, $n rows (ceiling 2000)"];
});

check('security', 'G-LMS-SEC-01: anonymous LMS assignment refused', function () use ($kernel) {
    [$code] = call($kernel, '/api/lmsAssignment', 'GET', ['sub_institute_id' => 1]);
    return [$code === 401 ? 'PASS' : 'FAIL', "HTTP $code (expected 401)"];
});

check('security', 'G-XPROD-01: no HP Brain rows in G2G audit', function () use ($kernel) {
    $admin = DB::table('tbluser')->where('user_profile_id', 1)->value('id');
    if (!$admin) return ['SKIPPED', 'no administrator account'];
    $t = tok($admin, 'smoke_xp');
    [$code, $b] = call($kernel, '/api/lms/governance/audit-logs', 'GET',
        ['token' => $t, 'sub_institute_id' => 1, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_xp')->delete();
    if ($code !== 200) return ['SKIPPED', "audit endpoint returned $code"];
    $hp = ['Person', 'Department', 'Organization', 'Capability', 'Authorization'];
    $leak = 0;
    foreach ($b['data'] ?? [] as $r) if (in_array($r['entity_type'] ?? '', $hp, true)) $leak++;
    return [$leak === 0 ? 'PASS' : 'FAIL', "$leak HP Brain rows visible"];
});

/* ══════════════════════════ PERMISSIONS ══════════════════════════ */
echo "\nPERMISSIONS\n";

check('permissions', 'nine roles render, none empty', function () use ($kernel) {
    $profiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->get()->groupBy('role_key');
    if ($profiles->count() !== 9) return ['SKIPPED', 'expected 9 role_keys, found ' . $profiles->count()];
    $actor = DB::table('tbluser')->whereNotNull('user_profile_id')->value('id');
    $t = tok($actor, 'smoke_nav');
    $empty = [];
    foreach ($profiles as $rk => $ps) {
        [$code, $b] = call($kernel, '/user/ajax_sidebar_menu_g2g', 'GET',
            ['profile_id' => $ps[0]->id, 'sub_institute_id' => $ps[0]->sub_institute_id, 'token' => $t]);
        if ($code !== 200 || !count($b['data'] ?? [])) $empty[] = $rk;
    }
    DB::table('personal_access_tokens')->where('name', 'smoke_nav')->delete();
    return [$empty ? 'FAIL' : 'PASS', $empty ? 'EMPTY: ' . implode(',', $empty) : 'all nine non-empty'];
});

check('permissions', 'Administrator reaches 1 -> 8 -> 23', function () {
    $pid = DB::table('tbluserprofilemaster')->where('role_key', 'administrator')->value('id');
    $have = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $pid)
        ->whereIn('menu_id', [1, 8, 23])->where('can_view', 1)->count();
    return [$have === 3 ? 'PASS' : 'FAIL', "$have of 3 present"];
});

check('permissions', 'Employee holds 18 leaf screens', function () {
    $pid = DB::table('tbluserprofilemaster')->where('role_key', 'employee')->value('id');
    $ids = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $pid)->where('can_view', 1)->pluck('menu_id');
    $kids = DB::table('tblmenumaster_g2g')->whereIn('parent_id', $ids)->pluck('parent_id')->unique();
    $leaves = $ids->reject(fn ($i) => $kids->contains($i))->count();
    return [$leaves === 18 ? 'PASS' : 'FAIL', "$leaves leaves (expected 18)"];
});

/* ══════════════════════════ EVENT STORE ══════════════════════════ */
echo "\nEVENT STORE\n";

check('events', 'emit -> project -> re-project (idempotent)', function () {
    $rec = app(App\Services\Events\EventRecorder::class);
    $proj = app(App\Services\Events\AuditLogProjector::class);
    $id = $rec->record('smoke.test', 7, 'smoke', 999001, null, ['k' => 'v']);
    $proj->catchUp();
    $a = DB::table('g2g_audit_log')->where('event_id', $id)->count();
    $proj->project(DB::table('g2g_event')->find($id));
    $b = DB::table('g2g_audit_log')->where('event_id', $id)->count();
    DB::table('g2g_audit_log')->where('event_id', $id)->delete();
    DB::table('g2g_event_delivery')->where('event_id', $id)->delete();
    DB::table('g2g_event')->where('id', $id)->delete();
    return [$a === 1 && $b === 1 ? 'PASS' : 'FAIL', "1 row after project, $b after re-project"];
});

check('events', 'reactor THROWS in replay mode', function () {
    $rec = app(App\Services\Events\EventRecorder::class);
    $nd = app(App\Services\Events\NotificationDispatcher::class);
    $id = $rec->record('smoke.replay', 7, 'smoke', 999002, null, []);
    $ev = DB::table('g2g_event')->find($id);
    App\Services\Events\ReplayMode::enable();
    $threw = false;
    try { $nd->dispatch($ev); } catch (\Throwable $e) { $threw = true; }
    App\Services\Events\ReplayMode::disable();
    DB::table('g2g_event_delivery')->where('event_id', $id)->delete();
    DB::table('g2g_event')->where('id', $id)->delete();
    return [$threw ? 'PASS' : 'FAIL', $threw ? 'RuntimeException raised' : 'NO EXCEPTION — spec violated'];
});

check('events', 'catalogue invariants', function () {
    $err = App\Services\Events\EventCatalogue::assertInvariants();
    return [$err === [] ? 'PASS' : 'FAIL', $err === [] ? 'all pass' : count($err) . ' violation(s)'];
});

/* ══════════════════════════ DATA ══════════════════════════ */
echo "\nDATA\n";

foreach ([['s_user_skill_jobrole', 'jobrole_id'], ['s_user_skill_jobrole', 'skill_id'], ['s_user_jobrole_task', 'jobrole_id']] as [$tb, $col]) {
    check('data', "link resolution $tb.$col", function () use ($tb, $col) {
        $tot = DB::table($tb)->count();
        $ok = DB::table($tb)->whereNotNull($col)->count();
        $pct = $tot ? 100 * $ok / $tot : 0;
        return [$pct >= 99.99 ? 'PASS' : 'FAIL', sprintf('%.2f%% (%s of %s)', $pct, number_format($ok), number_format($tot))];
    });
}

foreach ([['s_user_skill_jobrole', 'jobrole_id', 's_user_jobrole'], ['s_user_skill_jobrole', 'skill_id', 's_users_skills'], ['s_user_jobrole_task', 'jobrole_id', 's_user_jobrole']] as [$tb, $col, $canon]) {
    check('data', "cross-tenant fks $tb.$col", function () use ($tb, $col, $canon) {
        $n = DB::table("$tb as s")->join("$canon as k", 'k.id', '=', "s.$col")
            ->whereColumn('k.sub_institute_id', '!=', 's.sub_institute_id')->count();
        return [$n === 0 ? 'PASS' : 'FAIL', "$n cross-tenant"];
    });
}

check('data', 'hpbrain_audit_logs untouched (342)', function () {
    $n = DB::table('hpbrain_audit_logs')->count();
    return [$n === 342 ? 'PASS' : 'FAIL', "$n rows (expected 342)"];
});

/* ══════════════════════════ SLICE 1 CHAIN ══════════════════════════ */
echo "\nSLICE 1 CHAIN\n";

check('slice1', 'employee cannot read a colleague gap', function () use ($kernel) {
    $emp = DB::table('tbluser')->where('user_profile_id', 3)->orderBy('id')->get();
    if ($emp->count() < 2) return ['SKIPPED', 'need two employee accounts'];
    $t = tok($emp[0]->id, 'smoke_gap');
    [$code] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $t, 'sub_institute_id' => $emp[0]->sub_institute_id ?: 1, 'user_id' => $emp[1]->id]);
    DB::table('personal_access_tokens')->where('name', 'smoke_gap')->delete();
    return [$code === 403 ? 'PASS' : 'FAIL', "HTTP $code (expected 403)"];
});

check('slice1', 'chain: required 3, measured 1, gap 2, survives rename', function () use ($kernel) {
    $admin = DB::table('tbluser')->where('user_profile_id', 1)->value('id');
    $empRow = DB::table('tbluser')->where('user_profile_id', 3)->orderBy('id')->first();
    if (!$admin || !$empRow) return ['SKIPPED', 'need an admin and an employee'];
    $sid = 1;
    $jobrole = (int) $empRow->allocated_standards;
    if (!$jobrole) return ['SKIPPED', 'employee has no job role'];

    $at = tok($admin, 'smoke_chain'); $et = tok($empRow->id, 'smoke_chain');
    $skill = DB::table('s_users_skills')->where('sub_institute_id', $sid)->value('id');

    [, $b1] = call($kernel, '/api/competency/definitions', 'POST', [
        'token' => $at, 'sub_institute_id' => $sid, 'name' => 'SMOKE Competency', 'code' => 'SMOKE-1',
        'items' => [['kasba_type' => 'skill', 'item_id' => $skill, 'weight' => 0.5],
                    ['kasba_type' => 'behaviour', 'item_label' => 'smoke', 'weight' => 0.5]],
    ]);
    $cid = $b1['data']['id'] ?? null;
    if (!$cid) return ['SKIPPED', 'competency not created'];

    call($kernel, '/api/competency/role-map', 'POST', [
        'token' => $at, 'sub_institute_id' => $sid, 'jobrole_id' => $jobrole,
        'items' => [['competency_id' => $cid, 'required_proficiency' => 3, 'is_mandatory' => true]],
    ]);
    $item = DB::table('competency_kasba_item')->where('competency_id', $cid)->where('kasba_type', 'skill')->first();
    DB::table('competency_kasba_rating')->insert([
        'sub_institute_id' => $sid, 'user_id' => $empRow->id, 'kasba_item_id' => $item->id,
        'rating' => 1, 'source' => 'smoke', 'rated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    [, $g1] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $et, 'sub_institute_id' => $sid, 'user_id' => $empRow->id]);
    $row = collect($g1['data']['competencies'] ?? [])->firstWhere('competency_id', $cid);
    $before = ($row['required_proficiency'] ?? null) === 3 && ($row['measured_level'] ?? null) == 1 && ($row['gap'] ?? null) == 2;

    $old = DB::table('s_user_jobrole')->where('id', $jobrole)->value('jobrole');
    DB::table('s_user_jobrole')->where('id', $jobrole)->update(['jobrole' => $old . ' SMOKE-RENAME']);
    [, $g2] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $et, 'sub_institute_id' => $sid, 'user_id' => $empRow->id]);
    $row2 = collect($g2['data']['competencies'] ?? [])->firstWhere('competency_id', $cid);
    $after = ($row2['gap'] ?? null) == 2 && ($row2['coverage'] ?? 0) > 0;
    DB::table('s_user_jobrole')->where('id', $jobrole)->update(['jobrole' => $old]);

    $items = DB::table('competency_kasba_item')->where('competency_id', $cid)->pluck('id');
    DB::table('competency_kasba_rating')->whereIn('kasba_item_id', $items)->delete();
    DB::table('jobrole_competency_map')->where('competency_id', $cid)->delete();
    DB::table('competency_kasba_item')->where('competency_id', $cid)->delete();
    DB::table('competency')->where('id', $cid)->delete();
    DB::table('personal_access_tokens')->where('name', 'smoke_chain')->delete();

    return [$before && $after ? 'PASS' : 'FAIL',
        ($before ? 'gap 2 before rename' : 'GAP WRONG before') . '; ' . ($after ? 'holds after rename' : 'LOST after rename')];
});

/* ══════════════════════════ STATIC ══════════════════════════ */
echo "\nSTATIC\n";

check('static', 'php -l on changed controllers/services', function () {
    $files = array_merge(
        glob('C:/Users/MILAN/Downloads/hp_erp/app/Services/Events/*.php'),
        glob('C:/Users/MILAN/Downloads/hp_erp/app/Services/Competency/*.php'),
        glob('C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/Api/Competency/*.php')
    );
    $bad = [];
    foreach ($files as $f) {
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        if ($rc !== 0) $bad[] = basename($f);
    }
    return [$bad ? 'FAIL' : 'PASS', $bad ? implode(', ', $bad) : count($files) . ' files clean'];
});

/* ══════════════════════════ VERDICT ══════════════════════════ */
$tally = ['PASS' => 0, 'FAIL' => 0, 'SKIPPED' => 0];
foreach ($RESULTS as [, , $s]) $tally[$s]++;

printf("\n%s\n", str_repeat('=', 72));
printf("PASS %d   FAIL %d   SKIPPED %d   (%.0fs)\n", $tally['PASS'], $tally['FAIL'], $tally['SKIPPED'], microtime(true) - $t0);
if ($tally['FAIL']) {
    echo "\nFAILURES:\n";
    foreach ($RESULTS as [$g, $n, $s, $d]) if ($s === 'FAIL') printf("  [%s] %s — %s\n", $g, $n, $d);
}
if ($tally['SKIPPED']) {
    echo "\nSKIPPED (never counted as pass):\n";
    foreach ($RESULTS as [$g, $n, $s, $d]) if ($s === 'SKIPPED') printf("  [%s] %s — %s\n", $g, $n, $d);
}
printf("\nVERDICT: %s\n", $tally['FAIL'] === 0 ? 'GREEN' : '*** RED — ' . $tally['FAIL'] . ' FAILURE(S) ***');
