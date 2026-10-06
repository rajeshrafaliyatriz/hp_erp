<?php
/**
 * G-NAV-02b — rights for menus 225 (Readiness Gates) and 226 (Terminology).
 *
 * ── THE DEMONSTRATION CASE ──────────────────────────────────────────────────
 *
 * `hr_manager` gets **can_view on Readiness Gates and NOT can_edit**.
 *
 * Acknowledging a gate is the act that started matrix-enforced authorization: an
 * HR Manager can currently call `/readiness/gates/acknowledge` because the route
 * says `profile:admin,hr`, while `03-rbac-matrix.md` §3.1 gives HR view-only on
 * configuration screens. **This row is what the guard must enforce**, and it is
 * the row the acceptance test flips.
 *
 * Per §3.x, mirroring the pattern the matrix already uses for configuration
 * screens (Role & Permissions, Group-wise rights: `V` for HR Mgr, `V C E D` for
 * Admin):
 *
 *              Readiness Gates          Terminology
 *   admin      view + add/edit/delete   view + edit
 *   hr_manager VIEW ONLY                VIEW ONLY
 *   others     no row  (= no view)
 *
 * A role with no row cannot see the menu at all - that is the precedence tail,
 * "role default > deny", expressed as data.
 *
 *   php G-NAV-02b-seed-rights-for-new-menus.php --dry
 *   php G-NAV-02b-seed-rights-for-new-menus.php
 *   php G-NAV-02b-seed-rights-for-new-menus.php --rollback
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const READINESS = 225;
const TERMINOLOGY = 226;
$dry      = in_array('--dry', $argv, true);
$rollback = in_array('--rollback', $argv, true);

if ($rollback) {
    $n = DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', [READINESS, TERMINOLOGY])->delete();
    printf("rolled back: %d rights rows\n", $n);
    exit;
}

// menu => role_key => [view, add, edit, delete]
$GRANTS = [
    READINESS => [
        'administrator' => [1, 1, 1, 1],
        'hr_manager'    => [1, 0, 0, 0],   // ← THE DEMONSTRATION CASE
    ],
    TERMINOLOGY => [
        'administrator' => [1, 0, 1, 0],
        'hr_manager'    => [1, 0, 0, 0],
    ],
];

$profiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager'])
    ->get(['id', 'role_key', 'sub_institute_id']);

$rows = [];
foreach ($GRANTS as $menu => $byRole) {
    foreach ($profiles as $p) {
        if (!isset($byRole[$p->role_key])) continue;
        [$v, $a, $e, $d] = $byRole[$p->role_key];
        $rows[] = [
            'menu_id'          => $menu,
            'profile_id'       => $p->id,
            'sub_institute_id' => $p->sub_institute_id,
            'can_view'         => $v, 'can_add' => $a, 'can_edit' => $e, 'can_delete' => $d,
            // right_* ARE LEFT NULL, DELIBERATELY, and that is what every
            // existing row does. MEASURED, not assumed: they are
            // enum('allow','deny') NULL - THE TRI-STATE ITSELF, not a mirror of
            // can_*. NULL means "no individual override"; the group grant in
            // can_* decides. Writing 'allow' here would create an explicit
            // override where the matrix intends a default, and the precedence
            // ranks individual above group - so it would outrank the very row an
            // administrator later edits.
            //
            // My first version wrote $v/$a/$e/$d into them and MySQL truncated
            // the enum. The error was the schema refusing a wrong assumption.
            'created_at'       => now(),
        ];
    }
}

printf("BLAST RADIUS\n");
printf("  rights rows           : %d (menus %d and %d)\n", count($rows), READINESS, TERMINOLOGY);
printf("  profiles touched      : %d across %d tenants\n",
    $profiles->count(), $profiles->pluck('sub_institute_id')->unique()->count());
printf("  roles WITHOUT a row   : every other role - they cannot see either menu\n");
printf("  THE DEMONSTRATION ROW : hr_manager on %d = view 1, edit 0\n\n", READINESS);

$existing = DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', [READINESS, TERMINOLOGY])->count();
if ($existing) { exit("REFUSING: $existing rights row(s) already exist for these menus.\n"); }
if ($dry) { exit("--dry: nothing written.\n"); }

DB::transaction(function () use ($rows) {
    foreach (array_chunk($rows, 200) as $c) DB::table('tblgroupwise_rights_g2g')->insert($c);
});

foreach ([READINESS, TERMINOLOGY] as $m) {
    foreach (DB::table('tblgroupwise_rights_g2g as r')
        ->join('tbluserprofilemaster as p', 'p.id', '=', 'r.profile_id')
        ->where('r.menu_id', $m)->where('r.sub_institute_id', 3)
        ->get(['p.role_key', 'r.can_view', 'r.can_edit']) as $x) {
        printf("menu %d  %-14s can_view=%d can_edit=%d\n", $m, $x->role_key, $x->can_view, $x->can_edit);
    }
}
printf("\nrollback: php %s --rollback\n", basename(__FILE__));
