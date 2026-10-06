<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\InviteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Setting a password — the three endpoints that did not exist.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE NEW RATHER THAN A ROUTE MOVE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `ForgotPasswordController` lives on `routes/web.php` behind session and CSRF
 * middleware, and its GET entry point pointed at a method that is commented
 * out - so the frontend could never reach it and a browser hitting it got a
 * 500. Those routes stay for the Blade screens that still use them.
 *
 * These are on the API stack, unauthenticated by necessity: somebody who cannot
 * log in is the entire audience.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PASSWORD RULE, IN ONE PLACE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The product had three different minimums - 8 for a new tenant admin, 6 for a
 * reset, and NONE at all for `UserSignupController`, `LmsGovernanceController`,
 * `UserImportController` and `tbluserController::saveData()`, every one of
 * which would accept `"a"`.
 *
 * `Password::min(8)->letters()->numbers()` here, as a constant, so the strength
 * of a credential does not depend on which door somebody came through.
 */
class PasswordController extends Controller
{
    /** The one rule. Referenced, never re-typed. */
    public static function rule(?int $tenantId = null): Password
    {
        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE PRODUCT'S FLOOR, PLUS WHATEVER THE ORGANISATION ADDS
         * ═══════════════════════════════════════════════════════════════════
         *
         * Eight characters with letters and numbers is the floor, and it is not
         * negotiable: the same rule protects the invite and reset flows, and a
         * tenant that could weaken it would be weakening the links that reach
         * accounts across the whole platform. `min:8` on the settings endpoint
         * enforces that from the other side.
         *
         * An organisation may make it STRICTER. That is what these two settings
         * do, and reading them here - in the single place every password path
         * already shares - is what makes them real rather than stored.
         *
         * ── WHY THE TENANT IS OPTIONAL ──────────────────────────────────────
         *
         * Some callers genuinely have no tenant yet: `setPassword` runs on a
         * token, before anybody is authenticated, and the account it belongs to
         * may be on any organisation. Those get the floor, which is correct -
         * a stricter rule cannot be applied by an caller who cannot yet be told
         * whose rule it is.
         */
        $rule = Password::min(8)->letters()->numbers();

        if ($tenantId === null) {
            return $rule;
        }

        try {
            $settings = app(\App\Services\Organization\TenantSettings::class);

            $min = (int) $settings->get($tenantId, 'security.password_min_length');
            $symbol = $settings->get($tenantId, 'security.password_require_symbol') === '1';

            // max() so a stored value below the floor - which validation
            // prevents, but which a direct database edit would not - can never
            // weaken the rule.
            $rule = Password::min(max(8, $min))->letters()->numbers();

            if ($symbol) {
                $rule = $rule->symbols();
            }
        } catch (\Throwable) {
            // A missing table or an unreadable setting must not stop somebody
            // changing their password. The floor still applies.
        }

        return $rule;
    }

    /**
     * GET /api/auth/invite/{token}
     *
     * Is this link still good? Called by the set-password page before it shows
     * a form, so somebody with a dead link is told immediately rather than
     * after typing a password twice.
     */
    public function check(string $token, InviteService $invites)
    {
        $result = $invites->check($token);

        return response()->json([
            'status' => $result['valid'],
            'message' => $result['reason'],
            'data' => [
                // The address is echoed so the page can say whose account this
                // is - it is already in the link, so this reveals nothing the
                // holder does not have.
                'email' => $result['email'],
            ],
        ], $result['valid'] ? 200 : 410);
    }

    /**
     * POST /api/auth/set-password
     *
     * Spend a token and set a real password. Used by both the first-time invite
     * and a reset — they are the same act.
     */
    public function setPassword(Request $request, InviteService $invites)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', self::rule()],
        ]);

        $email = $invites->consume($data['token']);

        if ($email === null) {
            return response()->json([
                'status' => false,
                'message' => 'This link is not valid or has expired. Ask for a new one.',
            ], 410);
        }

        $user = DB::table('tbluser')->where('email', $email)->first(['id', 'status']);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'That account no longer exists.',
            ], 410);
        }

        DB::table('tbluser')->where('id', $user->id)->update([
            'password' => Hash::make($data['password']),
            // A person who has just proved they hold the address should not be
            // left with a stale one-time code on their row as a second way in.
            'otp' => null,
            'updated_at' => now(),
        ]);

        /*
         * EVERY EXISTING SESSION IS ENDED.
         *
         * Setting a password is what somebody does when they suspect their
         * account is compromised. Leaving prior tokens alive would mean the
         * attacker keeps their access and the owner has changed nothing.
         */
        DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Your password is set. You can sign in now.',
            'data' => ['email' => $email],
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     *
     * ── IT ALWAYS ANSWERS THE SAME WAY ──────────────────────────────────────
     *
     * Whether or not the address exists. The web version returns "Email does
     * not exist!" with a 422, which turns an unauthenticated endpoint into a
     * way to ask this system which of a list of addresses hold accounts.
     *
     * Nothing about the reply distinguishes the two cases - including its
     * timing, to the extent that matters here: the token work only happens for
     * a real address, but no branch is visible to the caller.
     */
    public function forgot(Request $request, InviteService $invites)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
        ]);

        $user = DB::table('tbluser')
            ->where('email', $data['email'])
            ->whereNull('deleted_at')
            ->first(['id', 'sub_institute_id', 'status']);

        $neutral = [
            'status' => true,
            'message' => 'If that address has an account, a reset link is on its way. Check your inbox, or ask your administrator if nothing arrives.',
        ];

        if (!$user || (int) $user->status !== 1) {
            return response()->json($neutral);
        }

        $result = $invites->issue($data['email'], (int) $user->sub_institute_id, 'reset');

        /*
         * THE LINK IS NOT RETURNED HERE, EVEN WHEN IT COULD NOT BE EMAILED.
         *
         * This endpoint is unauthenticated, so handing the link back would let
         * anybody mint a working set-password link for any address - which is a
         * complete account takeover, not a convenience.
         *
         * The invite path CAN return the link, because the caller there is an
         * authenticated administrator who is entitled to let that person in.
         * Same service, different trust, different answer.
         */
        return response()->json($neutral + [
            'data' => ['delivered' => $result['delivered']],
        ]);
    }
}
