<?php
/**
 * EVIDENCE — "require two-step verification" is a policy, not a preference.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE TWO WAYS THIS FEATURE COULD BE WORTHLESS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. IT IS ONLY A UI HINT. This codebase has the cautionary example already:
 *    `can_view` / `can_edit` were honoured by `MenuMiddleware`, which returns
 *    early on `type=API` and never aborts - so thirty profiles could read the
 *    organisation profile and the server would have let them WRITE it. A policy
 *    that only a React component respects is a suggestion.
 *
 * 2. IT LOCKS THE ORGANISATION OUT OF ITSELF. Enrolment requires being signed in.
 *    Refuse the sign-in and an administrator who switches the policy on and signs
 *    out has made the tenant unreachable - for everybody, including whoever would
 *    turn the policy back off. There is no recovery that is not a database edit.
 *
 * Both are asserted, and they pull in OPPOSITE directions: section 2 proves the
 * product is genuinely refused, section 3 proves the way out is genuinely open.
 * Either one alone would pass on a broken implementation.
 *
 * ── AND THE DEFAULT IS THE THIRD THING THAT MATTERS ────────────────────────
 *
 * This middleware runs on every API and web request for eleven live tenants. Its
 * default must be `off`, and its failure direction must also be `off` - a policy
 * nobody can parse must not be the reason an organisation cannot work. Section 1.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-two-factor-policy.php';"
 */

use App\Services\Account\TwoFactor;
use App\Services\Organization\TenantSettings;
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

$tf = new TwoFactor();
$settings = app(TenantSettings::class);

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body)
        );
        $request->headers->set('Accept', 'application/json');

        $response = $kernel->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
        ];
    };

    /*
     * The middleware memoises its verdict per (tenant, user) so it is not two extra
     * queries on every request. That memo is a STATIC, and this script changes the
     * policy between sections - so without clearing it, section 3 would be answered
     * by section 2's cached verdict and would pass no matter what the code did.
     *
     * Cleared by reflection rather than worked around, because a check that cannot
     * see its own cache is a check that measures the cache.
     */
    $forget = function () {
        $property = new ReflectionProperty(\App\Http\Middleware\RequireTwoFactorEnrolment::class, 'decided');
        $property->setAccessible(true);
        $property->setValue([]);
    };

    $makeUser = function (string $roleKey, string $label) use ($db, $tenant) {
        $profile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)->where('role_key', $roleKey)->first(['id']);

        if (!$profile) {
            return null;
        }

        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profile->id,
            'sub_institute_id' => $tenant,
            'first_name' => 'Policy',
            'last_name' => $label,
            'email' => 'policy.' . $label . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('PolicyCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'token' => \App\Models\auth\tbluserModel::find($id)->createToken('Policy laptop')->plainTextToken,
        ];
    };

    $employee = $makeUser('employee', 'employee');
    $admin = $makeUser('administrator', 'admin');

    if (!$employee || !$admin) {
        $bad('tenant ' . $tenant . ' has no employee or administrator profile - nothing below can run');
        DB::rollBack();
        printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
        return;
    }

    /*
     * A route that is NOT on the allow-list and that this person can otherwise
     * reach. `/account/activity` is their own security history - no role gate on it
     * at all, so a refusal there can only have come from the policy.
     */
    $product = '/api/account/activity';

    // ── 1. OFF BY DEFAULT, AND OFF WHEN UNPARSEABLE ─────────────────────────
    echo "══ 1. the default, and the failure direction ══\n";

    TenantSettings::DEFAULTS['security.require_two_factor'] === 'off'
        ? $ok('the shipped default is off, so no live tenant changes behaviour on deploy')
        : $bad('the default is ' . TenantSettings::DEFAULTS['security.require_two_factor'] . ' - eleven tenants would change on deploy');

    $forget();
    $before = $call('GET', $product, $employee['token']);

    $before['status'] === 200
        ? $ok('with it off, somebody who has not enrolled reaches the product')
        : $bad('the product is refused with the policy off: HTTP ' . $before['status'] . ' ' . json_encode($before['body']));

    /*
     * A value nothing recognises, written straight to the table - the endpoint's
     * validator would refuse it, which is exactly the point: this asserts what
     * happens when a row is edited by hand or written by an older version.
     */
    $settings->save($tenant, ['security.require_two_factor' => 'Administrators']);
    $forget();
    $nonsense = $call('GET', $product, $admin['token']);

    $nonsense['status'] === 200
        ? $ok('and an unrecognised value behaves as off, rather than locking everybody out')
        : $bad('an unparseable policy blocked the product: HTTP ' . $nonsense['status']);

    // ── 2. WITH IT ON, THE PRODUCT IS ACTUALLY REFUSED ──────────────────────
    echo "\n══ 2. enforced on the server, not in the interface ══\n";

    $settings->save($tenant, ['security.require_two_factor' => 'everyone']);
    $forget();
    $blocked = $call('GET', $product, $employee['token']);

    $blocked['status'] === 403
        ? $ok('somebody who has not enrolled is refused with 403')
        : $bad('the product was served anyway: HTTP ' . $blocked['status'] . ' ' . json_encode($blocked['body']));

    ($blocked['body']['two_factor_setup_required'] ?? false) === true
        ? $ok('carrying two_factor_setup_required, so a client can route rather than guess')
        : $bad('no machine-readable reason: ' . json_encode($blocked['body']));

    /*
     * 403 and NOT 401. A 401 tells every client in this product that the credential
     * is bad and sends somebody back to the sign-in screen - which is the one place
     * that cannot fix this, because enrolling requires being signed in.
     */
    $blocked['status'] !== 401
        ? $ok('and not a 401, which would send them to the one screen that cannot help')
        : $bad('401 - every client will discard a perfectly valid token');

    // ── 3. AND THE WAY OUT IS OPEN ──────────────────────────────────────────
    //
    // The assertion that stops this being a lockout, and it pulls AGAINST section 2.
    // Both passing is the only proof the allow-list is neither too wide nor too narrow.
    echo "\n══ 3. nobody is locked out - enrolment is still reachable ══\n";

    $me = $call('GET', '/api/account/me', $employee['token']);

    $me['status'] === 200
        ? $ok('/account/me still answers, so the product can render and explain itself')
        : $bad('the account payload is refused too - the screen cannot even say why: HTTP ' . $me['status']);

    ($me['body']['data']['two_factor']['required'] ?? false) === true
        ? $ok('and it reports required=true, which is the sentence the screen shows')
        : $bad('the payload does not say the person is obliged: ' . json_encode($me['body']['data']['two_factor'] ?? null));

    $start = $call('POST', '/api/account/2fa/start', $employee['token']);

    $start['status'] === 200
        ? $ok('and enrolment can be started')
        : $bad('enrolment itself is blocked by the gate - this IS a lockout: HTTP ' . $start['status']);

    $secret = $start['body']['data']['secret'] ?? null;

    $confirm = $call('POST', '/api/account/2fa/confirm', $employee['token'], [
        'code' => $secret ? $tf->codeAt($secret, time()) : '000000',
    ]);

    $confirm['status'] === 200
        ? $ok('and confirmed')
        : $bad('confirmation is blocked by the gate: HTTP ' . $confirm['status']);

    $forget();
    $after = $call('GET', $product, $employee['token']);

    $after['status'] === 200
        ? $ok('after which the product works again - no administrator needed')
        : $bad('still refused after enrolling: HTTP ' . $after['status'] . ' ' . json_encode($after['body']));

    // ── 4. THE POLICY CANNOT BE SHRUGGED OFF BY THE PERSON IT BINDS ─────────
    echo "\n══ 4. and it cannot be turned off by the person it applies to ══\n";

    $disable = $call('POST', '/api/account/2fa/disable', $employee['token'], [
        'current_password' => 'PolicyCheck123',
    ]);

    $disable['status'] === 409
        ? $ok('turning it off is refused with 409 while the organisation requires it')
        : $bad('it was turned off with HTTP ' . $disable['status'] . ' - the policy is defeated by its own subject');

    $tf->isEnabled($employee['id'])
        ? $ok('and it is still on')
        : $bad('two-step verification was switched off despite the policy');

    // ── 5. "administrators" MEANS ADMINISTRATORS ────────────────────────────
    //
    // The setting most tenants actually want, and the one that goes silently wrong
    // if the role is resolved by profile NAME: ten live tenants have a legacy
    // profile called "Admin" rather than one with role_key = 'administrator'.
    echo "\n══ 5. the narrower policy covers administrators and nobody else ══\n";

    $settings->save($tenant, ['security.require_two_factor' => 'administrators']);
    $forget();

    $fresh = $makeUser('employee', 'notcovered');

    if (!$fresh) {
        $bad('could not make a second employee');
    } else {
        $call('GET', $product, $fresh['token'])['status'] === 200
            ? $ok('an employee who has not enrolled is NOT required to, and works normally')
            : $bad('an employee was blocked by a policy that names administrators only');
    }

    $adminBlocked = $call('GET', $product, $admin['token']);

    $adminBlocked['status'] === 403
        ? $ok('and an administrator who has not enrolled IS refused')
        : $bad('an administrator was not covered: HTTP ' . $adminBlocked['status']
            . ' - RoleKey resolved ' . var_export(\App\Support\RoleKey::forUserId($admin['id']), true));

    // ── 6. ONE ORGANISATION'S DECISION, AND ONLY THEIRS ─────────────────────
    echo "\n══ 6. one organisation's policy, not the platform's ══\n";

    $otherTenant = (int) $db->table('school_setup')->where('id', '!=', $tenant)->value('id');

    if ($otherTenant <= 0) {
        $bad('only one tenant on this database - cannot check isolation');
    } else {
        $otherProfile = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $otherTenant)->whereNotNull('role_key')->first(['id']);

        if (!$otherProfile) {
            $bad("tenant $otherTenant has no profile with a role_key");
        } else {
            $otherId = $db->table('tbluser')->insertGetId([
                'user_profile_id' => $otherProfile->id,
                'sub_institute_id' => $otherTenant,
                'first_name' => 'Other',
                'last_name' => 'Tenant',
                'email' => 'policy.other@example.test',
                'password' => \Illuminate\Support\Facades\Hash::make('PolicyCheck123'),
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherToken = \App\Models\auth\tbluserModel::find($otherId)
                ->createToken('Policy laptop')->plainTextToken;

            $settings->save($tenant, ['security.require_two_factor' => 'everyone']);
            $forget();

            $call('GET', $product, $otherToken)['status'] === 200
                ? $ok("tenant $tenant requiring it does not affect tenant $otherTenant")
                : $bad("tenant $otherTenant was blocked by tenant $tenant's policy - the setting leaks across organisations");
        }
    }
} finally {
    // The static outlives the transaction, so it is cleared here too - otherwise a
    // later script in the same process would inherit this one's verdicts.
    $property = new ReflectionProperty(\App\Http\Middleware\RequireTwoFactorEnrolment::class, 'decided');
    $property->setAccessible(true);
    $property->setValue([]);

    DB::rollBack();
    echo "\n(rolled back - no account, policy row or enrolment kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
