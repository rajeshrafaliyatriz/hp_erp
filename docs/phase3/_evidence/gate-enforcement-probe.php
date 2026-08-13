<?php
/**
 * THE FIRST ENFORCEMENT POINT, proven through the real endpoint.
 *
 * capability_coverage -> gap reporting. Four gate states, four outcomes, and the
 * two that matter most are the ones that must NOT block:
 *
 *   blocked        refuse 409, with the reason and the remedy
 *   at_risk        ALLOW - the asymmetry: falling starts a warning, it does not
 *                  switch anything off
 *   ready          allow
 *   never computed ALLOW - a gate nobody has run has made no claim, and a
 *                  feature must not be switched off by a missing measurement
 *
 * The gate row is snapshotted and restored; the run ends by proving it is back.
 * Shared remote database.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\DB;

const T = 3;
$before = DB::table('tenant_readiness_gate')->where('sub_institute_id', T)
    ->where('gate_key', 'capability_coverage')->first();

$user = DB::table('tbluser')->where('sub_institute_id', T)->first(['id']);
$token = tbluserModel::find($user->id)->createToken('gateenf')->plainTextToken;

function setGate(?string $state, $value): void
{
    DB::table('tenant_readiness_gate')->where('sub_institute_id', T)
        ->where('gate_key', 'capability_coverage')
        ->update(['state' => $state ?? 'blocked', 'value' => $value]);
}

function call($kernel, $token, $userId): array
{
    $req = Illuminate\Http\Request::create('/api/competency/gap', 'GET', [
        'token' => $token, 'type' => 'API', 'user_id' => $userId, 'syear' => '2025',
    ]);
    $req->headers->set('Accept', 'application/json');
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true) ?: []];
}

printf("gate before: state=%s value=%s\n\n", $before->state, $before->value ?? 'NULL');

$cases = [
    ['blocked', 4.10, 409, 'must REFUSE'],
    ['at_risk', 4.10, 200, 'must ALLOW - asymmetry'],
    ['ready',  60.00, 200, 'must ALLOW'],
    ['blocked', null, 200, 'must ALLOW - never computed'],
];

$fail = 0;
foreach ($cases as [$state, $value, $want, $label]) {
    setGate($state, $value);
    [$code, $body] = call($kernel, $token, $user->id);
    $ok = $code === $want;
    if (!$ok) $fail++;
    printf("  state=%-8s value=%-5s -> HTTP %-4d %-22s %s\n",
        $state, $value === null ? 'NULL' : $value, $code,
        $ok ? $label : '*** WANTED ' . $want . ' ***',
        $code === 409 ? 'gate=' . ($body['blocked_by_readiness_gate'] ?? '?') : '');

    if ($code === 409) {
        // THE REFUSAL MUST SAY WHY AND WHAT WOULD FIX IT. A 409 with an empty
        // body would be the silent-empty error wearing a status code.
        $hasWhy = !empty($body['message']) && str_contains((string) $body['message'], '%');
        $hasFix = !empty($body['remedy']);
        printf("      why    : %s\n", $body['message'] ?? '(none)');
        printf("      remedy : %s\n", $body['remedy'] ?? '(none)');
        printf("      %s\n", $hasWhy && $hasFix
            ? 'carries the reason AND the remedy'
            : '*** REFUSAL IS SILENT ABOUT ' . (!$hasWhy ? 'WHY' : 'THE FIX') . ' ***');
        if (!$hasWhy || !$hasFix) $fail++;
    }
}

// ── restore, and prove it ───────────────────────────────────────────────────
DB::table('tenant_readiness_gate')->where('sub_institute_id', T)
    ->where('gate_key', 'capability_coverage')
    ->update(['state' => $before->state, 'value' => $before->value]);
$after = DB::table('tenant_readiness_gate')->where('sub_institute_id', T)
    ->where('gate_key', 'capability_coverage')->first();
DB::table('personal_access_tokens')->where('name', 'gateenf')->delete();

printf("\nRESTORED: state=%s value=%s  %s\n", $after->state, $after->value ?? 'NULL',
    ($after->state === $before->state && (string) $after->value === (string) $before->value)
        ? 'matches the snapshot' : '*** DID NOT RESTORE ***');
printf("VERDICT: %s\n", $fail === 0 ? 'PASS' : '*** ' . $fail . ' FAILURE(S) ***');
