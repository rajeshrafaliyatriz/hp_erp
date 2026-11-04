<?php

use App\Domain\OrgAccounts\OrgAccess;
use App\Http\Controllers\Api\OrgAccounts\OrgAccountsController;
use Illuminate\Support\Facades\Route;

/*
 * Organisation 360 (slice 3 of the agentic CRM, Docs/agentic-crm/slices/S03-organisation-360.md), folded into the Organizations screen.
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/product_master.php: these routes are reached by
 * the token-authenticated frontend and a session guard would 401 them.
 *
 * Rights are the Organizations screen's, with the same `|` rule routes/api.php uses for the CRM organization routes: a role holding the right on EITHER
 * `/module/crm/marketing/organizations` or `/module/crm/support/organizations` passes (OrgAccess::middleware). view -> reads, edit -> PATCH and the
 * confirm/reject of a link, add -> creating the CRM organisation. There is no administrator bypass.
 *
 * Literal paths come before `/{id}` and every `{id}` is whereNumber. Every `{id}` route answers another organisation's id with 404:
 * OrgAccountsTenantSweepTest walks this file's routes and fails for any id route that does not. In `for-crm/{id}` the id is a CRM organization's.
 */
Route::prefix('api/org-accounts')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        Route::middleware(OrgAccess::middleware('view'))->group(function () {
            Route::get('/', [OrgAccountsController::class, 'index']);
            Route::get('/meta', [OrgAccountsController::class, 'meta']);
            Route::get('/suggestions', [OrgAccountsController::class, 'suggestions']);
            // Makes the identity of a CRM organization that has none (it only writes g2g_org_accounts, derived data), then answers with its 360.
            // A POST behind the view right on purpose: opening an organization's Account 360 must work for anyone who may open the organization.
            Route::post('/for-crm/{id}', [OrgAccountsController::class, 'forCrmOrganization'])->whereNumber('id');
            Route::get('/{id}', [OrgAccountsController::class, 'show'])->whereNumber('id');
        });

        Route::middleware(OrgAccess::middleware('edit'))->group(function () {
            Route::post('/links', [OrgAccountsController::class, 'links']);
            Route::patch('/{id}', [OrgAccountsController::class, 'update'])->whereNumber('id');
        });

        Route::post('/{id}/create-crm-organisation', [OrgAccountsController::class, 'createCrmOrganisation'])
            ->whereNumber('id')->middleware(OrgAccess::middleware('add'));
    });
