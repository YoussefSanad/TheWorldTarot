<?php

namespace App\Jobs;

use App\Contracts\NewsletterList;
use App\Contracts\PermanentFailure;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts somebody on the mailing list, away from the request that asked.
 *
 * Per .claude/api.md nothing waits on a third party, so the endpoint answered
 * long before this runs. **The person has already been told they are
 * subscribed.** That is the correct trade for a mailing list and it would be
 * indefensible for a purchase, and it is why the retry window below is wide and
 * why a failure here is loud.
 *
 * ## Surviving an outage rather than a hiccup
 *
 * A fixed `tries = 3` covers a dropped connection and nothing more. Mailchimp
 * being down for an afternoon is the case worth designing for, so this carries
 * a deadline instead: attempts continue for six hours, settling into one every
 * fifteen minutes.
 *
 * **Fifteen minutes is a ceiling, not a taste.** CLAUDE.md section 2 records
 * that Laravel Cloud's managed queues cannot express a delay longer than that.
 * A backoff of an hour would not produce an hour, it would produce whatever the
 * platform does with an out of range delay, so the schedule is built to stay
 * inside the limit rather than to discover it in production.
 *
 * Six hours is roughly two dozen attempts. Long enough that an outage spanning
 * a working day is survived without anybody noticing, short enough that a
 * genuinely dead integration surfaces the same day rather than retrying quietly
 * for a week.
 *
 * ## Running twice is expected, not exceptional
 *
 * Managed queues deliver at least once and will redeliver a job that is still
 * running. Nothing here guards against that, deliberately, because the guard
 * belongs where the knowledge is: `NewsletterList` requires its implementations
 * to treat an address that is already subscribed as success.
 */
class SubscribeToNewsletter implements ShouldQueue
{
    use Queueable;

    /**
     * Unlimited, because `retryUntil` is what bounds this.
     *
     * Zero means the worker stops counting attempts and asks the deadline
     * instead. Left at its default of one, the deadline would be decoration and
     * a single timeout would end it.
     */
    public int $tries = 0;

    /**
     * One small JSON call, and the client already gives up sooner.
     *
     * Deliberately shorter than the fifteen minute floor between attempts, so a
     * redelivery can never overlap an attempt that is still running.
     */
    public int $timeout = 30;

    public function __construct(public NewsletterSignup $signup) {}

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Laravel repeats the last value, so this is a minute, five minutes,
        // then every fifteen for the rest of the window.
        return [60, 300, 900];
    }

    public function handle(NewsletterList $list): void
    {
        try {
            $list->subscribe($this->signup);
        } catch (PermanentFailure $e) {
            // **Stops now rather than spending the whole window.** A malformed
            // address, a rejected key or a missing audience refuses identically
            // every time, and a batch of them would keep a worker busy for six
            // hours achieving nothing.
            //
            // Anything else is left to propagate, which is what asks the queue
            // to try again: a timeout, a 5xx, or Mailchimp rate limiting us,
            // which is exactly what a launch spike looks like.
            $this->fail($e);
        }
    }

    /**
     * Every attempt is gone, or the refusal was permanent.
     *
     * **The address is not lost.** The failed job driver is `database-uuids`, so
     * the payload sits in `failed_jobs` with the address, the consent time and
     * the signup address intact, and `queue:retry` replays it once the service
     * is back. That is the recovery path, and it is the reason this feature
     * needs no subscribers table of its own.
     *
     * What it costs, in writing: a signup that gets here needs a person to run
     * one command. Nothing is silently dropped and nothing is automatically
     * recovered.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('A newsletter signup never reached the list.', [
            'email' => $this->signup->email,
            'consented_at' => $this->signup->consentedAt->toIso8601String(),
            'reason' => $exception?->getMessage(),
        ]);
    }
}
