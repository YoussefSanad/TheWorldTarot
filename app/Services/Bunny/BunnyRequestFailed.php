<?php

namespace App\Services\Bunny;

use RuntimeException;

/**
 * Bunny refused something.
 *
 * Thrown rather than returned, so a caller cannot forget to check. The
 * ingestion job catches it and marks the card failed, because a video that
 * silently never arrives looks exactly like one that is still transcoding.
 */
class BunnyRequestFailed extends RuntimeException
{
    public static function for(string $action, int $status, string $body): self
    {
        // Truncated, because Bunny returns HTML on some failures and a log line
        // holding an entire error page is a log line nobody reads.
        return new self("Bunny refused to {$action}. It answered {$status}: ".mb_substr($body, 0, 200));
    }

    public static function misconfigured(string $missing): self
    {
        return new self("Bunny is not configured in this environment: {$missing} is missing.");
    }
}
