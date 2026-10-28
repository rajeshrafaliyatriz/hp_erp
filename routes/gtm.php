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
use App\Support\ModuleGate;
use Illuminate\Support\Facades\Route;

/*
 * GTM routes added after the foundation (accounts, playbooks, agents, deals, outreach, import,
 * forecast). This file is `require`d INSIDE the `gtm` prefix group in routes/api.php, so every route
 * here already sits behind `api.token` and the /api/gtm prefix.
 *
 * Sales & Marketing unification, Phase 2: middleware is now resolved per route via
 * App\Support\ModuleGate::gtm($accessLink, $action), grouped by the Phase 1 menu row each
 * route logically belongs to. With `gtm.permissions.use_menu_rights` false (the default),
 * every ModuleGate::gtm() call below still resolves to exactly 'profile:admin,executive'
 * (view) or 'profile:admin' (any mutating action) - identical to the original blanket gate,
 * confirmed by ModuleGate's own fallback. See Docs/sales-marketing-merge/DECISIONS.md for
 * why one action per original profile: group, not per HTTP verb, was the correct choice here.
 * Literal paths are declared before `/{id}` and every `{id}` is whereNumber.
 */

// Segments + Campaigns -> the Campaigns screen (/gtm/campaigns): segments exist to build campaign audiences.
Route::middleware(ModuleGate::gtm('/gtm/campaigns', 'view'))->group(function () {
    Route::get('/segments', [SegmentController::class, 'index']);
    Route::get('/segments/{id}/preview', [SegmentController::class, 'preview'])->whereNumber('id');
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');
});

Route::middleware(ModuleGate::gtm('/gtm/campaigns', 'add'))->group(function () {
    Route::post('/segments', [SegmentController::class, 'store']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
});

Route::middleware(ModuleGate::gtm('/gtm/campaigns', 'edit'))->group(function () {
    Route::patch('/segments/{id}', [SegmentController::class, 'update'])->whereNumber('id');
    Route::patch('/campaigns/{id}', [CampaignController::class, 'update'])->whereNumber('id');
    Route::post('/campaigns/{id}/status', [CampaignController::class, 'status'])->whereNumber('id');
    Route::post('/campaigns/{id}/members', [CampaignController::class, 'addMembers'])->whereNumber('id');
    Route::delete('/campaigns/{id}/members/{memberId}', [CampaignController::class, 'removeMember'])->whereNumber('id')->whereNumber('memberId');
});

Route::delete('/segments/{id}', [SegmentController::class, 'destroy'])->whereNumber('id')->middleware(ModuleGate::gtm('/gtm/campaigns', 'delete'));

// Customers + Renewals -> the Customers screen (/gtm/customers): renewals are customer lifecycle events.
Route::middleware(ModuleGate::gtm('/gtm/customers', 'view'))->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/alerts', [CustomerController::class, 'alerts']);
    Route::get('/customers/{accountId}', [CustomerController::class, 'show'])->whereNumber('accountId');
    Route::get('/renewals', [RenewalController::class, 'index']);
});

Route::post('/renewals', [RenewalController::class, 'store'])->middleware(ModuleGate::gtm('/gtm/customers', 'add'));

Route::middleware(ModuleGate::gtm('/gtm/customers', 'edit'))->group(function () {
    Route::patch('/renewals/{id}', [RenewalController::class, 'update'])->whereNumber('id');
    Route::post('/renewals/{id}/status', [RenewalController::class, 'status'])->whereNumber('id');
});

Route::delete('/renewals/{id}', [RenewalController::class, 'destroy'])->whereNumber('id')->middleware(ModuleGate::gtm('/gtm/customers', 'delete'));

// Outreach + Suppressions -> the Outreach screen (/gtm/outreach): suppression is outreach compliance.
Route::middleware(ModuleGate::gtm('/gtm/outreach', 'view'))->group(function () {
    Route::get('/outreach/{id}/attempts', [OutreachController::class, 'attempts'])->whereNumber('id');
    Route::get('/suppressions', [SuppressionController::class, 'index']);
});

Route::post('/suppressions', [SuppressionController::class, 'store'])->middleware(ModuleGate::gtm('/gtm/outreach', 'add'));

Route::middleware(ModuleGate::gtm('/gtm/outreach', 'edit'))->group(function () {
    Route::post('/outreach/{id}/unschedule', [OutreachController::class, 'unschedule'])->whereNumber('id');
    Route::post('/outreach/sequences/{key}/submit', [OutreachController::class, 'sequenceSubmit'])->where('key', '[a-z0-9]{10,40}');
    Route::post('/outreach/sequences/{key}/approve', [OutreachController::class, 'sequenceApprove'])->where('key', '[a-z0-9]{10,40}');
    Route::post('/outreach/sequences/{key}/cancel', [OutreachController::class, 'sequenceCancel'])->where('key', '[a-z0-9]{10,40}');
});

Route::delete('/suppressions/{id}', [SuppressionController::class, 'destroy'])->whereNumber('id')->middleware(ModuleGate::gtm('/gtm/outreach', 'delete'));

// Workflows + Workflow Runs -> the Workflows screen (/gtm/workflows).
Route::middleware(ModuleGate::gtm('/gtm/workflows', 'view'))->group(function () {
    Route::get('/workflows/catalog', [WorkflowController::class, 'catalog']);
    Route::get('/workflows', [WorkflowController::class, 'index']);
    Route::get('/workflows/{id}', [WorkflowController::class, 'show'])->whereNumber('id');
    Route::get('/workflow-runs', [WorkflowController::class, 'runs']);
    Route::get('/workflow-runs/{id}', [WorkflowController::class, 'run'])->whereNumber('id');
});

Route::post('/workflows', [WorkflowController::class, 'store'])->middleware(ModuleGate::gtm('/gtm/workflows', 'add'));

Route::middleware(ModuleGate::gtm('/gtm/workflows', 'edit'))->group(function () {
    Route::patch('/workflows/{id}', [WorkflowController::class, 'update'])->whereNumber('id');
    Route::post('/workflows/{id}/status', [WorkflowController::class, 'status'])->whereNumber('id');
    Route::post('/workflows/{id}/test', [WorkflowController::class, 'test'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/workflow-runs/{id}/approve', [WorkflowController::class, 'approve'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/reject', [WorkflowController::class, 'reject'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/retry', [WorkflowController::class, 'retry'])->whereNumber('id');
    Route::post('/workflow-runs/{id}/cancel', [WorkflowController::class, 'cancel'])->whereNumber('id');
});

// Reports -> the Reports screen (/gtm/reports).
Route::middleware(ModuleGate::gtm('/gtm/reports', 'view'))->group(function () {
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z_]+');
    Route::get('/reports/{key}/export', [ReportController::class, 'export'])->where('key', '[a-z_]+')->middleware('throttle:20,1');
});

// Monitoring -> the Monitoring screen (/gtm/monitoring).
Route::get('/monitoring', [MonitoringController::class, 'show'])->middleware([ModuleGate::gtm('/gtm/monitoring', 'view'), 'throttle:30,1']);

// Inbound event recording against a contact -> the Prospecting screen (/gtm/prospecting), where contacts live.
Route::post('/contacts/{contactId}/record-event', [InboundController::class, 'record'])->whereNumber('contactId')->middleware([ModuleGate::gtm('/gtm/prospecting', 'edit'), 'throttle:60,1']);
