<?php

use App\Enums\PaymentMethod;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeWallet;

return [

    /*
    |--------------------------------------------------------------------------
    | The ways money can arrive
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4.
    | ~~Adding a way to pay is a class implementing App\Contracts\PaymentGateway
    | and one line here.~~ **Three places from 29 August 2026**: the class, a
    | line here, and a case in App\Enums\PaymentMethod, which is what the keys
    | below are written with. The third exists because these names are a
    | published contract the frontend switches on, and they were being spelled
    | out again in a request rule, a resource and the staging walk with nothing
    | tying them together. PaymentsConfigTest pins that the two lists agree.
    |
    | **A registry rather than one gateway, because the customer picks.** This
    | file named a single class while there was a single class, and the reading
    | page has a wallet button and a card button on it. Gift code redemption
    | joins as a further method in the gifting milestone, which is why it is the
    | method that is chosen per payment rather than the gateway that is chosen
    | per environment.
    |
    | **What is listed here is what this environment offers.** ~~Locally that is
    | hand settlement alone, since nobody has Jennifer's Stripe keys on a laptop
    | and an offered method with no credentials is a button that fails at the
    | worst moment.~~ **Struck 22 October 2026: hand settlement is retired.**
    | An environment with no Stripe keys offers nothing, and an offered method
    | with no credentials is still a button that fails at the worst moment. A
    | deployed environment configures Stripe and gets it.
    |
    | **A name that is not in this list is refused, never substituted.** That is
    | the opposite of config/newsletter.php, which falls through to Mailchimp on
    | purpose so a typo cannot quietly stop collecting subscribers. The direction
    | reverses here: a typo must never quietly start charging cards.
    |
    */

    'methods' => array_filter([

        /*
        | ~~Somebody settles the order by hand in the admin panel. Not
        | scaffolding. Support needs it after launch for the customer who pays
        | some other way and for the order that goes wrong, so it is offered
        | everywhere and is never removed.~~
        |
        | **Retired 22 October 2026.** The panel's "Mark as paid" action is
        | gone, so nothing could ever complete an order this method began, and
        | anyone holding a pay token could still start one. Stripe is the only
        | road to paid.
        */

        /*
        | Card, on Stripe's own hosted page. The card button.
        |
        | ~~Apple Pay, Google Pay and card are one method here because they are
        | one PaymentIntent at Stripe.~~ **Two methods from 29 August 2026**,
        | because they are now a hosted Session and a PaymentIntent, and no
        | Stripe parameter reconciles those: a Session is an address you send a
        | browser to, and the express checkout element is an iframe you mount.
        | The customer's choice between them is still made in the page, by
        | pressing one of two buttons that ask this API for different things.
        | See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
        |
        | **Offered only where Stripe is configured.** Nobody has Jennifer's
        | keys on a laptop, and a method that resolves and then fails on the
        | first API call is a button that breaks at the worst moment a shop has.
        | Refusing to offer it at all fails earlier and says why.
        |
        | **Configured means both keys, not just the secret.** Corrected 26
        | August 2026. The secret alone is what it takes to charge a card. The
        | webhook secret is what it takes to hear that the charge succeeded, and
        | an environment holding the first without the second takes the money and
        | then verifies nothing, which is hard rule 6 arriving one step later and
        | at the only moment it costs anything. A payment nobody can confirm is
        | worse than a payment nobody can start.
        |
        | **`blank()` rather than `=== null`, because `.env.example` ships both
        | of these present and empty.** A key that is set to nothing is not a key,
        | and `=== null` read an empty string as configured, which registered
        | Stripe on every laptop that had copied the example file. That is the
        | defect this comment described and the code did not do.
        |
        | Read from `env()` rather than `config('services.stripe.*')`, which
        | would be the tidier seam and does not work here: config files load in
        | filename order, so `services.php` is not in the repository yet when
        | this line runs. It would resolve to null uncached and to the right
        | value once `config:cache` ran, which is the worst of both.
        */
        PaymentMethod::Stripe->value => blank(env('STRIPE_SECRET')) || blank(env('STRIPE_WEBHOOK_SECRET'))
            ? null
            : StripeCheckout::class,

        /*
        | Apple Pay and Google Pay, in the express checkout element on the
        | frontend's own reading page. The wallet button.
        |
        | **`stripe_wallet` rather than `stripe_express`.** The element is
        | Stripe's "Express Checkout Element", and what the customer picks is a
        | wallet. The name says how the money arrives, which is what a method is
        | here. It is a published value the moment the frontend switches on it,
        | so it was agreed with them before it shipped.
        |
        | **The same both-keys test as the line above, deliberately.** Both
        | roads reach the same Stripe account with the same secret and settle
        | through the same webhook, so nothing could legitimately configure one
        | and not the other. An environment where these two disagree is a panel
        | drawing one button where the frontend expected to decide.
        |
        | **It fails by the button silently not appearing**, which is
        | indistinguishable from a device with no wallet and therefore
        | indistinguishable from working correctly, unless
        | `theworldtarot.com` is a registered Stripe payment method domain in
        | that mode. Section 10 of API_CONTRACT.md item 10 is where that sits.
        */
        PaymentMethod::StripeWallet->value => blank(env('STRIPE_SECRET')) || blank(env('STRIPE_WEBHOOK_SECRET'))
            ? null
            : StripeWallet::class,

    ]),

    /*
    |--------------------------------------------------------------------------
    | Which method is used when the caller does not name one
    |--------------------------------------------------------------------------
    |
    | The frontend names a method once there is more than one to name. Until
    | then, and for anything on this side that starts a payment without a
    | customer in front of it, this is the answer.
    |
    | **Stripe, decided 22 October 2026.** ~~It was `manual`.~~ An environment
    | with no Stripe keys has an empty registry, so this names a method that is
    | not offered, and `POST /pay` answers the existing unknown-method refusal,
    | a 422. That is deliberate: nothing there can be paid.
    |
    | It must be one of the methods above. It is not a fallback for an
    | unrecognised name, which is refused: it is the answer to a question that
    | was never asked.
    |
    */

    'default' => env('PAYMENT_METHOD', 'stripe'),

];
