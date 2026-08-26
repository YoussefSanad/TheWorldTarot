<?php

namespace App\Providers;

use App\Contracts\FilmSource;
use App\Contracts\NewsletterList;
use App\Contracts\PaymentGateway;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardVideoController;
use App\Models\Customer;
use App\Observers\TranslatableContentObserver;
use App\Services\Payments\PaymentMethods;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Which film each caller plays, named in config so changing either is
        // one line and a deploy. Resolved fresh each time rather than as a
        // singleton, so a test can swap the config and get what it asked for.
        // See docs/plans/reveal-video-plan.md.
        //
        // **Bound per caller, with no default, and that is the point.** Two
        // adapters satisfy this interface: one serves the public reveal, the
        // other serves the Viewing Room, which is sold. A bare
        // `app(FilmSource::class)` is deliberately an error rather than a
        // silent choice, because the two differ in what they cost somebody and
        // the wrong one is not a thing to pick up by accident.
        $this->app->when(CardController::class)
            ->needs(FilmSource::class)
            ->give(fn () => $this->app->make(config('reveal.source')));

        $this->app->when(CardVideoController::class)
            ->needs(FilmSource::class)
            ->give(fn () => $this->app->make(config('viewing-room.source')));

        // How money is collected, named in config/payments.php.
        //
        // **A registry rather than one class**, because the checkout design
        // lets the customer pick, so the method belongs to the payment rather
        // than to the environment. PaymentMethods turns a name into a gateway
        // and refuses a name it does not recognise.
        //
        // This binding is what a caller that does not choose gets: the method
        // named by `payments.default`. Resolved fresh rather than as a
        // singleton, so a test can name different methods and get them.
        $this->app->bind(
            PaymentGateway::class,
            fn () => $this->app->make(PaymentMethods::class)->resolve(),
        );

        /*
        | The one place this application talks to Stripe's wire.
        |
        | Resolved from the container and configured from config/services.php,
        | so a test substitutes one object in one place. **Nothing wraps it in
        | an interface of ours.** An interface naming a payment intent would be
        | Stripe with a different name, which is the seam in the wrong place by
        | the rule in .claude/external-services.md. The seam we do own is
        | PaymentGateway, one method wide, and it names no Stripe concept at all.
        */
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            /*
            | **Bounded, because this call happens inside a customer's request.**
            |
            | Added 26 August 2026. Creating a PaymentIntent cannot be queued:
            | the client secret it returns is the only thing that lets the page
            | take a payment, so the customer waits for it. .claude/api.md wants
            | a slow downstream queued and this one cannot be, which is a hard
            | rule 14 departure stated in PayOrderController.
            |
            | What is left of that concern is the part worth honouring. The
            | library defaults to eighty seconds with thirty to connect, so a
            | Stripe outage would hold a PHP worker for over a minute per
            | customer while they sit on a spinner, and enough of them together
            | is the whole site rather than the checkout. Ten and five turn that
            | into a fast, honest failure they can retry.
            |
            | **No network retries**, left at the library's default of zero. A
            | retry doubles the worst case in the one place where waiting is the
            | thing being avoided, and a customer pressing the button again is a
            | better retry than one they cannot see. The webhook and the sweep
            | are what make a payment that did go through reconcilable.
            |
            | Set globally rather than per client, because stripe-php takes its
            | HTTP client from a static and its config array has no key for one.
            */
            $http = CurlClient::instance();
            $http->setTimeout(10);
            $http->setConnectTimeout(5);

            ApiRequestor::setHttpClient($http);

            return new StripeClient([
                'api_key' => config('services.stripe.secret'),
            ]);
        });

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
