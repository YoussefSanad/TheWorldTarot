<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        // Who may read the generated API documentation at /docs/api.
        //
        // Scramble opens it in local development only. Everywhere else it
        // enumerates the whole API surface, including auth routes, so it is
        // gated behind an admin session. The check is against the admin guard
        // directly rather than the passed user, because the default guard here
        // is the customer one and a customer must never see this.
        Gate::define('viewApiDocs', fn ($user = null) => auth('admin')->check());
    }
}
