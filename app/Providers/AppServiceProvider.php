<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One SchemaCache per request, so its memoised table/column probes are shared
        // by every AI class that asks — see SchemaCache for why Schema::hasColumn() is
        // not used (it crashes on the live MariaDB 10.1).
        $this->app->scoped(\App\Domain\AI\Support\SchemaCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
         Schema::defaultStringLength(191);
    }
}
