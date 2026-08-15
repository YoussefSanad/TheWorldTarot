<?php

namespace App\Support;

/**
 * Whose forwarded headers this application believes.
 *
 * Traffic reaches this app as visitor, then Cloudflare, then Laravel Cloud's
 * load balancer. Only the last of those opens the TCP connection, so
 * `$request->ip()` is a proxy unless the chain in `X-Forwarded-For` is read,
 * and that header is only worth reading for the hops we actually trust.
 *
 * **Symfony walks the chain from the right and returns the first address it
 * does not trust.** With Cloudflare and the platform's load balancer trusted
 * and nothing else, that is the address Cloudflare itself observed. A forged
 * entry sits further left and is never reached, which is the whole mechanism:
 *
 *     X-Forwarded-For: 1.2.3.4, 203.0.113.7, 172.68.0.1
 *                      ^forged  ^real        ^Cloudflare   peer: the balancer
 *
 * Trusting the balancer and Cloudflare discards the right hand two, stops at
 * 203.0.113.7, and never looks at what the client invented.
 *
 * ## Why not the obvious `trustProxies(at: '*')`
 *
 * Trusting everything discards the entire chain and returns its **leftmost**
 * entry, which is exactly the value a client can invent. That is not a
 * hypothetical default: `Illuminate\Http\Middleware\TrustProxies` applies `'*'`
 * on its own when nothing is configured and `laravel_cloud()` is true, so it is
 * what this application did in both deployed environments until 14 August 2026.
 * Anything keyed on an address, `LoginRequest`'s limiter included, could be
 * moved by setting one header.
 *
 * ## Why not the `REMOTE_ADDR` sentinel
 *
 * Symfony replaces that string with whoever opened the connection, which looks
 * like a tidy way to trust the balancer without knowing its address. It is
 * wrong here. It trusts the peer unconditionally, and this origin is reachable
 * without going through Cloudflare at all, on the `*.laravel.cloud` hostname
 * the frontend's own `.env.example` names. A request sent straight there would
 * have its invented `X-Forwarded-For` believed. `PRIVATE_SUBNETS` covers the
 * balancer without extending that trust to whatever happens to connect.
 *
 * ## What this does not fix, in writing
 *
 * **Reaching the origin directly is still possible, and a request that does so
 * is still trusted one hop too far**, because the balancer in front of it is
 * trusted whichever hostname was used. Closing that means refusing traffic that
 * did not come through Cloudflare, at the platform or with authenticated origin
 * pulls, and it is not something this class can do. Until then the exposure is
 * smaller than it was, not gone: forging an address requires knowing the origin
 * hostname rather than merely sending a header to the public one.
 */
final class TrustedProxies
{
    /**
     * Cloudflare's published edge ranges, fetched 14 August 2026 from
     * https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6.
     *
     * **Both families are here and the v6 half is not decoration.** Cloudflare
     * is dual stack and a real visitor arrives over whichever their network
     * prefers. Omitting v6 would pass every test written on a machine curling
     * v4 and silently mis-attribute every v6 visitor, which is the shape of the
     * playback failure recorded in CLAUDE.md section 4.
     *
     * Cloudflare changes this list rarely and does change it. It is pinned here
     * rather than fetched at runtime, because a network call in the middleware
     * of every request is a worse problem than a list that goes stale slowly.
     * Refreshing it is re-fetching both URLs.
     */
    private const CLOUDFLARE = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        // PRIVATE_SUBNETS is a sentinel Symfony expands to the RFC 1918 and
        // unique-local ranges. It covers Laravel Cloud's balancer without this
        // repo having to know its address, and without trusting a public peer.
        return [...self::CLOUDFLARE, 'PRIVATE_SUBNETS'];
    }
}
