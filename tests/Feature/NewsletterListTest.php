<?php

use App\Contracts\NewsletterList;
use App\Services\Newsletter\LoggedNewsletterList;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The seam itself, not any particular list.
 *
 * What is worth proving here is that config decides, because that is the entire
 * claim the interface makes: replacing the service is a new class and one line.
 * A test asserting that the container returns the class we hardcoded into it
 * would prove nothing and would keep passing if the config were ignored.
 *
 * **It must not read the answer out of an env file either, which is what this
 * one was doing until 16 August 2026.** It resolved the default and expected the
 * logged list, and that only held because one developer's .env carried
 * NEWSLETTER_DRIVER=log. CI copies .env.example, where that line is commented
 * out, so config fell through to Mailchimp exactly as designed and the test
 * failed on the first pull request that ran it.
 *
 * The config default is right and stays: an unrecognised or absent driver gets
 * Mailchimp, because a typo in an env file must not be a quiet way to stop
 * collecting subscribers. What was wrong was a test that asserted an
 * environment while claiming to assert a seam. It now names the class it
 * expects, and phpunit.xml pins the driver as well, so nothing in the suite can
 * resolve the real Mailchimp client while the queue runs inline.
 */
it('resolves whatever the config names', function () {
    config(['newsletter.list' => LoggedNewsletterList::class]);

    expect(app(NewsletterList::class))->toBeInstanceOf(LoggedNewsletterList::class);
});

it('resolves a different implementation when the config names one', function () {
    $other = new class implements NewsletterList
    {
        public array $subscribed = [];

        public function subscribe(NewsletterSignup $signup): void
        {
            $this->subscribed[] = $signup->email;
        }
    };

    config(['newsletter.list' => $other::class]);
    app()->bind($other::class, fn () => $other);

    // Resolved fresh rather than as a singleton, which is what makes this
    // swappable at all. A singleton bound before the config changed would
    // hand back the old one and this would fail.
    app(NewsletterList::class)->subscribe(signup());

    expect($other->subscribed)->toBe(['someone@example.com']);
});

it('logs a signup rather than sending it anywhere', function () {
    Log::spy();

    (new LoggedNewsletterList)->subscribe(signup());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'logged rather than sent')
            && $context['email'] === 'someone@example.com'
            && $context['ip'] === '203.0.113.7');
});

function signup(): NewsletterSignup
{
    return new NewsletterSignup(
        email: 'someone@example.com',
        consentedAt: CarbonImmutable::parse('2026-08-15 12:00:00'),
        ip: '203.0.113.7',
    );
}
