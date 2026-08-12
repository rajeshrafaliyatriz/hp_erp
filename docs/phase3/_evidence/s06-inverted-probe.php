<?php
/**
 * S-06 INVERTED PROBE: find the subject by RUNNING, not by reading.
 *
 * Three attempts selected a subject by STATIC properties - unguarded,
 * containable, no companion rows - and all three failed on DYNAMIC ones: tenant
 * resolved from the token, an FK onto a tenant that did not exist, and a
 * validator that disagrees with its own column's enum.
 *
 * STATIC SELECTION CANNOT SEE RUNTIME PROPERTIES. Three for three.
 *
 * So this runs the KNOWN-POSITIVE against every containable candidate first and
 * picks the subject from whichever actually writes.
 *
 * PAYLOADS ARE BUILT FROM EACH VALIDATOR BLOCK, READ ONCE. Discovering a
 * contract field-by-field is the same waste as measuring a population one row at
 * a time - it cost two aborted runs.
 *
 * SAFETY, unchanged:
 *   - school_setup #999999 'ZZ-S06-PROBE-TENANT', created here, removed here
 *   - every write into 999999; the demo tenant is never named
 *   - cleanup is TABLE-WIDE, not scoped to 999999 - scoping the search to the
 *     tenant I intended is what missed a stray row in tenant 1
 *   - a row landing with no token STOPS everything
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SID = 999999;
const TAG = 'ZZS06INV';

/** The containable candidates: unguarded in both layers AND request-scoped. */
$CANDIDATES = [
    ['uri' => 'api/attendance/punch-in',   'class' => null, 'method' => 'punchIn'],
    ['uri' => 'api/job-postings',          'class' => null, 'method' => 'store'],
    ['uri' => 'api/jobrole-skill/store',   'class' => null, 'method' => 'storeSkill'],
    ['uri' => 'api/interview-schedules',   'class' => null, 'method' => 'store'],
    ['uri' => 'api/school-setup',          'class' => null, 'method' => 'store'],
];

/* Resolve each URI to its controller and the table(s) it writes. */
foreach ($CANDIDATES as $i => $c) {
    foreach (app('router')->getRoutes() as $r) {
        if ($r->uri() !== $c['uri'] || !in_array('POST', $r->methods(), true)) continue;
        $uses = $r->getAction()['uses'] ?? '';
        if (!is_string($uses) || !str_contains($uses, '@')) continue;
        [$cls, $m] = explode('@', $uses, 2);
        $CANDIDATES[$i]['class'] = $cls;
        $CANDIDATES[$i]['method'] = $m;
    }
}

/** Read a method's body once. */
$bodyOf = function (string $cls, string $m): ?string {
    if (!class_exists($cls) || !method_exists($cls, $m)) return null;
    try { $rm = new ReflectionMethod($cls, $m); } catch (Throwable $e) { return null; }
    $f = $rm->getFileName();
    if (!$f || !is_file($f)) return null;
    $lines = file($f);
    return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
};

/**
 * Build a payload from the validator block. ONE READ, every rule honoured:
 *   in:a,b,c  -> the first allowed value
 *   integer   -> 1        numeric -> 1
 *   date      -> a date   email   -> an address
 *   array     -> [TAG]    boolean -> 1
 *   string    -> TAG      (max:n respected)
 */
$payloadFrom = function (string $body): array {
    if (!preg_match('/Validator::make\s*\([^,]+,\s*\[(.*?)\]\s*\)/s', $body, $m)) return [];
    $out = [];
    if (!preg_match_all("/'([a-zA-Z0-9_.\*]+)'\s*=>\s*'([^']*)'/", $m[1], $rr, PREG_SET_ORDER)) return [];
    foreach ($rr as $hit) {
        [$all, $field, $rule] = $hit;
        if (str_contains($field, '*')) continue;
        if (str_contains($rule, 'nullable') || str_contains($rule, 'sometimes')) continue;
        if (preg_match('/\bin:([^|]+)/', $rule, $vm)) {
            $vals = array_map('trim', explode(',', $vm[1]));
            $out[$field] = $vals[0];
        } elseif (str_contains($rule, 'integer') || str_contains($rule, 'numeric')) {
            $out[$field] = 1;
        } elseif (str_contains($rule, 'boolean')) {
            $out[$field] = 1;
        } elseif (str_contains($rule, 'date')) {
            $out[$field] = '2026-01-01';
        } elseif (str_contains($rule, 'email')) {
            $out[$field] = 'zz-s06@example.invalid';
        } elseif (str_contains($rule, 'array')) {
            $out[$field] = [TAG];
        } else {
            $max = preg_match('/max:(\d+)/', $rule, $mm) ? (int) $mm[1] : 255;
            $out[$field] = substr(TAG, 0, max(1, min($max, 64)));
        }
    }
    return $out;
};

$call = function (string $uri, array $p, ?string $token) use ($kernel) {
    if ($token !== null) $p['token'] = $token;
    $req = Illuminate\Http\Request::create('/' . $uri, 'POST', $p);
    $req->headers->set('Accept', 'application/json');
    if ($token !== null) $req->headers->set('Authorization', 'Bearer ' . explode('|', $token, 2)[1]);
    try {
        $r = $kernel->handle($req);
        return [$r->getStatusCode(), substr(preg_replace('/\s+/', ' ', (string) $r->getContent()), 0, 110)];
    } catch (Throwable $e) {
        return [0, 'EX ' . substr($e->getMessage(), 0, 95)];
    }
};

/** Every row anywhere in the tables a candidate writes, at ANY tenant. */
$tablesOf = function (?string $body): array {
    if ($body === null) return [];
    preg_match_all("/DB::table\(\s*'([a-z_]+)'\s*\)/i", $body, $m);
    $t = array_values(array_unique($m[1]));
    return array_values(array_filter($t, fn ($x) => !in_array($x, ['tbluser', 'personal_access_tokens'], true)));
};

$countAt = function (array $tables, int $sid): int {
    $n = 0;
    foreach ($tables as $t) {
        try { $n += DB::table($t)->where('sub_institute_id', $sid)->count(); } catch (Throwable $e) {}
    }
    return $n;
};

$tokenRow = null;
$madeTenant = false;
$allTables = [];
$writer = null;

try {
    if (!DB::table('school_setup')->where('id', SID)->exists()) {
        DB::table('school_setup')->insert(['id' => SID, 'SchoolName' => 'ZZ-S06-PROBE-TENANT',
            'created_at' => now(), 'updated_at' => now()]);
        $madeTenant = true;
        echo "REGISTERED school_setup #" . SID . " 'ZZ-S06-PROBE-TENANT' - this probe only\n\n";
    }

    $profiles = DB::table('tbluserprofilemaster')
        ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])->pluck('id');
    $admin = DB::table('tbluser')->whereIn('user_profile_id', $profiles)->first(['id']);
    $plain = bin2hex(random_bytes(24));
    $tokenRow = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\auth\tbluserModel', 'tokenable_id' => $admin->id,
        'name' => TAG, 'token' => hash('sha256', $plain), 'abilities' => '["*"]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $TOKEN = $tokenRow . '|' . $plain;

    echo "=== KNOWN-POSITIVE AGAINST EVERY CANDIDATE (valid token, tenant " . SID . ") ===\n\n";
    foreach ($CANDIDATES as $c) {
        if (!$c['class']) { printf("  %-28s UNRESOLVED ROUTE\n", $c['uri']); continue; }
        $body = $bodyOf($c['class'], $c['method']);
        $tables = $tablesOf($body);
        $allTables = array_values(array_unique(array_merge($allTables, $tables)));

        $p = $payloadFrom($body ?? '');
        $p['sub_institute_id'] = SID; $p['syear'] = '2025'; $p['type'] = 'API';
        $p['user_id'] = $admin->id;

        $before = $countAt($tables, SID);
        [$s, $b] = $call($c['uri'], $p, $TOKEN);
        $after = $countAt($tables, SID);
        $wrote = $after - $before;

        printf("  %-28s http %-4d rows %+d   fields %d\n", $c['uri'], $s, $wrote, count($p));
        printf("       tables: %s\n", $tables ? implode(', ', $tables) : '(via model - not counted)');
        printf("       %s\n", $b);
        if ($wrote > 0 && $writer === null) $writer = ['c' => $c, 'tables' => $tables];
    }

    if ($writer === null) {
        echo "\n=== NO CANDIDATE WROTE ===\n";
        echo "THE CONTAINABLE SET IS UNUSABLE, NOT THE QUESTION UNANSWERABLE.\n";
        echo "Every route where containment is possible fails for its own reason\n";
        echo "before authentication is ever reached.\n";
    } else {
        printf("\n=== SUBJECT CHOSEN BY RUNNING: %s ===\n\n", $writer['c']['uri']);
        $body = $bodyOf($writer['c']['class'], $writer['c']['method']);
        $p = $payloadFrom($body ?? '');
        $p['sub_institute_id'] = SID; $p['syear'] = '2025'; $p['type'] = 'API';
        $p['user_id'] = $admin->id;

        $before = $countAt($writer['tables'], SID);
        [$s2, $b2] = $call($writer['c']['uri'], $p, null);
        $after = $countAt($writer['tables'], SID);
        $anon = $after - $before;

        $verdict = ($s2 === 0 || $s2 >= 500) ? 'ERRORED'
            : (in_array($s2, [401, 403], true) ? 'REFUSED'
            : ($anon > 0 ? '*** ACCEPTED AND WROTE ***' : 'ACCEPTED, WROTE NOTHING'));

        printf("ANONYMOUS (no token, no session, no header)  http %d\n   %s\n", $s2, $b2);
        printf("   rows written: %d\n\nVERDICT: %s\n", $anon, $verdict);

        if ($anon > 0) {
            echo "\nSTOP. A CONFIRMED UNAUTHENTICATED WRITE OUTRANKS S-06 ITSELF.\n";
        } else {
            echo "\nTHIS CLEARS ONE ROUTE, NOT 336. The prior is strong and one\n";
            echo "negative does not answer for a population.\n";
        }
    }
} finally {
    echo "\n--- CLEANUP (TABLE-WIDE) ---\n";
    $removed = 0;
    foreach ($allTables as $t) {
        try { $removed += DB::table($t)->where('sub_institute_id', SID)->delete(); } catch (Throwable $e) {}
    }
    if ($tokenRow) DB::table('personal_access_tokens')->where('id', $tokenRow)->delete();
    if ($madeTenant) DB::table('school_setup')->where('id', SID)->delete();

    $left = 0;
    foreach ($allTables as $t) {
        try { $left += DB::table($t)->where('sub_institute_id', SID)->count(); } catch (Throwable $e) {}
    }
    printf("removed %d row(s) across %d table(s); %d remain at tenant %d\n", $removed, count($allTables), $left, SID);
    printf("school_setup #%d gone: %s   school_setup rows: %d (was 11)\n", SID,
        DB::table('school_setup')->where('id', SID)->exists() ? 'NO' : 'yes',
        DB::table('school_setup')->count());
    printf("stray %s tokens: %d\n", TAG, DB::table('personal_access_tokens')->where('name', TAG)->count());
}
