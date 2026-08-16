<?php

use App\Contracts\PermanentFailure;
use App\Services\Newsletter\MailchimpAudience;
use App\Services\Newsletter\MailchimpRequestFailed;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * What this file can and cannot prove.
 *
 * It proves the decisions: that an already-subscribed address is success, that
 * a 429 is worth retrying and a 400 is not, that a missing credential says so
 * rather than becoming a 401. Those are ours and asserting them against our own
 * intent is exactly right.
 *
 * **It does not prove Mailchimp accepts the request.** Only Mailchimp can say
 * that, and `Http::fake()` will agree with whatever this file sends whether or
 * not their API has ever seen it. That is the failure mode recorded in
 * `.claude/testing.md`, and the answer is the captured vector at the bottom,
 * which is a manual step and not something this suite can do for itself.
 */
beforeEach(function () {
    config([
        'services.mailchimp.key' => 'test-key',
        'services.mailchimp.audience_id' => 'aud123',
        'services.mailchimp.server_prefix' => 'us20',
        'services.mailchimp.timeout' => 10,
    ]);
});

/**
 * The exception a call threw, for asking what kind it was.
 *
 * **Not `expect()->toThrow(PermanentFailure::class)`, which does not work and
 * does not say so.** Pest reads a name that is not a class as an expected
 * message, and `PermanentFailure` is an interface, so that form quietly asserts
 * the message contains the string "App\Contracts\PermanentFailure". It fails on
 * the permanent cases, which is how this was noticed, and it **passes** on
 * `not->toThrow(...)` for every transient case, which is a green assertion
 * proving nothing at all.
 */
function refusal(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected a refusal and nothing was thrown.');
}

function newsletterSignup(?string $name = null, ?string $ip = '203.0.113.7'): NewsletterSignup
{
    return new NewsletterSignup(
        email: 'someone@example.com',
        consentedAt: CarbonImmutable::parse('2026-08-15 12:34:56', 'Europe/Amsterdam'),
        ip: $ip,
        name: $name,
    );
}

it('subscribes an address to the configured audience', function () {
    Http::fake([
        '*' => Http::response(['id' => 'abc', 'status' => 'subscribed'], 200),
    ]);

    (new MailchimpAudience)->subscribe(newsletterSignup());

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://us20.api.mailchimp.com/3.0/lists/aud123/members');
        expect($request['email_address'])->toBe('someone@example.com');

        // Single opt-in. `pending` here would hold the contact until they
        // clicked a confirmation email nobody decided to send.
        expect($request['status'])->toBe('subscribed');
        expect($request['ip_signup'])->toBe('203.0.113.7');

        // Their format, and in UTC rather than the timezone the person was in.
        expect($request['timestamp_signup'])->toBe('2026-08-15 10:34:56');

        return $request->hasHeader('Authorization', 'Basic '.base64_encode('key:test-key'));
    });
});

it('sends a first name when there is one', function () {
    Http::fake(['*' => Http::response([], 200)]);

    (new MailchimpAudience)->subscribe(newsletterSignup(name: 'Jennifer'));

    Http::assertSent(fn ($request) => $request['merge_fields'] === ['FNAME' => 'Jennifer']);
});

it('omits fields it has no value for rather than sending null', function () {
    // Mailchimp validates these when present, so an explicit null is a value
    // that fails validation rather than a field that was not offered.
    Http::fake(['*' => Http::response([], 200)]);

    (new MailchimpAudience)->subscribe(newsletterSignup(ip: null));

    Http::assertSent(fn ($request) => ! array_key_exists('ip_signup', $request->data())
        && ! array_key_exists('merge_fields', $request->data()));
});

/**
 * The one that makes the whole thing safe to run twice, which managed queues
 * guarantee it will be.
 */
it('treats an address already on the list as success', function () {
    Http::fake([
        '*' => Http::response([
            'title' => 'Member Exists',
            'status' => 400,
            'detail' => 'someone@example.com is already a list member.',
        ], 400),
    ]);

    // A throw would fail this test on its own, so the absence of one is the
    // assertion. What is checked here is that it did not go on to send a
    // second request trying to fix the first.
    (new MailchimpAudience)->subscribe(newsletterSignup());

    Http::assertSentCount(1);
});

it('does not resubscribe somebody who unsubscribed', function () {
    // Mailchimp answers the same way for a former member, and returning
    // quietly is right. Putting them back because they filled in a form is how
    // a list earns a spam complaint.
    Http::fake([
        '*' => Http::response(['title' => 'Member Exists', 'status' => 400], 400),
    ]);

    (new MailchimpAudience)->subscribe(newsletterSignup());

    Http::assertSentCount(1);
});

it('refuses permanently when the address is rejected', function () {
    Http::fake([
        '*' => Http::response([
            'title' => 'Invalid Resource',
            'status' => 400,
            'detail' => 'Please provide a valid email address.',
        ], 400),
    ]);

    expect(refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup())))
        ->toBeInstanceOf(PermanentFailure::class);
});

it('refuses permanently when the key is rejected', function () {
    Http::fake(['*' => Http::response(['title' => 'API Key Invalid'], 401)]);

    expect(refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup())))
        ->toBeInstanceOf(PermanentFailure::class);
});

/**
 * 429 is the case a launch spike looks like. Calling it permanent would drop
 * addresses at the one moment the list is actually filling up.
 */
it('treats rate limiting as worth retrying', function () {
    Http::fake(['*' => Http::response(['title' => 'Too Many Requests'], 429)]);

    expect(refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup())))
        ->toBeInstanceOf(MailchimpRequestFailed::class)
        ->not->toBeInstanceOf(PermanentFailure::class);
});

it('treats a server error as worth retrying', function () {
    Http::fake(['*' => Http::response('', 503)]);

    expect(refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup())))
        ->toBeInstanceOf(MailchimpRequestFailed::class)
        ->not->toBeInstanceOf(PermanentFailure::class);
});

it('treats never reaching them as worth retrying', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup())))
        ->toBeInstanceOf(MailchimpRequestFailed::class)
        ->not->toBeInstanceOf(PermanentFailure::class);
});

/**
 * A missing credential is permanent on purpose. Retrying for six hours cannot
 * produce a key that was never set, and a queue quietly grinding on one is how
 * a deploy mistake stays invisible.
 */
it('says so when it is not configured, rather than letting it become a 401', function () {
    config(['services.mailchimp.key' => null]);
    Http::fake();

    $refusal = refusal(fn () => (new MailchimpAudience)->subscribe(newsletterSignup()));

    expect($refusal)->toBeInstanceOf(PermanentFailure::class);
    expect($refusal->getMessage())->toContain('MAILCHIMP_API_KEY is missing');

    Http::assertNothingSent();
});
