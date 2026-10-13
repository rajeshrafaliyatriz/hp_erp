<?php

/**
 * One-off, READ-ONLY export of Signals "Company opportunities" and "Product Portfolio"
 * data to a SQL file that can be imported on another database (e.g. live).
 *
 *   php scripts/export_signals_portfolio_seed.php <source_tenant_id> [target_tenant_id]
 *
 * - Only SELECTs from this app's configured database; nothing is modified here.
 * - Output uses INSERT IGNORE, so importing never overwrites or deletes existing rows.
 * - Ids are preserved so the links between companies, opportunities and sources hold.
 * - sub_institute_id is rewritten to the target tenant (defaults to the source tenant).
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$source = (int) ($argv[1] ?? 0);
$target = (int) ($argv[2] ?? $source);

if ($source <= 0) {
    fwrite(STDERR, "Usage: php scripts/export_signals_portfolio_seed.php <source_tenant_id> [target_tenant_id]\n");
    exit(1);
}

// Parents first, so foreign keys (if any) are satisfied on import.
$tables = [
    'g2g_product_profiles',
    'g2g_research_runs',
    'g2g_companies',
    'g2g_company_opportunities',
    'g2g_research_sources',
    'g2g_product_offers',
];

$pdo = DB::connection()->getPdo();
$out = [
    '-- G2G temporary seed: Company opportunities + Product Portfolio',
    "-- Source tenant {$source}, target tenant {$target}. Generated " . date('c'),
    '-- INSERT IGNORE: never overwrites or deletes existing rows. Safe to re-run.',
    'SET NAMES utf8mb4;',
    'SET FOREIGN_KEY_CHECKS = 0;',
    '',
];

foreach ($tables as $table) {
    $rows = DB::table($table)->where('sub_institute_id', $source)->orderBy('id')->get();
    $out[] = "-- {$table}: {$rows->count()} rows";

    foreach ($rows as $row) {
        $row = (array) $row;
        $row['sub_institute_id'] = $target;
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
        $out[] = "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES ({$vals});";
    }

    $out[] = '';
}

$out[] = 'SET FOREIGN_KEY_CHECKS = 1;';

$dir = __DIR__ . '/../database/seed-data';
@mkdir($dir, 0777, true);
$file = "{$dir}/g2g_signals_portfolio_seed_tenant{$target}.sql";
file_put_contents($file, implode("\n", $out) . "\n");

echo "Wrote {$file}\n";
