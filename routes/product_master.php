<?php

use App\Http\Controllers\Api\ProductMaster\ProductMasterController;
use Illuminate\Support\Facades\Route;

/*
 * Product Master (slice 1 of the agentic CRM, Docs/agentic-crm/slices/S01-product-master.md).
 *
 * It is the data layer behind the existing Products screen (Sales & Marketing > Products): the screen's product lines, sub-products, Signals counts and
 * "Signals & ICP" / "Usage" tabs read and write through these routes. It has NO menu row of its own (the owner, 2026-11-03: "we already have the product
 * sub module ... you should update it"), so its rights are the Products row's: `/module/crm/sales/products`, checked per action, the same
 * `platformright:<access_link>,<action>` pattern the other CRM entities use in routes/api.php. There is no administrator bypass: a profile needs a rights row
 * on Products. What the caller may do with a given RECORD is then decided inside ProductWriter / ProductAccess (an administrator for a product line, the
 * Portfolio's right for an offer, the Products or Services right for a catalogue item).
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/platform.php and routes/ai.php: these
 * routes are reached by the token-authenticated frontend and a session guard would 401 them.
 *
 * Literal paths come before `/{id}` and every `{id}` is whereNumber.
 */
Route::prefix('api/product-master')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        $productsLink = '/module/crm/sales/products';

        Route::middleware("platformright:{$productsLink},view")->group(function () {
            Route::get('/', [ProductMasterController::class, 'index']);
            Route::get('/lines', [ProductMasterController::class, 'lines']);
            Route::get('/options', [ProductMasterController::class, 'options']);
            Route::get('/meta', [ProductMasterController::class, 'meta']);
            Route::get('/{id}', [ProductMasterController::class, 'show'])->whereNumber('id');
            Route::get('/{id}/usage', [ProductMasterController::class, 'usage'])->whereNumber('id');
        });

        // Writes. The route's right says who may use the Products screen; ProductWriter then checks the rights of the screens it writes THROUGH
        // (an administrator for a product line, the Portfolio's right for an offer, the Products or Services right for a catalogue item).
        Route::post('/', [ProductMasterController::class, 'store'])->middleware("platformright:{$productsLink},add");
        Route::middleware("platformright:{$productsLink},edit")->group(function () {
            Route::put('/{id}', [ProductMasterController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/restore', [ProductMasterController::class, 'restore'])->whereNumber('id');
        });
        Route::post('/{id}/archive', [ProductMasterController::class, 'archive'])->whereNumber('id')->middleware("platformright:{$productsLink},delete");
    });
