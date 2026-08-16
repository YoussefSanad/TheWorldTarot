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
 * ## `REMOTE_ADDR`, rejected on 14 August 2026 and adopted on 15 August
 *
 * Symfony replaces that string with whoever opened the connection. It was left
 * out at first because it trusts the peer unconditionally, and `PRIVATE_SUBNETS`
 * looked like a way to cover the platform's balancer without doing that.
 *
 * **Then staging was walked, and the balancer turned out to sit on a public
 * address.** Two signups arriving minutes apart were both recorded from
 * `2600:1f16:e0:85`, an AWS range, which is neither Cloudflare nor private. So
 * nothing in the trusted list matched the peer, Symfony stopped at the first
 * address it did not recognise, and `$request->ip()` was that balancer for every
 * visitor on earth.
 *
 * That is worse in practice than what it replaced. Every limiter in
 * `routes/api.php` keys on the address, so a single counter was being shared by
 * the whole internet: six newsletter signups a minute in total, and one caller
 * able to lock everybody out. `LoginRequest`'s `email|ip` limiter lost its per
 * attacker dimension the same way.
 *
 * **The lesson is the one this project keeps paying for.** The synthetic chains
 * in the test suite were correct and the assumption underneath them was not,
 * and only a request travelling the path a real visitor travels could show it.
 * See CLAUDE.md section 4 on the channel a proof travels down.
 *
 * `PRIVATE_SUBNETS` stays, since it costs nothing and covers an internal hop if
 * the platform ever grows one.
 *
 * ## What this does not fix, in writing
 *
 * **A request that reaches the origin without passing through Cloudflare is
 * trusted one hop too far, and can name whatever address it likes.** The origin
 * answers on its `*.laravel.cloud` hostname, which the frontend's own
 * `.env.example` names, and the balancer is the peer whichever hostname was
 * used, so nothing here can tell the two apart.
 *
 * **Swapping to `CF-Connecting-IP` does not help**, and it is worth writing down
 * because it is the obvious next idea. Cloudflare overwrites that header so a
 * client cannot forge it in transit, but a client talking straight to the origin
 * is not in transit and simply sets it. The missing thing is proof that
 * Cloudflare was involved at all, which no header can supply by itself.
 *
 * Closing it needs one of: refusing non-Cloudflare traffic at the platform,
 * authenticated origin pulls, or a secret header set by a Cloudflare transform
 * rule and checked here. All three are configuration rather than code, and none
 * is done. Until one is, this class buys correct addresses for ordinary traffic
 * and nothing against somebody who knows the origin hostname.
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
        // Two sentinels Symfony expands for us. PRIVATE_SUBNETS becomes the
        // RFC 1918 and unique-local ranges. REMOTE_ADDR becomes whoever opened
        // the connection, which is how the platform's balancer is trusted
        // without this repo knowing or maintaining its address.
        return [...self::CLOUDFLARE, 'PRIVATE_SUBNETS', 'REMOTE_ADDR'];
    }
}
