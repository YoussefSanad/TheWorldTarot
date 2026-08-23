<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Permission to watch one film, for a while.
 *
 * **Deliberately holds no video id and nothing about where the file lives.** The
 * URL is the whole of what a player needs, and naming the video anywhere else
 * only helps somebody trying to reach it another way.
 *
 * In `App\Support` rather than beside any one adapter, and in our words rather
 * than a vendor's, for the same reason `NewsletterSignup` is: this is what
 * crosses the seam, so a Bunny library id or a token scheme appearing here would
 * mean the seam is in the wrong place. See `.claude/external-services.md`.
 *
 * Was `App\Services\Reveal\RevealPlayback` while the reveal was the only caller.
 */
final readonly class Playback
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}
}
