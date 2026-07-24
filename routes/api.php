<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| All API routes are versioned under /api/v1. There is no unversioned route.
| See .claude/api.md.
*/
Route::prefix('v1')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');
});
