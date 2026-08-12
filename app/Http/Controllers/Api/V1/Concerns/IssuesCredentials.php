<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Turning "this is definitely them" into something they can use next.
 *
 * Two ways in, and every protected route has to work under both, per
 * .claude/api.md and CLAUDE.md section 3.
 *
 * - **No `device_name`:** the website. A session cookie, scoped to the shared
 *   root domain so the frontend on a sibling subdomain can use it
 * - **A `device_name`:** a native app, which is a stated direction rather than
 *   speculation. A bearer token, returned once and never retrievable again
 *
 * Shared by registering and signing in because they end identically. The only
 * difference between them is how certain we became.
 */
trait IssuesCredentials
{
    private function issueCredentials(Request $request, Customer $customer, int $status = 200): JsonResponse
    {
        $device = $request->input('device_name');

        if (is_string($device) && $device !== '') {
            // Named after the device so a customer can be told what is signed
            // in, and so one can be revoked without ending every session.
            $token = $customer->createToken($device)->plainTextToken;

            return $this->respond(new CustomerResource($customer, $token), $status);
        }

        if (! $request->hasSession()) {
            // No cookie session available and no device named, so there is
            // nothing we could hand back. This is what a request from an
            // unregistered origin looks like: the stateful middleware did not
            // apply, so telling them the truth saves an afternoon.
            throw ValidationException::withMessages([
                'device_name' => 'This origin cannot hold a session, so name a device to receive a token instead.',
            ]);
        }

        Auth::guard('web')->login($customer);

        // Session fixation. A session id that existed before sign in must never
        // still be valid after it.
        $request->session()->regenerate();

        return $this->respond(new CustomerResource($customer), $status);
    }

    private function respond(CustomerResource $resource, int $status): JsonResponse
    {
        return $resource->response()
            ->setStatusCode($status)
            // Identity, and sometimes a bearer token. Neither belongs in a
            // cache, a service worker or a persisted store.
            ->header('Cache-Control', 'no-store');
    }
}
