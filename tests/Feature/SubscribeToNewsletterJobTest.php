<?php

use App\Contracts\NewsletterList;
use App\Jobs\SubscribeToNewsletter;
use App\Services\Newsletter\MailchimpRequestFailed;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Log;

function jobSignup(): NewsletterSignup
{
    return new NewsletterSignup(
        email: 'someone@example.com',
        consentedAt: CarbonImmutable::parse('2026-08-15 12:00:00'),
        ip: '203.0.113.7',
    );
}

/**
 * A list that records what it was asked, or refuses in a chosen way.
 *
 * Proves the job, and nothing whatsoever about Mailchimp. That distinction is
 * the whole of .claude/external-services.md's warning: a fake makes a suite
 * green and the vector is what makes it mean something.
 */
function listThat(?Throwable $refusal = null): NewsletterList
{
    return new class($refusal) implements NewsletterList
    {
        public array $subscribed = [];

        public function __construct(private ?Throwable $refusal) {}

        public function subscribe(NewsletterSignup $signup): void
        {
            if ($this->refusal) {
                throw $this->refusal;
            }

            $this->subscribed[] = $signup->email;
        }
    };
}

it('puts the address on the list', function () {
    $list = listThat();

    (new SubscribeToNewsletter(jobSignup()))->handle($list);

    expect($list->subscribed)->toBe(['someone@example.com']);
});

it('carries the whole signup, not just the address', function () {
    $job = new SubscribeToNewsletter(jobSignup());

    // The consent time and the address travel in the payload, so a job sitting
    // in `failed_jobs` for a day still says when and where somebody agreed
    // rather than when somebody got round to retrying it.
    expect($job->signup->consentedAt->toDateTimeString())->toBe('2026-08-15 12:00:00');
    expect($job->signup->ip)->toBe('203.0.113.7');
});

/**
 * The retry window, which is the whole reason this job is not `tries = 3`.
 */
it('keeps trying for six hours', function () {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');

    expect((new SubscribeToNewsletter(jobSignup()))->retryUntil()->format('Y-m-d H:i:s'))
        ->toBe('2026-08-15 15:00:00');

    CarbonImmutable::setTestNow();
});

it('never asks the queue for a delay it cannot express', function () {
    // Laravel Cloud's managed queues cap a delay at fifteen minutes, per
    // CLAUDE.md section 2. A backoff past that does not produce a longer wait,
    // it produces whatever the platform does with an out of range value.
    expect(max((new SubscribeToNewsletter(jobSignup()))->backoff()))->toBeLessThanOrEqual(900);
});

it('counts attempts by the deadline rather than by a number', function () {
    // Zero means the worker stops counting and asks retryUntil. Left at the
    // default of one, the deadline would be decoration and a single timeout
    // would end the whole thing.
    expect((new SubscribeToNewsletter(jobSignup()))->tries)->toBe(0);
});

/**
 * **A refusal that cannot improve must not spend the window.**
 *
 * Under a six hour deadline a malformed address would otherwise be retried
 * roughly two dozen times, and a batch of them would keep a worker busy
 * achieving nothing.
 */
it('gives up immediately when retrying cannot help', function () {
    $refusal = MailchimpRequestFailed::permanent(400, '{"title":"Invalid Resource"}');

    $job = new SubscribeToNewsletter(jobSignup());
    $queued = Mockery::mock(QueueJob::class)->shouldIgnoreMissing();
    $queued->shouldReceive('fail')->once()->with($refusal);
    $job->setJob($queued);

    $job->handle(listThat($refusal));
});

/**
 * Everything else is Mailchimp having a bad minute, and the exception is left
 * to propagate because that is what asks the queue to try again.
 */
it('lets a temporary refusal reach the queue', function () {
    $refusal = MailchimpRequestFailed::transient(503, 'unavailable');

    $job = new SubscribeToNewsletter(jobSignup());
    $queued = Mockery::mock(QueueJob::class)->shouldIgnoreMissing();
    $queued->shouldNotReceive('fail');
    $job->setJob($queued);

    expect(fn () => $job->handle(listThat($refusal)))
        ->toThrow(MailchimpRequestFailed::class);
});

it('says who was lost when every attempt is gone', function () {
    Log::spy();

    (new SubscribeToNewsletter(jobSignup()))->failed(new RuntimeException('gave up'));

    // The address stays readable in failed_jobs, so this line is how somebody
    // knows to go and look rather than being the only record of it.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'never reached the list')
            && $context['email'] === 'someone@example.com');
});
