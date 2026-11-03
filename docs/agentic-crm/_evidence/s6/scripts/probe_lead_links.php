<?php
// READ-ONLY (dev): per lead, an md5 of every column EXCEPT the two link columns, and the two link columns.
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION TRANSACTION READ ONLY');
echo 'connection ', DB::getDefaultConnection(), ', database ', DB::selectOne('SELECT DATABASE() d')->d, "\n";
foreach (DB::table('crm_leads')->orderBy('id')->get() as $l) {
    $a = (array) $l;
    $links = ['product_master_id' => $a['product_master_id'], 'org_account_id' => $a['org_account_id']];
    unset($a['product_master_id'], $a['org_account_id']);
    echo 'lead ', str_pad((string) $l->id, 4), ' ', $l->lead_no, ' tenant ', $l->sub_institute_id, ' other-columns md5 ', md5(json_encode($a)), ' product ', var_export($links['product_master_id'], true), ' account ', var_export($links['org_account_id'], true), ' updated_at ', $l->updated_at, "\n";
}
