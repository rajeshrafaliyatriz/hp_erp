<?php

use App\Http\Controllers\Api\ProductMaster\ProductMasterController;
use App\Http\Controllers\Api\ProductMaster\ProductPickerController;
use App\Http\Controllers\Api\ProductMaster\ProductRemovalController;
use Illuminate\Support\Facades\Route;

/*
 * Product Master (slice 1 of the agentic CRM, Docs/agentic-crm/slices/S01-product-master.md).
 *
 * It is the data layer behind the existing Products screen (Sales & Marketing > Products): the screen's product lines, sub-products, Signals counts and
 * "Signals & ICP" / "Usage" tabs read and write through these routes. It has NO menu row of its own (the owner, 2026-11-03: "we already have the product
 * sub module ... you should update it"), so its rights are the Products row's: `/module/crm/sales/products`, checked per action, the same
 * `platformright:<access_link>,<action>` pattern the other CRM entities use in routes/api.php. There is no administrator bypass: a profile needs a rights row
 * on Products. That holds for the reads, archive and restore. Create and update are the exception (slice 15, P2-C): a Sub-product is ONE record with up to three
 * sections, so they ask only for a signed-in token and ProductWriter / ProductAccess decide by SECTION (an administrator for a product line, the Portfolio's right
 * for an About or an ICP, the Products or Services right for a Sell section).
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/platform.php and routes/ai.php: these
 * routes are reached by the token-authenticated frontend and a session guard would 401 them.
 *
 * Literal paths come before `/{id}` and every `{id}` is whereNumber.
 */
Route::prefix('api/product-master')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        // Reads (slice 15, P2-E1): every one asks only for a signed-in token (the group's `api.token`) and is scoped to the caller's organisation. Names, ids and lines are open to
        // any signed-in user, so HR, who has no row on the Products screen, can open the Portfolio. What is priced or Sell-related is gated INSIDE the controller by the
        // view right of the item's own type (ProductReadRights::canViewSell, the Products row for a product and the Services row for a service): `/list` and `/{id}` leave the
        // Sell fields out, `/{id}/sell`, `/{id}/usage` and `/picker` answer 403, `/{id}/history` leaves the Sell lines out. tests/Feature/ProductMasterRouteMapTest.php
        // lists every route of this file with its right.
        Route::get('/', [ProductMasterController::class, 'index']);
        Route::get('/list', [ProductMasterController::class, 'list']);
        // `lines` and `picker` are served by ProductPickerController (S15 follow-up, 24 Nov 2026): the same answers by default, and the new ?withSubProducts=1 and ?scope=all.
        Route::get('/lines', [ProductPickerController::class, 'lines']);
        Route::get('/options', [ProductMasterController::class, 'options']);
        Route::get('/meta', [ProductMasterController::class, 'meta']);
        Route::get('/picker', [ProductPickerController::class, 'picker']);
        Route::get('/by-crm/{crmId}', [ProductMasterController::class, 'byCrm'])->whereNumber('crmId');
        Route::get('/{id}', [ProductMasterController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/sell', [ProductMasterController::class, 'sell'])->whereNumber('id');
        Route::get('/{id}/history', [ProductMasterController::class, 'history'])->whereNumber('id');
        Route::get('/{id}/usage', [ProductMasterController::class, 'usage'])->whereNumber('id');

        // Writes. Create and update carry up to three SECTIONS (About, Sell, ICP) and are gated by section inside ProductWriter, so these two routes ask only for a signed-in
        // token (the group's `api.token`): a person with no row at all on the Products screen (HR writes the Portfolio) has to reach the writer, which refuses each section
        // the person has no right on with a 403 that names it, and writes nothing (slice 15, P2-C, rule 4). Archive and restore are judged by section too (slice 15, WPA1, D-E1):
        // they used to ask the Products row's delete and edit right, so HR could not archive a Sub-product whose About it owns. ProductWriter::setStatus decides: a Product by
        // an administrator, a record with an About by the Portfolio's right, a Sell-only record by the right of its own type (delete to archive, edit to restore).
        Route::post('/', [ProductMasterController::class, 'store']);
        Route::put('/{id}', [ProductMasterController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/restore', [ProductMasterController::class, 'restore'])->whereNumber('id');
        Route::post('/{id}/archive', [ProductMasterController::class, 'archive'])->whereNumber('id');

        // Delete and move (S15 follow-up, 24 Nov 2026; Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md section 4). The same middleware as archive: a signed-in token only,
        // and the RIGHTS are judged per record inside the domain classes (ProductRemoval, ProductMover), from the record and never from the body: an administrator for a Product, the
        // Portfolio's right and the Sell right of its type for a Sub-product. `removal` is the preview (what blocks it, what hangs under it); `move` takes one or several
        // Sub-products to another Product; `remove` deletes, all or nothing, and a Product says what becomes of its Sub-products.
        Route::get('/{id}/removal', [ProductRemovalController::class, 'removal'])->whereNumber('id');
        Route::post('/move', [ProductRemovalController::class, 'move']);
        Route::post('/{id}/remove', [ProductRemovalController::class, 'remove'])->whereNumber('id');
    });

/*
 * The pair review (slice 15, track P2-D; Docs/agentic-crm/slices/S15-product-portfolio-merge.md section 6): which catalogue items and Sub-products look like the same product, and
 * a person's answer for each pair. A block of its own, appended: nothing above is changed. ADMINISTRATORS ONLY: ProductMatchController checks ProductAccess::isAdministrator
 * (a 403 for anybody else), on top of the Products row's own right here, because the review shows prices and a "same" answer changes what a quote can sell.
 * The literal paths cannot be taken for the `/{id}` routes above (those are whereNumber).
 */
Route::prefix('api/product-master')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        $productsLink = '/module/crm/sales/products';

        Route::get('/matches', [\App\Http\Controllers\Api\ProductMaster\ProductMatchController::class, 'index'])->middleware("platformright:{$productsLink},view");
        Route::post('/matches/decide', [\App\Http\Controllers\Api\ProductMaster\ProductMatchController::class, 'decide'])->middleware("platformright:{$productsLink},edit");
    });
