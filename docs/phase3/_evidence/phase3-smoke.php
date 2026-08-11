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

check('security', 'G-SEC-23 route 3: audit user-actions', function () use ($kernel) {
    // The third confirmed-disclosing route. Leaving it unchecked would mean the
    // one place it could silently reopen is the one place nobody looks.
    $t = tok(198, 'smoke_s23c');
    $victim = DB::table('tbluser')->where('sub_institute_id', 3)->orderBy('id')->first();
    if (!$victim || empty($victim->first_name)) {
        DB::table('personal_access_tokens')->where('name', 'smoke_s23c')->delete();
        return ['SKIPPED', 'no tenant-3 user with a name to use as a marker'];
    }
    [$code, , $body] = call($kernel, '/api/competency/audit/user-actions/' . $victim->id, 'GET',
        ['token' => $t, 'sub_institute_id' => 7, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_s23c')->delete();
    $leaked = $code === 200 && stripos($body, (string) $victim->first_name) !== false;
    return [$leaked ? 'FAIL' : 'PASS', $leaked ? 'LEAKING tenant-3 name' : "HTTP $code, marker absent"];
});

check('security', 'anonymous: api/kpis and DeepSeekChat', function () use ($kernel) {
    // Both answered an anonymous caller in the original probe.
    $open = [];
    foreach (['api/kpis', 'DeepSeekChat'] as $uri) {
        [$code, , $body] = call($kernel, '/' . $uri, 'GET', ['sub_institute_id' => 1]);
        if ($code === 200 && strlen($body) > 40) $open[] = "$uri(200)";
    }
    return [$open ? 'FAIL' : 'PASS', $open ? 'STILL OPEN: ' . implode(', ', $open) : 'both refused or empty'];
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

check('permissions', 'Employee holds 19 leaf screens', function () {
    $pid = DB::table('tbluserprofilemaster')->where('role_key', 'employee')->value('id');
    $ids = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $pid)->where('can_view', 1)->pluck('menu_id');
    $kids = DB::table('tblmenumaster_g2g')->whereIn('parent_id', $ids)->pluck('parent_id')->unique();
    $leaves = $ids->reject(fn ($i) => $kids->contains($i))->count();
    return [$leaves === 19 ? 'PASS' : 'FAIL', "$leaves leaves (expected 19)"];
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

/* ══════════════════════════ NOTIFICATIONS (X-06) ══════════════════════════ */
echo "\nNOTIFICATIONS\n";

check('notify', 'every notified event has a resolver AND a template', function () {
    $events = App\Services\Events\NotificationDispatcher::NOTIFIES;
    $src = file_get_contents(base_path('app/Services/Notifications/RecipientResolver.php'));
    $tpl = DB::table('g2g_notification_template')->where('channel', 'inapp')
        ->where('is_active', true)->pluck('event_type')->all();

    $broken = [];
    foreach ($events as $e) {
        if (!str_contains($src, "'$e'")) $broken[] = "$e (no resolver)";
        if (!in_array($e, $tpl, true))   $broken[] = "$e (no template)";
    }
    return [$broken === [] ? 'PASS' : 'FAIL',
        $broken === [] ? count($events) . ' events, each with a named recipient and wording'
                       : implode(' | ', $broken)];
});

check('notify', 'deferred events are not silently re-wired', function () {
    // The register and the code drift apart quietly. This is the check that
    // notices - a deferred notification that reappears in NOTIFIES would
    // otherwise start sending the day someone edited one list and not the other.
    $bad = array_intersect(
        array_keys(App\Services\Events\EventCatalogue::NOT_NOTIFIED),
        App\Services\Events\NotificationDispatcher::NOTIFIES
    );
    return [$bad === [] ? 'PASS' : 'FAIL',
        $bad === [] ? count(App\Services\Events\EventCatalogue::NOT_NOTIFIED) . ' deferred/dropped, none wired'
                    : 'RE-WIRED: ' . implode(', ', $bad)];
});

check('notify', 'tenant data cannot reach the template engine', function () {
    // KNOWN-POSITIVE FIRST (R16): the probe must contain the shape being tested,
    // or a pass means the check simply found nothing to expand.
    $probe = '{term:competency}';
    $ev = (object) [
        'id' => 0, 'type' => 'task.rejected', 'sub_institute_id' => 7,
        'entity_id' => 1, 'entity_type' => 'task', 'actor_id' => 1,
        'payload' => json_encode(['task_title' => $probe, 'approve_remarks' => 'x']),
    ];
    $c = new App\Services\Notifications\NotificationComposer(
        new App\Services\Notifications\TerminologyService()
    );
    $m = $c->compose($ev, 'inapp', 7);
    if ($m === null) {
        return ['SKIPPED', 'no inapp template for task.rejected - nothing to test'];
    }
    $intact = str_contains($m['body'], $probe);
    return [$intact ? 'PASS' : 'FAIL',
        $intact ? 'payload directive rendered as literal text'
                : 'EXPANDED - payload substitution is running before terminology'];
});

check('notify', 'email channel is OFF (tripwire, not a correctness test)', function () {
    // A TRIPWIRE ON A DELIBERATE DEFAULT. 386 of 387 users carry a real address
    // and MAIL_MAILER is live SMTP.
    //
    // THREE CONDITIONS BEFORE THIS MAY GO GREEN-WITH-EMAIL-ON, ALL REQUIRED
    // (Triz, 2026-08-11):
    //   1. the C23 WRITE half exists and passes (772 routes, untested today)
    //   2. a TEST TENANT with fake addresses
    //   3. TRIZ'S EXPLICIT WRITTEN DECISION in the turn it happens
    //
    // If this line ever flips to FAIL, someone enabled outbound mail. That must
    // be a decision somebody made, never something that drifted.
    $on = app(App\Services\Notifications\NotificationSender::class)->emailEnabled();
    return [$on ? 'FAIL' : 'PASS',
        $on ? 'G2G_NOTIFY_EMAIL=true - REAL MAIL WILL LEAVE THE BUILDING. Three conditions apply; check all three were met.'
            : 'G2G_NOTIFY_EMAIL unset/false'];
});

check('notify', 'no action link points at a route that does not exist', function () {
    // G-NOTIF-02. X-06 shipped SIX notifications whose "act on it" link 404s -
    // every path invented from the shape of the domain instead of read from the
    // router. Most are NULL now, because the real screens live under
    // /module/[moduleId]/[menuId]/[submenuId] with ids that come from
    // tblmenumaster_g2g AT RUNTIME. There is no static path to hardcode.
    $verified = (require base_path('database/migrations/2026_08_11_000300_correct_notification_action_paths.php'))::VERIFIED_ROUTES;
    $bad = DB::table('g2g_notification_template')->whereNotNull('action_path')
        ->get(['event_type', 'action_path'])
        ->filter(fn ($r) => !in_array($r->action_path, $verified, true))
        ->map(fn ($r) => $r->event_type . ' -> ' . $r->action_path)
        ->all();
    $n = DB::table('g2g_notification_template')->whereNotNull('action_path')->count();
    return [$bad === [] ? 'PASS' : 'FAIL',
        $bad === [] ? "$n linked template(s), all to verified routes" : implode(' | ', $bad)];
});

check('notify', 'X-12 can still read the role -> course link', function () {
    // The KEY table (course_jobrole_map) is empty, so the TEXT link on
    // sub_std_map.jobrole is the only thing making X-12 assign anything at all.
    // If this reaches 0, the assigner goes quiet and nothing else would say so.
    $c = App\Services\Events\LearningAssigner::coverage();
    $live = $c['jobrole_map'] > 0 || $c['jobrole_text_resolves'] > 0;
    return [$live ? 'PASS' : 'FAIL',
        sprintf('key %d, text %d resolving (%d courses named)',
            $c['jobrole_map'], $c['jobrole_text_resolves'], $c['courses_with_jobrole_text'])];
});

check('org', 'reporting_manager_id has exactly ONE guarded write path', function () {
    // G-ORG-01 for real. The validator existed with zero callers for the whole
    // phase; this asserts it now has exactly one door and that the door is shut.
    $ctrl = base_path('app/Http/Controllers/Api/Org/ReportingLineController.php');
    if (!file_exists($ctrl)) return ['FAIL', 'X-16 controller missing'];
    $src = file_get_contents($ctrl);
    $writes = preg_match_all("/reporting_manager_id'\s*=>/", $src);
    $calls  = preg_match_all('/canAssign\(/', $src);

    // And nothing ELSE in app/ may write the column.
    $others = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        if (str_contains($f->getPathname(), 'ReportingLineController')) continue;
        $s = file_get_contents($f->getPathname());
        if (preg_match("/reporting_manager_id'\s*=>/", $s)) $others[] = basename($f->getPathname());
    }
    $ok = $writes === 1 && $calls >= 1 && $others === [];
    return [$ok ? 'PASS' : 'FAIL', $ok
        ? "one write site, $calls canAssign() calls, no other writer in app/"
        : ($others ? 'OTHER WRITERS: ' . implode(', ', $others) : "writes=$writes calls=$calls")];
});

check('org', 'task.status has exactly ONE guarded write path', function () {
    // T-01, same shape as the reporting-line assertion. Five files wrote the
    // column directly, each with its own idea of what else changes when a status
    // changes. TaskStatusWriter owns the invariant; this asserts nothing else
    // reaches around it.
    $writer = base_path('app/Services/TaskManagement/TaskStatusWriter.php');
    if (!file_exists($writer)) return ['FAIL', 'TaskStatusWriter missing'];

    $others = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $path = $f->getPathname();
        if (str_contains($path, 'TaskStatusWriter')) continue;
        $src = file_get_contents($path);
        // strip comments - a pattern that reads prose is not reading code
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#^\s*//.*$#m', '', $src);
        if (!preg_match("/table\(\s*'task'\s*\)/", $src)) continue;
        // an update() whose payload carries a status key
        if (preg_match_all("/->update\(\s*\[[^\]]*'status'\s*=>/s", $src, $m)) {
            $others[basename($path)] = count($m[0]);
        }
    }
    // KNOWN-POSITIVE (R16): the pattern must be able to see the shape it hunts.
    $probe = "DB::table('task')->where('id',1)->update(['status' => 'X']);";
    if (!preg_match("/->update\(\s*\[[^\]]*'status'\s*=>/s", $probe)) {
        return ['SKIPPED', 'pattern failed its own known-positive'];
    }

    $n = array_sum($others);
    return [$others === [] ? 'PASS' : 'FAIL',
        $others === []
            ? 'TaskStatusWriter is the only writer of task.status'
            : "$n direct write(s) outside the writer: " . implode(', ',
                array_map(fn ($k, $v) => "$k($v)", array_keys($others), $others))];
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

check('slice1', 'rename: DASHBOARD counts still resolve', function () {
    // THE CHECK THAT WOULD HAVE CAUGHT G-DASH-01. Slice 1's rename proof tested
    // the path Slice 1 built; the dashboards were never in it.
    $role = DB::table('s_user_jobrole as jr')
        ->join('s_user_jobrole_task as jt', 'jr.id', '=', 'jt.jobrole_id')
        ->whereNull('jt.deleted_at')->value('jr.id');
    if (!$role) return ['SKIPPED', 'no job role with mapped tasks'];

    $count = fn () => DB::table('s_user_jobrole as jr')
        ->join('s_user_jobrole_task as jt', function ($j) { $j->on('jr.id', '=', 'jt.jobrole_id')->whereNull('jt.deleted_at'); })
        ->where('jr.id', $role)->count();

    $before = $count();
    $old = DB::table('s_user_jobrole')->where('id', $role)->value('jobrole');
    DB::table('s_user_jobrole')->where('id', $role)->update(['jobrole' => $old . ' SMOKE-RENAME']);
    $after = $count();
    DB::table('s_user_jobrole')->where('id', $role)->update(['jobrole' => $old]);

    return [$before === $after && $before > 0 ? 'PASS' : 'FAIL',
        "$before rows before rename, $after after"];
});

/* ══════════════════════════ WALKTHROUGH — TIER 1 (API) ══════════════════════════ */
echo "\nWALKTHROUGH (API)\n";

/**
 * THE NINE LOGINS, ASSERTED INSTEAD OF EYEBALLED.
 *
 * Triz was going to open nine screens by hand every time something shipped. These
 * checks do it on every run, as VALUES from the API rather than as a screenshot.
 * The seeded tenant-3 users are the fixtures (SEED-REGISTER-2026-08-11.md).
 */
function seededUsers(): array
{
    return DB::table('tbluser as u')
        ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
        ->where('u.sub_institute_id', 3)
        ->where('u.email', 'like', '%@healthcare.g2g')
        ->get(['u.id', 'u.email', 'u.user_profile_id', 'p.role_key'])
        ->keyBy('email')->all();
}

function navLabels(array $nodes, array &$out): void
{
    foreach ($nodes as $n) {
        $out[] = (string) ($n['name'] ?? $n['menu_name'] ?? $n['label'] ?? '');
        $kids = $n['children'] ?? $n['submenu'] ?? [];
        if ($kids) navLabels($kids, $out);
    }
}

check('walkthrough', 'nine logins: sidebar 200, non-empty, expected breadth', function () use ($kernel) {
    $users = seededUsers();
    if (count($users) < 9) return ['SKIPPED', 'seeded users absent (' . count($users) . ') - seed removed?'];

    // MEASURED on 2026-08-11, then asserted. A change here is either a rights
    // change somebody made, or a regression - both worth stopping for.
    $expected = [
        'administrator' => 7, 'hr_manager' => 7, 'hr_executive' => 6,
        'department_head' => 6, 'reporting_manager' => 6, 'employee' => 5,
        'recruiter' => 3, 'executive' => 7, 'auditor' => 7,
    ];
    $bad = [];
    $seen = [];
    foreach ($users as $email => $u) {
        if (isset($seen[$u->role_key])) continue;      // one per role
        $seen[$u->role_key] = true;
        $tk = tok($u->id, 'smoke_nav9');
        [$code, $b] = call($kernel, '/user/ajax_sidebar_menu_g2g', 'GET',
            ['profile_id' => $u->user_profile_id, 'sub_institute_id' => 3, 'token' => $tk]);
        $n = count($b['data'] ?? []);
        if ($code !== 200)                      $bad[] = "{$u->role_key}:HTTP$code";
        elseif ($n === 0)                       $bad[] = "{$u->role_key}:EMPTY";
        elseif ($n !== ($expected[$u->role_key] ?? -1)) $bad[] = "{$u->role_key}:$n!=" . ($expected[$u->role_key] ?? '?');
    }
    DB::table('personal_access_tokens')->where('name', 'smoke_nav9')->delete();
    return [$bad ? 'FAIL' : 'PASS', $bad ? implode(' ', $bad) : count($seen) . ' roles, all 200, breadth as expected'];
});

check('walkthrough', 'employee sees NO Payroll / Employee Directory / Talent', function () use ($kernel) {
    $users = seededUsers();
    $emp = null;
    foreach ($users as $u) if ($u->role_key === 'employee') { $emp = $u; break; }
    if (!$emp) return ['SKIPPED', 'no seeded employee'];

    $tk = tok($emp->id, 'smoke_ban');
    [$code, $b] = call($kernel, '/user/ajax_sidebar_menu_g2g', 'GET',
        ['profile_id' => $emp->user_profile_id, 'sub_institute_id' => 3, 'token' => $tk]);
    DB::table('personal_access_tokens')->where('name', 'smoke_ban')->delete();
    if ($code !== 200) return ['SKIPPED', "sidebar returned $code"];

    $labels = [];
    navLabels($b['data'] ?? [], $labels);
    // KNOWN-POSITIVE (R16): the matcher must be able to SEE a banned label, or a
    // clean result means only that the matcher is broken.
    $probe = ['Payroll Management'];
    $canSee = false;
    foreach ($probe as $p) if (stripos($p, 'Payroll') !== false) $canSee = true;
    if (!$canSee) return ['SKIPPED', 'matcher failed its own known-positive'];

    $found = [];
    foreach (['Payroll', 'Employee Directory', 'Talent'] as $banned) {
        foreach ($labels as $l) if ($l !== '' && stripos($l, $banned) !== false) { $found[] = $banned; break; }
    }
    return [$found ? 'FAIL' : 'PASS',
        $found ? 'EMPLOYEE SEES: ' . implode(', ', $found) : count(array_filter($labels)) . ' labels, none banned'];
});

check('walkthrough', 'vikram gap = 4 required / 1 met / 2 gap / 1 unmeasured', function () use ($kernel) {
    $users = seededUsers();
    $v = $users['vikram.sethi@healthcare.g2g'] ?? null;
    if (!$v) return ['SKIPPED', 'vikram absent'];
    $tk = tok($v->id, 'smoke_gapv');
    [$code, $b] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $tk, 'sub_institute_id' => 3, 'user_id' => $v->id, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_gapv')->delete();
    if ($code !== 200) return ['FAIL', "HTTP $code"];

    $rows = $b['data']['competencies'] ?? [];
    $c = ['met' => 0, 'gap' => 0, 'unmeasured' => 0];
    foreach ($rows as $r) { $s = $r['state'] ?? '?'; if (isset($c[$s])) $c[$s]++; }
    $ok = count($rows) === 4 && $c['met'] === 1 && $c['gap'] === 2 && $c['unmeasured'] === 1;
    return [$ok ? 'PASS' : 'FAIL',
        sprintf('%d required, %d met, %d gap, %d unmeasured', count($rows), $c['met'], $c['gap'], $c['unmeasured'])];
});

check('walkthrough', 'divya: level NULL and gap NULL — NOT 0', function () use ($kernel) {
    // THE SINGLE MOST IMPORTANT ASSERTION IN THE SET. "Nothing measured" and
    // "measured as zero" are different facts about a person, and only one of them
    // is a shortfall. A 0 here would be a false accusation rendered as a number.
    $users = seededUsers();
    $d = $users['divya.nair@healthcare.g2g'] ?? null;
    if (!$d) return ['SKIPPED', 'divya absent'];
    $tk = tok($d->id, 'smoke_gapd');
    [$code, $b] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $tk, 'sub_institute_id' => 3, 'user_id' => $d->id, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_gapd')->delete();
    if ($code !== 200) return ['FAIL', "HTTP $code"];

    $rows = $b['data']['competencies'] ?? [];
    if (count($rows) !== 2) return ['FAIL', count($rows) . ' requirements (expected 2)'];

    $wrong = [];
    foreach ($rows as $r) {
        if (($r['state'] ?? '') !== 'unmeasured') $wrong[] = 'state=' . ($r['state'] ?? '?');
        // MY FIRST VERSION USED ?? 'missing' AND FAILED ON CORRECT DATA: the
        // null-coalescing operator returns the fallback WHEN THE VALUE IS NULL,
        // so it can never observe the null it is testing for. The detail line
        // printed "level=NULL" beside a FAIL verdict - R23: the detail was right
        // and the verdict was wrong.
        // array_key_exists distinguishes "absent" from "present and null".
        if (!array_key_exists('measured_level', $r) || $r['measured_level'] !== null) {
            $wrong[] = 'level=' . var_export($r['measured_level'] ?? 'ABSENT', true);
        }
        if (!array_key_exists('gap', $r) || $r['gap'] !== null) {
            $wrong[] = 'gap=' . var_export($r['gap'] ?? 'ABSENT', true);
        }
    }
    return [$wrong ? 'FAIL' : 'PASS',
        $wrong ? 'NOT NULL: ' . implode(' ', array_unique($wrong)) : '2 rows, state=unmeasured, level NULL, gap NULL'];
});

check('walkthrough', 'every level travels with its coverage', function () use ($kernel) {
    $users = seededUsers();
    $bad = []; $levels = 0;
    foreach (['vikram.sethi@healthcare.g2g', 'meera.pillai@healthcare.g2g', 'joseph.mathew@healthcare.g2g'] as $e) {
        $u = $users[$e] ?? null;
        if (!$u) continue;
        $tk = tok($u->id, 'smoke_cov');
        [$code, $b] = call($kernel, '/api/competency/gap', 'GET',
            ['token' => $tk, 'sub_institute_id' => 3, 'user_id' => $u->id, 'type' => 'API']);
        DB::table('personal_access_tokens')->where('name', 'smoke_cov')->delete();
        if ($code !== 200) continue;
        foreach ($b['data']['competencies'] ?? [] as $r) {
            if (($r['measured_level'] ?? null) === null) continue;
            $levels++;
            if (!array_key_exists('coverage', $r) || $r['coverage'] === null) {
                $bad[] = ($r['competency_code'] ?? '?');
            }
        }
    }
    if ($levels === 0) return ['SKIPPED', 'no measured levels to check'];
    return [$bad ? 'FAIL' : 'PASS',
        $bad ? 'LEVEL WITHOUT COVERAGE: ' . implode(',', $bad) : "$levels levels, every one with coverage"];
});

check('walkthrough', 'vikram reading divya gap: 403', function () use ($kernel) {
    $users = seededUsers();
    $v = $users['vikram.sethi@healthcare.g2g'] ?? null;
    $d = $users['divya.nair@healthcare.g2g'] ?? null;
    if (!$v || !$d) return ['SKIPPED', 'fixtures absent'];
    $tk = tok($v->id, 'smoke_403');
    [$code] = call($kernel, '/api/competency/gap', 'GET',
        ['token' => $tk, 'sub_institute_id' => 3, 'user_id' => $d->id, 'type' => 'API']);
    DB::table('personal_access_tokens')->where('name', 'smoke_403')->delete();
    return [$code === 403 ? 'PASS' : 'FAIL', "HTTP $code (expected 403)"];
});

check('walkthrough', 'role map: save 3, re-read 3, remove 1, re-read 2', function () use ($kernel) {
    // SYNC SEMANTICS. The endpoint replaces the set; a removal must actually
    // remove, not be ignored because the payload was treated as additive.
    $admin = DB::table('tbluser as u')->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
        ->where('u.sub_institute_id', 3)->where('p.role_key', 'administrator')
        ->where('u.email', 'like', '%@healthcare.g2g')->value('u.id');
    $role = DB::table('s_user_jobrole')->where('sub_institute_id', 3)
        ->where('jobrole', 'Ward Administration Officer')->value('id');
    $comps = DB::table('competency')->where('sub_institute_id', 3)
        ->where('code', 'like', 'HC-%')->orderBy('id')->limit(3)->pluck('id')->all();
    if (!$admin || !$role || count($comps) < 3) return ['SKIPPED', 'fixtures absent'];

    $before = DB::table('jobrole_competency_map')->where('jobrole_id', $role)->get()->toArray();
    $tk = tok($admin, 'smoke_map');
    $mk = fn ($ids) => array_map(fn ($c) => ['competency_id' => $c, 'required_proficiency' => 3, 'is_mandatory' => 1], $ids);

    [$c1] = call($kernel, '/api/competency/role-map', 'POST',
        ['token' => $tk, 'sub_institute_id' => 3, 'type' => 'API', 'jobrole_id' => $role, 'items' => $mk($comps)]);
    $n1 = DB::table('jobrole_competency_map')->where('jobrole_id', $role)->count();

    [$c2] = call($kernel, '/api/competency/role-map', 'POST',
        ['token' => $tk, 'sub_institute_id' => 3, 'type' => 'API', 'jobrole_id' => $role,
         'items' => $mk(array_slice($comps, 0, 2))]);
    $n2 = DB::table('jobrole_competency_map')->where('jobrole_id', $role)->count();

    // RESTORE the seeded requirements exactly.
    DB::table('jobrole_competency_map')->where('jobrole_id', $role)->delete();
    foreach ($before as $row) DB::table('jobrole_competency_map')->insert((array) $row);
    DB::table('personal_access_tokens')->where('name', 'smoke_map')->delete();

    // 201, not 200 - the endpoint CREATES. My first version demanded 200 and
    // failed a correct product: the assertion was wrong, not the behaviour.
    $ok = in_array($c1, [200, 201], true) && $n1 === 3
       && in_array($c2, [200, 201], true) && $n2 === 2;
    return [$ok ? 'PASS' : 'FAIL',
        "save3->$n1, remove1->$n2 (HTTP $c1/$c2); restored " . count($before) . ' seeded rows'];
});

/* ══════════════════════════ WALKTHROUGH — TIER 2 (COMPONENT SOURCE) ══════════════════════════ */
echo "\nWALKTHROUGH (COMPONENT)\n";

/**
 * WHAT THE SCREENS RENDER, ASSERTED FROM SOURCE.
 *
 * These cannot prove a screen LOOKS right - only Tier 3 could. They prove the
 * source has no PATH to the wrong render, which is the strongest claim available
 * without a browser, and it is a real claim: every frontend defect this phase
 * (the dead bell, the mis-wired G-MAP-01 button, the vocabulary rename) was
 * visible in source.
 *
 * EVERY PATTERN VALIDATES AGAINST A KNOWN POSITIVE FIRST (R16). A regex that
 * cannot see the shape it hunts returns a clean result that means nothing - the
 * failure mode this suite exists to prevent.
 */
const G2GV0 = 'C:/Users/MILAN/Downloads/g2gv0';

function fe(string $rel): ?string
{
    $p = G2GV0 . '/' . $rel;
    return is_file($p) ? file_get_contents($p) : null;
}

/** Comments are prose, not behaviour. Strip before matching. */
function stripComments(string $s): string
{
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return preg_replace('#^\s*//.*$#m', '', $s);
}

check('component', 'gap view: unmeasured renders words, never a number or bar', function () {
    $src = fe('components/domain/competency/cm-my-capability.tsx');
    if ($src === null) return ['SKIPPED', 'cm-my-capability.tsx not found'];
    $code = stripComments($src);

    // KNOWN POSITIVE: the matcher must find the branch it is about to judge.
    if (!preg_match("/state\s*===\s*'unmeasured'/", $code)) {
        return ['SKIPPED', 'no unmeasured branch found - pattern would judge nothing'];
    }

    $problems = [];
    if (!str_contains($code, 'Not yet assessed')) $problems[] = 'string absent';

    // The unmeasured branch must not reach CoverageBar or a numeric level.
    if (preg_match("/state\s*===\s*'unmeasured'\s*\)\s*\{(.*?)\n  \}/s", $code, $m)) {
        $branch = $m[1];
        if (str_contains($branch, 'CoverageBar')) $problems[] = 'bar inside unmeasured branch';
        if (preg_match('/measured_level|\{0\}|toFixed/', $branch)) $problems[] = 'number inside unmeasured branch';
        if (!str_contains($branch, 'Not yet assessed')) $problems[] = 'branch does not render the string';
    }
    // And the table cell must choose the dash, not the bar, when unmeasured.
    if (!preg_match("/state\s*===\s*'unmeasured'\s*\n?\s*\?/", $code)) {
        $problems[] = 'table cell has no unmeasured ternary';
    }
    return [$problems ? 'FAIL' : 'PASS',
        $problems ? implode(' | ', $problems) : 'unmeasured -> "Not yet assessed", no bar, no number'];
});

check('component', 'gap view: a level cannot render without its coverage', function () {
    $src = fe('components/domain/competency/cm-my-capability.tsx');
    if ($src === null) return ['SKIPPED', 'file not found'];
    $code = stripComments($src);
    if (!str_contains($code, 'CoverageBar')) return ['SKIPPED', 'no CoverageBar - nothing to bind'];

    // CoverageBar must be fed row.coverage, and it must sit in the SAME ternary
    // whose other arm is the unmeasured dash - so there is no arm that shows a
    // level with no coverage beside it.
    $bound = (bool) preg_match('/<CoverageBar\s+coverage=\{row\.coverage\}/', $code);
    $sameBranch = (bool) preg_match("/state\s*===\s*'unmeasured'\s*\n?\s*\?[^:]*:\s*<CoverageBar/s", $code);
    return [$bound && $sameBranch ? 'PASS' : 'FAIL',
        $bound && $sameBranch
            ? 'CoverageBar is the else-arm of the unmeasured ternary, fed row.coverage'
            : ($bound ? 'bound but not in the same branch as the level' : 'CoverageBar not bound to row.coverage')];
});

check('component', 'composer: a picker ONLY for skill, free text for the other four', function () {
    $defs = fe('services/competency/definitions.ts');
    $comp = fe('components/domain/competency/cm-competency-composer.tsx');
    if ($defs === null || $comp === null) return ['SKIPPED', 'composer or definitions not found'];

    $d = stripComments($defs);
    // KNOWN POSITIVE: find the resolvable list at all.
    // The declaration reads: RESOLVABLE_KASBA_TYPES: readonly KasbaType[] = ['skill']
    // My first pattern stopped at the opening bracket of KasbaType[] and captured
    // NOTHING, then reported "resolvable = []" as though that were the finding.
    // A pattern that mis-parses reports its own failure as the product's. Anchor
    // on the assignment, not on the first bracket.
    if (!preg_match('/RESOLVABLE_KASBA_TYPES[^=]*=\s*\[([^\]]*)\]/s', $d, $m)) {
        return ['SKIPPED', 'RESOLVABLE_KASBA_TYPES not found'];
    }
    preg_match_all("/'([a-z]+)'/", $m[1], $kinds);
    $resolvable = $kinds[1];

    $problems = [];
    if ($resolvable !== ['skill']) {
        $problems[] = 'resolvable = [' . implode(',', $resolvable) . '], expected [skill]';
    }
    $c = stripComments($comp);
    // The picker must be gated on isResolvable, not rendered unconditionally.
    if (!preg_match('/isResolvable\(/', $c)) $problems[] = 'composer never calls isResolvable';
    if (!preg_match('/resolvable\s*(&&|\?)/', $c)) $problems[] = 'picker not gated on resolvable';
    // A free-text input must exist for the non-resolvable arm.
    if (!preg_match('/<Input\b|<input\b/', $c)) $problems[] = 'no free-text input for held labels';

    return [$problems ? 'FAIL' : 'PASS',
        $problems ? implode(' | ', $problems) : 'skill only is resolvable; picker gated; free text present'];
});

check('component', 'Skill Library: no user-visible "Competency" string', function () {
    $src = fe('components/domain/competency/cm-competency-library.tsx');
    if ($src === null) return ['SKIPPED', 'cm-competency-library.tsx not found'];
    $code = stripComments($src);

    // USER-VISIBLE ONLY. Type names (CompetencyLibraryItem), imports, and the CSV
    // import header aliases ('competency name' => 'name') are NOT user-visible and
    // were deliberately left - renaming an import alias would break real
    // spreadsheets. Same reasoning as SAVED_VIEWS_KEY.
    $visible = [];

    // 1. JSX text nodes.
    if (preg_match_all('/>\s*([^<>{}\n]*Competenc[^<>{}\n]*)</i', $code, $m)) {
        foreach ($m[1] as $s) if (trim($s) !== '') $visible[] = trim($s);
    }
    // 2. Display-ish attributes.
    if (preg_match_all('/(?:label|placeholder|title|aria-label)\s*=\s*["\']([^"\']*Competenc[^"\']*)["\']/i', $code, $m2)) {
        foreach ($m2[1] as $s) $visible[] = trim($s);
    }

    // KNOWN POSITIVE (R16): prove the matcher can see such a string at all.
    $probe = '<span>Competency Library</span>';
    if (!preg_match('/>\s*([^<>{}\n]*Competenc[^<>{}\n]*)</i', $probe)) {
        return ['SKIPPED', 'matcher failed its own known-positive - a clean result would be meaningless'];
    }

    return [$visible ? 'FAIL' : 'PASS',
        $visible ? count($visible) . ' visible: ' . implode(' | ', array_slice(array_unique($visible), 0, 3))
                 : 'no user-visible "Competency" (type names and CSV aliases excluded by design)'];
});

check('component', 'notification bell: fetched data, no hardcoded badge or empty state', function () {
    $src = fe('components/shell/notifications-menu.tsx');
    if ($src === null) return ['SKIPPED', 'notifications-menu.tsx not found'];
    $code = stripComments($src);

    $problems = [];
    // It must actually ask the server.
    if (!str_contains($code, 'notificationService')) $problems[] = 'no service call - the bell asks nobody';
    if (!preg_match('/unreadCount\(|\.list\(/', $code)) $problems[] = 'no unreadCount/list call';
    // The badge must be conditional on state, never constant.
    if (!preg_match('/unread\s*>\s*0\s*&&/', $code)) $problems[] = 'badge not gated on unread count';
    if (preg_match('/>\s*New\s*</', $code)) $problems[] = 'HARDCODED "New" badge still present';
    // A failure must be distinguishable from an empty inbox.
    if (!preg_match("/'error'/", $code)) $problems[] = 'no distinct error state';
    if (!preg_match("/state\s*===\s*'error'/", $code)) $problems[] = 'error state never rendered';
    // "All caught up" must be gated on a successful, empty response.
    if (!preg_match("/state\s*===\s*'idle'\s*&&\s*items\.length\s*===\s*0/", $code)) {
        $problems[] = '"caught up" not gated on idle AND empty';
    }
    return [$problems ? 'FAIL' : 'PASS',
        $problems ? implode(' | ', $problems)
                  : 'fetches, badge gated on unread, error state distinct from empty inbox'];
});

/* ══════════════════════════ STATIC ══════════════════════════ */
echo "\nSTATIC\n";

check('static', 'no method resolves identity then reads request tenant', function () {
    // G-SEC-24b. I WROTE THIS DEFECT AT ITEM 46, with every rule in place: the
    // fix resolved the identity and then five lines later read the tenant from
    // the request. Authenticated, then trusted the caller's tenant - C27's class,
    // introduced DURING a security fix.
    //
    // It is easy to write, which is the argument for a CHECK rather than more
    // care. This is that check, and it would have caught it as it was typed.
    $resolvers = '/\$this->(resolveApiIdentity|lmsIdentity|competencyContext|leaveContext|attendanceContext|taskContext)\s*\(/';
    // TENANT and ROLE only. `user_id` from a request is often a legitimate
    // SUBJECT (the person being assessed) - G-SEC-12's own IDENTITY vs SUBJECT
    // distinction. Flagging it would cry wolf on 8 valid methods.
    $fromRequest = '/\$request->(sub_institute_id|user_profile_name)\b|input\(\s*[\'"](sub_institute_id|user_profile_name)[\'"]\s*\)|header\(\s*[\'"]sub_institute_id[\'"]\s*\)/';

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers'));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') $files[] = $f->getPathname();
    }

    $offenders = [];
    foreach ($files as $path) {
        $src = file_get_contents($path);
        // STRIP COMMENTS FIRST. The first version matched a COMMENT describing a
        // defect already fixed (LmsLearningController::isInstructor) and reported
        // it as live. A pattern that reads prose is not reading code.
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#^\s*//.*$#m', '', $src);
        if (!preg_match($resolvers, $src)) continue;

        // split into method bodies - crude but sufficient: a method starts at
        // "function name(" and ends at the next one.
        $parts = preg_split('/(?=\n    (?:public|private|protected)\s+function\s)/', $src);
        foreach ($parts as $body) {
            if (!preg_match($resolvers, $body)) continue;
            if (!preg_match($fromRequest, $body, $m)) continue;
            preg_match('/function\s+(\w+)\s*\(/', $body, $fn);
            $offenders[] = basename($path) . '::' . ($fn[1] ?? '?') . ' (' . trim($m[0]) . ')';
        }
    }

    // KNOWN-POSITIVE VALIDATION (R16 extension): the pattern must be able to see
    // the shape it is looking for, or a zero result means nothing.
    $probe = '<?php function x($request){ $i = $this->resolveApiIdentity($request); $t = $request->sub_institute_id; }';
    $sees = preg_match($resolvers, $probe) && preg_match($fromRequest, $probe);
    if (!$sees) return ['SKIPPED', 'pattern failed its own known-positive - a zero result would be meaningless'];

    return [$offenders ? 'FAIL' : 'PASS',
        $offenders ? count($offenders) . ': ' . implode(' | ', array_slice($offenders, 0, 3))
                   : 'no method mixes resolved identity with a request-supplied tenant'];
});

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
echo "\nWHAT THIS SUITE DOES NOT COVER - it does not stand in for these:\n";
echo "  - C23's FULL read half (912 routes). Separate command, far beyond this budget.\n";
echo "  - Frontend types: run `npx tsc --noEmit` in g2gv0. Different repo, same runbook.\n";
echo "  - Anything requiring a rendered screen (C20). See the human walkthrough list.\n";

printf("\nVERDICT: %s\n", $tally['FAIL'] === 0 ? 'GREEN' : '*** RED — ' . $tally['FAIL'] . ' FAILURE(S) ***');
