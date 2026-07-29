<?php

use App\Http\Controllers\Api\V1\BunnyWebhookController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Http\Request;
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

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

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
        // "random". Both together mean changing one later cannot break it.
        Route::get('/cards/random', [CardController::class, 'random'])->name('cards.random');
        Route::get('/cards/{card}', [CardController::class, 'show'])
            ->whereNumber('card')
            ->name('cards.show');
    });
});
