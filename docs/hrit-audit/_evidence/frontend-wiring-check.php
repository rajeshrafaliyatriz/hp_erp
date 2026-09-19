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

// ------------------------------------------- F-166 Bank-wise Payment Advice
/*
 * The opposite test from My HR's, and it matters that it IS the opposite.
 *
 * My HR shows a person their own payslip, so every profile must be able to view
 * it - the assertion there is `rights == profiles`. This advice lists every
 * employee's salary and bank account, so the assertion here is that rights are
 * a STRICT SUBSET: granted to admin/hr, withheld from everyone else. A
 * migration that accidentally granted it to all profiles would leave My HR's
 * check green and hand every employee the payroll.
 */
$bank = DB::table('tblmenumaster_g2g')
    ->where('access_link', '/module/hrit-solutions/payroll-management/payroll-bank-report')
    ->whereNull('deleted_at')->where('status', 1)->first();

$bankRights = $bank
    ? DB::table('tblgroupwise_rights_g2g')->where('menu_id', $bank->id)->where('can_view', 1)->count()
    : 0;

// Profiles that SHOULD have it, computed the same way the migration did.
$bankExpected = 0;
foreach (DB::table('tbluserprofilemaster')->get(['id', 'role_key', 'name']) as $profile) {
    if (\App\Support\RoleKey::satisfies(\App\Support\RoleKey::fromProfile($profile), ['admin', 'hr'])) {
        $bankExpected++;
    }
}

// The label collision: menu 140 mounts the payroll ENTRY grid, so it must not
// be called a Report.
$menu140 = DB::table('tblmenumaster_g2g')->where('id', 140)->value('menu_name');

// ------------------------------------ F-171 Monthly Attendance Report
/*
 * The THIRD rights shape in this module, and each is deliberate:
 *   My HR (305)                  every profile - you see only yourself
 *   Bank advice (307) / history (308)   admin/hr only - everyone's pay
 *   Monthly attendance (309)     every profile - the CONTROLLER enforces
 *                                HR-or-self, so a rights row grants an
 *                                employee their own month and nothing more
 *
 * If 309 ever narrows to the admin/hr count, either the controller check was
 * removed (and the menu should have narrowed with it) or a migration went
 * wrong. Either way it is worth failing on.
 */
$monthlyAtt = DB::table('tblmenumaster_g2g')
    ->where('access_link', '/module/hrit-solutions/attendance-management/monthly-attendance-report')
    ->whereNull('deleted_at')->where('status', 1)->first();

$monthlyAttRights = $monthlyAtt
    ? DB::table('tblgroupwise_rights_g2g')->where('menu_id', $monthlyAtt->id)->where('can_view', 1)->count()
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

// --------------------------------------------- offered vs actually backed
/*
 * A catalogue that offers more than the API can serve.
 *
 * Leave Reports listed FIFTEEN reports against THREE endpoints. Nine had no
 * backing at all, the preview rendered the same leave-type summary whichever
 * was chosen, and Export wrote that summary under the selected report's
 * filename - so "Holiday Calendar Report" downloaded a file whose own name
 * asserted what it did not contain.
 */
$reportsFile = $hrit . '/leave-management/leave-reports/services/leave-reports-data.ts';
$reportsSrc  = $code($reportsFile);
$offered     = preg_match_all("/^\s{4}id: '/m", $reportsSrc);

// Columns that can only ever render a constant dash, because the dataset behind
// the grouping carries no such field.
$dashColumns = 0;
foreach ($files as $file) {
    $dashColumns += preg_match_all("/(punchIn|punchOut|expectedIn|expectedOut|earlyBy):\s*'--'/", $code($file));
}

// A Print button with no print stylesheet emits the whole application - sidebar,
// tabs, filter panel and every button - rather than the report (F-99).
$printNoStyles = 0;
foreach ($files as $file) {
    $src = $code($file);
    if (strpos($src, 'window.print()') === false) {
        continue;
    }
    $dir = dirname($file);
    $hasRules = false;
    foreach (array_merge($walk($dir), $walk(dirname($dir))) as $sibling) {
        if (strpos($code($sibling), '@media print') !== false) {
            $hasRules = true;
            break;
        }
    }
    if (!$hasRules) {
        $printNoStyles++;
    }
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

/*
 * A named metric assigned a literal zero.
 *
 * F-175. `earlyGoing: 0` appeared in two mappers: one fed a table column, the
 * other a charted series with its own legend entry. Both rendered as real
 * measurements on every tenant, every filter and every date range, because the
 * endpoints behind them carry no such field. A column of zeros is a CLAIM -
 * "nobody left early" - which is worse than an absent column.
 *
 * Narrow on purpose, listing only the metric names this module charts, so it
 * cannot fire on a legitimate accumulator initialiser.
 */
$constantZeroMetric = 0;
foreach ($files as $file) {
    $constantZeroMetric += preg_match_all('/\b(earlyGoing|lateCount|absentCount|presentCount)\s*:\s*0\s*,/', $code($file));
}

/*
 * Two Group By options sharing one branch.
 *
 * F-175. `groupBy === 'organization' || groupBy === 'date'` meant "Date"
 * grouped by DEPARTMENT, and differed only by a column holding the same string
 * on every row - an option in a dropdown that does not do what it says.
 */
$mergedGroupBranch = 0;
foreach ($files as $file) {
    $mergedGroupBranch += preg_match_all("/groupBy\s*===\s*'[a-z]+'\s*\|\|\s*groupBy\s*===\s*'[a-z]+'/", $code($file));
}

// ------------------------------------------------------------------- output
printf("routed=%d\n", count($routed));
printf("routed_without_menu=%d\n", count($routedWithoutMenu));
printf("live_leaf_no_route=%d\n", count($liveLeafNoRoute));
printf("myhr_menu=%d\n", $myHr ? 1 : 0);
printf("myhr_rights_gap=%d\n", $profiles - $myHrRights);
printf("bank_menu=%d\n", $bank ? 1 : 0);
printf("bank_rights=%d\n", $bankRights);
printf("bank_rights_expected=%d\n", $bankExpected);
printf("bank_rights_leaked=%d\n", max(0, $bankRights - $bankExpected));
printf("profiles_total=%d\n", $profiles);
printf("monthly_att_menu=%d\n", $monthlyAtt ? 1 : 0);
printf("monthly_att_rights_gap=%d\n", $profiles - $monthlyAttRights);
printf("menu140_name=%s\n", (string) $menu140);
printf("noop_handlers=%d\n", $noop);
printf("hash_href=%d\n", $hashHref);
printf("fake_trend=%d\n", $fakeTrend);
printf("pinned_month=%d\n", $pinnedMonth);
printf("letter_icon=%d\n", $letterIcon);
printf("drifted_duplicates=%d\n", $drifted);
printf("reports_offered=%d\n", $offered);
printf("dash_only_columns=%d\n", $dashColumns);
printf("constant_zero_metric=%d\n", $constantZeroMetric);
printf("merged_group_branch=%d\n", $mergedGroupBranch);
printf("print_without_styles=%d\n", $printNoStyles);
printf("files_scanned=%d\n", count($files));

foreach ($routedWithoutMenu as $x) { printf("detail_no_menu=%s\n", $x); }
foreach ($liveLeafNoRoute as $x)   { printf("detail_no_route=%s\n", $x); }
