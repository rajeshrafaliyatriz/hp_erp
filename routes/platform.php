<?php

use App\Http\Controllers\Platform\CustomFieldValueController;
use App\Http\Controllers\Platform\EventBusController;
use App\Http\Controllers\Platform\FieldConfigController;
use App\Http\Controllers\Platform\ProcessController;
use App\Http\Controllers\Platform\RegistryController;
use App\Http\Controllers\Platform\SchedulerController;
use App\Http\Controllers\Platform\WorkflowController;
use App\Http\Middleware\AiAuth;
use App\Http\Middleware\AiContextHydrator;
use App\Http\Middleware\AiRateLimit;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Services API
|--------------------------------------------------------------------------
|
| Every route here sits behind the same middleware stack, in this order and for
| these reasons:
|
|   AiAuth                     validates the Sanctum token and resolves who is calling.
|   profile:admin              restricts the console to administrators — see below.
|   AiRateLimit:platform       throttles per user, in its OWN bucket.
|   AiContextHydrator          turns that identity into the scope the controllers read.
|
| Controllers read the organisation from that scope and never from request input, so an
| authenticated caller cannot name someone else's organisation. A route added to this
| file without the stack would have no scope at all and fail loudly — `scope()` raises
| when the hydrator did not run — rather than quietly reading a tenant from a query
| string.
|
| ── WHY THE AI MIDDLEWARE AND NOT A PARALLEL SET ───────────────────────────
|
| `AiAuth` and `AiContextHydrator` are not AI-specific in anything but their names: one
| validates a Sanctum token, the other resolves a tenant scope from it. Writing
| `PlatformAuth` and `PlatformContextHydrator` beside them would be two more classes
| doing the same job, to be kept in step by hand, so that a future fix to token handling
| has two places to be applied and one to be forgotten.
|
| ── `:platform` ON THE RATE LIMITER IS LOAD-BEARING ────────────────────────
|
| The limiter keyed on `'ai:'.$userId` and nothing else. Sharing that bucket would mean
| an administrator browsing the Event Bus spends the AI console's sixty-a-minute, and the
| AI screens then answer 429 for a reason nothing on either screen could explain. The
| parameter was added to the middleware for this route file.
|
| Registered bare in bootstrap/app.php, like routes/ai.php and routes/user-api.php: these
| routes are reached by the token-authenticated frontend and a session guard would 401
| them.
|
*/

/*
| WHY THE WHOLE GROUP IS ADMIN-ONLY
|
| These endpoints report the platform's own operation: every event this organisation has
| recorded, who acted, which consumers are failing and what the server runs on a timer.
| That is an operator's view of the estate's plumbing, not an employee's, and the event
| stream in particular carries actor ids for actions across every module.
|
| `profile:admin` is the same gate `routes/ai.php` applies, resolved from
| `tbluserprofilemaster.role_key`. Applying it to the group rather than per route is
| deliberate: a route added to this file later inherits the gate instead of being born
| open, which is how the 25 unguarded routes that `RequireApiToken` exists for came about.
|
| It sits after AiAuth so an unauthenticated caller gets 401 rather than 403 — "who are
| you" is a different answer from "not you".
|
| The frontend hides these entries for non-administrators as a courtesy. Hiding a button
| is not a control; this is.
*/
Route::prefix('api/platform')
    ->middleware(['api', AiAuth::class, 'profile:admin', AiRateLimit::class . ':platform', AiContextHydrator::class])
    ->group(function () {

        /*
        | Event Bus — read-only, over `g2g_event` and `g2g_event_delivery`.
        |
        | There is deliberately no replay, redrive or publish route. A projector is pure
        | and re-running it is harmless; a reactor enrols people on courses, issues
        | certificates and sends notifications, so replaying one does those things again.
        | `events:project` and `events:react` are separate commands for that reason, and
        | a button on a screen would hand that distinction to whoever clicks it.
        |
        | `/catalogue` and `/options` are declared before nothing in particular — there
        | are no wildcards in this block — but the ordering habit is kept so a future
        | `/{event}` cannot capture them.
        */
        Route::get('/events/summary', [EventBusController::class, 'summary']);
        Route::get('/events/stream', [EventBusController::class, 'stream']);
        Route::get('/events/consumers', [EventBusController::class, 'consumers']);
        Route::get('/events/failures', [EventBusController::class, 'failures']);
        Route::get('/events/catalogue', [EventBusController::class, 'catalogue']);
        Route::get('/events/options', [EventBusController::class, 'options']);

        /*
        | Scheduler — what is registered, when it next runs, and what the queue holds.
        |
        | Reads Laravel's live schedule rather than a list copied out of
        | routes/console.php, so a task added there appears here without an edit. See
        | ScheduleReader for why that matters in this codebase specifically.
        */
        Route::get('/scheduler/tasks', [SchedulerController::class, 'index']);
        Route::post('/scheduler/tasks', [SchedulerController::class, 'save']);

        /*
        | The catalogue every screen renders and every write below validates against.
        |
        | One endpoint over config/platform_services.php, so a screen physically cannot
        | offer a setting the API would refuse — both read the same declaration, and
        | neither has a second copy to drift from.
        */
        Route::get('/registry', [RegistryController::class, 'index']);

        /*
        | Workflow — approval chains against the points the registry declares.
        |
        | A POINT is ours and is declared in config; a CHAIN is the organisation's and
        | lives in g2g_platform_workflows. `points` returns every point INCLUDING the
        | ungoverned ones, because "which of our approvals has nobody signing them off"
        | is the question this screen exists to answer.
        |
        | `/points` before `/{id}` so it is not matched as an id — the numeric constraint
        | would reject it, but at the router rather than the handler, and the resulting
        | 404 would be confusing.
        */
        Route::get('/workflow/points', [WorkflowController::class, 'index']);
        Route::post('/workflow', [WorkflowController::class, 'store']);
        Route::put('/workflow/{id}', [WorkflowController::class, 'update'])->whereNumber('id');
        Route::delete('/workflow/{id}', [WorkflowController::class, 'destroy'])->whereNumber('id');

        /*
        | Fields Configuration — over tblcustom_fields, which has existed for a year
        | with no API in front of it.
        |
        | The table a field may be attached to is checked against an ALLOWLIST in
        | config/platform_services.php. LMS K-12's equivalent validates that field as
        | `required|string|max:50` and then runs ALTER TABLE on it; see
        | FieldConfigController for why this one does not.
        */
        Route::get('/fields', [FieldConfigController::class, 'index']);
        Route::post('/fields', [FieldConfigController::class, 'store']);
        Route::put('/fields/{id}', [FieldConfigController::class, 'update'])->whereNumber('id');
        Route::delete('/fields/{id}', [FieldConfigController::class, 'destroy'])->whereNumber('id');

        /*
        | The ANSWERS, which is what makes the definitions above worth having.
        |
        | A field nobody can fill in is a row in a table. These two endpoints are
        | what a form calls to render a record's custom fields and save them, and
        | the employee record is the first caller.
        |
        | Declared AFTER `/fields/{id}` so `values` cannot be captured as an id —
        | the numeric constraint would reject it, but at the router rather than the
        | handler, and the resulting 404 would be confusing.
        |
        | `{record}` is constrained to word characters: it is a table name, checked
        | against the registry allowlist inside the service, and a path segment that
        | can contain anything is a path segment somebody will try to put a slash in.
        */
        Route::get('/fields/values/{record}/{id}', [CustomFieldValueController::class, 'show'])
            ->where('record', '[a-z_]+')->whereNumber('id');
        Route::post('/fields/values/{record}/{id}', [CustomFieldValueController::class, 'store'])
            ->where('record', '[a-z_]+')->whereNumber('id');

        /*
        | Add Process — a written procedure, turned into tasks somebody can do.
        |
        | `convert` stores NOTHING: somebody pasting a procedure wants to see what
        | was understood before committing to it, and a convert that saved would
        | leave a trail of drafts from people who were only looking.
        |
        | `publish` is the one action here that creates work in other people's
        | queues. It is guarded twice — a unique key on (process, step) and an
        | idempotency key per task — so a retry replays rather than raising a
        | second set. See ProcessController.
        |
        | `/convert` before `/{id}` so it is not captured as an id.
        */
        Route::post('/process/convert', [ProcessController::class, 'convert']);
        Route::get('/process', [ProcessController::class, 'index']);
        Route::post('/process', [ProcessController::class, 'store']);
        Route::get('/process/{id}', [ProcessController::class, 'show'])->whereNumber('id');
        Route::put('/process/{id}', [ProcessController::class, 'update'])->whereNumber('id');
        Route::delete('/process/{id}', [ProcessController::class, 'destroy'])->whereNumber('id');
        Route::post('/process/{id}/publish', [ProcessController::class, 'publish'])->whereNumber('id');
    });
