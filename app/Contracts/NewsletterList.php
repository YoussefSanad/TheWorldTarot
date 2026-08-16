<?php

namespace App\Contracts;

use App\Support\NewsletterSignup;

/**
 * The list people join to hear from us.
 *
 * One method, because one thing is all this system needs. Mailchimp offers
 * campaigns, segments, automations, reports and a great deal else, and an
 * interface that offered them back would be a Mailchimp client wearing a
 * different name. The test in .claude/external-services.md is whether somebody
 * could implement this against a different service without touching a caller,
 * and nothing here mentions an audience, a list id or a merge field.
 *
 * Named in config/newsletter.php, so replacing the service is a new class and
 * one config line.
 *
 * ## What implementations may assume, and what they may not
 *
 * **This is always called from a queued job, never in a request.** Per
 * .claude/api.md, a signup must never make somebody wait on a third party, so
 * an implementation is free to be slow. It is not free to be careless: the
 * queue delivers at least once and will redeliver a job that is still running,
 * so **subscribing an address that is already subscribed must succeed rather
 * than throw**. Idempotency lives here, in the implementation, because only the
 * service being spoken to knows what a repeat looks like.
 *
 * Throwing is for a genuine refusal. **An implementation that knows retrying
 * cannot help throws an exception implementing App\Contracts\PermanentFailure**,
 * and anything else is treated as worth trying again. Which of its own failures
 * are permanent is knowledge only the implementation has, so the decision is
 * made here rather than by the job reading status codes it should not know
 * about.
 *
 * ## The rule that binds every implementation of this
 *
 * A fake proves a caller and never proves an implementation. Anything here that
 * talks to a real service carries a vector captured from that service, per
 * .claude/testing.md. An interface makes a fake trivial, a fake makes a suite
 * green, and that is precisely how M4 shipped playback that had never worked.
 */
interface NewsletterList
{
    /**
     * Add somebody to the list, or do nothing if they are already on it.
     *
     * @throws \RuntimeException when the service refused in a way the caller
     *                           should know about
     */
    public function subscribe(NewsletterSignup $signup): void;
}
