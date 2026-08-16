<?php

use App\Http\Middleware\ResolveRequestContext;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Who this application believes when it reads a forwarded address.
        //
        // **Not a tidy default, load bearing.** Left unset, Laravel trusts every
        // proxy whenever it detects Laravel Cloud, and `$request->ip()` then
        // returns the leftmost entry of a header the client wrote. Anything
        // keyed on an address moves by sending one header, `LoginRequest`'s
        // limiter included. Reasoning, and what this still does not fix, in
        // App\Support\TrustedProxies.
        $middleware->trustProxies(at: TrustedProxies::all());

        // Cookie based Sanctum auth for the Next.js frontend. Without this the
        // API has no session at all, only token auth works, and an
        // authenticated admin cannot preview a non-public locale.
        $middleware->statefulApi();

        // The currency override cookie is written by the Next.js frontend in
        // plain text, so Laravel must not try to decrypt it. Without this
        // exception the cookie is silently discarded and a customer's explicit
        // currency choice is lost on every request.
        $middleware->encryptCookies(except: [
            'currency',
        ]);

        // Locale and currency for every API request, resolved once and bound.
        $middleware->api(append: [
            ResolveRequestContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
