<?php

use App\Domain\AgentGateway\AgentAccess;
use App\Http\Controllers\Api\Agent\AgentGatewayController;
use Illuminate\Support\Facades\Route;

/*
 * The agent gateway (slice 7, thin, of the agentic CRM: the Organisation agent, Docs/agentic-crm/slices/S07-agent-gateway-thin.md).
 *
 * Declares its own prefix and middleware, so it is registered bare in bootstrap/app.php, like routes/org_accounts.php: these routes are reached by the
 * token-authenticated frontend and a session guard would 401 them.
 *
 * Rights come from the CRM menu rows, found by access_link and checked per action, with no administrator bypass:
 *   - an organisation's agent state: VIEW on CRM Organizations (the Marketing row OR the Support row, as the CRM's own organisation routes accept either)
 *   - run the agent, accept, dismiss: EDIT on CRM Organizations
 *   - the runs list and one run: VIEW on Agent Run History
 *   - the waiting queue (S7b) and the autonomy settings: VIEW on Agent Run History to read; EDIT on it AND an administrator to change (the controller checks that)
 * Literal paths come before the ones with an id and every id is whereNumber. Every id route answers another organisation's id with 404:
 * AgentGatewayTenantSweepTest walks this file's routes and fails for any id route that does not, or that has no rights middleware.
 */
Route::prefix('api/agent')
    ->middleware(['api', 'api.token'])
    ->group(function () {
        $organizations = 'platformright:' . AgentAccess::ORG_RIGHT;
        $history = 'platformright:' . AgentAccess::RUN_HISTORY;

        Route::get('/organisations/resolve', [AgentGatewayController::class, 'resolve'])->middleware($organizations . ',view');
        Route::get('/organisations/{orgAccountId}', [AgentGatewayController::class, 'organisation'])->whereNumber('orgAccountId')->middleware($organizations . ',view');
        Route::post('/organisations/{orgAccountId}/run', [AgentGatewayController::class, 'run'])->whereNumber('orgAccountId')->middleware($organizations . ',edit');

        Route::post('/proposals/{id}/accept', [AgentGatewayController::class, 'accept'])->whereNumber('id')->middleware($organizations . ',edit');
        Route::post('/proposals/{id}/dismiss', [AgentGatewayController::class, 'dismiss'])->whereNumber('id')->middleware($organizations . ',edit');

        Route::get('/runs', [AgentGatewayController::class, 'runs'])->middleware($history . ',view');
        Route::get('/runs/{id}', [AgentGatewayController::class, 'showRun'])->whereNumber('id')->middleware($history . ',view');

        // S7b. The waiting queue and the autonomy settings live on the Agent Run History screen (tabs), so they take its row: reading is VIEW, changing is
        // EDIT, and changing needs an administrator on top (checked in the controller: HR holds EDIT on the history row on tenant 6). A DECISION on a queued
        // proposal is not here: it is the accept / dismiss above, with the Organizations right. `{actionKey}` is a key from config/agent_actions.php, not an
        // id: the controller answers a key that is not an action with 404, whatever it is made of.
        Route::get('/proposals', [AgentGatewayController::class, 'proposals'])->middleware($history . ',view');
        Route::get('/autonomy', [AgentGatewayController::class, 'autonomy'])->middleware($history . ',view');
        Route::put('/autonomy-kill-switch', [AgentGatewayController::class, 'setKillSwitch'])->middleware($history . ',edit');
        Route::put('/autonomy/{actionKey}', [AgentGatewayController::class, 'setAutonomy'])->middleware($history . ',edit');
    });
