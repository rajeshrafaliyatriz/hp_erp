<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The single place the API decides *who is calling* and *which organisation
 * they belong to*.
 *
 * Every module used to answer both questions from the request body:
 *
 *     PersonalAccessToken::findToken($token)                  // token valid? yes
 *     'sub_institute_id' => $request->input('sub_institute_id')
 *     'user_id'          => $request->input('user_id')
 *
 * That validates the token and then throws away its owner, so any employee
 * holding any valid token could read and write another organisation's records
 * by changing one query parameter, and could attribute those writes to any
 * user id they cared to name. Task Management already resolved this correctly
 * (see ResolvesTaskContext); this trait is that fix, extracted so the nine
 * other modules cannot drift away from it again.
 *
 * The rule: the token decides. The request is never trusted for identity.
 */
trait ResolvesApiIdentity
{
    /**
     * Resolve the caller from their Sanctum token.
     *
     * @return array{user:object, user_id:int, sub_institute_id:int}|\Illuminate\Http\JsonResponse
     */
    protected function resolveApiIdentity(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Invalid token'], 401);
        }

        // findToken() resolves a token by hash; it does not care whether that
        // token has since expired. Without this an expired credential keeps
        // working forever.
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return response()->json(['status' => 0, 'message' => 'Token expired'], 401);
        }

        $ownTenant = (int) ($user->sub_institute_id ?? 0);

        if ($ownTenant > 0) {
            // The token owner's own organisation wins, always. A different
            // sub_institute_id in the request is ignored rather than refused:
            // a stale value in the caller's localStorage is common and must not
            // lock a legitimate user out, and ignoring it is equally safe
            // because the attacker-supplied value never reaches a query.
            $subInstituteId = $ownTenant;
        } else {
            // tbluser.sub_institute_id is nullable and historical rows predate
            // tenant assignment. Those accounts have no organisation of their
            // own, so the request is the only source left.
            $requested = $request->input('sub_institute_id') ?? $request->header('sub_institute_id');

            if (!$requested || !is_numeric($requested)) {
                return response()->json(['status' => 0, 'message' => 'sub_institute_id is required'], 400);
            }

            $subInstituteId = (int) $requested;
        }

        return [
            'user'             => $user,
            'user_id'          => (int) $user->id,
            'sub_institute_id' => $subInstituteId,
        ];
    }

    /**
     * The caller's organisation, as a plain value.
     *
     * For controllers that read the tenant inline, mid-expression, rather than
     * resolving a context object at the top of the method. Lets
     * `$request->sub_institute_id` be swapped for `$this->apiTenantId($request)`
     * without restructuring the method around it.
     *
     * Returns null when the caller cannot be identified. That is deliberate:
     * every caller feeds this into a `where sub_institute_id = ?`, so an
     * unidentified caller matches nothing instead of matching a tenant they
     * named themselves.
     */
    protected function apiTenantId(Request $request): ?int
    {
        $identity = $this->resolveApiIdentity($request);

        return is_array($identity) ? $identity['sub_institute_id'] : null;
    }

    /**
     * The caller's own user id, as a plain value.
     *
     * Only for reads that mean "me" - the actor. Where a request's `user_id`
     * genuinely names somebody else (an admin acting on an employee's record),
     * leave it alone and bound it with apiTenantId() instead.
     */
    protected function apiUserId(Request $request): ?int
    {
        $identity = $this->resolveApiIdentity($request);

        return is_array($identity) ? $identity['user_id'] : null;
    }

    /**
     * Boolean "is this caller authenticated", for controllers that guard with a
     * plain condition rather than an early return.
     *
     * Exists to replace `$this->jwtToken()->validate()` in the three
     * controllers that still used GenTux\Jwt. That package is not in
     * composer.json and is not installed, so the trait it provides could not be
     * loaded: those controllers were fatal on every request, and the reflection
     * Laravel performs over controller classes made them fatal for
     * `route:list` and `route:cache` too - for the whole application, not just
     * themselves.
     *
     * Sanctum is what the rest of the codebase authenticates with, so these
     * now agree with everything else instead of depending on a second,
     * uninstalled token system.
     *
     * $request is optional so the swap needed no change at the call sites.
     */
    protected function apiTokenIsValid(?Request $request = null): bool
    {
        return is_array($this->resolveApiIdentity($request ?: request()));
    }
}
