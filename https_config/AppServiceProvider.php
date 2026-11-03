<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        Vite::prefetch(concurrency: 3);

        // Superadmin bypasses every Gate/policy check (Spatie permission
        // checks resolve through the Gate, so they are bypassed too).
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('superadmin') ? true : null;
        });

        // Force HTTPS in production or when explicitly enabled via FORCE_HTTPS=true
        if ($this->app->environment('production') || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}
