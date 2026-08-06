<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address a playback token is signed for.
 *
 * Bunny binds a signed URL to the viewer's IP, so this has to be the person
 * watching and never a machine in between. Get it wrong and every request 403s,
 * which reads like a broken signature rather than a wrong address.
 *
 * Cloudflare's header first, the same source the currency detection already
 * trusts, because in production the connecting address is a Cloudflare edge and
 * `Request::ip()` would hand back the proxy. No trusted proxies are configured,
 * deliberately: trusting `X-Forwarded-For` would let a caller name their own
 * address and mint a token for somebody else, while `CF-Connecting-IP` is
 * written by Cloudflare and cannot be forged from outside it.
 *
 * Falls back to the connecting address for local development and any request
 * that has not come through the CDN.
 */
class ViewerIp
{
    public static function from(Request $request): string
    {
        $forwarded = $request->header('CF-Connecting-IP');

        if (is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }

        return (string) $request->ip();
    }
}
