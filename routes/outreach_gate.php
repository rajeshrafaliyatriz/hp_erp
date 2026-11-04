<?php

use App\Domain\OutreachGate\OutreachGateAccess;
use App\Http\Controllers\Api\Outreach\OutreachClaimsController;
use App\Http\Controllers\Api\Outreach\OutreachGateController;
use Illuminate\Support\Facades\Route;

/*
 * The outreach gate (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md): a preview of the checks of one draft and the approved product claims.
 *
 * NOTHING HERE SENDS, SCHEDULES OR SWITCHES ANYTHING ON. There is no route that sends and none that touches a sending flag; the gate it previews is a reason NOT to send.
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/facts.php: these routes are reached by the token-authenticated
 * frontend and a session guard would 401 them. NO MENU ROW OF ITS OWN (the owner's rule of 3 Nov 2026: no duplicate screens); the rights are those of the screens it
 * serves (App\Domain\OutreachGate\OutreachGateAccess):
 *
 *   GET  /api/outreach/gate?table=&id=   either the Outreach Approvals row or the GTM outreach row (view); the controller then asks for the right of the table asked about.
 *                                        Throttled: every call runs the checks and records a set.
 *   GET  /api/outreach/claims            the Products row, view
 *   POST /api/outreach/claims            the Products row, add (propose)
 *   PUT  /api/outreach/claims/{id}       the Products row, edit (approve or retire)
 *
 * Every id is whereNumber and every id route answers another organisation's id with a 404: OutreachGateRoutesTest walks this file's routes and fails for any route that
 * lacks the token check or a rights middleware, or any id route that does not 404 for another organisation.
 */
Route::prefix('api/outreach')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        Route::get('/gate', [OutreachGateController::class, 'show'])->middleware([OutreachGateAccess::previewMiddleware(), 'throttle:60,1']);

        Route::get('/claims', [OutreachClaimsController::class, 'index'])->middleware(OutreachGateAccess::claimsMiddleware('view'));
        Route::post('/claims', [OutreachClaimsController::class, 'store'])->middleware(OutreachGateAccess::claimsMiddleware('add'));
        Route::put('/claims/{id}', [OutreachClaimsController::class, 'update'])->whereNumber('id')->middleware(OutreachGateAccess::claimsMiddleware('edit'));
    });
