<?php
/** Dump the real navigation tree from tblmenumaster_g2g - the source of truth for what a user can reach. */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('tblmenumaster_g2g')
    ->whereNull('deleted_at')
    ->orderBy('level')->orderBy('sort_order')->orderBy('id')
    ->get();

$byParent = [];
foreach ($rows as $r) {
    $byParent[(int) ($r->parent_id ?? 0)][] = $r;
}

$out = [];
function walk($byParent, $pid, $depth, &$out)
{
    foreach ($byParent[$pid] ?? [] as $r) {
        $out[] = [
            'depth'  => $depth,
            'id'     => $r->id,
            'name'   => $r->menu_name,
            'level'  => $r->level,
            'link'   => $r->access_link,
            'status' => $r->status,
            'tenant' => $r->sub_institute_id,
            'type'   => $r->menu_type,
        ];
        walk($byParent, (int) $r->id, $depth + 1, $out);
    }
}
walk($byParent, 0, 0, $out);

// anything whose parent is not itself a row (orphans)
$ids = [];
foreach ($rows as $r) { $ids[(int) $r->id] = true; }
$orphans = [];
foreach ($rows as $r) {
    $p = (int) ($r->parent_id ?? 0);
    if ($p !== 0 && !isset($ids[$p])) {
        $orphans[] = $r;
    }
}

echo "TOTAL ROWS: " . count($rows) . " | RENDERED IN TREE: " . count($out) . " | ORPHANS: " . count($orphans) . PHP_EOL;
echo str_repeat('=', 110) . PHP_EOL;
foreach ($out as $o) {
    // access_link must NOT be truncated: it is the join key against the
    // frontend content maps, and a cut string produces false "dead nav" hits.
    printf(
        "%s%-46s id=%-5s lvl=%-2s status=%-2s tenant=%-4s %s\n",
        str_repeat('    ', $o['depth']),
        substr((string) $o['name'], 0, 46),
        $o['id'], $o['level'], $o['status'], $o['tenant'],
        $o['link'] !== null && $o['link'] !== '' ? (string) $o['link'] : '(no access_link)'
    );
}
if ($orphans) {
    echo PHP_EOL . "ORPHANED (parent_id points at a row that does not exist - unreachable):" . PHP_EOL;
    foreach ($orphans as $r) {
        printf("  id=%-5s parent=%-5s %-40s %s\n", $r->id, $r->parent_id, $r->menu_name, $r->access_link);
    }
}
