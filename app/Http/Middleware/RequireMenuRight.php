<?php

namespace App\Http\Middleware;

use App\Support\MenuRight;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * MATRIX-ENFORCED AUTHORIZATION. The guard CONSULTS `tblgroupwise_rights_g2g`.
 *
 * ── WHY THIS AND NOT `profile:admin` ────────────────────────────────────────
 *
 * Narrowing a route to `profile:admin` hardcodes the boundary in code, where an
 * administrator can never change it. The point is an admin screen that controls
 * what HR and employees can do — so the fine-grained model must be the ENFORCED
 * one, not a second opinion the API never asks for.
 *
 * The matrix already exists, is seeded correctly, and distinguishes the roles as
 * the spec intended: 14 write-menus separate Admin from HR, all configuration.
 * **The defect was that no route read it.**
 *
 * ── PRECEDENCE (decided, not invented here) ────────────────────────────────
 *
 *   individual DENY > group DENY > individual ALLOW > group ALLOW
 *                   > role default > DENY
 *
 * `right_*` are `enum('allow','deny') NULL` — the INDIVIDUAL tri-state. NULL is
 * "no override", not "deny". `can_*` are the GROUP grant. A row that is absent
 * entirely falls to the tail and is denied.
 *
 * ── THE ALIAS IS `menuright`, NOT `menu`. ─────────────────────────────────
 *
 * `menu` WAS ALREADY TAKEN by `MenuMiddleware`, and registering mine under that
 * name silently overwrote it - later keys win in a PHP array. Every route group
 * in `hrms.php`, `lms.php` and `user.php` runs `['auth','session','menu']`, so
 * all of them began invoking THIS class with no parameters and fataled. The
 * sidebar returned 500 for all nine roles.
 *
 * NOTHING WARNED. The array accepted the duplicate, the app booted, and the only
 * symptom was a 500 with no message on unrelated routes - the undifferentiated
 * signal a fourth time. **An alias name is a namespace, and adding to it without
 * checking what is already there is an overwrite, not an addition.**
 *
 * ── USAGE ───────────────────────────────────────────────────────────────────
 *
 *   ->middleware('menuright:225,view')     must be able to VIEW menu 225
 *   ->middleware('menuright:225,edit')     must be able to EDIT menu 225
 *
 * A ROUTE WITH NO DECLARATION IS NOT SILENTLY ALLOWED — it simply does not carry
 * this middleware yet, and keeps whatever guard it has. Enforcement lands
 * per-route, never globally: turning it on everywhere before the 653 unmapped
 * routes are declared would deny most of the product, because the precedence
 * tail is DENY.
 */
class RequireMenuRight
{
    private const ACTIONS = ['view', 'add', 'edit', 'delete'];

    public function handle(Request $request, Closure $next, string $menuId, string $action = 'view'): Response
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->deny($request, 'unknown action: ' . $action, 500);
        }

        // IDENTITY FROM THE TOKEN, never from the request. Same rule as
        // RequireProfile: a role or tenant supplied by the caller is not
        // identity, it is a suggestion.
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

        // The precedence rule and the tenant-preference row selection (F-152)
        // now live in MenuRight::can(), shared with RequirePlatformRight —
        // see that class for the reasoning. Behavior here is unchanged.
        if (!MenuRight::can($profileId, (int) $menuId, $tenant, $action)) {
            // The message names the MENU, not the role. A refusal that says
            // "admins only" would be describing a hardcoded rule; this one is
            // describing a row an administrator can change.
            return $this->deny($request, sprintf(
                'Your role does not have %s rights on this screen (menu %d).', $action, (int) $menuId
            ), 403);
        }

        return $next($request);
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}
