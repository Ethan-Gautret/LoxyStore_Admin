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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            Schema::defaultStringLength(191);
        } catch (\Throwable $e) {
            // If Schema isn't available or the method is missing, ignore to keep bootstrap stable in dev.
        }
    }
}
