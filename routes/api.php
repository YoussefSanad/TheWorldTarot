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
    // The frontend contract. These two shapes are agreed with the frontend
    // partner: adding a field is safe, renaming or removing one is not.
    Route::get('/languages', LanguageController::class);
    Route::get('/currencies', CurrencyController::class);

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');
});
