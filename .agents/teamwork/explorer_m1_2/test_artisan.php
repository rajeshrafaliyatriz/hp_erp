<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;

echo "Artisan call test...\n";
$exitCode = Artisan::call('list', ['--raw' => true]);
echo "Artisan exit code: {$exitCode}\n";
