<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Somebody asking to hear from us.
 *
 * **Deliberately not a Customer.** Whoever joins a mailing list has no account,
 * may never have one, and the two ideas drifting together is how a marketing
 * list ends up gating on whether a person happens to have bought something.
 *
 * Everything here is in our words. There is no audience, no list id and no
 * merge field, because those belong to whichever service is implementing
 * App\Contracts\NewsletterList today. See .claude/external-services.md: a
 * vendor's own concepts appearing in this file would mean the seam is in the
 * wrong place.
 */
final readonly class NewsletterSignup
{
    public function __construct(
        public string $email,

        /** When they ticked the box, not when the queue got to it. */
        public CarbonImmutable $consentedAt,

        /**
         * Where they were when they did.
         *
         * Nullable because a signup could one day be made by something other
         * than a browser, a console command backfilling for instance, and a
         * fabricated address would be worse than an absent one.
         *
         * Only meaningful because the proxy chain is read correctly. See
         * App\Support\TrustedProxies, without which this would be one balancer
         * address for every subscriber on earth.
         */
        public ?string $ip = null,

        /**
         * Optional throughout. The coming-soon form asks for an address and
         * nothing else, and the footer form asks for a first name as well, so
         * the narrower case is the default rather than the exception.
         */
        public ?string $name = null,
    ) {}
}
