<?php

namespace App\Http\Middleware;

use App\Services\Account\TwoFactor;
use App\Services\Organization\TenantSettings;
use App\Support\RoleKey;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE ORGANISATION POLICY, ENFORCED ON THE SERVER.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A MIDDLEWARE AND NOT A CHECK AT SIGN-IN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation is to refuse the sign-in: policy says you must have
 * two-step verification, you do not, goodbye. That locks an entire organisation out
 * of itself. Enrolment requires being signed in, so an administrator who switches
 * the policy on and then signs out has just made the tenant unreachable - including
 * for themselves, including for the person who would turn the policy back off.
 *
 * So the sign-in succeeds and the PRODUCT is gated instead: everything is refused
 * except the handful of endpoints needed to enrol. The person lands in the product,
 * is sent to Sign-in & security, sets it up, and carries on. Nobody is ever locked
 * out, and nobody can work around it either.
 *
 * ── AND WHY IT IS NOT LEFT TO THE FRONTEND ──────────────────────────────────
 *
 * This codebase already has the cautionary example: `can_view` / `can_edit` were
 * enforced only by `MenuMiddleware`, which returns early on `type=API` and never
 * aborts - so thirty profiles could read the organisation profile and the server
 * would have let them write it. A policy that only a React component honours is a
 * suggestion. This one returns 403.
 *
 * ── OFF BY DEFAULT, WHICH IS WHAT MAKES THIS SAFE TO ADD ────────────────────
 *
 * `security.require_two_factor` defaults to `off` for every tenant, and this
 * middleware returns early on it before reading anything else. Eleven live tenants
 * are unaffected until somebody deliberately changes the setting. An unrecognised
 * value is also treated as `off` - the failure direction for a policy nobody can
 * parse is "behave as before", not "lock everybody out".
 *
 * ── AND IT NEVER THROWS ─────────────────────────────────────────────────────
 *
 * It runs on every API and web request. A failure here that raised would be a
 * platform outage, so anything unexpected is reported and waved through: this
 * middleware can refuse a request it understands, and must never break one it
 * does not.
 */
class RequireTwoFactorEnrolment
{
    /**
     * What a person who has not enrolled may still reach.
     *
     * Exactly enough to get signed in, see the shell, and enrol. Anything wider and
     * the gate becomes advisory; anything narrower and there is no way out of it.
     *
     * Matched as a PREFIX against the normalised path, so `account/2fa/start` covers
     * itself and nothing else, while `account/2fa` would also have covered
     * `account/2fa/disable` - which must stay closed, or the policy is defeated by
     * the person it applies to.
     */
    private const ALLOWED = [
        // The account payload. The shell cannot render without it, and it is what
        // carries `two_factor.setup_required` to the screen that explains this.
        'account/me',
        // Enrolment itself.
        'account/2fa/start',
        'account/2fa/confirm',
        // Signing out must always work. Trapping somebody in a session they cannot
        // leave is its own kind of lockout.
        'logout',
        'account/logout',
    ];

    /**
     * Per-request memo, keyed so it can never answer for the wrong person.
     *
     * A bare boolean would be a cross-request cache under a long-lived worker: one
     * person's "already enrolled" would wave the next request through. Keyed by
     * tenant and user, it is safe wherever it runs.
     *
     * @var array<string, bool>
     */
    private static array $decided = [];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $refusal = $this->refuse($request);

            if ($refusal !== null) {
                return $refusal;
            }
        } catch (\Throwable $caught) {
            /*
             * Waved through, deliberately. This runs on every request in two
             * middleware groups; a raise here is an outage for every tenant,
             * including the ones with the policy switched off.
             */
            report($caught);
        }

        return $next($request);
    }

    /** The decision, separated so the try/catch above stays one line of intent. */
    private function refuse(Request $request): ?Response
    {
        $path = trim($request->path(), '/');

        // Routes are registered under an /api prefix; the allow-list is written
        // without it so the same entries cover the web group.
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        foreach (self::ALLOWED as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return null;
            }
        }

        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            // Not authenticated, or not in a way this can read. Whatever gate the
            // route has will deal with it - this middleware is not one of them.
            return null;
        }

        $tenantId = (int) \Illuminate\Support\Facades\DB::table('tbluser')
            ->where('id', $userId)
            ->value('sub_institute_id');

        if ($tenantId <= 0) {
            return null;
        }

        $key = $tenantId . ':' . $userId;

        if (array_key_exists($key, self::$decided)) {
            return self::$decided[$key] ? null : $this->setupRequired();
        }

        $policy = (string) app(TenantSettings::class)->get($tenantId, 'security.require_two_factor');

        // `off`, and anything unrecognised. A policy that cannot be parsed must not
        // be the reason an organisation cannot work.
        if ($policy !== 'administrators' && $policy !== 'everyone') {
            self::$decided[$key] = true;

            return null;
        }

        if ($policy === 'administrators' && RoleKey::forUserId($userId) !== 'administrator') {
            self::$decided[$key] = true;

            return null;
        }

        $enrolled = app(TwoFactor::class)->isEnabled($userId);
        self::$decided[$key] = $enrolled;

        return $enrolled ? null : $this->setupRequired();
    }

    /**
     * Token first, then session - the same order as RequireHritRole and
     * PayrollController, and never the request body, which is a claim by the caller.
     */
    private function resolveUserId(Request $request): ?int
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token !== '') {
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return null;
            }

            // An expired token is not an identity. Without this the gate would be
            // reasoning about somebody whose session another middleware is about to
            // refuse anyway - see the bug where TouchTokenActivity revived one.
            if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
                return null;
            }

            return (int) $accessToken->tokenable_id;
        }

        $sessionUserId = $request->hasSession() ? $request->session()->get('user_id') : null;

        return is_numeric($sessionUserId) ? (int) $sessionUserId : null;
    }

    /**
     * 403 with a machine-readable reason, so the frontend can route rather than
     * guess from a sentence.
     *
     * Not 401: the credential is valid and the session is real. A 401 would tell
     * every client in the product to throw the token away and send somebody back to
     * the sign-in screen, which is the one place that cannot fix this.
     */
    private function setupRequired(): Response
    {
        return response()->json([
            'status' => 0,
            'two_factor_setup_required' => true,
            'message' => 'Your organisation requires two-step verification. Set it up in Settings, under Sign-in & security, to carry on.',
        ], 403);
    }
}
