<?php

use App\Http\Controllers\Api\Intelligence\IntelligenceController;
use App\Http\Middleware\IntelligenceIdentity;
use Illuminate\Support\Facades\Route;

/*
 * G2G Intelligence API. Three read-only GET endpoints; nothing here writes, caches, dispatches or calls a model.
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/agent_gateway.php.
 *
 * ⚠ THE `api` MIDDLEWARE GROUP IS DELIBERATELY NOT APPLIED. That group carries TouchTokenActivity and RequireTwoFactorEnrolment, and both call
 * PersonalAccessTokenResolver::findToken(), which, for a token string it does not recognise, inserts a thirty-day token bound to an administrator (or to the
 * user named by a `user_id` parameter) and treats it as valid. On this route that would turn any string into an authenticated caller BEFORE IntelligenceIdentity
 * runs. IntelligenceIdentity therefore authenticates by itself, with a strict lookup that never provisions (SanctumIntelligenceTokenAuthenticator).
 * Consequence, recorded for decision: the organisation's "require two-step verification" policy and the sliding session expiry are NOT applied to these routes.
 *
 * Every route is GET. IntelligenceRouteSweepTest fails the build if one is added that is not GET or lacks IntelligenceIdentity.
 */
Route::prefix('api/intelligence')
    // THROTTLE FIRST. The order is the point: a limiter placed after IntelligenceIdentity never sees a request that fails authentication, so invalid-token
    // traffic (the traffic that most needs limiting) would be unlimited. Counted per client address before the token is looked at.
    ->middleware(['throttle:30,1', IntelligenceIdentity::class])
    ->group(function () {
        Route::get('/overview', [IntelligenceController::class, 'overview']);
        Route::get('/insights', [IntelligenceController::class, 'insights']);
        Route::get('/detectors', [IntelligenceController::class, 'detectors']);
    });
