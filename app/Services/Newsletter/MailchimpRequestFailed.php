<?php

namespace App\Services\Newsletter;

use App\Contracts\PermanentFailure;
use RuntimeException;

/**
 * Mailchimp refused a subscription.
 *
 * Two flavours, and the difference is the whole reason this class exists rather
 * than a bare RuntimeException. `permanent()` will refuse identically forever
 * and the job stops. `transient()` is Mailchimp having a bad minute and the job
 * keeps its retry window.
 *
 * The marker is on the permanent variant only, so `instanceof PermanentFailure`
 * is the question the job asks and it never learns whose API this was.
 */
class MailchimpRequestFailed extends RuntimeException
{
    public static function transient(int $status, string $body): self
    {
        return new self(self::message('Mailchimp could not be reached', $status, $body));
    }

    /**
     * Refused in a way that cannot improve: a malformed address, a rejected
     * key, an audience that does not exist.
     */
    public static function permanent(int $status, string $body): PermanentMailchimpFailure
    {
        return new PermanentMailchimpFailure(self::message('Mailchimp refused a signup', $status, $body));
    }

    public static function misconfigured(string $missing): PermanentMailchimpFailure
    {
        // Permanent, because no number of retries produces a key that was never
        // set, and a queue quietly grinding on a missing credential for six
        // hours is how a deploy mistake stays invisible.
        return new PermanentMailchimpFailure(
            "Mailchimp is not configured in this environment: {$missing} is missing."
        );
    }

    private static function message(string $prefix, int $status, string $body): string
    {
        // Truncated. Mailchimp's error bodies carry a full problem document
        // with a link to their docs, and a log line holding all of it is a log
        // line nobody reads.
        return "{$prefix}. It answered {$status}: ".mb_substr($body, 0, 200);
    }
}
