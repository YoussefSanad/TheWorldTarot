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
 */
it('resolves whatever the config names', function () {
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
