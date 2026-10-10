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
 *       (some module's rights, not none) rather than an unlimited one.
 *
 *   ->middleware('platformright:/ai/{capability}!agents=/module/agentic-ai/agentic-library,view')
 *       Any other `{name}` is read from the ROUTE's own parameter of that
 *       name (`$request->route('capability')`), not request input — the AI
 *       console addresses a capability as a URL path segment
 *       (`/api/ai/capabilities/{capability}`), not a query string, unlike
 *       Platform Services' `?module=`. No widening on a missing/blank value:
 *       a route that declares `{capability}` always carries the segment, so
 *       an empty read means something is actually wrong, not "unscoped".
 *
 *       `!value=link` (repeatable) overrides the substituted link for one
 *       specific value instead of the generic `/ai/{value}` pattern — needed
 *       because Laravel's router keys a route by its URI TEMPLATE alone
 *       (`where()` constraints don't create a second, distinct route for the
 *       same templated path — confirmed directly: two `Route::get()` calls
 *       at the identical `{capability}` URI silently collapsed to whichever
 *       registered last, discarding the first with no error). So a value
 *       that rides a DIFFERENT real access_link than the generic pattern —
 *       Agent Management reuses the real Agentic AI Library screen, not a
 *       new `/ai/agents` row — has to be expressed as one exception on one
 *       route, not as two competing route registrations.
 *
 *   ->middleware('platformright:/ai/providers|/ai/models,view')
 *       `|`-separated tokens are OR'd — the caller needs only ONE of them.
 *       For endpoints that genuinely serve more than one capability at once
 *       (the AI console's own `/capabilities` index; `/configuration/options`,
 *       which populates both the provider and model dropdowns in one call).
 */
class RequirePlatformRight
{
    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    private const DECENTRALIZED_MODULES = ['organization', 'hrms', 'talent', 'lms', 'competency', 'task', 'crm'];

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

        $allowed = false;
        foreach (explode('|', $linkTemplate) as $linkToken) {
            if ($this->checkToken($request, $linkToken, $profileId, $tenant, $action)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return $this->deny($request, 'Your role does not have access to this screen.', 403);
        }

        return $next($request);
    }

    private function checkToken(Request $request, string $linkTemplate, int $profileId, int $tenant, string $action): bool
    {
        if (str_contains($linkTemplate, '{module}')) {
            return $this->checkModuleScoped($request, $linkTemplate, $profileId, $tenant, $action);
        }

        if (preg_match('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $linkTemplate, $match)) {
            return $this->checkRouteParamScoped($request, $linkTemplate, $match[1], $profileId, $tenant, $action);
        }

        return $this->checkFixedLink($linkTemplate, $profileId, $tenant, $action);
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

    /**
     * A templated segment read from the ROUTE's own parameter, e.g.
     * `/ai/{capability}` against a route declaring `Route::get('/capabilities/{capability}', ...)`.
     * Unlike `{module}`, no widening on a missing value — see class docblock.
     *
     * `$linkTemplate` may carry `!value=link` suffixes (see class docblock)
     * overriding the substituted link for one specific route-parameter value.
     */
    private function checkRouteParamScoped(Request $request, string $linkTemplate, string $param, int $profileId, int $tenant, string $action): bool
    {
        $value = $request->route($param);

        if (!is_string($value) || $value === '') {
            return false;
        }

        [$baseTemplate, $overrides] = $this->parseOverrides($linkTemplate);

        $accessLink = $overrides[$value] ?? str_replace('{' . $param . '}', $value, $baseTemplate);

        return $this->checkFixedLink($accessLink, $profileId, $tenant, $action);
    }

    /** @return array{0: string, 1: array<string, string>} [base template with overrides stripped, value => link map] */
    private function parseOverrides(string $linkTemplate): array
    {
        $overrides = [];
        $base = preg_replace_callback(
            '/!([a-zA-Z0-9\-]+)=(\/[^!]*)/',
            function ($match) use (&$overrides) {
                $overrides[$match[1]] = rtrim($match[2]);
                return '';
            },
            $linkTemplate
        );

        return [$base, $overrides];
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}
