<?php

use App\Jobs\SubscribeToNewsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

// The last test here reaches another endpoint that reads the customers table.
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('newsletter');
});

it('accepts a signup and answers without waiting for the list', function () {
    $this->postJson('/api/v1/newsletter', [
        'email' => 'someone@example.com',
        'consent' => true,
    ])
        // Accepted, not created. Nothing exists yet, and saying 201 would claim
        // a contact that the queue has not made.
        ->assertStatus(202)
        ->assertExactJson(['subscribed' => true]);

    Queue::assertPushed(SubscribeToNewsletter::class, fn ($job) => $job->signup->email === 'someone@example.com');
});

it('records where and when somebody agreed', function () {
    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 172.68.0.1',
    ])->postJson('/api/v1/newsletter', [
        'email' => 'someone@example.com',
        'consent' => true,
    ])->assertStatus(202);

    // The visitor's address, not the proxy's, which is only true because
    // trusted proxies are configured. See TrustedProxiesTest.
    Queue::assertPushed(SubscribeToNewsletter::class, fn ($job) => $job->signup->ip === '203.0.113.7'
        && $job->signup->consentedAt->isToday());
});

it('takes a name when one is offered', function () {
    $this->postJson('/api/v1/newsletter', [
        'email' => 'someone@example.com',
        'name' => 'Jennifer',
        'consent' => true,
    ])->assertStatus(202);

    Queue::assertPushed(SubscribeToNewsletter::class, fn ($job) => $job->signup->name === 'Jennifer');
});

it('lowercases an address so one person cannot become two contacts', function () {
    $this->postJson('/api/v1/newsletter', [
        'email' => '  SomeOne@Example.COM ',
        'consent' => true,
    ])->assertStatus(202);

    Queue::assertPushed(SubscribeToNewsletter::class, fn ($job) => $job->signup->email === 'someone@example.com');
});

/**
 * **The checkbox is client side and gates a button.** Anybody can post without
 * it, and the copy beside it makes a promise about what somebody agreed to, so
 * a request that never agreed must not be able to satisfy that promise by
 * leaving the field out.
 */
it('refuses a signup that did not consent', function () {
    $this->postJson('/api/v1/newsletter', [
        'email' => 'someone@example.com',
    ])->assertStatus(422)->assertJsonValidationErrorFor('consent');

    $this->postJson('/api/v1/newsletter', [
        'email' => 'someone@example.com',
        'consent' => false,
    ])->assertStatus(422)->assertJsonValidationErrorFor('consent');

    Queue::assertNothingPushed();
});

it('refuses an address it cannot use', function (string $email) {
    $this->postJson('/api/v1/newsletter', [
        'email' => $email,
        'consent' => true,
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');

    Queue::assertNothingPushed();
})->with([
    'missing' => '',
    'not an address' => 'not-an-address',
    // 254 is RFC 5321's limit and the frontend field now says the same number.
    'longer than RFC 5321 allows' => [str_repeat('a', 246).'@example.com'],
]);

/**
 * **The same answer whatever the address turns out to be.**
 *
 * A different status, body or timing for an address already on the list would
 * turn a public endpoint into a way to ask whether a given person subscribed.
 */
it('answers identically for an address that is already subscribed', function () {
    $first = $this->postJson('/api/v1/newsletter', ['email' => 'someone@example.com', 'consent' => true]);
    $second = $this->postJson('/api/v1/newsletter', ['email' => 'someone@example.com', 'consent' => true]);

    expect($second->status())->toBe($first->status());
    expect($second->json())->toBe($first->json());

    // Both queued. Whether the second is a duplicate is Mailchimp's answer to
    // give, and the implementation treats it as success.
    Queue::assertPushed(SubscribeToNewsletter::class, 2);
});

/**
 * The honeypot. A person never sees the field, so anything in it is automated.
 * Answered exactly like a success, because a 422 would tell whoever sent it
 * which field gave them away.
 */
it('drops a signup that filled in the honeypot, without saying so', function () {
    $this->postJson('/api/v1/newsletter', [
        'email' => 'bot@example.com',
        'consent' => true,
        'website' => 'http://example.com',
    ])
        ->assertStatus(202)
        ->assertExactJson(['subscribed' => true]);

    Queue::assertNothingPushed();
});

it('stops somebody spending our Mailchimp quota', function () {
    foreach (range(1, 6) as $i) {
        $this->postJson('/api/v1/newsletter', [
            'email' => "someone{$i}@example.com",
            'consent' => true,
        ])->assertStatus(202);
    }

    $this->postJson('/api/v1/newsletter', [
        'email' => 'someone7@example.com',
        'consent' => true,
    ])->assertStatus(429);

    Queue::assertPushed(SubscribeToNewsletter::class, 6);
});

/**
 * The limiter's key prefix is load bearing, per the comment at the top of
 * routes/api.php. Without it every throttled route shares one counter per
 * address, which on 13 August 2026 meant six orders could refuse a password
 * reset.
 */
it('spends its own rate limit rather than another route\'s', function () {
    foreach (range(1, 6) as $i) {
        $this->postJson('/api/v1/newsletter', [
            'email' => "someone{$i}@example.com",
            'consent' => true,
        ])->assertStatus(202);
    }

    // Exhausted for the newsletter, and the next route along has not noticed.
    $this->postJson('/api/v1/newsletter', ['email' => 'x@example.com', 'consent' => true])
        ->assertStatus(429);

    // Asserted as "not throttled" rather than as a status, because what this
    // proves is whose counter was spent. Forgot-password answers 200 for an
    // address it does not know, deliberately, so pinning 200 here would be
    // pinning that endpoint's disclosure behaviour in the wrong file.
    expect($this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com'])->status())
        ->not->toBe(429);
});
