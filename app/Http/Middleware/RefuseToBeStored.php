<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Cache-Control: no-store` on every answer a route gives, refusals included.
 *
 * Added 14 November 2026 for `POST /orders/enter`, issue #128, whose contract
 * says every answer carries it. A controller can only mark the answers it
 * builds: a 422 from validation, a 404 thrown on the way, and a 429 from the
 * throttle are rendered elsewhere. Listed **before** the throttle on the route,
 * so it wraps the 429 as well.
 */
class RefuseToBeStored
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
