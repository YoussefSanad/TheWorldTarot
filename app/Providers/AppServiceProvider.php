<?php

namespace App\Providers;

use App\Contracts\RevealVideoSource;
use App\Observers\TranslatableContentObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // What the homepage reveal plays, named in config/reveal.php so changing
        // it is one line and a deploy. Resolved fresh each time rather than as a
        // singleton, so a test can swap the config and get what it asked for.
        // See docs/plans/reveal-video-plan.md.
        $this->app->bind(
            RevealVideoSource::class,
            fn () => $this->app->make(config('reveal.source')),
        );
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

        // A language shows everything or nothing, so anything appearing on the
        // website untranslated takes that language off it. Registered for every
        // model the language gate walks, so cards inherit this in M3 by joining
        // config/translatable.php.
        foreach (config('translatable.models', []) as $model) {
            $model::observe(TranslatableContentObserver::class);
        }
    }
}
