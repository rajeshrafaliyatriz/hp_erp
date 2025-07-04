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
        ]);
          $middleware->validateCsrfTokens(except: [
            'stripe/*',
            'http://localhost:8000/*',
            'http://localhost:3000/*',
            'http://127.0.0.1:8000/*',
            'https://hp.triz.co.in/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
