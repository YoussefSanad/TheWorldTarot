<?php

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
    });
});
