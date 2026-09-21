<?php

use App\Http\Controllers\AI\AiConfigurationController;
use App\Http\Controllers\AI\AiPolicyController;
use App\Http\Controllers\AI\AiTemplateController;
use App\Http\Controllers\AI\AskController;
use App\Http\Controllers\AI\CapabilityController;
use App\Http\Controllers\AI\EvaluationController;
use App\Http\Controllers\AI\RecommendationController;
use App\Http\Controllers\AI\UsageController;
use App\Http\Middleware\AiAuth;
use App\Http\Middleware\AiContextHydrator;
use App\Http\Middleware\AiRateLimit;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI & Intelligence API
|--------------------------------------------------------------------------
|
| Every route here sits behind the same middleware stack, in this order and for
| these reasons:
|
|   AiAuth              validates the Sanctum token and resolves who is calling.
|   profile:admin       restricts the console to administrators — see below.
|   AiRateLimit         throttles per user, which is only possible after AiAuth.
|   AiContextHydrator   turns that identity into the scope the controllers read.
|
| Controllers read the organisation from that scope and never from request input,
| so an authenticated caller cannot name someone else's organisation. A route
| added to this file without the stack would have no scope at all and fail loudly
| rather than quietly reading a tenant from a query string.
|
| Mirrors LMS K-12's `routes/ai.php` deliberately: same prefix, same paths, same
| response envelope. What differs is what is behind them — G2G's own tables — and
| which capabilities exist, because the generation, workspace and agent-execution
| routes LMS also serves have no G2G implementation and are therefore not
| declared. A route that 500s is worse than a route that is honestly absent.
|
| Registered in bootstrap/app.php beside the other route files, so no existing
| route file is touched.
|
*/

/*
| WHY THE WHOLE GROUP IS ADMIN-ONLY
|
| These screens configure the AI that every module then uses: they hold provider
| credentials, decide which model a module calls, carry the prompt text sent on
| behalf of the organisation, and say what the AI is permitted to do. None of
| that is an ordinary employee's to read, let alone to change — a credential
| preview is still a credential fact, and a retired policy is a control removed.
|
| `profile:admin` is the same gate `RequireProfile` applies to G2G's other
| privileged writes, resolved from `tbluserprofilemaster.role_key`. Applying it
| to the group rather than per route is deliberate: a route added to this file
| later inherits the gate instead of being born open, which is how the 25
| unguarded routes that `RequireApiToken` exists for came about.
|
| It sits after AiAuth so an unauthenticated caller gets 401 rather than 403 —
| "who are you" is a different answer from "not you".
*/
Route::prefix(config('ai.route_prefix', 'api/ai'))
    ->middleware(['api', AiAuth::class, 'profile:admin', AiRateLimit::class, AiContextHydrator::class])
    ->group(function () {

        /*
        | The AI & Intelligence console.
        |
        | The twelve capabilities behind the AI & Intelligence menu, each reported
        | for the organisation in the caller's own token. Read-only: this is where
        | an administrator sees what is configured and what the AI has done, and
        | the screens that change any of it are the routes below.
        */
        Route::get('/capabilities', [CapabilityController::class, 'index']);
        Route::get('/capabilities/{capability}', [CapabilityController::class, 'show'])
            ->where('capability', '[a-z0-9\-]+');

        /*
        | AI Providers & Model Management — the write half of the console.
        |
        | `configuration/options` is the one call the Add/Edit form makes to
        | populate its three dropdowns (module → provider → model), and the rest
        | are the CRUD behind them. Writes are stamped with the organisation from
        | the caller's token, never from input.
        |
        | Model Management writes to the same catalogue the provider screen's model
        | dropdown reads, so a model added here is immediately selectable there —
        | one list, not two.
        */
        Route::get('/configuration/options', [AiConfigurationController::class, 'options']);
        Route::get('/configuration', [AiConfigurationController::class, 'index']);
        Route::post('/configuration', [AiConfigurationController::class, 'store']);
        Route::put('/configuration/{id}', [AiConfigurationController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::delete('/configuration/{id}', [AiConfigurationController::class, 'destroy'])
            ->where('id', '[0-9]+');

        Route::get('/configuration-models', [AiConfigurationController::class, 'models']);
        Route::post('/configuration-models', [AiConfigurationController::class, 'storeModel']);
        Route::put('/configuration-models/{id}', [AiConfigurationController::class, 'updateModel'])
            ->where('id', '[0-9]+');

        /*
        | Template Management — module-wise AI templates, managed centrally.
        |
        | One set of routes for every module. `index` filters by `module_key` and
        | nothing else here mentions a module at all, because the module is a field
        | on the record rather than a branch in the code — which is what lets a
        | module in `ai_modules` show up in the selector without a route, a
        | controller or a screen of its own.
        */
        Route::get('/templates/options', [AiTemplateController::class, 'options']);
        // Before `/{id}`, or "preview" is matched as an id and rejected by the
        // numeric constraint rather than reaching the handler.
        Route::post('/templates/preview', [AiTemplateController::class, 'preview']);
        Route::get('/templates', [AiTemplateController::class, 'index']);
        Route::post('/templates', [AiTemplateController::class, 'store']);
        Route::get('/templates/{id}', [AiTemplateController::class, 'show'])
            ->where('id', '[0-9]+');
        Route::put('/templates/{id}', [AiTemplateController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::delete('/templates/{id}', [AiTemplateController::class, 'destroy'])
            ->where('id', '[0-9]+');

        /*
        | Conversational AI — the assistant.
        |
        | `ask` is the only route here that spends money, and the only one that
        | writes a transcript. `conversations` and `conversations/{id}` are the
        | reads behind the capability screen, and `grounding-context` shows what
        | the assistant was actually told about this organisation — so the claim
        | that it is grounded can be checked rather than taken on trust.
        |
        | There is deliberately no `/ask/stream`. See AskController.
        */
        Route::post('/ask', [AskController::class, 'ask']);
        Route::get('/grounding-context', [AskController::class, 'groundingContext']);
        Route::get('/conversations', [AskController::class, 'conversations']);
        Route::get('/conversations/{conversation}', [AskController::class, 'conversation'])
            ->whereNumber('conversation');

        /*
        | Recommendation Engine — the approval gate.
        |
        | `pending` is the work queue; `show` returns the reasoning step and the
        | evidence behind one recommendation, because a recommendation without its
        | explanation is an instruction from nowhere.
        |
        | Ids are UUIDs, so the constraint is a uuid shape rather than a number.
        | Without it `/recommendations/pending` would be matched as an id.
        */
        Route::get('/recommendations/pending', [RecommendationController::class, 'pending']);
        Route::get('/recommendations', [RecommendationController::class, 'index']);
        Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])
            ->where('recommendation', '[0-9a-fA-F\-]{36}');
        Route::post('/recommendations/{recommendation}/approve', [RecommendationController::class, 'approve'])
            ->where('recommendation', '[0-9a-fA-F\-]{36}');
        Route::post('/recommendations/{recommendation}/reject', [RecommendationController::class, 'reject'])
            ->where('recommendation', '[0-9a-fA-F\-]{36}');
        Route::post('/recommendations/{recommendation}/defer', [RecommendationController::class, 'defer'])
            ->where('recommendation', '[0-9a-fA-F\-]{36}');

        /*
        | AI Evaluation — test sets and scores.
        |
        | `run` is the expensive one: one provider call per case, synchronously.
        | See EvaluationController for why it is bounded rather than queued.
        */
        Route::get('/evaluations/options', [EvaluationController::class, 'options']);
        Route::get('/evaluations', [EvaluationController::class, 'index']);
        Route::post('/evaluations', [EvaluationController::class, 'store']);
        Route::get('/evaluations/{evaluation}', [EvaluationController::class, 'show'])
            ->whereNumber('evaluation');
        Route::post('/evaluations/{evaluation}/run', [EvaluationController::class, 'run'])
            ->whereNumber('evaluation');
        Route::delete('/evaluations/{evaluation}', [EvaluationController::class, 'destroy'])
            ->whereNumber('evaluation');

        /*
        | Usage & Cost — what the AI spent, and the quota that bounds it.
        |
        | All reads except the quota write. Metering itself is not a route: it
        | happens inside AiModelClient, which is the only place a model call is
        | made, so nothing can spend without being counted.
        */
        Route::get('/usage/options', [UsageController::class, 'options']);
        Route::get('/usage', [UsageController::class, 'summary']);
        Route::get('/usage/events', [UsageController::class, 'events']);
        Route::post('/usage/quota', [UsageController::class, 'saveQuota']);

        /*
        | AI Policies — what the AI is permitted to do, and where.
        */
        Route::get('/policies/options', [AiPolicyController::class, 'options']);
        Route::get('/policies', [AiPolicyController::class, 'index']);
        Route::post('/policies', [AiPolicyController::class, 'store']);
        Route::put('/policies/{id}', [AiPolicyController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::delete('/policies/{id}', [AiPolicyController::class, 'destroy'])
            ->where('id', '[0-9]+');
    });
