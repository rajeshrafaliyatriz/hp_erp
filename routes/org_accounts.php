<?php

use App\Http\Controllers\Api\OrgAccounts\OrgAccountsController;
use Illuminate\Support\Facades\Route;

/*
 * Organisation 360 (slice 3 of the agentic CRM, Docs/agentic-crm/slices/S03-organisation-360.md).
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/product_master.php: these routes are reached by
 * the token-authenticated frontend and a session guard would 401 them.
 *
 * Rights come from the menu row `/module/crm/organisation-360` (tblgroupwise_rights_g2g, found by access_link), checked per action. There is no
 * administrator bypass. Creating a CRM organisation also needs the CRM Organisations add right, which the controller checks (OrgAccess).
 * Literal paths come before `/{id}` and every `{id}` is whereNumber. Every `{id}` route answers another organisation's id with 404:
 * OrgAccountsTenantSweepTest walks this file's routes and fails for any id route that does not.
 */
Route::prefix('api/org-accounts')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        Route::middleware('platformright:/module/crm/organisation-360,view')->group(function () {
            Route::get('/', [OrgAccountsController::class, 'index']);
            Route::get('/meta', [OrgAccountsController::class, 'meta']);
            Route::get('/suggestions', [OrgAccountsController::class, 'suggestions']);
            Route::get('/{id}', [OrgAccountsController::class, 'show'])->whereNumber('id');
        });

        Route::middleware('platformright:/module/crm/organisation-360,edit')->group(function () {
            Route::post('/links', [OrgAccountsController::class, 'links']);
            Route::patch('/{id}', [OrgAccountsController::class, 'update'])->whereNumber('id');
        });

        Route::post('/{id}/create-crm-organisation', [OrgAccountsController::class, 'createCrmOrganisation'])
            ->whereNumber('id')->middleware('platformright:/module/crm/organisation-360,add');
    });
