<?php
// READ-ONLY on dev: runs the REAL CrmLeadController index/show against the REAL dev rows, with the identity fixed to organisation 6 (no token is minted and
// nothing is written: the session is READ ONLY). Prints only the fields slice 6 adds, and how the filters answer.
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Facts\ConsentGate;
use App\Http\Controllers\Api\Crm\CrmLeadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (DB::getDefaultConnection() !== 'mysql') {
    exit("not the dev connection\n");
}
DB::statement('SET SESSION TRANSACTION READ ONLY');

class ProbeLeadController extends CrmLeadController
{
    protected function resolveApiIdentity(Request $request)
    {
        return ['user' => (object) ['id' => 28], 'user_id' => 28, 'sub_institute_id' => 6];
    }
}

$c = new ProbeLeadController;
$call = fn (string $m, array $query = [], ...$args) => json_decode($c->$m(Request::create('/api/crm/leads', 'GET', $query), ...$args)->getContent(), true);
$new = ['id', 'leadNo', 'company', 'product', 'organisation_account', 'source_signal_id'];
$show = fn (array $item) => json_encode(array_intersect_key($item, array_flip($new)), JSON_UNESCAPED_UNICODE);

echo 'connection ', DB::getDefaultConnection(), ', organisation 6', "\n\n== list (no filter)\n";
$list = $call('index');
echo 'total ', $list['data']['pagination']['total'], "\n";
foreach ($list['data']['items'] as $i) {
    echo '  ', $show($i), "\n";
}

foreach (['product_id=1' => ['product_id' => 1], 'product_id=2 (another product)' => ['product_id' => 2], 'product_id=0' => ['product_id' => 0], 'has_signal=1' => ['has_signal' => 1],
    'consent=allowed' => ['consent' => 'allowed'], 'consent=blocked' => ['consent' => 'blocked'], 'product_id=1&has_signal=1&consent=allowed' => ['product_id' => 1, 'has_signal' => 1, 'consent' => 'allowed']] as $label => $q) {
    $r = $call('index', $q);
    echo "\n== list ?{$label}: total ", $r['data']['pagination']['total'], ' leads ', implode(', ', array_map(fn ($i) => $i['leadNo'], $r['data']['items'])), "\n";
}

foreach ([78, 548, 504] as $id) {
    $d = $call('show', [], $id)['data'];
    echo "\n== show lead {$id}\n  ", $show($d), "\n";
    echo '  source_signal: ', json_encode($d['source_signal'] === null ? null : array_merge($d['source_signal'], ['quote' => isset($d['source_signal']['quote']) ? mb_substr((string) $d['source_signal']['quote'], 0, 90) . '...' : null]), JSON_UNESCAPED_UNICODE), "\n";
    echo '  consent: ', json_encode($d['consent']), "\n";
}

echo "\n== the filter against the gate, lead by lead (organisation 6)\n";
$gate = app(ConsentGate::class);
$filterBlocked = array_map(fn ($i) => $i['id'], $call('index', ['consent' => 'blocked', 'per_page' => 100])['data']['items']);
foreach (DB::table('crm_leads')->where('sub_institute_id', 6)->whereNull('deleted_at')->where('converted', false)->orderBy('id')->pluck('id') as $id) {
    $g = $gate->allows('lead', (int) $id, 'email', 6);
    echo '  lead ', $id, ': gate says ', $g['allowed'] ? 'allowed' : 'BLOCKED (' . $g['reason'] . ')', '; filter says ', in_array((string) $id, $filterBlocked, true) ? 'blocked' : 'allowed', "\n";
}
