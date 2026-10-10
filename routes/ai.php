<?php

use App\Http\Controllers\AI\ActionRequestController;
use App\Http\Controllers\AI\AiConfigurationController;
use App\Http\Controllers\AI\AiGenerationController;
use App\Http\Controllers\AI\AiModuleController;
use App\Http\Controllers\AI\AiModuleExampleController;
use App\Http\Controllers\AI\AiModuleModelController;
use App\Http\Controllers\AI\AiReportController;
use App\Http\Controllers\AI\AiToolAgentController;
use App\Http\Controllers\AI\AiPolicyController;
use App\Http\Controllers\AI\AiTemplateController;
use App\Http\Controllers\AI\AskController;
use App\Http\Controllers\AI\CapabilityController;
use App\Http\Controllers\AI\ChatArtifactsController;
use App\Http\Controllers\AI\EvaluationController;
use App\Http\Controllers\AI\RecommendationController;
use App\Http\Controllers\AI\UsageController;
use App\Http\Controllers\AI\WorkspaceController;
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
| ── WHY THIS FILE IS NO LONGER ONE BLANKET `profile:admin` GROUP ────────────
|
| It used to be. That meant the twelve capabilities behind the central AI &
| Intelligence console (the second section of the avatar-icon dropdown menu,
| alongside Platform Services) could never be opened by anyone but a literal
| `administrator` — an admin screen meant to let an admin decide who reaches
| these consoles was itself hardcoded to admins-only, the identical defect
| `routes/platform.php` had and was fixed for the same reason. Real
| `tblmenumaster_g2g` rows now exist for eleven of the twelve (Agent Management
| rides its own pre-existing real row instead — see below); an admin grants
| access to any of them through Role & Permissions like any other screen.
|
| Below, the twelve-capability console (Set A) moves onto `platformright` —
| the same middleware and mechanism `routes/platform.php` uses, generalized
| slightly (see RequirePlatformRight's own docblock) to read a capability
| slug from a ROUTE parameter rather than a query string, and to accept an
| "any of N" list for the two endpoints that genuinely serve more than one
| capability at once.
|
| A SEPARATE set of routes in this same file (Set B, clearly marked below) —
| the decentralised, per-business-module "AI Stack" embedded inside each
| module's own UI (HRMS/Talent/etc.'s own AI Stack tab), not reached from the
| central console at all — is NOT part of this change. Nobody asked for it to
| be decentralised and it stays exactly `profile:admin`, unchanged, in its
| own explicit group below, specifically so restructuring the rest of this
| file cannot accidentally leave it ungated.
|
*/

/*
| The twelve AI & Intelligence capability slugs (`CapabilityController::TABLES`'s
| own keys, confirmed to match `AI_CAPABILITIES`'s `slug` field on the frontend
| exactly) mapped to the real `tblmenumaster_g2g` access_link each rides.
| `agents` is the one exception — it reuses a real, pre-existing menu row
| (Agentic AI's own module screen) rather than a new one, the same reuse
| pattern Platform Services uses for RBAC/Onboarding/Mobile App Rights.
*/
$aiCapabilityLinks = [
    'providers' => '/ai/providers',
    'models' => '/ai/models',
    'prompts' => '/ai/prompts',
    'policies' => '/ai/policies',
    'agents' => '/module/agentic-ai/agentic-library',
    'conversational-ai' => '/ai/conversational-ai',
    'knowledge-rag' => '/ai/knowledge-rag',
    'recommendations' => '/ai/recommendations',
    'knowledge-graph' => '/ai/knowledge-graph',
    'evaluation' => '/ai/evaluation',
    'usage-cost' => '/ai/usage-cost',
    'audit' => '/ai/audit',
];
$anyAiCapability = implode('|', $aiCapabilityLinks);
$allAiCapabilitySlugs = implode('|', array_keys($aiCapabilityLinks));

Route::prefix(config('ai.route_prefix', 'api/ai'))
    ->middleware(['api', AiAuth::class, AiRateLimit::class, AiContextHydrator::class])
    ->group(function () use ($aiCapabilityLinks, $allAiCapabilitySlugs, $anyAiCapability) {

        /*
        | ════════════════════════════════════════════════════════════════
        | SET A — the twelve-capability AI & Intelligence console
        | ════════════════════════════════════════════════════════════════
        */

        /*
        | The AI & Intelligence console index — every capability, summarised.
        |
        | Genuinely serves all twelve at once (it's the table the console index
        | renders), so the gate is "any of the twelve" rather than one capability —
        | the same shape Platform Services' own `/registry` uses.
        */
        Route::middleware('platformright:' . $anyAiCapability . ',view')->group(function () {
            Route::get('/capabilities', [CapabilityController::class, 'index']);
        });

        /*
        | One capability's own summary. `agents` needs an EXCEPTION, not a
        | second route: Laravel keys a route by its URI template alone, so
        | two `Route::get('/capabilities/{capability}', ...)` calls differing
        | only by `where()` do not coexist as tried-in-order alternatives —
        | confirmed directly, the second silently discarded the first with no
        | error. `!agents=...` (see RequirePlatformRight's own docblock)
        | overrides just that one value's resolved link to the real Agentic
        | AI Library screen instead of the generic `/ai/agents` pattern,
        | which doesn't exist as its own row.
        */
        Route::middleware('platformright:/ai/{capability}!agents=' . $aiCapabilityLinks['agents'] . ',view')->group(function () use ($allAiCapabilitySlugs) {
            Route::get('/capabilities/{capability}', [CapabilityController::class, 'show'])
                ->where('capability', $allAiCapabilitySlugs);
        });

        /*
        | AI Providers & Model Management — the write half of the console.
        |
        | `configuration/options` is the one call the Add/Edit form makes to
        | populate its three dropdowns (module → provider → model) — it serves
        | BOTH the Providers and Model Management screens at once, so it's
        | gated on either, not just one. The rest are the CRUD behind each
        | screen individually. Writes are stamped with the organisation from
        | the caller's token, never from input.
        |
        | Model Management writes to the same catalogue the provider screen's model
        | dropdown reads, so a model added here is immediately selectable there —
        | one list, not two.
        */
        Route::middleware('platformright:' . $aiCapabilityLinks['providers'] . '|' . $aiCapabilityLinks['models'] . ',view')->group(function () {
            Route::get('/configuration/options', [AiConfigurationController::class, 'options']);
        });

        Route::middleware('platformright:' . $aiCapabilityLinks['providers'] . ',view')->group(function () {
            Route::get('/configuration', [AiConfigurationController::class, 'index']);
            Route::post('/configuration', [AiConfigurationController::class, 'store']);
            Route::put('/configuration/{id}', [AiConfigurationController::class, 'update'])
                ->where('id', '[0-9]+');
            Route::delete('/configuration/{id}', [AiConfigurationController::class, 'destroy'])
                ->where('id', '[0-9]+');
        });

        Route::middleware('platformright:' . $aiCapabilityLinks['models'] . ',view')->group(function () {
            Route::get('/configuration-models', [AiConfigurationController::class, 'models']);
            Route::post('/configuration-models', [AiConfigurationController::class, 'storeModel']);
            Route::put('/configuration-models/{id}', [AiConfigurationController::class, 'updateModel'])
                ->where('id', '[0-9]+');
        });

        /*
        | Template Management — module-wise AI templates, managed centrally.
        |
        | One set of routes for every module. `index` filters by `module_key` and
        | nothing here mentions a module at all, because the module is a field
        | on the record rather than a branch in the code — which is what lets a
        | module in `ai_modules` show up in the selector without a route, a
        | controller or a screen of its own.
        */
        Route::middleware('platformright:' . $aiCapabilityLinks['prompts'] . ',view')->group(function () {
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
        });

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
        Route::middleware('platformright:' . $aiCapabilityLinks['conversational-ai'] . ',view')->group(function () {
            Route::post('/ask', [AskController::class, 'ask']);
            // The page the chat is opened on: its module and the questions worth asking there.
            // GET for a bare page; POST when the browser also sends what it read off the page.
            Route::match(['get', 'post'], '/workspace/context', [WorkspaceController::class, 'context']);
            // Approval ledger for actions proposed from a page. The browser performs the action;
            // these routes hold the decision and the lifecycle.
            Route::get('/action-requests', [ActionRequestController::class, 'index']);
            Route::post('/action-requests', [ActionRequestController::class, 'store']);
            Route::get('/action-requests/{actionRequest}', [ActionRequestController::class, 'show'])->whereNumber('actionRequest');
            Route::post('/action-requests/{actionRequest}/resolve', [ActionRequestController::class, 'resolve'])->whereNumber('actionRequest');
            Route::post('/action-requests/{actionRequest}/claim', [ActionRequestController::class, 'claim'])->whereNumber('actionRequest');
            Route::post('/action-requests/{actionRequest}/complete', [ActionRequestController::class, 'complete'])->whereNumber('actionRequest');
            Route::post('/action-requests/{actionRequest}/cancel', [ActionRequestController::class, 'cancel'])->whereNumber('actionRequest');
            // Reports and templates the chat can offer or build for the module it is open in.
            Route::get('/chat/report-suggestions', [ChatArtifactsController::class, 'reportSuggestions']);
            Route::post('/chat/report', [ChatArtifactsController::class, 'report']);
            Route::get('/chat/template-suggestions', [ChatArtifactsController::class, 'templateSuggestions']);
            Route::get('/chat/templates/{id}/preview', [ChatArtifactsController::class, 'templatePreview'])->whereNumber('id');
            // Send a saved report to real people of the tenant (mail gate + admin only), and its history.
            Route::get('/chat/reports', [\App\Http\Controllers\AI\ReportDeliveryController::class, 'recent']);
            Route::get('/chat/report-recipients', [\App\Http\Controllers\AI\ReportDeliveryController::class, 'recipients']);
            Route::post('/chat/reports/{id}/send', [\App\Http\Controllers\AI\ReportDeliveryController::class, 'send'])->whereNumber('id');
            Route::get('/chat/reports/{id}/deliveries', [\App\Http\Controllers\AI\ReportDeliveryController::class, 'history'])->whereNumber('id');
            Route::get('/grounding-context', [AskController::class, 'groundingContext']);
            Route::get('/conversations', [AskController::class, 'conversations']);
            Route::get('/conversations/{conversation}', [AskController::class, 'conversation'])
                ->whereNumber('conversation');
        });

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
        Route::middleware('platformright:' . $aiCapabilityLinks['recommendations'] . ',view')->group(function () {
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
        });

        /*
        | AI Evaluation — test sets and scores.
        |
        | `run` is the expensive one: one provider call per case, synchronously.
        | See EvaluationController for why it is bounded rather than queued.
        */
        Route::middleware('platformright:' . $aiCapabilityLinks['evaluation'] . ',view')->group(function () {
            Route::get('/evaluations/options', [EvaluationController::class, 'options']);
            Route::get('/evaluations', [EvaluationController::class, 'index']);
            Route::post('/evaluations', [EvaluationController::class, 'store']);
            Route::get('/evaluations/{evaluation}', [EvaluationController::class, 'show'])
                ->whereNumber('evaluation');
            Route::post('/evaluations/{evaluation}/run', [EvaluationController::class, 'run'])
                ->whereNumber('evaluation');
            Route::delete('/evaluations/{evaluation}', [EvaluationController::class, 'destroy'])
                ->whereNumber('evaluation');
        });

        /*
        | Usage & Cost — what the AI spent, and the quota that bounds it.
        |
        | All reads except the quota write. Metering itself is not a route: it
        | happens inside AiModelClient, which is the only place a model call is
        | made, so nothing can spend without being counted.
        */
        Route::middleware('platformright:' . $aiCapabilityLinks['usage-cost'] . ',view')->group(function () {
            Route::get('/usage/options', [UsageController::class, 'options']);
            Route::get('/usage', [UsageController::class, 'summary']);
            Route::get('/usage/events', [UsageController::class, 'events']);
            Route::post('/usage/quota', [UsageController::class, 'saveQuota']);
        });

        /*
        | AI Policies — what the AI is permitted to do, and where.
        */
        Route::middleware('platformright:' . $aiCapabilityLinks['policies'] . ',view')->group(function () {
            Route::get('/policies/options', [AiPolicyController::class, 'options']);
            Route::get('/policies', [AiPolicyController::class, 'index']);
            Route::post('/policies', [AiPolicyController::class, 'store']);
            Route::put('/policies/{id}', [AiPolicyController::class, 'update'])
                ->where('id', '[0-9]+');
            Route::delete('/policies/{id}', [AiPolicyController::class, 'destroy'])
                ->where('id', '[0-9]+');
        });

        /*
        | ════════════════════════════════════════════════════════════════
        | SET B — decentralised, per-module "AI Stack" screens.
        | ════════════════════════════════════════════════════════════════
        |
        | Reached from WITHIN each business module's own UI (its "AI Stack"
        | tab) — not from the central AI & Intelligence console, not linked
        | from the avatar-icon dropdown at all. Explicitly not part of the
        | centralization work this file's own header comment describes:
        | nobody asked for these to be decentralised, and this group keeps
        | them exactly `profile:admin`, unchanged, so restructuring
        | everything above cannot accidentally leave them ungated.
        */
        Route::middleware('profile:admin')->group(function () {

            /*
            | One module's own AI Stack — the decentralised, LMS_K12-universal screens
            | embedded inside a G2G submodule. Same paths and envelopes as LMS_K12's,
            | answered from G2G's tables, scoped to one `ai_modules` key.
            */
            Route::get('/modules/{module}/usage', [AiModuleController::class, 'usage'])
                ->where('module', '[a-z0-9_\-]+');
            Route::get('/modules/{module}/guardrails', [AiModuleController::class, 'guardrails'])
                ->where('module', '[a-z0-9_\-]+');
            Route::get('/modules/{module}/activity', [AiModuleController::class, 'activity'])
                ->where('module', '[a-z0-9_\-]+');
            Route::post('/modules/{module}/activity', [AiModuleController::class, 'recordActivity'])
                ->where('module', '[a-z0-9_\-]+');
            // The worked "Example" shown at the top of each AI Stack tab, built live, and the
            // guardrail check it runs (records an audit row for the caller).
            Route::get('/modules/{module}/examples', [AiModuleExampleController::class, 'index'])
                ->where('module', '[a-z0-9_\-]+');
            Route::get('/modules/{module}/page-examples', [AiModuleExampleController::class, 'pages'])
                ->where('module', '[a-z0-9_\-]+');
            Route::post('/modules/{module}/examples/guardrail-check', [AiModuleExampleController::class, 'guardrailCheck'])
                ->where('module', '[a-z0-9_\-]+');
            Route::get('/modules/{module}/models', [AiModuleModelController::class, 'index'])
                ->where('module', '[a-z0-9_\-]+');
            Route::put('/modules/{module}/models', [AiModuleModelController::class, 'update'])
                ->where('module', '[a-z0-9_\-]+');
            Route::delete('/modules/{module}/models', [AiModuleModelController::class, 'destroy'])
                ->where('module', '[a-z0-9_\-]+');
            Route::post('/modules/{module}/models/credentials', [AiModuleModelController::class, 'storeCredential'])
                ->where('module', '[a-z0-9_\-]+');
            Route::put('/modules/{module}/models/credentials/{credential}', [AiModuleModelController::class, 'updateCredential'])
                ->where(['module' => '[a-z0-9_\-]+', 'credential' => '[0-9]+']);

            /*
            | Reports built from a module's AI Stack Templates tab, and the read-only data
            | sources they (and the Knowledge Base "Check", and Automations agents) run.
            */
            Route::post('/workspace/report', [AiReportController::class, 'build']);
            Route::get('/reports/{id}', [AiReportController::class, 'show'])->where('id', '[0-9]+');
            Route::put('/reports/{id}', [AiReportController::class, 'update'])->where('id', '[0-9]+');
            Route::post('/reports/{id}/regenerate', [AiReportController::class, 'regenerate'])->where('id', '[0-9]+');
            Route::post('/data-sources/{name}/run', [AiReportController::class, 'runSource'])
                ->where('name', '[a-z0-9_.\-]+');

            /*
            | Tool agents for a module's AI Stack Automations tab, stored in G2G's own
            | Agentic AI tables (agentic_agents / agentic_agent_runs).
            */
            Route::get('/tool-agents', [AiToolAgentController::class, 'index']);
            Route::post('/tool-agents', [AiToolAgentController::class, 'store']);
            Route::patch('/tool-agents/{id}', [AiToolAgentController::class, 'setStatus'])->where('id', '[0-9]+');
            Route::post('/tool-agents/{id}/run', [AiToolAgentController::class, 'run'])->where('id', '[0-9]+');
            Route::get('/tool-agent-runs', [AiToolAgentController::class, 'runs']);

            /*
            | Render one published prompt template and return what the model wrote — the
            | AI Stack field assistant's backend. Goes through AiModelClient, so it is
            | resolved, quota-checked and metered like every other call.
            */
            Route::post('/generate', [AiGenerationController::class, 'generate']);
        });
    });
