<?php

namespace App\Services\Newsletter;

use App\Contracts\NewsletterList;
use App\Support\NewsletterSignup;
use Illuminate\Support\Facades\Log;

/**
 * The list, written to the log instead of to anybody's mailing service.
 *
 * For local development, where subscribing writes to the audience Jennifer
 * mails, since both environments share one. A developer testing a form should
 * not be putting rows in it.
 *
 * **Not a fake, and not for tests.** The suite fakes HTTP and asserts against
 * the real implementation, per .claude/testing.md. This is a real
 * implementation of the interface whose behaviour happens to be "write it
 * down", ~~which is the same standing `ManualSettlement` has in config/payments.~~ (Struck 22 October 2026: that class is retired.)
 * Naming it in a deployed environment would silently stop collecting
 * subscribers, which is why the log line is a warning rather than info.
 */
final class LoggedNewsletterList implements NewsletterList
{
    public function subscribe(NewsletterSignup $signup): void
    {
        Log::warning('A newsletter signup was logged rather than sent to a list.', [
            'email' => $signup->email,
            'consented_at' => $signup->consentedAt->toIso8601String(),
            'ip' => $signup->ip,
        ]);
    }
}
