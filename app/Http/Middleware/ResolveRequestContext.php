<?php

namespace App\Http\Middleware;

use App\Support\CurrencyResolver;
use App\Support\LocaleResolver;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves locale and currency for the request and binds both.
 *
 * One middleware, not two. Locale and currency are the same kind of thing:
 * per-request context taken from an explicit parameter with a safe fallback.
 * See .claude/translation.md section 6.
 *
 * Both arrive as query parameters rather than headers because these endpoints
 * sit behind Cloudflare and cache keys are built from URLs. A header-based
 * locale means either Vary on a header browsers fill with long weighted
 * strings, or no edge caching at all.
 */
class ResolveRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LocaleResolver::resolve(
            $this->stringParam($request->query('locale')),
            $request->user('admin') !== null,
        );

        // Sets the locale for translated content, lang files, and validation
        // messages for the rest of the request.
        app()->setLocale($locale);

        $currency = CurrencyResolver::resolve(
            // An explicit choice, from the query string or the frontend's
            // override cookie. When present it is honoured as is, and the
            // country is never consulted.
            $this->stringParam($request->query('currency'))
                ?? $this->stringParam($request->cookie('currency')),

            // Cloudflare's country header. No third-party geolocation service.
            $this->stringParam($request->header('CF-IPCountry')),
        );

        app()->instance(RequestContext::class, new RequestContext($locale, $currency));

        return $next($request);
    }

    /**
     * Query parameters and cookies can arrive as arrays. Anything that is not a
     * plain string is treated as absent rather than coerced.
     */
    private function stringParam(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
