<?php
// DEV only. Proves crm_leads.description now takes 2,000 multi-byte characters, inside a transaction that is ALWAYS rolled back, and that the row is
// byte-identical afterwards (md5 of the whole row before and after).
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::getDefaultConnection() !== 'mysql') {
    exit("not the dev connection\n");
}
$id = (int) DB::table('crm_leads')->where('sub_institute_id', 6)->orderBy('id')->value('id');
$snapshot = fn () => md5(json_encode(DB::table('crm_leads')->where('id', $id)->first()));
$before = $snapshot();
$type = DB::selectOne("SELECT DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'description'")->t;
echo "description column type: {$type}\n";

$text = str_repeat("Kindergarten \u{00E9}\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947} ", 112);   // multi-byte, well over 2,000 characters
$text = mb_substr($text, 0, 2000);
DB::beginTransaction();
try {
    DB::table('crm_leads')->where('id', $id)->update(['description' => $text]);
    $row = DB::selectOne('SELECT CHAR_LENGTH(description) c, LENGTH(description) b, description d FROM crm_leads WHERE id = ?', [$id]);
    echo 'written 2,000 characters (', strlen($text), ' bytes): read back ', $row->c, ' characters, ', $row->b, ' bytes, identical text: ', $row->d === $text ? 'yes' : 'NO', "\n";
} finally {
    DB::rollBack();
}
echo 'row identical after the rollback: ', $snapshot() === $before ? 'yes' : 'NO', "\n";
echo 'longest description now stored: ', DB::selectOne('SELECT MAX(LENGTH(description)) m FROM crm_leads')->m, " bytes\n";
