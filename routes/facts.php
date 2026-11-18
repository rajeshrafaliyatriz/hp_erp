<?php

use App\Domain\Facts\FactAccess;
use App\Domain\Facts\FactEntities;
use App\Http\Controllers\Api\Facts\ConsentController;
use App\Http\Controllers\Api\Facts\FactsController;
use Illuminate\Support\Facades\Route;

/*
 * The contact facts ledger and the consent line (slice 4 of the agentic CRM, docs/agentic-crm/slices/S04-contact-facts-and-consent.md).
 *
 * Declares its own prefixes and middleware, so it is registered bare in bootstrap/app.php, like routes/agent_gateway.php: these routes are reached by the
 * token-authenticated frontend and a session guard would 401 them.
 *
 * NO MENU ROW OF ITS OWN (the owner's rule of 3 Nov 2026: no duplicate screens). The rights are those of the screen a record belongs to, found by access_link
 * (App\Domain\Facts\FactAccess): contact = the Contacts rows, organization = the Organizations rows, lead = the Leads row; view reads, edit decides.
 *
 *   - /entity/{type}/{id} and /api/consent/{type}/{id} are registered once per kind of record (a literal `contact`, `organization`, `lead`), so each route
 *     carries the right of ITS screen; a `{type}` placeholder cannot be mapped to a different menu row by the platformright middleware;
 *   - the queue and the two decisions serve every kind, so their middleware is "the right on at least one kind" and the controller then narrows it to the
 *     kinds the caller may view (the queue) or to the kind of the fact (accept, dismiss);
 *   - literal paths come before the ones with an id and every id is whereNumber. Every id route answers another organisation's id with 404:
 *     FactsTenantSweepTest walks this file's routes and fails for any id route that does not, or that has no rights middleware.
 */
Route::prefix('api/facts')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        foreach (FactEntities::TYPES as $type) {
            Route::get("/entity/{$type}/{id}", [FactsController::class, 'entity'])->whereNumber('id')->defaults('entityType', $type)->middleware(FactAccess::middleware($type, 'view'));
        }

        Route::get('/queue', [FactsController::class, 'queue'])->middleware(FactAccess::middlewareAny('view'));

        Route::post('/{id}/accept', [FactsController::class, 'accept'])->whereNumber('id')->middleware(FactAccess::middlewareAny('edit'));
        Route::post('/{id}/dismiss', [FactsController::class, 'dismiss'])->whereNumber('id')->middleware(FactAccess::middlewareAny('edit'));
    });

Route::prefix('api/consent')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        foreach (FactEntities::TYPES as $type) {
            Route::get("/{$type}/{id}", [ConsentController::class, 'show'])->whereNumber('id')->defaults('entityType', $type)->middleware(FactAccess::middleware($type, 'view'));
        }
    });
