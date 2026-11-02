<?php

use App\Http\Controllers\Api\ProductMaster\ProductMasterController;
use Illuminate\Support\Facades\Route;

/*
 * Product Master (slice 1 of the agentic CRM, Docs/agentic-crm/slices/S01-product-master.md).
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/platform.php and routes/ai.php: these
 * routes are reached by the token-authenticated frontend and a session guard would 401 them.
 *
 * Rights come from the menu row `/module/crm/product-master` (tblgroupwise_rights_g2g, found by access_link), checked per action. There is no
 * administrator bypass: a profile needs a rights row, and `php artisan product-master:grant` gives it to one organisation's administrators.
 * Literal paths come before `/{id}` and every `{id}` is whereNumber.
 */
Route::prefix('api/product-master')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        Route::middleware('platformright:/module/crm/product-master,view')->group(function () {
            Route::get('/', [ProductMasterController::class, 'index']);
            Route::get('/options', [ProductMasterController::class, 'options']);
            Route::get('/{id}', [ProductMasterController::class, 'show'])->whereNumber('id');
            Route::get('/{id}/usage', [ProductMasterController::class, 'usage'])->whereNumber('id');
        });

        // Writes. The route's right says who may use the screen; ProductWriter then checks the rights of the screens it writes THROUGH (an
        // administrator for a product, the Portfolio's right for an offer, the Products or Services right for a catalogue item).
        Route::post('/', [ProductMasterController::class, 'store'])->middleware('platformright:/module/crm/product-master,add');
        Route::middleware('platformright:/module/crm/product-master,edit')->group(function () {
            Route::put('/{id}', [ProductMasterController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/restore', [ProductMasterController::class, 'restore'])->whereNumber('id');
        });
        Route::post('/{id}/archive', [ProductMasterController::class, 'archive'])->whereNumber('id')->middleware('platformright:/module/crm/product-master,delete');
    });
