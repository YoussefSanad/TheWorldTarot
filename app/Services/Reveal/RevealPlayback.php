<?php

namespace App\Services\Reveal;

use Carbon\CarbonInterface;

/**
 * Permission to watch one reveal, for a while.
 *
 * Deliberately holds no video id and nothing about where the file lives. The URL
 * is the whole of what a player needs, and naming the video anywhere else only
 * helps somebody trying to reach it another way.
 */
class RevealPlayback
{
    public function __construct(
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
