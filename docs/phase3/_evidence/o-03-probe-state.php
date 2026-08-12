<?php
/**
 * O-03, SECOND PROBE. The first one did not decide three routes and said so.
 *
 * WHY IT DID NOT: saveCredentials and testConnection wrap the tenant guard in a
 * try that ends in ONE generic catch, so the guard's "Invalid sub institute
 * access." is rewritten as "Failed to save..." / "...test failed." A refusal and
 * a Google outage come back identical. The message cannot discriminate, so this
 * probe stops using it and uses STATE instead:
 *
 *   - a full row snapshot of institute_google_credentials, before and after.
 *     If the guard held, the foreign tenant's row is byte-identical afterwards.
 *     A catch can rewrite a message; it cannot un-write a row.
 *   - an OWN-TENANT control for testConnection. If the own-tenant call returns
 *     the same 500 as the foreign one, that message is "Google unreachable" and
 *     is not evidence about the guard in either direction - which is a result
 *     about the PROBE, and gets reported as one.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;

$snap = fn() => DB::table('institute_google_credentials')->orderBy('id')->get()
    ->map(fn($r) => json_encode((array) $r))->implode("\n");

$tok = [];
foreach ([3, 6] as $t) {
    $u = DB::table('tbluser')->where('sub_institute_id', $t)->value('id');
    $tok[$t] = tbluserModel::find($u)->createToken('o03b')->plainTextToken;
}

function call($kernel, $method, $uri, $params)
{
    $req = Illuminate\Http\Request::create('/' . $uri, $method, $params);
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    $b = json_decode((string) $res->getContent(), true);
    return [$res->getStatusCode(), trim(substr((string) ($b['message'] ?? ''), 0, 44))];
}

// ── testConnection: the control the first probe was missing ──────────────────
echo "testConnection - is its 500 the guard, or is it Google?\n";
foreach ([[3, null], [3, 3], [3, 6], [6, 3]] as [$mine, $ask]) {
    $p = ['token' => $tok[$mine]];
    if ($ask !== null) $p['sub_institute_id'] = $ask;
    [$c, $m] = call($kernel, 'POST', 'api/excel-agent/test-connection', $p);
    printf("  caller %d asks %-4s -> HTTP %-4d %s\n", $mine, $ask === null ? 'none' : $ask, $c, $m);
}

// ── saveCredentials: state, not messages ─────────────────────────────────────
echo "\nsaveCredentials - decided by STATE. A catch can rewrite a message;\n";
echo "                  it cannot un-write a row.\n";
$before = $snap();
$rowsBefore = substr_count($before, "\n") + 1;

foreach ([[3, 6], [6, 3]] as [$mine, $theirs]) {
    [$c, $m] = call($kernel, 'POST', 'api/excel-agent/credentials', [
        'token' => $tok[$mine],
        'sub_institute_id' => $theirs,
        'google_sheet_id' => 'O03-PROBE-MARKER-' . $mine . '-' . $theirs,
        'sheet_name' => 'o03probe',
        'service_account_key' => '{"type":"service_account"}',
    ]);
    printf("  caller %d writing into tenant %d -> HTTP %d  %s\n", $mine, $theirs, $c, $m);
}

$after = $snap();
$rowsAfter = substr_count($after, "\n") + 1;
$marker = DB::table('institute_google_credentials')->where('google_sheet_id', 'like', 'O03-PROBE-MARKER-%')->count();

printf("\n  rows before/after      : %d / %d\n", $rowsBefore, $rowsAfter);
printf("  probe markers landed   : %d\n", $marker);
printf("  snapshot identical     : %s\n", $before === $after ? 'YES - nothing was written' : 'NO - STATE CHANGED');

if ($before !== $after) {
    echo "\n  *** A FOREIGN-TENANT WRITE LANDED. This is the finding. ***\n";
    // Known-positive for the snapshot itself: it must be able to SEE a change.
    echo "  (the snapshot proved it can detect a change by detecting this one)\n";
} else {
    // R29: a snapshot that never sees a change has not been shown to work.
    // Done on a COPY of the string, not on the table. Writing a row to prove a
    // write-detector works would be writing to the shared database to test the
    // test.
    echo "\n  KNOWN-NEGATIVE for the snapshot: it must be able to SEE a change.\n";
    $mutated = str_replace('"id":', '"ID":', $before);
    printf("  snapshot vs a mutated copy of itself: %s\n",
        $before === $mutated ? '*** BLIND - the check cannot detect a change ***'
                             : 'differs, so the snapshot can discriminate');
}

DB::table('personal_access_tokens')->where('name', 'o03b')->delete();
echo "cleaned up: probe tokens deleted\n";
