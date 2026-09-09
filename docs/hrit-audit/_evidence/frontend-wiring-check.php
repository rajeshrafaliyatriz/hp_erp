<?php
/**
 * The menu/route contract, and the static-data patterns that a backend probe
 * cannot see. Emits one `key=value` line per check for probe-frontend-wiring.sh.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/frontend-wiring-check.php';"
 *
 * Why this exists. Twelve phases and 253 API assertions all passed while:
 *   - the Leave Dashboard calendar was hardcoded to June 2026,
 *   - four KPI trend deltas were invented string constants,
 *   - two labelled buttons were wired to `() => {}` passed down as props, and
 *   - My HR - the only screen showing an employee their own payslip - had no
 *     row in tblmenumaster_g2g and so was unreachable by navigation.
 *
 * None of that is visible from an endpoint. This checks the layer above.
 *
 * Read-only.
 */

$fe = getenv('FE') ?: dirname(base_path()) . '/g2gv0';
$hrit = $fe . '/components/domain/hrms/hrit';

/** Strip // line comments and block comments so a comment ABOUT a defect is not counted AS one. */
$code = function (string $file): string {
    $src = @file_get_contents($file);
    if ($src === false) {
        return '';
    }
    $src = preg_replace('#/\*.*?\*/#s', '', $src);            // block comments
    $src = preg_replace('#^\s*//.*$#m', '', $src);            // whole-line //
    $src = preg_replace('#\{/\*.*?\*/\}#s', '', $src);        // JSX comments
    return (string) $src;
};

$walk = function (string $dir) use (&$walk): array {
    $out = [];
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, $walk($path));
        } elseif (preg_match('/\.(tsx|ts)$/', $entry)) {
            $out[] = $path;
        }
    }
    return $out;
};

$files = $walk($hrit);

// ---------------------------------------------------------------- the contract
$map = @file_get_contents($fe . '/hooks/content-map-m5.ts') ?: '';
preg_match_all("#accessLink: '(/module/hrit-solutions/[^']*)'#", $map, $m);
$routed = array_values(array_unique($m[1] ?? []));

$menuRows = DB::table('tblmenumaster_g2g')
    ->whereNull('deleted_at')
    ->where('access_link', 'like', '/module/hrit-solutions/%')
    ->get(['id', 'access_link', 'status', 'parent_id']);

$liveParents = DB::table('tblmenumaster_g2g')
    ->whereNull('deleted_at')->where('status', 1)->pluck('id')->all();

$haveMenu = $menuRows->pluck('access_link')->all();

// A routed screen with no menu row is unreachable by navigation.
$routedWithoutMenu = array_values(array_diff($routed, $haveMenu));

// A LIVE leaf whose parent is also live, with no route, renders the
// "Application Shell Ready" developer placeholder to whoever clicks it.
// The parent check matters: a leaf under a disabled parent is not in the
// sidebar at all, so it is not a dead link - getting that wrong produced a
// wrong finding once.
$liveLeafNoRoute = [];
foreach ($menuRows as $row) {
    if ((int) $row->status !== 1) {
        continue;
    }
    if (!in_array((int) $row->parent_id, array_map('intval', $liveParents), true)) {
        continue;   // parent disabled -> not reachable -> not a dead link
    }
    if (substr_count($row->access_link, '/') < 4) {
        continue;   // a section, not a leaf
    }
    if (!in_array($row->access_link, $routed, true)) {
        $liveLeafNoRoute[] = $row->access_link;
    }
}

// -------------------------------------------------------------------- My HR
$myHr = DB::table('tblmenumaster_g2g')
    ->where('access_link', '/module/hrit-solutions/my-hr')
    ->whereNull('deleted_at')->where('status', 1)->first();

$profiles = DB::table('tbluserprofilemaster')->count();
$myHrRights = $myHr
    ? DB::table('tblgroupwise_rights_g2g')->where('menu_id', $myHr->id)->where('can_view', 1)->count()
    : 0;

// ------------------------------------------------- dead controls & fake data
$noop = 0; $hashHref = 0; $fakeTrend = 0; $pinnedMonth = 0; $letterIcon = 0;

foreach ($files as $file) {
    $src = $code($file);
    $noop        += preg_match_all('/on[A-Z][A-Za-z]*=\{\(\)\s*=>\s*\{\s*\}\}/', $src);
    $hashHref    += preg_match_all('/href="#"/', $src);
    $fakeTrend   += preg_match_all("/trendValue:\s*'[+-]?[0-9]/", $src);
    $pinnedMonth += preg_match_all('/getDaysInMonth\(\s*[0-9]{4}\s*,/', $src);
    // A placeholder character typed where an icon belongs: <span ...>c</span>
    $letterIcon  += preg_match_all('#place-items-center[^>]*>\s*[A-Za-z]\s*</span>#s', $src);
}

// The eight drifted duplicates of live panels.
$drifted = 0;
foreach ([
    'today-status-card', 'today-summary-card', 'monthly-summary-card', 'leave-balance-card',
    'leave-balance-modal', 'recent-attendance-card', 'upcoming-events-card', 'attendance-summary-cards',
] as $name) {
    if (file_exists($hrit . '/attendance-management/attendance-tracking/components/' . $name . '.tsx')) {
        $drifted++;
    }
}

// ------------------------------------------------------------------- output
printf("routed=%d\n", count($routed));
printf("routed_without_menu=%d\n", count($routedWithoutMenu));
printf("live_leaf_no_route=%d\n", count($liveLeafNoRoute));
printf("myhr_menu=%d\n", $myHr ? 1 : 0);
printf("myhr_rights_gap=%d\n", $profiles - $myHrRights);
printf("noop_handlers=%d\n", $noop);
printf("hash_href=%d\n", $hashHref);
printf("fake_trend=%d\n", $fakeTrend);
printf("pinned_month=%d\n", $pinnedMonth);
printf("letter_icon=%d\n", $letterIcon);
printf("drifted_duplicates=%d\n", $drifted);
printf("files_scanned=%d\n", count($files));

foreach ($routedWithoutMenu as $x) { printf("detail_no_menu=%s\n", $x); }
foreach ($liveLeafNoRoute as $x)   { printf("detail_no_route=%s\n", $x); }
