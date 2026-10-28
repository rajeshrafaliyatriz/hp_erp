<?php
// Registers every item of a UTF-8 manifest as a PLATFORM asset through the project's own simulations:asset-add command.
// Usage: php run_asset_manifest.php <manifest.json> [--dry-run] [--live]
use Illuminate\Support\Facades\Artisan;

require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$manifest = json_decode((string) file_get_contents($argv[1] ?? ''), true, 16, JSON_THROW_ON_ERROR);
$dry = in_array('--dry-run', $argv, true);
$live = in_array('--live', $argv, true);
$failed = 0;
foreach ($manifest as $item) {
    $options = [
        'file' => $item['file'],
        '--platform' => true,
        '--name' => $item['name'],
        '--licence' => $item['licence'],
        '--credit' => $item['credit'],
        '--source' => $item['source'],
    ];
    if ($dry) {
        $options['--dry-run'] = true;
    }
    if ($live) {
        $options['--database'] = 'live';
    }
    $code = Artisan::call('simulations:asset-add', $options);
    $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));
    echo ($code === 0 ? 'ok   ' : 'FAIL ') . basename($item['file']) . '  ->  ' . ($lines[count($lines) - 1] ?? '') . PHP_EOL;
    $failed += $code === 0 ? 0 : 1;
}
echo $failed === 0 ? 'all done' : "{$failed} failed", PHP_EOL;
exit($failed === 0 ? 0 : 1);
