<?php
/**
 * PROBE AUDIT — the two clearances that rest on a READ and have never been
 * measured.
 *
 * Scope, per the practice: a probe that FOUND a defect proved it could reach and
 * discriminate. A probe that CLEARED something may never have arrived. So only
 * clearances are worth re-reading - and of the six in "TRIAGE OF THE SEVEN":
 *
 *   DependencyController / MyTasksController / ProjectController
 *       superseded - G-SEC-28 DELETED the fallback and probed before/after.
 *   ExcelAutomationAgentController::resolveSubInstituteId
 *       later probed behaviourally: 4 cases, both throws verified.
 *   AJAXController::tableDataRequestedTenant        <- READ ONLY, never measured
 *   jobroletaskcontroller::g2gActorId               <- READ ONLY, never measured
 *
 * The last two are what this file tests. Each clearance made a specific claim;
 * each claim is now put to a request.
 *
 * TWO PROPERTIES, both of which can fail silently:
 *   (1) can the probe REACH the code under test?
 *   (2) can its check DISTINGUISH the two outcomes?
 * Reported separately per result, because a "no leak" from a probe that never
 * arrived is not a clearance.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function head(string $t): void { printf("\n%s\n%s\n", $t, str_repeat('-', strlen($t))); }

// ── 1. AJAXController::tableDataRequestedTenant ─────────────────────────────
// THE CLAIM: "fallback, not a source. The call site reads
// tableDataTenant() ?? tableDataRequestedTenant() - proven identity wins
// outright."
head('1. AJAXController::tableDataRequestedTenant  - claim: identity wins outright');

$ctrl = new ReflectionClass(App\Http\Controllers\AJAXController::class);
$src = file_get_contents($ctrl->getFileName());
$src = preg_replace('#/\*.*?\*/#s', '', $src);
$src = preg_replace('#(?<!:)//[^\n]*#', '', $src);

// REACH: is the ?? call site the ONLY consumer? If the helper is called anywhere
// else, the clearance covers one site and the claim covers all of them.
preg_match_all('/tableDataRequestedTenant\s*\(/', $src, $calls);
preg_match_all('/tableDataTenant\s*\([^)]*\)\s*\?\?\s*\$?this?->?tableDataRequestedTenant/', $src, $guarded);
printf("  call sites of the helper        : %d\n", count($calls[0]) - 1);   // minus the declaration
printf("  guarded by `?? ` behind identity: %d\n", count($guarded[0]));

$reach = (count($calls[0]) - 1) > 0;
printf("  (1) REACH       : %s\n", $reach ? 'yes - the helper is called' : 'NO - nothing calls it, so nothing was cleared');

// DISCRIMINATE: invoke both helpers directly with a request carrying a FOREIGN
// tenant and an authenticated user, and see which value the pair yields.
$m1 = $ctrl->getMethod('tableDataTenant');       $m1->setAccessible(true);
$m2 = $ctrl->getMethod('tableDataRequestedTenant'); $m2->setAccessible(true);
$inst = $ctrl->newInstanceWithoutConstructor();

$user = DB::table('tbluser')->where('sub_institute_id', 3)->first(['id']);
$req = Illuminate\Http\Request::create('/x', 'GET', ['sub_institute_id' => 999]);

try {
    $identity = $m1->invoke($inst, $req);
    $requested = $m2->invoke($inst, $req);
    $winner = $identity ?? $requested;
    printf("  identity helper returns         : %s\n", var_export($identity, true));
    printf("  requested helper returns        : %s\n", var_export($requested, true));
    printf("  the `??` pair yields            : %s\n", var_export($winner, true));
    // The two outcomes this must tell apart: identity-wins vs request-wins.
    $canTell = $identity !== $requested;
    printf("  (2) DISCRIMINATE: %s\n", $canTell
        ? 'yes - the two helpers return different values, so the pair is testable'
        : 'NO - both return the same value here; this input cannot separate the outcomes');
    printf("  VERDICT         : %s\n", (!$reach || !$canTell)
        ? 'CANNOT BE ESTABLISHED -> back to CANDIDATE'
        : ($winner === $identity && $identity !== null
            ? 'CLEARANCE STANDS - identity wins with a foreign tenant present'
            : 'CLEARANCE DOES NOT STAND - the requested tenant reached the result'));
} catch (Throwable $e) {
    printf("  probe could not run: %s\n", substr($e->getMessage(), 0, 80));
    printf("  VERDICT         : CANNOT BE ESTABLISHED -> back to CANDIDATE\n");
}

// ── 2. jobroletaskcontroller::g2gActorId ────────────────────────────────────
// THE CLAIM: "token first, session fallback, NO request value - and it resolves
// an ACTOR, not a tenant."
head('2. jobroletaskcontroller::g2gActorId  - claim: no request value reaches it');

$c2 = new ReflectionClass(App\Http\Controllers\Api\jobroletaskcontroller::class);
$s2 = preg_replace('#(?<!:)//[^\n]*#', '', preg_replace('#/\*.*?\*/#s', '', file_get_contents($c2->getFileName())));
preg_match('/function\s+g2gActorId\s*\([^)]*\)\s*(?::\s*\??\w+\s*)?\{(.*?)\n    \}/s', $s2, $body);

$hasBody = !empty($body[1]);
printf("  (1) REACH       : %s\n", $hasBody ? 'yes - method body isolated' : 'NO - could not isolate the body');

if ($hasBody) {
    $readsRequest = (bool) preg_match('/\$request->(input|query|post|get)\s*\(|\$request->\w+\b/', $body[1]);
    // KNOWN-NEGATIVE for this check: a body that DOES read the request must be
    // detected, or "no request value" means nothing.
    $fixture = '$x = $request->input("user_id");';
    $canTell = (bool) preg_match('/\$request->(input|query|post|get)\s*\(|\$request->\w+\b/', $fixture);
    printf("  (2) DISCRIMINATE: %s\n", $canTell
        ? 'yes - the matcher detects a request read in a fixture that has one'
        : 'NO - the matcher cannot see a request read at all');
    printf("  body reads request?             : %s\n", $readsRequest ? 'YES' : 'no');
    printf("  VERDICT         : %s\n", !$canTell
        ? 'CANNOT BE ESTABLISHED -> back to CANDIDATE'
        : ($readsRequest ? 'CLEARANCE DOES NOT STAND - the body reads a request value'
                         : 'CLEARANCE STANDS - no request value in the body'));
} else {
    printf("  VERDICT         : CANNOT BE ESTABLISHED -> back to CANDIDATE\n");
}

echo "\nread only, nothing written\n";
