<?php
// DEV only. The real LeadFactory on the real MariaDB, inside ONE transaction that is ALWAYS rolled back: two leads are made (one with a product and an account),
// their number, score, links and event are read back, then everything is undone. Counts before and after prove nothing was left behind.
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Crm\Leads\LeadFactory;
use Illuminate\Support\Facades\DB;

if (DB::getDefaultConnection() !== 'mysql') {
    exit("not the dev connection\n");
}
$count = fn () => ['leads' => DB::table('crm_leads')->count(), 'events' => DB::table('g2g_event')->count(), 'sequence rows' => DB::table('g2g_number_sequences')->count()];
echo 'before: ', json_encode($count()), "\n";

DB::beginTransaction();
try {
    $a = LeadFactory::create(['last_name' => 'Probe One', 'company' => 'Probe Co', 'assigned_to' => 28, 'lead_status' => 'New', 'description' => str_repeat('d', 1800), 'product_master_id' => 1, 'org_account_id' => 37, 'product_master_id_note' => null], 6, 28);
} catch (\Throwable $e) {
    // product_master_id_note is not a column: this is the probe checking that an unknown key is the caller's error, not the factory's
    echo 'unknown column refused as expected: ', substr($e->getMessage(), 0, 90), "\n";
    DB::rollBack();
    DB::beginTransaction();
    $a = LeadFactory::create(['last_name' => 'Probe One', 'company' => 'Probe Co', 'assigned_to' => 28, 'lead_status' => 'New', 'description' => str_repeat('d', 1800), 'product_master_id' => 1, 'org_account_id' => 37], 6, 28);
}
$b = LeadFactory::create(['last_name' => 'Probe Two', 'company' => 'Probe Co', 'assigned_to' => 28, 'product_master_id' => 999999, 'org_account_id' => 424242], 6, 28);
foreach ([$a, $b] as $id) {
    $l = DB::table('crm_leads')->where('id', $id)->first();
    $e = DB::table('g2g_event')->where('type', 'crm.lead.created')->where('entity_id', $id)->first();
    echo 'lead ', $id, ': ', $l->lead_no, ', score ', $l->lead_score, ', product ', var_export($l->product_master_id, true), ', account ', var_export($l->org_account_id, true), ', description ', mb_strlen((string) $l->description), ' chars, event ', $e ? $e->type : 'NONE', "\n";
}
echo 'sequence row inside the transaction: ', json_encode(DB::table('g2g_number_sequences')->get(['seq_key', 'next_value'])->all()), "\n";
DB::rollBack();
echo 'after rollback: ', json_encode($count()), "\n";
