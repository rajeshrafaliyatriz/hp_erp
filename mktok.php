<?php
require __DIR__.'/vendor/autoload.php';
$app=require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = \App\Models\auth\tbluserModel::find(28);
$u->tokens()->where('name','eso-b')->delete();
echo $u->createToken('eso-b')->plainTextToken;
