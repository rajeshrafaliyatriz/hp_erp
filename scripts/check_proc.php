<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$proc = Illuminate\Support\Facades\DB::select('SHOW PROCESSLIST');
foreach ($proc as $p) {
    if ($p->db === 'hp_erp') {
        printf("ID: %d | User: %s | Host: %s | Cmd: %s | Time: %d | State: %s | Info: %s\n",
            $p->Id, $p->User, $p->Host, $p->Command, $p->Time, $p->State, substr($p->Info ?? '', 0, 80));
    }
}
