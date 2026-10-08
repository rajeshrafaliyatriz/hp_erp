<?php

namespace App\Http\Middleware;

use App\Support\MenuRight;
use App\Support\RoleKey;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * One gate for a write that more than one screen legitimately performs.
 *
 *     ->middleware('anyaccess:admin+hr,/module/lms/administration/course-builder@view')
 *
 * The caller passes when ANY of the listed conditions holds:
 *
 *   - a bare token is a profile list joined with `+` ("admin+hr"), in the same
 *     vocabulary as `profile:` (App\Support\RoleKey::ALIASES), matched exactly on
 *     role_key; or
 *   - a token beginning with `/` is `<access_link>@<view|add|edit|delete>`: the
 *     caller's profile holds that right on that page in tblgroupwise_rights_g2g,
 *     read through the same rule `platformright` uses (App\Support\MenuRight).
 *
 * WHY NOT `platformright` ALONE OR `profile` ALONE
 *
 * `platformright:/a|/b,view` ORs pages but uses one action for all of them and has
 * no notion of a role. `profile:` has no notion of the rights matrix. Several
 * writes here are reachable from two screens that ask for different rights (a job
 * role is added from the Capability Library, which needs `add`, and from a
 * department's own panel, which needs `edit` on Department Management) or have a
 * role rule in the UI that the matrix does not carry (course authoring is
 * administrator/HR). A gate on just one of them would silently break the other
 * screen. This keeps every legitimate door open and closes the endpoint for
 * everyone who holds none of them.
 *
 * IDENTITY COMES FROM THE TOKEN, never from the request - a profile name or user id
 * in the body is ignored. A malformed spec fails closed (500), never open.
 */
class RequireAnyAccess
{
    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function handle(Request $request, Closure $next, string ...$tokens): Response
    {
        $profiles = [];
        $rights = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '/')) {
                [$link, $action] = array_pad(explode('@', $token, 2), 2, 'view');

                if (!in_array($action, self::ACTIONS, true)) {
                    return $this->deny('Unknown action in access rule: ' . $action, 500);
                }

                $rights[] = [$link, $action];
            } else {
                foreach (explode('+', $token) as $alias) {
                    if ($alias !== '') {
                        $profiles[] = $alias;
                    }
                }
            }
        }

        if ($profiles === [] && $rights === []) {
            return $this->deny('Access rule is empty.', 500);
        }

        $bearer = trim((string) ($request->bearerToken() ?: $request->input('token')));
        if ($bearer === '') {
            return $this->deny('Token not provided', 401);
        }

        $accessToken = PersonalAccessToken::findToken($bearer);
        $user = $accessToken?->tokenable;
        if (!$user) {
            return $this->deny('Invalid token', 401);
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->deny('Token expired', 401);
        }

        if ($profiles !== [] && RoleKey::satisfies(RoleKey::forUser($user), $profiles)) {
            return $next($request);
        }

        $profileId = (int) ($user->user_profile_id ?? 0);
        $tenant = (int) ($user->sub_institute_id ?? 0);

        if ($profileId > 0 && $tenant > 0) {
            foreach ($rights as [$link, $action]) {
                $menuId = MenuRight::idForAccessLink($link);

                if ($menuId !== null && MenuRight::can($profileId, (int) $menuId, $tenant, $action)) {
                    return $next($request);
                }
            }
        }

        return $this->deny('You do not have permission to perform this action.', 403);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['status' => $status === 500 ? 0 : false, 'message' => $message], $status);
    }
}
