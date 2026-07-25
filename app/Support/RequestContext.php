<?php

namespace App\Support;

/**
 * The locale and currency resolved for the current request.
 *
 * Bound by ResolveRequestContext so controllers read a decided value rather
 * than re-deriving one. Resolving twice in two places is how a page ends up
 * priced in one currency and checked out in another.
 */
final readonly class RequestContext
{
    public function __construct(
        public string $locale,
        public string $currency,
    ) {}
}
