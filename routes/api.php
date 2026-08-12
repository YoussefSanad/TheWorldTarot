<?php

use App\Http\Controllers\Api\V1\BunnyWebhookController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardVideoController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ResetPasswordController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SetPasswordController;
use Illuminate\Support\Facades\Route;

/*
| All API routes are versioned under /api/v1. There is no unversioned route.
| See .claude/api.md.
*/
Route::prefix('v1')->group(function () {
    // Not content, so no language segment. The language list in particular
    // cannot sit behind a language, since it is what tells the frontend which
    // languages there are.
    Route::get('/languages', LanguageController::class);
    Route::get('/currencies', CurrencyController::class);

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
        ->middleware('throttle:10,1')
        ->name('register');

    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login');

    Route::post('/logout', [SessionController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('logout');

    Route::get('/me', MeController::class)
        ->middleware('auth:sanctum')
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
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::post('/reset-password', ResetPasswordController::class)
        ->middleware('throttle:10,1')
        ->name('password.reset');

    // Claiming an account made for somebody when they bought something. The
    // same token machinery as a reset, and a separate endpoint because it only
    // works on an account that has never had a password.
    Route::post('/set-password', SetPasswordController::class)
        ->middleware('throttle:10,1')
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
        ->middleware('throttle:20,1')
        ->name('orders.store');

    /*
    | Bunny telling us a transcode finished. Unauthenticated by necessity, since
    | Bunny holds no credentials of ours, and untrusted by design: the payload
    | only names a video, and the answer comes from asking Bunny directly.
    |
    | Rate limited because it is public and triggers an outbound API call.
    */
    Route::post('/webhooks/bunny', BunnyWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('webhooks.bunny');

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
            ->middleware('throttle:30,1')
            ->name('cards.draw');
        Route::get('/cards/{card}', [CardController::class, 'show'])
            ->whereNumber('card')
            ->name('cards.show');

        // The Viewing Room, and no longer the only route answering with a
        // credential. It is still the only one that charges for it.
        Route::get('/cards/{card}/video', CardVideoController::class)
            ->middleware('auth:sanctum')
            ->whereNumber('card')
            ->name('cards.video');
    });
});
