<?php

namespace App\Http\Middleware;

use App\Support\MenuRight;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * MATRIX-ENFORCED AUTHORIZATION for Platform Services, replacing the group-
 * wide `profile:admin` gate `routes/platform.php` used to carry. See that
 * file's own comment for what changed and why — the short version: an admin
 * screen that is meant to let admins decide who can use these consoles
 * cannot itself be hardcoded to admins-only.
 *
 * ── WHY A SIBLING TO `RequireMenuRight`, NOT A REUSE OF IT ──────────────────
 *
 * `menuright:225,view` takes a fixed numeric id baked into the route
 * declaration. Platform Services' menu rows are module-scoped — which row
 * applies depends on a `module` request parameter resolved at request time,
 * not on anything known when the route is registered — so a fixed id can't
 * express it. Both middleware share the same enforcement rule via
 * `MenuRight::can()`; only how the menu id is found differs.
 *
 * ── USAGE ────────────────────────────────────────────────────────────────
 *
 *   ->middleware('platformright:/platform-services/event-bus,view')
 *       One fixed access_link — Event Bus and Audit, neither of which has a
 *       per-module concept.
 *
 *   ->middleware('platformright:/platform-services/workflow?module={module},view')
 *       `{module}` is substituted with the request's own `module` input.
 *       When it's blank or not one of the six real module keys — the
 *       unscoped avatar-menu link, and id-based writes like
 *       `PUT /workflow/{id}` that don't carry `module` at all — this falls
 *       back to "does the caller have view rights on AT LEAST ONE of the six
 *       module rows". Deliberately not admin-only: an administrator already
 *       sees every module unscoped today, so this is a bounded widening
 *       (some module's rights, not none) rather than an unlimited one. See
 *       the plan's own note on this being a documented, accepted trade-off
 *       rather than a precise per-row scope for id-based writes.
 */
class RequirePlatformRight
{
    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    private const DECENTRALIZED_MODULES = ['organization', 'hrms', 'talent', 'lms', 'competency', 'task'];

    public function handle(Request $request, Closure $next, string $linkTemplate, string $action = 'view'): Response
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->deny($request, 'unknown action: ' . $action, 500);
        }

        // IDENTITY FROM THE TOKEN, never from the request — same rule as
        // RequireMenuRight and RequireProfile.
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        if ($token === '') {
            return $this->deny($request, 'Token not provided', 401);
        }

        $user = PersonalAccessToken::findToken($token)?->tokenable;
        if (!$user) {
            return $this->deny($request, 'Invalid token', 401);
        }

        $profileId = $user->user_profile_id ?? null;
        $tenant = (int) ($user->sub_institute_id ?? 0);
        if (!$profileId || !$tenant) {
            return $this->deny($request, 'Unable to resolve profile or organization', 403);
        }

        $allowed = str_contains($linkTemplate, '{module}')
            ? $this->checkModuleScoped($request, $linkTemplate, $profileId, $tenant, $action)
            : $this->checkFixedLink($linkTemplate, $profileId, $tenant, $action);

        if (!$allowed) {
            return $this->deny($request, 'Your role does not have access to this Platform Services screen.', 403);
        }

        return $next($request);
    }

    private function checkFixedLink(string $accessLink, int $profileId, int $tenant, string $action): bool
    {
        $menuId = MenuRight::idForAccessLink($accessLink);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $tenant, $action);
    }

    private function checkModuleScoped(Request $request, string $linkTemplate, int $profileId, int $tenant, string $action): bool
    {
        $module = trim((string) $request->input('module', ''));

        if ($module !== '' && in_array($module, self::DECENTRALIZED_MODULES, true)) {
            return $this->checkFixedLink(str_replace('{module}', $module, $linkTemplate), $profileId, $tenant, $action);
        }

        // Blank/unrecognized module — any one of the six module rows grants access. See class docblock.
        foreach (self::DECENTRALIZED_MODULES as $candidate) {
            if ($this->checkFixedLink(str_replace('{module}', $candidate, $linkTemplate), $profileId, $tenant, $action)) {
                return true;
            }
        }

        return false;
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}
