<?php

namespace App\Providers;

use App\Contracts\FilmSource;
use App\Contracts\NewsletterList;
use App\Contracts\PaymentGateway;
use App\Models\Customer;
use App\Observers\TranslatableContentObserver;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
            FilmSource::class,
            fn () => $this->app->make(config('reveal.source')),
        );

        // How money is collected, named in config/payments.php. Same reasoning
        // as above, and resolved fresh rather than as a singleton so a test can
        // name a different one and get it.
        $this->app->bind(
            PaymentGateway::class,
            fn () => $this->app->make(config('payments.gateway')),
        );

        // Which mailing list people join, named in config/newsletter.php. Same
        // reasoning again. See docs/plans/newsletter-signup-plan.md.
        $this->app->bind(
            NewsletterList::class,
            fn () => $this->app->make(config('newsletter.list')),
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

        // Who may watch the Living Tarot films.
        //
        // A gate rather than a policy on Card, because the question is not about
        // a card. It is "has this person bought the pass", and a CardPolicy
        // method that ignored the card it was handed would be a lie about what
        // is being decided. If a card is ever free to watch, that is the day
        // this becomes a policy.
        //
        // The one authority on the answer stays Customer::hasViewingRoomAccess.
        // This names it so the API, the panel and support all ask one question
        // rather than three that could drift.
        Gate::define('enter-viewing-room', fn (Customer $customer): Response => $customer->hasViewingRoomAccess()
            ? Response::allow()
            : Response::deny('This is for pass holders.'));

        // A language shows everything or nothing, so anything appearing on the
        // website untranslated takes that language off it. Registered for every
        // model the language gate walks, so cards inherit this in M3 by joining
        // config/translatable.php.
        foreach (config('translatable.models', []) as $model) {
            $model::observe(TranslatableContentObserver::class);
        }

        // What counts as an acceptable password, in one place, so registering
        // and resetting can never drift apart.
        //
        // Deliberately not `uncompromised()`, which checks the password against
        // a breach corpus over the network. It is a real protection and it puts
        // a third party in the request path of every registration, which
        // .claude/api.md forbids, and every test that makes an account would
        // then have to fake it. Revisit if credential stuffing shows up in the
        // logs, and put it behind a queued check rather than in the request.
        Password::defaults(fn (): Password => Password::min(8));
    }
}
