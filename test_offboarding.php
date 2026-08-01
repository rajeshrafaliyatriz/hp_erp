<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = new \Illuminate\Http\Request(['token' => 'abc', 'sub_institute_id' => 3, 'user_id' => 6]);
$controller = app()->make(\App\Http\Controllers\Api\Talent\OffboardingCaseController::class);
try {
    $resp = $controller->index($req);
    echo get_class($resp) . "\n";
    echo $resp->getContent();
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
