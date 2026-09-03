<?php

use App\Http\Controllers\Api\V1\BunnyWebhookController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardVideoController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Http\Controllers\Api\V1\GiftController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NewsletterController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentStatusController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PayOrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ResetPasswordController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SetPasswordController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| All API routes are versioned under /api/v1. There is no unversioned route.
| See .claude/api.md.
|
| **The third argument to every `throttle` below is load-bearing and must not
| be tidied away.** It is the limiter's key prefix, and without it every
| throttled route in this file shares one counter per address.
|
| Laravel keys a guest's rate limiter on the domain and the IP, never on the
| route, so `register`, `login`, `forgot-password`, `orders` and the rest all
| increment the same number, and the strictest limit among them applies to the
| sum of all of them. A signed in customer is keyed on their id, which shares a
| bucket exactly the same way.
|
| Found on staging on 13 August 2026 by tests/Staging, and it was not
| theoretical: six requests to `/api/v1/orders` refused the next
| `/api/v1/forgot-password` on its first call of the minute, so somebody who
| had just bought something could not reset their password. It stayed invisible
| to the feature suite because each test there makes one or two requests
| against a fresh cache. `PasswordResetTest` now pins it.
*/
Route::prefix('v1')->group(function () {
    // Not content, so no language segment. The language list in particular
    // cannot sit behind a language, since it is what tells the frontend which
    // languages there are.
    Route::get('/languages', LanguageController::class);
    Route::get('/currencies', CurrencyController::class);

    // Which buttons the reading page draws. Not content either, and flat for
    // the same reason: it carries no copy at all, only the names `/pay` accepts
    // as `method`.
    //
    // **Here because the frontend cannot infer it.** Whether the wallet button
    // exists depends on whether this environment configured Stripe, which is
    // config/payments.php's decision and invisible from outside.
    //
    // Unthrottled, like the two above. It is read once as a page loads, it
    // reaches no third party, and it answers from config with no query behind
    // it, so there is nothing here for a limit to protect.
    Route::get('/payment-methods', PaymentMethodController::class)->name('payment-methods');

    /*
    | Member accounts. Flat, with no language segment, because auth is not
    | content: an account is the same account in every language.
    |
    | Every one of these works under both a session cookie and a bearer token.
    | The website uses the cookie, a future native app names a device and gets a
    | token, and nothing here cares which arrived.
    |
    | Throttles are per address and are the coarse backstop. Signing in carries
    | a second, tighter limit per address and email inside LoginRequest, which
    | is the one that matters against a slow guess at a single account.
    */
    Route::post('/register', RegisterController::class)
        ->middleware('throttle:10,1,register')
        ->name('register');

    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:20,1,login')
        ->name('login');

    Route::post('/logout', [SessionController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('logout');

    // Cheap to call and not cheap to answer: it cannot be cached and it reads
    // the customer every time. Loose enough that a frontend re-checking who is
    // signed in never notices.
    Route::get('/me', MeController::class)
        ->middleware(['auth:sanctum', 'throttle:60,1,me'])
        ->name('me');

    /*
    | Forgotten passwords. Throttled harder than signing in, because an
    | unthrottled request-a-link endpoint is an email bombing tool aimed at
    | whatever address the sender names, and it costs the sender nothing.
    |
    | The route limit is per address. The broker adds a limit per account, sixty
    | seconds in config/auth.php, which is what stops one mailbox being buried
    | by requests arriving from everywhere.
    */
    Route::post('/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:6,1,forgot-password')
        ->name('password.email');

    Route::post('/reset-password', ResetPasswordController::class)
        ->middleware('throttle:10,1,reset-password')
        ->name('password.reset');

    // Claiming an account made for somebody when they bought something. The
    // same token machinery as a reset, and a separate endpoint because it only
    // works on an account that has never had a password.
    Route::post('/set-password', SetPasswordController::class)
        ->middleware('throttle:10,1,set-password')
        ->name('password.set');

    /*
    | Placing an order. Open to anybody, because demanding an account before
    | somebody can buy costs sales at the worst possible moment.
    |
    | Not behind a language, because buying is not content: an order is the same
    | order whatever language it was placed in.
    |
    | Throttled because an unauthenticated endpoint that creates a customer row
    | for an unrecognised email is a way to fill a table. Nothing is emailed
    | when an order is placed, which is what keeps it from being worse than
    | that. See step 7.
    */
    Route::post('/orders', OrderController::class)
        ->middleware('throttle:20,1,orders')
        ->name('orders.store');

    /*
    | Paying for an order that has already been placed. Open to anybody for the
    | same reason placing one is, and addressed by the order's `pay_token`
    | rather than its id, because ids here are sequential integers and the token
    | is what a guest holds to prove an order is theirs.
    |
    | Not behind a language, for the same reason as above: paying is not
    | content.
    |
    | **Its own throttle key prefix**, per the note at the top of this file.
    | Looser than placing an order, because a declined card is a retry and a
    | customer fighting with a wallet sheet must not be locked out of paying by
    | the attempts that failed.
    */
    Route::post('/orders/{payToken}/pay', PayOrderController::class)
        ->middleware('throttle:30,1,orders-pay')
        ->name('orders.pay');

    /*
    | Reading back what happened to the money for an order, which is what a
    | confirmation screen has to ask. A hosted checkout returns the customer
    | holding a Checkout Session id, and that id is opaque: it cannot be
    | resolved with a publishable key, so the only party who can say what
    | became of the payment is this one.
    |
    | **A POST with the token in the body, deliberately, and not
    | `GET /orders/{pay_token}`.** The pay token is a credential, and a path
    | segment is written to every access log between the browser and this
    | application. The shape of this route is the whole of that decision.
    |
    | Not behind a language, for the same reason as the two above: money is not
    | content.
    |
    | **Its own throttle key prefix**, per the note at the top of this file, and
    | the loosest of the three because this one is polled. A confirmation screen
    | asks repeatedly for a few seconds after the customer comes back, and a 429
    | there is a paid customer looking at a screen that cannot tell them so.
    */
    Route::post('/orders/status', OrderPaymentStatusController::class)
        ->middleware('throttle:60,1,orders-status')
        ->name('orders.status');

    /*
    | Joining the mailing list. Open to anybody, and flat rather than behind a
    | language, because a list is not content and whoever joins it has no
    | account and may never have one.
    |
    | Throttled harder than orders. This is unauthenticated, every call costs a
    | queued job and reaches a paid third party, and unlike an order there is no
    | point at which a person would legitimately do it repeatedly. Six a minute
    | is far more than filling in a form takes and far less than a useful way to
    | spend our Mailchimp quota.
    */
    Route::post('/newsletter', NewsletterController::class)
        ->middleware('throttle:6,1,newsletter')
        ->name('newsletter.subscribe');

    /*
    | Bunny telling us a transcode finished. Unauthenticated by necessity, since
    | Bunny holds no credentials of ours, and untrusted by design: the payload
    | only names a video, and the answer comes from asking Bunny directly.
    |
    | Rate limited because it is public and triggers an outbound API call.
    */
    Route::post('/webhooks/bunny', BunnyWebhookController::class)
        ->middleware('throttle:60,1,bunny-webhook')
        ->name('webhooks.bunny');

    /*
    | Stripe telling us what happened to a payment. Unauthenticated by
    | necessity, since Stripe holds no credentials of ours, and **trusted only
    | because the signature is verified against the raw body before anything is
    | read**, per hard rule 6.
    |
    | Rate limited, because it is a public endpoint that queues work. Higher
    | than the Bunny callback: videos are uploaded twenty-two times and then
    | almost never, while payments arrive whenever people buy, and a 429 here is
    | a payment Stripe has to redeliver.
    */
    Route::post('/webhooks/stripe', StripeWebhookController::class)
        ->middleware('throttle:120,1,stripe-webhook')
        ->name('webhooks.stripe');

    /*
    | Content, addressed per language: /api/v1/en/products. Every language is
    | prefixed, English included, so none is the odd one out and the frontend's
    | routes map to ours exactly.
    |
    | A language that is not public 404s here rather than falling back, because
    | a path segment is an address and not a hint. See .claude/translation.md
    | section 7.
    */
    Route::prefix('{locale}')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{key}', [ProductController::class, 'show'])->name('products.show');

        Route::get('/cards', [CardController::class, 'index'])->name('cards.index');

        // Above the single card route, and the id is constrained to digits.
        // Either alone would keep the reveal from being read as a card called
        // "draw". Both together mean changing one later cannot break it.
        //
        // Rate limited because it now hands a signed film to anybody who asks.
        // **This is not the paywall and does not pretend to be one.** The limit
        // on reveals per visitor lives in a frontend cookie by an earlier
        // decision, and twenty-two requests is the whole deck, so no ceiling
        // worth having stops somebody collecting it. What this does is keep one
        // caller from signing thousands of URLs an hour.
        Route::get('/cards/draw', [CardController::class, 'draw'])
            ->middleware('throttle:30,1,cards-draw')
            ->name('cards.draw');
        Route::get('/cards/{card}', [CardController::class, 'show'])
            ->whereNumber('card')
            ->name('cards.show');

        /*
        | Looking a gift code up, and spending it. B7, issue #73.
        |
        | **Two routes on purpose**, and the reason is the recipient's inbox:
        | email scanners and link prefetchers follow links, so a page that spent
        | the code on arrival would hand a present to whatever opened the mail
        | first. Resolving is one call and spending is another.
        |
        | **POST for the read as well**, because the code is a bearer credential
        | and a path segment lands in every access log between the browser and
        | this application. `POST /orders/status` takes the pay token the same
        | way and for the same reason.
        |
        | **Behind the language segment, unlike orders and payment.** These do
        | answer content: the lookup carries the reading's name and copy, in the
        | language the visitor opened the link in, because a code for a
        | withdrawn product still redeems and `/products/{key}` 404s on one.
        |
        | **Two limits, and the lookup is the tighter of them.** It is the
        | endpoint somebody would guess against, it costs nothing to call, and
        | ten a minute is far more than anybody retyping a code off a screen
        | needs. Redemption is looser because a person fighting with a
        | validation error must not be locked out of the present they were
        | given, and the guessing budget that appears to buy is not one:
        | **neither number is what closes the oracle**, which is the sixty bits
        | in the code. `App\Models\Gift::CODE_LENGTH` carries that arithmetic.
        |
        | **Their own throttle key prefixes**, per the note at the top of this
        | file, and separately from each other: somebody who mistyped their code
        | ten times must still be able to redeem it.
        */
        Route::post('/gifts/lookup', [GiftController::class, 'lookup'])
            ->middleware('throttle:10,1,gifts-lookup')
            ->name('gifts.lookup');

        Route::post('/gifts/redeem', [GiftController::class, 'redeem'])
            ->middleware('throttle:20,1,gifts-redeem')
            ->name('gifts.redeem');

        // The Viewing Room, and no longer the only route answering with a
        // credential. It is still the only one that charges for it.
        //
        // **Throttled for the same reason the reveal above is, and the reason
        // does not weaken for being behind a pass.** This mints the identical
        // artifact, a thirty minute signed URL to the same film, so a pass
        // holder without a limit here can sign the whole deck as fast as they
        // can ask and keep doing it. Paying once does not buy the right to
        // hand out fresh credentials all day. The same ceiling as the reveal,
        // which is far above anybody watching films and far below anybody
        // harvesting them.
        Route::get('/cards/{card}/video', CardVideoController::class)
            ->middleware(['auth:sanctum', 'throttle:30,1,cards-video'])
            ->whereNumber('card')
            ->name('cards.video');
    });
});
