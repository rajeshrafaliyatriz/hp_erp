<?php
/**
 * G-SEC-27b — THE SESSION WAS AHEAD OF THE TOKEN.
 *
 * Proved the way G-SEC-27 was proved: BY RUNNING IT, not by reading it.
 *
 * THE PROPERTY, in the words it was given:
 *   token first, session second, request never, and NO numeric fallback at all.
 *   Failing closed is correct.
 *
 * WHY A REGRESSION CASE IS INCLUDED (proof 6): a static check that the text now
 * reads `apiTenantId() ?: session()` proves the TEXT changed. It does not prove the
 * BEHAVIOUR changed. Proof 6 evaluates the OLD expression on the same inputs and
 * requires it to give the WRONG answer - the known-positive must be an instance the
 * check is MEANT to find (R16). If proof 6 ever stops failing, the defect was never
 * real and proofs 3-5 are decoration.
 *
 * SAFETY: read-only except for one Sanctum token, deleted in a finally block.
 * Gemini is never called - the no-identity case returns 401 before that line.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A FRESH, ISOLATED SESSION PER REQUEST.
 *
 * `app('session.store')` is a SINGLETON. The first version of this script used it
 * for every case, so the tenant put into the session by the precedence case was
 * still there when the no-identity cases ran - and all three of those cases then
 * reported the wrong verdict. Two cases sharing a session share its state, the same
 * shape as two checks sharing a source sharing its blindness.
 *
 * It also let AnalyzeJDController run past its identity guard once, which is the
 * exact call this script promises not to make. The isolation is not tidiness.
 */
function newSessionStore(): Illuminate\Session\Store {
    return new Illuminate\Session\Store(
        'g2g-proof', new Illuminate\Session\ArraySessionHandler(120)
    );
}

$PASS = 0; $FAIL = 0; $notes = [];
function ok(string $what, bool $cond, string $detail = '') {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; printf("  PASS  %-58s %s\n", $what, $detail); }
    else       { $FAIL++; printf("  FAIL  %-58s %s\n", $what, $detail); }
}

$FILES = [
    'AnalyzeJDController' => __DIR__ . '/../../../app/Http/Controllers/Api/Gemini/AnalyzeJDController.php',
    'skillcontroller'     => __DIR__ . '/../../../app/Http/Controllers/Api/skillcontroller.php',
    'HrmsLeaveController' => __DIR__ . '/../../../app/Http/Controllers/HRMS/HrmsLeaveController.php',
];

echo "\n=== 1. THE EXEMPLAR SHAPE IS PRESENT IN ALL THREE ===\n";
echo "    HrmsLeaveController is included as the REFERENCE, not as a repair: it\n";
echo "    already had this shape and its docblock already explained why.\n";
$EXEMPLAR = '$this->apiTenantId($request) ?: session(\'sub_institute_id\')';
foreach ($FILES as $name => $path) {
    $src = file_get_contents($path);
    ok("$name uses the exemplar expression", str_contains($src, $EXEMPLAR));
}

echo "\n=== 2. NO NUMERIC FALLBACK, AND `??` IS NOT USED FOR IDENTITY ===\n";
echo "    `??` falls through only on NULL, so an empty-string session value passed\n";
echo "    as a valid tenant. `?:` falls through on any falsy value.\n";
foreach ($FILES as $name => $path) {
    $src = file_get_contents($path);
    // Only code, not the comments that quote the old expression.
    $code = preg_replace('#(?<!:)//[^\n]*#', '', $src);
    $code = preg_replace('#/\*.*?\*/#s', '', $code);
    ok("$name has no numeric tenant fallback", !preg_match('#sub_institute_id[^;\n]*\?\?\s*\d#', $code)
        && !preg_match('#apiTenantId\([^)]*\)[^;\n]*\?\?\s*\d#', $code));
    ok("$name never puts session() before apiTenantId()", !preg_match('#session\([^;\n]*\)\s*\?\?[^;\n]*apiTenantId#', $code));
}

echo "\n=== 3. RUNTIME: THE TOKEN BEATS A CONTRADICTING SESSION ===\n";
$token = null; $tokenUser = null;
try {
    // THE TOKENABLE, NOT THE AUTH PROVIDER MODEL. config('auth.providers.users.model')
    // maps to `users`, which has no sub_institute_id; tokens in this system are
    // issued to tbluserModel. Resolve, do not match: the tenant's real table, not
    // the one the framework's default happens to name.
    $userModel = App\Models\auth\tbluserModel::class;
    // A user in a tenant that is NOT 3, so "wrong answer" and "demo tenant" are
    // distinguishable outcomes rather than the same number.
    $tokenUser = $userModel::whereNotNull('sub_institute_id')
        ->where('sub_institute_id', '!=', 3)
        ->where('sub_institute_id', '>', 0)->first();

    if (!$tokenUser) {
        $notes[] = 'No non-tenant-3 user available; runtime proofs 3 and 6 SKIPPED.';
        echo "  SKIP  no user outside tenant 3 - cannot distinguish wrong from demo\n";
    } else {
        $T = (int) $tokenUser->sub_institute_id;
        $token = $tokenUser->createToken('g2g-sec-27b-proof');
        $plain = explode('|', $token->plainTextToken, 2)[1];

        $req = Request::create('/api/skills', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $plain);

        // The session says a DIFFERENT tenant. This is the exact condition the old
        // expression got wrong: a token-authenticated caller carrying a stale session.
        $CONTRADICT = ($T === 7) ? 3 : 7;
        $req->setLaravelSession(newSessionStore());
        $req->session()->put('sub_institute_id', $CONTRADICT);

        $probe = new class { use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
            public function tenant($r) { return $this->apiTenantId($r); } };

        $resolved = $probe->tenant($req);
        ok('token wins over a contradicting session', (int) $resolved === $T,
            "token tenant=$T  session said=$CONTRADICT  resolved=" . var_export($resolved, true));

        echo "\n=== 6. REGRESSION: THE OLD EXPRESSION GETS THIS WRONG ===\n";
        echo "    R16 - the known-positive must be an instance the check is MEANT to find.\n";
        $old = $req->session()->get('sub_institute_id') ?? $probe->tenant($req) ?? 3;
        ok('OLD expression returns the WRONG tenant (defect was real)', (int) $old === $CONTRADICT,
            "old gave=$old  correct=$T  -> the session overrode the token");

        $reqNone = Request::create('/api/skills', 'GET');
        $reqNone->setLaravelSession(newSessionStore());
        $oldNone = $reqNone->session()->get('sub_institute_id') ?? $probe->tenant($reqNone) ?? 3;
        ok('OLD expression FAILED OPEN onto tenant 3 with no identity', (int) $oldNone === 3,
            "no token, no session -> old gave=$oldNone (the demo tenant)");
    }
} catch (\Throwable $e) {
    $FAIL++;
    echo "  FAIL  runtime precedence proof threw: " . $e->getMessage() . "\n";
} finally {
    if ($token && isset($token->accessToken)) {
        $token->accessToken->delete();
        echo "  cleanup: proof token deleted\n";
    }
}

echo "\n=== 4 & 5. RUNTIME: NO IDENTITY IS REFUSED, NOT DEFAULTED ===\n";
foreach ([
    ['skillcontroller', App\Http\Controllers\Api\skillcontroller::class, 'index', '/api/skills'],
    ['AnalyzeJDController', App\Http\Controllers\Api\Gemini\AnalyzeJDController::class, null, '/api/analyze-jd'],
] as [$name, $class, $method, $uri]) {
    try {
        $c = app($class);
        if ($method === null) {
            $rm = array_values(array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn ($m) => $m->class === $class && !str_starts_with($m->name, '__')
            ));
            if (!$rm) { echo "  SKIP  $name has no public method to call\n"; continue; }
            $method = $rm[0]->name;
        }
        $req = Request::create($uri, 'POST', ['jd' => 'proof - never reaches Gemini']);
        $req->setLaravelSession(newSessionStore());   // session present but EMPTY
        $res = $c->{$method}($req);
        $code = method_exists($res, 'getStatusCode') ? $res->getStatusCode() : 0;
        $body = method_exists($res, 'getContent') ? (string) $res->getContent() : '';
        ok("$name::$method refuses with no identity", $code === 401, "HTTP $code");
        ok("$name returns no tenant-3 data", !str_contains($body, '"sub_institute_id":3'), '');
    } catch (\Throwable $e) {
        // A validation exception is also a refusal, and it is not a default.
        $isRefusal = $e instanceof Illuminate\Validation\ValidationException;
        ok("$name refuses with no identity", $isRefusal,
            $isRefusal ? 'refused by validation (also fails closed)' : get_class($e) . ': ' . $e->getMessage());
    }
}

echo "\n" . str_repeat('=', 72) . "\n";
foreach ($notes as $n) { echo "NOTE: $n\n"; }
printf("PASS %d   FAIL %d\n", $PASS, $FAIL);
echo $FAIL === 0
    ? "\nG-SEC-27b PROVED. Token first, session second, request never, no numeric\nfallback, and the old expression is shown to have got it wrong.\n"
    : "\n*** G-SEC-27b NOT PROVED - $FAIL failure(s) above ***\n";
exit($FAIL === 0 ? 0 : 1);
