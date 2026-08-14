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
            'menuright' => \App\Http\Middleware\RequireMenuRight::class,
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
