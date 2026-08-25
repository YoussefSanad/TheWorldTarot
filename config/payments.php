<?php

use App\Services\Payments\ManualSettlement;
use App\Services\Payments\StripeCheckout;

return [

    /*
    |--------------------------------------------------------------------------
    | The ways money can arrive
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4.
    | Adding a way to pay is a class implementing App\Contracts\PaymentGateway
    | and one line here.
    |
    | **A registry rather than one gateway, because the customer picks.** This
    | file named a single class while there was a single class, and the checkout
    | design has four buttons on the reading page. Apple Pay, Google Pay and card
    | are all `stripe`, and gift code redemption joins as a fourth method in the
    | gifting milestone, which is why it is the method that is chosen per payment
    | rather than the gateway that is chosen per environment.
    |
    | **What is listed here is what this environment offers.** Locally that is
    | hand settlement alone, since nobody has Jennifer's Stripe keys on a laptop
    | and an offered method with no credentials is a button that fails at the
    | worst moment. A deployed environment configures Stripe and gets it.
    |
    | **A name that is not in this list is refused, never substituted.** That is
    | the opposite of config/newsletter.php, which falls through to Mailchimp on
    | purpose so a typo cannot quietly stop collecting subscribers. The direction
    | reverses here: a typo must never quietly start charging cards.
    |
    */

    'methods' => array_filter([

        /*
        | Somebody settles the order by hand in the admin panel.
        |
        | Not scaffolding. Support needs it after launch for the customer who
        | pays some other way and for the order that goes wrong, so it is
        | offered everywhere and is never removed.
        */
        'manual' => ManualSettlement::class,

        /*
        | Apple Pay, Google Pay and card, which are one method here because they
        | are one PaymentIntent at Stripe and the customer's choice between them
        | is made in the page.
        |
        | **Offered only where Stripe is configured.** Nobody has Jennifer's
        | keys on a laptop, and a method that resolves and then fails on the
        | first API call is a button that breaks at the worst moment a shop has.
        | Refusing to offer it at all fails earlier and says why.
        */
        'stripe' => env('STRIPE_SECRET') === null ? null : StripeCheckout::class,

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
    | It must be one of the methods above. It is not a fallback for an
    | unrecognised name, which is refused: it is the answer to a question that
    | was never asked.
    |
    */

    'default' => env('PAYMENT_METHOD', 'manual'),

];
