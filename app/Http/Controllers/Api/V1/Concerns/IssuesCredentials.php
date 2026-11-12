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
 * difference between them is how certain we became. Since 12 November 2026,
 * issue #124, following a password link ends the same way too, through
 * `signInFromPasswordLink()` below.
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

        $this->startSession($request, $customer);

        return $this->respond(new CustomerResource($customer), $status);
    }

    /**
     * Signing somebody in because they just followed a password link.
     *
     * Added 12 November 2026, issue #124. Setting a password from a claim link
     * or a reset link proves control of the mailbox, which is the same thing
     * the website used to prove for itself by posting `/login` a second time
     * with the password just chosen. One request now does both.
     *
     * **Different from `issueCredentials` in two ways, both on purpose:**
     *
     * - **The website only.** No `device_name` branch, so no bearer token is
     *   minted from a link. A token outlives the link by months and native apps
     *   do not exist yet; when they do, they sign in with `/login` afterwards
     * - **No session is not a refusal.** By the time this runs the password is
     *   saved, so the 422 `issueCredentials` throws for an origin that cannot
     *   hold a session would tell somebody their password was not set when it
     *   was. They get the message alone, which is what both endpoints answered
     *   before #124, and sign in by hand
     *
     * **A different customer already signed in in this browser is replaced.**
     * The guard swaps the user and the session id is regenerated, so the old
     * id is worth nothing. Somebody following a link to an account means to be
     * that customer, and leaving the other one signed in would land them in
     * somebody else's account at `next`.
     *
     * The body is exactly login's, plus the `message` these endpoints have
     * always answered, so a client that reads it is not broken by the change.
     */
    private function signInFromPasswordLink(Request $request, Customer $customer, string $message): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json(['message' => $message])
                ->header('Cache-Control', 'no-store');
        }

        $this->startSession($request, $customer);

        return response()->json([
            ...(new CustomerResource($customer))->resolve($request),
            'message' => $message,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The cookie session, the one way the website is signed in.
     */
    private function startSession(Request $request, Customer $customer): void
    {
        Auth::guard('web')->login($customer);

        // Session fixation. A session id that existed before sign in must never
        // still be valid after it.
        $request->session()->regenerate();
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
