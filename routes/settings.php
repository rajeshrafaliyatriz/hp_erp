<?php

use App\Http\Controllers\settings\instituteDetailController;
use App\Http\Controllers\settings\organizationDetailsController;
use App\Http\Controllers\settings\discliplinaryManagementController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'settings', 'middleware' => ['auth','session','menu']], function () {
    Route::resource('institute_detail', instituteDetailController::class);

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE ORGANISATION IS ADMINISTRATOR-ONLY, AND UNTIL NOW NOTHING SAID SO
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `org_details` holds the organisation's legal name, CIN, GSTIN, PAN,
     * registered address and logo. Every authenticated user could read AND WRITE
     * all of it.
     *
     * Three layers each looked like they might be the gate, and none was:
     *
     *   `auth`            App\Http\Middleware\authMiddleware accepts ANY valid
     *                     Sanctum token - `PersonalAccessToken::findToken(...)
     *                     !== null`. No role, no tenant, no rights.
     *   `menu`            MenuMiddleware returns early when `type=API`, which is
     *                     the marker the frontend always sends, and even on the
     *                     Blade path it only populates session menu data and ends
     *                     in an unconditional `return $next($request)`. It aborts
     *                     on nothing.
     *   the rights table  `tblgroupwise_rights_g2g` grants Employee and HR
     *                     `can_view = 1` with `can_edit = 0` - and no server code
     *                     reads either column. They are UI hints.
     *
     * So `POST /settings/organization_data` with a plain employee's token
     * rewrote the organisation. Reported as "as an employee why am I seeing the
     * organisation setting", which was the visible half of it.
     *
     * ── WHY `hrit.role` AND NOT `profile` ───────────────────────────────────
     *
     * `RequireProfile::resolveRoleKey` returns 401 when there is no token, and
     * this is a WEB group whose `auth` accepts a session as well. A session
     * caller who is not an administrator should be told 403 - they are
     * authenticated, just not permitted - and 401 would send the frontend off to
     * re-authenticate over a permission problem. `RequireHritRole` is the same
     * gate resolving token first, then session; `bootstrap/app.php` says as much
     * where the alias is registered.
     *
     * ── THE LOCKOUT THIS HAD TO AVOID ───────────────────────────────────────
     *
     * Ten live tenants have no `role_key` on their administrator profile - it is
     * a legacy row named "Admin". `RoleKey::LEGACY_NAMES` maps `'admin' =>
     * 'administrator'`, so they still pass. Legacy `'hr' => 'hr_manager'`, so HR
     * correctly loses it, and legacy "Employee" resolves to null and is refused.
     */
    Route::middleware('hrit.role:admin')->group(function () {
        Route::resource('organization_data', organizationDetailsController::class);
    });

    Route::resource('discliplinary_management', discliplinaryManagementController::class);
});
