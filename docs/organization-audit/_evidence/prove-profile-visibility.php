<?php
/**
 * EVIDENCE — a colleague does not automatically get your mobile number.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * An HR product holds a personal mobile number, a date of birth and a home
 * address. `EmployeeDirectoryController::LIST_COLUMNS` carries `u.mobile`,
 * `u.city` and `u.state`, and the single-employee read adds `birthdate`,
 * `address`, `address_2` and `pincode` - so before this work, every employee who
 * could open the directory could read all of it about everybody. That was never
 * a setting anybody chose; it was the absence of one.
 *
 * ── WHY THIS READS THE API AND NOT THE SCREEN ───────────────────────────────
 *
 * A visibility control that only hides fields in React is not a privacy feature,
 * it is a privacy-shaped decoration: the data is still one request away. So every
 * assertion here inspects the JSON the server sent, which is the only place the
 * question can honestly be answered.
 *
 * ── AND WHY HR MUST STILL SEE EVERYTHING ────────────────────────────────────
 *
 * They own the record. They typed the address in, payroll needs it, and the edit
 * form has to round-trip what it loaded or saving blanks the field. A check that
 * only proved "nobody can see it" would be passing while the directory broke, so
 * the HR case is asserted positively.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-profile-visibility.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body)
        );
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    $make = function (string $roleKey, array $extra = []) use ($db, $tenant) {
        $profile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)->where('role_key', $roleKey)->first(['id']);

        if (!$profile) {
            return null;
        }

        $id = $db->table('tbluser')->insertGetId(array_merge([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Vis',
            'last_name' => $roleKey,
            'email' => 'vis.' . $roleKey . '.' . uniqid() . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('VisCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return [
            'id' => $id,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('vis')->plainTextToken,
        ];
    };

    /*
     * THE OWNER, with details worth protecting and a department.
     *
     * Distinct sentinel values rather than realistic ones, so a field that leaks
     * is unmistakable in the output instead of blending into other test data.
     */
    $owner = $make('employee', [
        'mobile' => '9000000001',
        'birthdate' => '1990-01-01',
        'address' => 'Sentinel Street 1',
        'address_2' => 'Sentinel Flat 2',
        'city' => 'SentinelCity',
        'state' => 'SentinelState',
        'pincode' => '360001',
        'department_id' => 117,
    ]);

    if (!$owner) {
        echo "no employee profile on tenant $tenant - cannot run\n";
        DB::rollBack();
        return;
    }

    $sameDept = $make('employee', ['department_id' => 117]);
    $otherDept = $make('employee', ['department_id' => 999999]);
    $hr = $make('hr_manager', ['department_id' => 999999]);

    $ownerRow = function ($body) use ($owner) {
        $data = json_decode($body, true)['data'] ?? [];

        foreach ($data as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $owner['id']) {
                return $row;
            }
        }

        return null;
    };

    /** Which sentinel fields survived into the response. */
    $visibleFields = function (?array $row) {
        if (!$row) {
            return null;
        }

        $seen = [];

        foreach (['mobile' => '9000000001', 'city' => 'SentinelCity', 'state' => 'SentinelState'] as $f => $sentinel) {
            if (($row[$f] ?? null) === $sentinel) {
                $seen[] = $f;
            }
        }

        return $seen;
    };

    // ── 1. THE DEFAULT IS UNCHANGED ─────────────────────────────────────────
    //
    // Nobody has chosen a setting yet, and existing directories must not change
    // the day this deploys. Asserted explicitly so "everything is private now"
    // cannot pass as a success.
    echo "══ 1. with nothing chosen, the directory is exactly as it was ══\n";

    $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $sameDept['token'])->getContent()));

    count($seen ?? []) === 3
        ? $ok('a colleague sees mobile, city and state while the default stands')
        : $bad('the default changed - existing directories would break on deploy: ' . json_encode($seen));

    // ── 2. PRIVATE MEANS PRIVATE ────────────────────────────────────────────
    echo "\n══ 2. set to 'just me', a colleague gets nothing ══\n";

    $call('PUT', '/api/account/preferences', $owner['token'], [
        'visible_mobile' => 'private',
        'visible_address' => 'private',
    ]);

    foreach ([
        'same department' => $sameDept,
        'another department' => $otherDept,
    ] as $label => $viewer) {
        $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $viewer['token'])->getContent()));

        empty($seen)
            ? $ok("a colleague in $label sees none of them")
            : $bad("a colleague in $label still sees: " . implode(', ', $seen));
    }

    // The owner themselves must never be redacted.
    $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $owner['token'])->getContent()));
    count($seen ?? []) === 3
        ? $ok('the owner still sees their own details')
        : $bad('the owner was redacted from their own record: ' . json_encode($seen));

    // And HR, who maintains the record.
    if ($hr) {
        $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $hr['token'])->getContent()));
        count($seen ?? []) === 3
            ? $ok('HR still sees everything, so the edit form round-trips')
            : $bad('HR was redacted, which would blank the field on their next save: ' . json_encode($seen));
    }

    // ── 3. DEPARTMENT MEANS DEPARTMENT ──────────────────────────────────────
    echo "\n══ 3. set to 'my department', only that department sees it ══\n";

    $call('PUT', '/api/account/preferences', $owner['token'], [
        'visible_mobile' => 'department',
        'visible_address' => 'department',
    ]);

    $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $sameDept['token'])->getContent()));
    count($seen ?? []) === 3
        ? $ok('the same department sees them')
        : $bad('the same department was refused: ' . json_encode($seen));

    $seen = $visibleFields($ownerRow($call('GET', '/api/employees-management', $otherDept['token'])->getContent()));
    empty($seen)
        ? $ok('another department does not')
        : $bad('another department still sees: ' . implode(', ', $seen));

    // ── 4. AN UNRECOGNISED VALUE MUST NOT MEAN "SHOW IT" ────────────────────
    echo "\n══ 4. a bad value is refused, and does not open the field up ══\n";

    $code = $call('PUT', '/api/account/preferences', $owner['token'], [
        'visible_mobile' => 'yes-please',
    ])->getStatusCode();

    $stored = $db->table('user_preferences')
        ->where('user_id', $owner['id'])->where('device_id', '')
        ->where('pref_key', 'visible_mobile')->value('pref_value');

    $code === 422
        ? $ok('an unrecognised visibility is rejected with 422')
        : $bad("an unrecognised visibility returned HTTP $code instead of 422");

    $stored === 'department'
        ? $ok('and the previous setting is untouched')
        : $bad('the stored value became ' . var_export($stored, true));
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, token or preference kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
