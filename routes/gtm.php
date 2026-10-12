<?php

use App\Http\Controllers\Api\Gtm\CampaignController;
use App\Http\Controllers\Api\Gtm\CustomerController;
use App\Http\Controllers\Api\Gtm\InboundController;
use App\Http\Controllers\Api\Gtm\MonitoringController;
use App\Http\Controllers\Api\Gtm\OutreachController;
use App\Http\Controllers\Api\Gtm\ReportController;
use App\Http\Controllers\Api\Gtm\SuppressionController;
use App\Http\Controllers\Api\Gtm\WorkflowController;
use App\Http\Controllers\Api\Gtm\RenewalController;
use App\Http\Controllers\Api\Gtm\SegmentController;
use Illuminate\Support\Facades\Route;

/*
 * GTM routes added after the foundation (accounts, playbooks, agents, deals, outreach, import,
 * forecast). This file is `require`d INSIDE the `gtm` prefix group in routes/api.php, so every route
 * here already sits behind `api.token` and the /api/gtm prefix.
 *
 * Reads: Administrator + Executive. Writes: Administrator only. The tenant always comes from the token.
 * Literal paths are declared before `/{id}` and every `{id}` is whereNumber.
 */

Route::middleware('profile:admin,executive')->group(function () {
    Route::get('/segments', [SegmentController::class, 'index']);
    Route::get('/segments/{id}/preview', [SegmentController::class, 'preview'])->whereNumber('id');
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/alerts', [CustomerController::class, 'alerts']);
    Route::get('/customers/{accountId}', [CustomerController::class, 'show'])->whereNumber('accountId');
    Route::get('/renewals', [RenewalController::class, 'index']);
    Route::get('/outreach/{id}/attempts', [OutreachController::class, 'attempts'])->whereNumber('id');
    Route::get('/suppressions', [SuppressionController::class, 'index']);
    Route::get('/workflows/catalog', [WorkflowController::class, 'catalog']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/monitoring', [MonitoringController::class, 'show'])->middleware('throttle:30,1');
    Route::get('/reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z_]+');
    Route::get('/reports/{key}/export', [ReportController::class, 'export'])->where('key', '[a-z_]+')->middleware('throttle:20,1');
    Route::get('/workflows', [WorkflowController::class, 'index']);
    Route::get('/workflows/{id}', [WorkflowController::class, 'show'])->whereNumber('id');
    Route::get('/workflow-runs', [WorkflowController::class, 'runs']);
    Route::get('/workflow-runs/{id}', [WorkflowController::class, 'run'])->whereNumber('id');
});

Route::middleware('profile:admin')->group(function () {
    Route::post('/segments', [SegmentController::class, 'store']);
    Route::patch('/segments/{id}', [SegmentController::class, 'update'])->whereNumber('id');
    Route::delete('/segments/{id}', [SegmentController::class, 'destroy'])->whereNumber('id');
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::patch('/campaigns/{id}', [CampaignController::class, 'update'])->whereNumber('id');
    Route::post('/campaigns/{id}/status', [CampaignController::class, 'status'])->whereNumber('id');
    Route::post('/campaigns/{id}/members', [CampaignController::class, 'addMembers'])->whereNumber('id');
    Route::delete('/campaigns/{id}/members/{memberId}', [CampaignController::class, 'removeMember'])->whereNumber('id')->whereNumber('memberId');
    Route::post('/renewals', [RenewalController::class, 'store']);
    Route::patch('/renewals/{id}', [RenewalController::class, 'update'])->whereNumber('id');
    Route::post('/renewals/{id}/status', [RenewalController::class, 'status'])->whereNumber('id');
    Route::delete('/renewals/{id}', [RenewalController::class, 'destroy'])->whereNumber('id');
    Route::post('/outreach/{id}/unschedule', [OutreachController::class, 'unschedule'])->whereNumber('id');
    Route::post('/outreach/sequences/{key}/submit', [OutreachController::class, 'sequenceSubmit'])->where('key', '[a-z0-9]{10,40}');
    Route::post('/outreach/sequences/{key}/approve', [OutreachController::class, 'sequenceApprove'])->where('key', '[a-z0-9]{10,40}');
    Route::post('/outreach/sequences/{key}/cancel', [OutreachController::class, 'sequenceCancel'])->where('key', '[a-z0-9]{10,40}');
    Route::post('/suppressions', [SuppressionController::class, 'store']);
    Route::post('/workflows', [WorkflowController::class, 'store']);
    Route::patch('/workflows/{id}', [WorkflowController::class, 'update'])->whereNumber('id');
    Route::post('/workflows/{id}/status', [WorkflowController::class, 'status'])->whereNumber('id');
    Route::post('/workflows/{id}/test', [WorkflowController::class, 'test'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/workflow-runs/{id}/approve', [WorkflowController::class, 'approve'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/reject', [WorkflowController::class, 'reject'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/retry', [WorkflowController::class, 'retry'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/cancel', [WorkflowController::class, 'cancel'])->whereNumber('id');
    Route::delete('/suppressions/{id}', [SuppressionController::class, 'destroy'])->whereNumber('id');
    Route::post('/contacts/{contactId}/record-event', [InboundController::class, 'record'])->whereNumber('contactId')->middleware('throttle:60,1');
});
