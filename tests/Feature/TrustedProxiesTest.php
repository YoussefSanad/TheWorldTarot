<?php

use Illuminate\Support\Facades\Route;

/**
 * What `$request->ip()` answers once the proxy chain is read.
 *
 * The address is not cosmetic here. `LoginRequest` keys its per-account limiter
 * on it, and the newsletter records it against a subscriber, so an address the
 * caller can choose is a limiter the caller can step around.
 *
 * These are written as chains rather than as calls to our own configuration,
 * deliberately. Asserting that the trusted list contains what we put in it
 * would agree with the implementation whatever it did. What matters is which
 * address comes out of a realistic request, so that is what is asserted.
 */
beforeEach(function () {
    Route::middleware('api')->get('/api/v1/test-client-ip', fn () => [
        'ip' => request()->ip(),
    ]);
});

/**
 * The chain as it actually arrives.
 *
 * Cloudflare appends the address it saw, the platform's balancer appends
 * Cloudflare's, and the balancer itself is the peer. Its address is private,
 * which is what PRIVATE_SUBNETS covers.
 */
function throughCloudflare(string $forwardedFor): array
{
    return [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_FOR' => $forwardedFor,
    ];
}

it('reads the visitor address from a Cloudflare chain', function () {
    $this->withServerVariables(throughCloudflare('203.0.113.7, 172.68.0.1'))
        ->getJson('/api/v1/test-client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
});

it('reads an IPv6 visitor through an IPv6 Cloudflare edge', function () {
    // The v6 ranges are in the trusted list for this reason. Dropping them
    // makes this case answer with Cloudflare's address instead, and nothing a
    // v4-only test does would notice.
    $this->withServerVariables(throughCloudflare('2001:db8::1234, 2606:4700::1'))
        ->getJson('/api/v1/test-client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '2001:db8::1234');
});

/**
 * **The assertion this file exists for.**
 *
 * A client that invents an X-Forwarded-For entry has it appended to, not
 * replaced, so the forgery sits to the left of the address Cloudflare observed.
 * Walking the chain from the right must stop before reaching it.
 *
 * This is what `trustProxies(at: '*')` gets wrong, and '*' is what Laravel
 * applies by itself on Laravel Cloud when nothing is configured.
 */
it('ignores a forged forwarded address in front of the real one', function () {
    $this->withServerVariables(throughCloudflare('1.2.3.4, 203.0.113.7, 172.68.0.1'))
        ->getJson('/api/v1/test-client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
});

it('ignores a forwarded address from a peer it does not trust', function () {
    // Nothing in front of this request that we recognise, so the header is
    // worth nothing and the peer is the only address there is.
    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.9',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ])
        ->getJson('/api/v1/test-client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.9');
});

it('falls back to the peer when a trusted proxy forwards nothing', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/v1/test-client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '10.0.0.5');
});
