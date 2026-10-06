<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * RECORD THAT A SESSION IS ALIVE, AND KEEP IT ALIVE WHILE IT IS USED.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE SESSION LIST WAS HALF DEAD
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `last_used_at` was NULL on all 4,960 live tokens, so Sign-in & security showed
 * "Never used since it was created" on every single row and sorted by a column
 * that was always null. The device names worked; the one thing that makes a
 * session list worth having - which of these is stale, which is me right now -
 * did not.
 *
 * Sanctum writes `last_used_at` in its own `Guard`. This application never uses
 * that guard: eight middlewares and several controllers resolve tokens by calling
 * `PersonalAccessToken::findToken()` directly, which does a lookup and nothing
 * else. So nothing ever wrote the column.
 *
 * ── WHY THIS IS ONE MIDDLEWARE AND NOT EIGHT EDITS ──────────────────────────
 *
 * The obvious fix is to touch the token wherever it is already resolved. There
 * are eight such places in `app/Http/Middleware` alone, plus controllers - and
 * 167 routes are role-gated WITHOUT `api.token` or `auth`, so no two of those
 * sites cover everything. Eight call sites is eight chances for the ninth to be
 * forgotten.
 *
 * This resolves the token itself and is appended to the route groups, so it covers
 * every route in them regardless of which of the eight happens to run. One class,
 * one place to reason about, no coordination.
 *
 * ── AND WHY IT IS SAFE TO ADD TO EVERY REQUEST ──────────────────────────────
 *
 * It writes at most once a minute per token, which is what Sanctum's own guard
 * does. Without that throttle a busy screen polling every few seconds would turn
 * one UPDATE per request into the most written-to table in the database.
 *
 * It never blocks: no token, an unknown token, or a failed write all fall through
 * to the next middleware. Authorisation is somebody else's job here - this is
 * bookkeeping, and bookkeeping must not be able to lock anybody out.
 */
class TouchTokenActivity
{
    /**
     * How long a session survives without being used.
     *
     * Every one of the 4,960 live tokens had `expires_at` NULL - an immortal
     * session, for everybody, forever. `RequireApiToken` has always REFUSED an
     * expired token; nothing ever set an expiry for it to refuse.
     *
     * Idle rather than absolute: a hard lifetime signs out somebody who is using
     * the product daily, which is a cost with no security benefit while they are
     * demonstrably present. Thirty days of silence is a session nobody is coming
     * back to.
     */
    public const IDLE_DAYS = 30;

    /** Written at most this often per token. Matches Sanctum's own guard. */
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $this->touch($request);

        return $next($request);
    }

    private function touch(Request $request): void
    {
        try {
            $raw = trim((string) ($request->bearerToken() ?: $request->input('token')));

            if ($raw === '') {
                return;
            }

            $token = PersonalAccessToken::findToken($raw);

            if (!$token) {
                return;
            }

            $now = now();

            /*
             * ═══════════════════════════════════════════════════════════════
             * NEVER TOUCH AN ALREADY-EXPIRED TOKEN
             * ═══════════════════════════════════════════════════════════════
             *
             * Without this check the feature defeated itself, and the evidence
             * caught it: this middleware is appended to the route GROUP, so it
             * runs BEFORE `RequireApiToken`. An expired token arrived, this slid
             * its `expires_at` thirty days into the future, and the gate then
             * looked at a perfectly valid expiry and let it through.
             *
             * So a token that should have died came back to life on the very
             * request that should have refused it - and because the same thing
             * happened every time, NO token could ever expire. That is strictly
             * worse than the original bug, which was only that sessions never
             * ended: this version looked like it had expiry and did not.
             *
             * A dead session's activity is not worth recording, and recording it
             * is what revived it.
             */
            if ($token->expires_at !== null && $token->expires_at->isPast()) {
                return;
            }

            /*
             * THE THROTTLE, and why it compares before writing.
             *
             * A single screen can make a dozen requests in a second. Writing on
             * each would put `personal_access_tokens` under more write load than
             * any business table in the product, to record a fact that is only
             * ever read to the nearest minute.
             */
            if ($token->last_used_at && $token->last_used_at->diffInSeconds($now) < self::THROTTLE_SECONDS) {
                return;
            }

            /*
             * SLIDING EXPIRY, in the same write.
             *
             * Using a session pushes its expiry out. So somebody who works daily
             * is never signed out, and a token abandoned on a borrowed laptop dies
             * thirty days later without anybody having to remember it existed.
             *
             * Written with the query builder rather than `$token->save()`: the
             * model would also touch `updated_at` and fire events, and this is a
             * bookkeeping write on the hot path for every authenticated request.
             */
            DB::table('personal_access_tokens')
                ->where('id', $token->id)
                ->update([
                    'last_used_at' => $now,
                    'expires_at' => $now->copy()->addDays(self::IDLE_DAYS),
                ]);
        } catch (\Throwable $caught) {
            /*
             * Deliberately swallowed, and deliberately reported.
             *
             * This runs on every authenticated request in the product. A failure
             * here - a locked table, a connection blip - must not turn into a 500
             * on somebody's dashboard, because the thing that failed was a
             * timestamp. `report()` so it is not invisible.
             */
            report($caught);
        }
    }
}
