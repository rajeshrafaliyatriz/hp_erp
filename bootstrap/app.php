<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
   ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['web','auth','session','menu'])
                ->group(base_path('routes/lms.php'));
            // REGISTERED BEFORE user.php, AND THAT ORDER IS THE POINT. These
            // four endpoints are called by the token-authenticated frontend and
            // must not carry the session guard. Declared first, so they win over
            // any same-path definition in user.php below.
            //
            // NOT wrapped in ['auth','session','menu'] — that wrapper is exactly
            // what returned 403 to an admin saving the rights matrix.
            Route::group([], base_path('routes/user-api.php'));

            Route::middleware(['web','auth','session','menu'])
                ->group(base_path('routes/user.php'));
            Route::middleware(['web','auth','session','menu'])
                ->group(base_path('routes/settings.php'));
            Route::middleware(['web','auth','session','menu'])
                ->group(base_path('routes/hrms.php'));
        }
    )
    // ... rest of configuration
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * ═══════════════════════════════════════════════════════════════════
         * KEEP `last_used_at` HONEST, AND EXPIRE ABANDONED SESSIONS
         * ═══════════════════════════════════════════════════════════════════
         *
         * `last_used_at` was NULL on all 4,960 live tokens, so Sign-in & security
         * reported "Never used since it was created" for every session anybody
         * had. Sanctum writes that column in its own Guard, and this application
         * never uses that guard - eight middlewares and several controllers call
         * `PersonalAccessToken::findToken()` directly, which looks up and nothing
         * more.
         *
         * Appended to BOTH groups rather than added to those eight call sites:
         * 167 routes are role-gated without `api.token` or `auth`, so no pair of
         * them covers everything, and eight edits is eight chances to miss the
         * ninth. See TouchTokenActivity for the throttle and the sliding expiry.
         *
         * It never blocks and never throws - a missing timestamp must not be able
         * to 500 somebody's dashboard.
         */
        $middleware->appendToGroup('api', [\App\Http\Middleware\TouchTokenActivity::class]);
        $middleware->appendToGroup('web', [\App\Http\Middleware\TouchTokenActivity::class]);

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE ORGANISATION'S "REQUIRE TWO-STEP VERIFICATION" POLICY
         * ═══════════════════════════════════════════════════════════════════
         *
         * Both groups, for the same reason as above: 167 routes are role-gated
         * without `api.token` or `auth`, so there is no narrower place that covers
         * the product. And the web group matters as much as the API one - a policy
         * enforced only on /api would leave every Blade screen reachable, which is
         * the same half-enforcement that made `can_view` a UI hint.
         *
         * AFTER TouchTokenActivity, deliberately. That one refuses to slide an
         * already-expired token forward, so by the time this runs an expired session
         * is still expired and this gate's own expiry check agrees with it.
         *
         * `security.require_two_factor` is `off` for every tenant by default and
         * this returns early on it, so adding it changes nothing until an
         * administrator deliberately switches it on. It never throws.
         */
        $middleware->appendToGroup('api', [\App\Http\Middleware\RequireTwoFactorEnrolment::class]);
        $middleware->appendToGroup('web', [\App\Http\Middleware\RequireTwoFactorEnrolment::class]);

        $middleware->alias([
            'menu' => \App\Http\Middleware\MenuMiddleware::class,
            'session' => \App\Http\Middleware\SessionMiddleware::class,
            'auth' => \App\Http\Middleware\authMiddleware::class,
            'task.sanitize' => \App\Http\Middleware\TaskSanitizeMiddleware::class,
            // Role-gates the task-management write routes. The routes have
            // declared this alias since they were written; registering it is
            // what makes them dispatch instead of 500ing.
            'task.permission' => \App\Http\Middleware\TaskPermissionMiddleware::class,
            // Requires a valid Sanctum token. Attached in routes/api.php to the
            // routes whose controllers do not authenticate themselves.
            'api.token' => \App\Http\Middleware\RequireApiToken::class,
            // Restricts a route to named user profiles, e.g. 'profile:admin,hr'.
            // Server-side role enforcement outside Task Management.
            'profile' => \App\Http\Middleware\RequireProfile::class,
            // Same gate, but resolves the caller from the session when there is
            // no token. The payroll routes in routes/hrms.php are reached by
            // both the token-authenticated frontend and the session-
            // authenticated Blade screens, so 'profile' alone would 401 the
            // latter - see RequireHritRole.
            'hrit.role' => \App\Http\Middleware\RequireHritRole::class,
            'menuright' => \App\Http\Middleware\RequireMenuRight::class,
            // Acting ABOVE the tenants - creating an organisation. No role_key
            // can authorise this: every role the platform defines is scoped to
            // one organisation. Membership is a row in `platform_owners`.
            'platform.owner' => \App\Http\Middleware\RequirePlatformOwner::class,
        ]);
        // CSRF exemptions.
        //
        // Stripe posts webhooks with no session and no token, so it has to be
        // exempt - that one is legitimate and stays.
        //
        // The rest were dev conveniences committed to config:
        //
        //   http://localhost:8000/*   these match every request served by a
        //   http://127.0.0.1:8000/*   local Laravel, i.e. CSRF entirely off
        //
        //   http://localhost:3000/*   the Next.js origin, never a URL this
        //                             application serves - it matched nothing
        //
        //   https://hp.triz.co.in/*   a different host altogether. Harmless
        //                             only for as long as this application is
        //                             never served from it; if it ever were,
        //                             CSRF would be off in production with no
        //                             obvious sign.
        //
        // The two localhost entries are kept, but only when APP_ENV is local,
        // so a production deployment cannot inherit them.
        $csrfExcept = ['stripe/*'];

        if (env('APP_ENV') === 'local') {
            $csrfExcept[] = 'http://localhost:8000/*';
            $csrfExcept[] = 'http://127.0.0.1:8000/*';
        }

        $middleware->validateCsrfTokens(except: $csrfExcept);

        // Web routes that the token-authenticated frontend posts to (POST /task
        // among them) answered 419 in production while passing locally, because
        // the localhost entries above blanket-exempt every route in local only.
        // This variant skips CSRF for a request that is BOTH type=API and
        // carrying a valid, unexpired Sanctum token - the case where the
        // controller uses the token's identity rather than the session cookie.
        // Everything else still goes through normal CSRF verification.
        //
        // ═══════════════════════════════════════════════════════════════════
        // THIS WAS `$middleware->replace(...)`, AND IT DID NOTHING AT ALL.
        // ═══════════════════════════════════════════════════════════════════
        //
        // TWO separate mistakes, either one enough to make it a silent no-op:
        //
        //   1. WRONG METHOD. `replace()` records into `$replacements`, which is
        //      applied to the GLOBAL middleware stack only. Middleware GROUPS
        //      are rewritten from `$groupReplacements`, which only
        //      `replaceInGroup()` writes to. See getMiddlewareGroups() in
        //      Foundation/Configuration/Middleware.php.
        //
        //   2. WRONG CLASS. Laravel 11 renamed it: the `web` group is built
        //      with ValidateCsrfToken. VerifyCsrfToken is now just its parent,
        //      kept as an alias, and is not in any group.
        //
        // Neither mistake raises anything. The call returns $this and the stock
        // CSRF middleware carries on running, so the code reads as a fix while
        // changing nothing.
        //
        // AND IT COULD NOT BE CAUGHT LOCALLY, for the exact reason written
        // above: the two localhost entries blanket-exempt every route when
        // APP_ENV=local, so CSRF never runs here whether this works or not.
        // Production kept answering 419 to POST /task the whole time.
        //
        // Both class names are named so the swap survives the alias being
        // retired or reinstated in a future release.
        foreach ([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ] as $stockCsrf) {
            $middleware->replaceInGroup('web', $stockCsrf, \App\Http\Middleware\VerifyCsrfTokenExceptApi::class);
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
