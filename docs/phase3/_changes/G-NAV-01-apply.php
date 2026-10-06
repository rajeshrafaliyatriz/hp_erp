<?php
/**
 * Apply G-NAV-01 inside a transaction, with pre-conditions checked and an
 * automatic rollback if anything is not exactly as expected.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$OLD = '/module/task-management/administration/task-priority';
$NEW = '/module/task-management/task-permission';

echo "=== BEFORE ===\n";
foreach (DB::table('tblmenumaster_g2g')->whereIn('id', [218, 219])->orderBy('id')->get() as $r) {
    printf("  id=%-4s %-22s %s\n", $r->id, $r->menu_name, $r->access_link);
}

// pre-condition 1: row 219 is exactly what we expect
$target = DB::table('tblmenumaster_g2g')->where('id', 219)->first();
if (!$target || $target->access_link !== $OLD) {
    echo "\nABORT: row 219 is not in the expected state. No change made.\n";
    exit(1);
}

// pre-condition 2: nothing already owns the new path
$clash = DB::table('tblmenumaster_g2g')->where('access_link', $NEW)->count();
if ($clash > 0) {
    echo "\nABORT: {$clash} row(s) already use the target path. No change made.\n";
    exit(1);
}

DB::beginTransaction();
try {
    $n = DB::table('tblmenumaster_g2g')
        ->where('id', 219)
        ->where('access_link', $OLD)
        ->update(['access_link' => $NEW, 'updated_at' => now()]);

    if ($n !== 1) {
        throw new RuntimeException("expected 1 row affected, got {$n}");
    }

    $after = DB::table('tblmenumaster_g2g')->whereIn('id', [218, 219])->orderBy('id')->get();
    $links = $after->pluck('access_link')->all();
    if (count(array_unique($links)) !== 2) {
        throw new RuntimeException('218 and 219 still share a path');
    }

    DB::commit();
    echo "\n=== AFTER (committed) ===\n";
    foreach ($after as $r) {
        printf("  id=%-4s %-22s %s\n", $r->id, $r->menu_name, $r->access_link);
    }
    echo "\nOK: 1 row updated. 218 and 219 now resolve to different screens.\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo "\nROLLED BACK: " . $e->getMessage() . "\nNo change made.\n";
    exit(1);
}
