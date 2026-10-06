<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Account\TwoFactor;
use App\Services\Events\EventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ENROLLING IN, AND LEAVING, TWO-STEP VERIFICATION.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE SHAPE OF THIS FLOW IS THE SECURITY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Four endpoints, and the order matters more than any one of them:
 *
 *   start      mints a secret that does NOT yet protect the account
 *   confirm    proves the person's app holds it, THEN turns it on and hands back
 *              ten single-use recovery codes
 *   recovery   re-issues those codes, behind the password
 *   destroy    turns it off, behind the password
 *
 * Enabling on `start` alone would lock somebody out of their own account by
 * closing the tab: the secret would be live and their authenticator would never
 * have received it. `confirmed_at` is what separates "enrolling" from "enrolled",
 * and it is only ever written after a valid code.
 *
 * ── THE PASSWORD IS REQUIRED TO WEAKEN, NOT TO STRENGTHEN ───────────────────
 *
 * `start` and `confirm` need no password: somebody already holds a valid session,
 * and asking again to ADD protection only discourages them from adding it.
 * `destroy` and `recovery` do need it, because both hand an attacker who has
 * borrowed a session a way past the second factor - which is the whole point of
 * having one.
 *
 * ── AND EVERY ATTEMPT IS RATE LIMITED ───────────────────────────────────────
 *
 * Six digits is a million possibilities, which sounds like plenty until you can
 * try them at HTTP speed: unthrottled, a determined attacker needs about a day.
 * `RateLimiter` caps attempts per account, so the search space stops being
 * reachable. The sign-in challenge is limited separately in `authController`.
 */
class TwoFactorController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private TwoFactor $twoFactor,
        private EventRecorder $events,
    ) {
    }

    /** How many code attempts per account per minute. */
    private const ATTEMPTS = 6;

    /** POST /api/account/2fa/start */
    public function start(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        if ($this->twoFactor->isEnabled($userId)) {
            /*
             * Refused rather than silently re-issued. Starting again would replace
             * a WORKING secret, so the authenticator the person is relying on would
             * stop matching - and they would discover it at their next sign-in,
             * with no way back except recovery codes. Turning it off first is an
             * explicit act that asks for their password.
             */
            return response()->json([
                'status' => false,
                'message' => 'Two-step verification is already on. Turn it off first to set it up again.',
            ], 409);
        }

        $email = (string) DB::table('tbluser')->where('id', $userId)->value('email');
        $secret = $this->twoFactor->beginEnrolment($userId, (int) $identity['sub_institute_id']);

        return response()->json([
            'status' => true,
            'data' => [
                'secret' => $secret,
                /*
                 * Grouped in fours because this is typed in by hand on a desktop,
                 * and 32 unbroken characters is where transcription errors live.
                 */
                'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
                /*
                 * On a phone, tapping this opens the authenticator and enrols in
                 * one step. There is no QR code: no encoder exists in this
                 * repository and writing one means Reed-Solomon error correction
                 * with no published vectors to check it against. See TwoFactor.
                 */
                'uri' => $this->twoFactor->enrolmentUri($secret, $email, config('app.name', 'GapstoGrowth')),
                'digits' => TwoFactor::DIGITS,
                'period' => TwoFactor::PERIOD,
            ],
        ]);
    }

    /** POST /api/account/2fa/confirm */
    public function confirm(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $userId = (int) $identity['user_id'];

        if ($limited = $this->throttle($userId, 'confirm')) {
            return $limited;
        }

        $codes = $this->twoFactor->confirmEnrolment($userId, (string) $request->input('code'));

        if ($codes === null) {
            return response()->json([
                'status' => false,
                'message' => 'That code is not right. Check your authenticator app and try the current code.',
            ], 422);
        }

        RateLimiter::clear($this->key($userId, 'confirm'));

        $this->record('account.two_factor_enabled', $identity);

        return response()->json([
            'status' => true,
            'message' => 'Two-step verification is on.',
            'data' => [
                /*
                 * Returned ONCE. Only hashes are stored, so this response is the
                 * only time these exist in readable form - which is the property
                 * that makes them safe to store at all, and the reason the screen
                 * must insist the person keeps them before moving on.
                 */
                'recovery_codes' => $codes,
            ],
        ]);
    }

    /**
     * POST /api/account/2fa/recovery-codes — issue a fresh set.
     *
     * Behind the password, because a new set invalidates the old one: somebody who
     * has borrowed a session could otherwise print themselves a way past the
     * second factor and leave the real owner holding codes that no longer work.
     */
    public function recoveryCodes(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate(['current_password' => ['required', 'string']]);

        $userId = (int) $identity['user_id'];

        if ($limited = $this->throttle($userId, 'password')) {
            return $limited;
        }

        if (!$this->passwordMatches($userId, (string) $request->input('current_password'))) {
            return response()->json([
                'status' => false,
                'message' => 'That password is not right.',
            ], 422);
        }

        if (!$this->twoFactor->isEnabled($userId)) {
            return response()->json([
                'status' => false,
                'message' => 'Two-step verification is not on for this account.',
            ], 409);
        }

        /*
         * Re-confirming with the existing secret is what re-issues the codes: the
         * secret is untouched, so the authenticator app keeps working. Only the
         * codes change.
         */
        $codes = $this->twoFactor->reissueRecoveryCodes($userId);

        RateLimiter::clear($this->key($userId, 'password'));

        $this->record('account.two_factor_recovery_reissued', $identity);

        return response()->json([
            'status' => true,
            'message' => 'New recovery codes. The old ones no longer work.',
            'data' => ['recovery_codes' => $codes],
        ]);
    }

    /** DELETE /api/account/2fa */
    public function destroy(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate(['current_password' => ['required', 'string']]);

        $userId = (int) $identity['user_id'];

        if ($limited = $this->throttle($userId, 'password')) {
            return $limited;
        }

        if (!$this->passwordMatches($userId, (string) $request->input('current_password'))) {
            return response()->json([
                'status' => false,
                'message' => 'That password is not right.',
            ], 422);
        }

        /*
         * ── THE ORGANISATION POLICY OUTRANKS THE PERSON'S OWN PREFERENCE ────────
         *
         * Without this the policy is defeated by exactly the person it applies to:
         * turn it off here, and `RequireTwoFactorEnrolment` puts them straight into
         * the setup gate - so they cannot work, the policy is not satisfied, and the
         * only thing achieved is an administrator locking themselves out of their own
         * product one endpoint at a time.
         *
         * Refused with the reason, rather than allowed and then immediately undone by
         * the middleware. 409, not 403: the request is understood and authorised, and
         * it conflicts with the state of the organisation.
         */
        if ($this->policyRequires($userId, (int) $identity['sub_institute_id'])) {
            return response()->json([
                'status' => false,
                'message' => 'Your organisation requires two-step verification, so it cannot be turned off. Ask an administrator to change the policy first.',
            ], 409);
        }

        $this->twoFactor->disable($userId);

        RateLimiter::clear($this->key($userId, 'password'));

        /*
         * Recorded, and this is one of the events most worth recording: turning the
         * second factor OFF is what an attacker does after taking an account, and
         * the entry is what lets the real owner see it happened.
         */
        $this->record('account.two_factor_disabled', $identity);

        return response()->json([
            'status' => true,
            'message' => 'Two-step verification is off.',
        ]);
    }

    /* ── shared ───────────────────────────────────────────────────────────── */

    private function key(int $userId, string $kind): string
    {
        return '2fa:' . $kind . ':' . $userId;
    }

    /**
     * Per-account throttling, keyed on the account rather than the IP.
     *
     * An IP key is the wrong choice twice over here: a whole office behind one
     * address would throttle each other, and an attacker with a handful of proxies
     * would not be throttled at all. The account is what is under attack, so the
     * account is what is counted.
     */
    private function throttle(int $userId, string $kind)
    {
        $key = $this->key($userId, $kind);

        if (!RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            RateLimiter::hit($key, 60);

            return null;
        }

        return response()->json([
            'status' => false,
            'message' => 'Too many attempts. Wait a minute and try again.',
        ], 429);
    }

    /**
     * Whether this organisation requires THIS person to have it on.
     *
     * One reader shared by the refusal above and by the `setup_required` flag on
     * `/account/me`, so the screen and the server cannot disagree about whether
     * somebody is obliged. The same three cases as the middleware, in the same order
     * and with the same failure direction: anything unrecognised means `off`.
     */
    public static function policyRequired(int $userId, int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        try {
            $policy = (string) app(\App\Services\Organization\TenantSettings::class)
                ->get($tenantId, 'security.require_two_factor');

            if ($policy === 'everyone') {
                return true;
            }

            return $policy === 'administrators'
                && \App\Support\RoleKey::forUserId($userId) === 'administrator';
        } catch (\Throwable $caught) {
            report($caught);

            // A policy nobody can read must not be the reason somebody is blocked.
            return false;
        }
    }

    private function policyRequires(int $userId, int $tenantId): bool
    {
        return self::policyRequired($userId, $tenantId);
    }

    private function passwordMatches(int $userId, string $password): bool
    {
        $hash = (string) DB::table('tbluser')->where('id', $userId)->value('password');

        return $hash !== '' && Hash::check($password, $hash);
    }

    /** Never allowed to fail the action it describes - see AccountController. */
    private function record(string $type, array $identity): void
    {
        try {
            $this->events->record(
                $type,
                (int) $identity['sub_institute_id'],
                'tbluser',
                (int) $identity['user_id'],
                (int) $identity['user_id'],
                []
            );
        } catch (\Throwable $caught) {
            report($caught);
        }
    }
}
