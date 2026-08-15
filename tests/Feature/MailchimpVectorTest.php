<?php

use App\Contracts\PermanentFailure;
use App\Services\Newsletter\MailchimpAudience;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * A known-answer vector, captured from Mailchimp on 15 August 2026.
 *
 * **This is the only file about Mailchimp with any authority.**
 * `MailchimpAudienceTest` proves our decisions against our own intent, which is
 * right and proves nothing about whether Mailchimp has ever accepted what we
 * send. `Http::fake()` agrees with the implementation whatever it does. Only
 * Mailchimp can say the request is valid, so Mailchimp was asked, by hand, once.
 *
 * ## How it was captured
 *
 * Three real POSTs to the live audience `3cef9f995a` on `us20`, sent through
 * `MailchimpAudience` itself rather than through a hand written curl, so what
 * is pinned below is the code path and not a reconstruction of it.
 *
 * 1. The first was accepted with **200**, creating `yosefsanad11@gmail.com`
 * 2. The second was identical and answered **400 Member Exists**, which is the
 *    real shape of the redelivery case the queue guarantees will happen
 * 3. The third came later, after that contact had been permanently deleted from
 *    the audience, and answered **400 Forgotten Email Not Subscribed**. Nobody
 *    would have invented that one, which is the argument for walking a thing
 *    rather than only testing it
 *
 * ## Regenerating this
 *
 * **Send a new pair against the live API and prove they are accepted before
 * changing a single character here.** Do not recompute the expected body from
 * the code this file exists to check. That turns a red test green while
 * removing the only thing protecting the integration, and it is exactly how
 * video playback stayed broken for five days with a green suite. See
 * `.claude/testing.md`.
 */

/** The exact bytes Mailchimp accepted. Key order included, since this is a string comparison. */
const ACCEPTED_BODY = '{"email_address":"yosefsanad11@gmail.com","status":"subscribed","ip_signup":"203.0.113.7","timestamp_signup":"2026-08-15 10:34:56"}';

const ACCEPTED_URL = 'https://us20.api.mailchimp.com/3.0/lists/3cef9f995a/members';

/** Trimmed to the fields that matter. The real response also carries links, stats and merge fields. */
const ACCEPTED_RESPONSE = '{"id":"db721ca05b041eef447707f148eabb25","email_address":"yosefsanad11@gmail.com","status":"subscribed","ip_signup":"203.0.113.7","timestamp_signup":"2026-08-15T10:34:56+00:00","list_id":"3cef9f995a"}';

/** Verbatim, from the second send. */
const DUPLICATE_RESPONSE = '{"title":"Member Exists","status":400,"detail":"yosefsanad11@gmail.com is already a list member. Use PUT to insert or update list members.","instance":"20c0ebb3-839c-d055-0456-0d25a5e8197e"}';

/**
 * Verbatim, from a third send later the same day, after the contact had been
 * **permanently deleted** from the audience rather than archived.
 *
 * Captured by accident during a local walk, which is the only reason it is
 * here: nobody would have thought to invent it. Mailchimp will not re-add an
 * address that was permanently deleted, ever, through any API call. The person
 * has to re-subscribe through a Mailchimp hosted form.
 */
const FORGOTTEN_RESPONSE = '{"title":"Forgotten Email Not Subscribed","status":400,"detail":"yosefsanad11@gmail.com was permanently deleted and cannot be re-imported. The contact must re-subscribe to get back on the list.","instance":"930732a9-1d46-45cf-a56f-5e4dfbf93aa9"}';

beforeEach(function () {
    config([
        'services.mailchimp.key' => 'test-key',
        'services.mailchimp.audience_id' => '3cef9f995a',
        'services.mailchimp.server_prefix' => 'us20',
        'services.mailchimp.timeout' => 10,
    ]);
});

/** The inputs that produced the capture, exactly. */
function capturedSignup(): NewsletterSignup
{
    return new NewsletterSignup(
        email: 'yosefsanad11@gmail.com',
        // Amsterdam, deliberately. The captured body is 10:34:56, so this also
        // pins the conversion to UTC rather than only the format.
        consentedAt: CarbonImmutable::parse('2026-08-15 12:34:56', 'Europe/Amsterdam'),
        ip: '203.0.113.7',
    );
}

it('sends the request Mailchimp accepted, byte for byte', function () {
    Http::fake([ACCEPTED_URL => Http::response(ACCEPTED_RESPONSE, 200)]);

    (new MailchimpAudience)->subscribe(capturedSignup());

    Http::assertSent(function ($request) {
        expect($request->url())->toBe(ACCEPTED_URL);
        expect($request->method())->toBe('POST');

        // The assertion with the authority. Anything that changes the shape of
        // what goes on the wire, a renamed field, a reordered array, a
        // different date format, fails here rather than in production.
        expect($request->body())->toBe(ACCEPTED_BODY);

        return true;
    });
});

it('reads the real acceptance as success', function () {
    Http::fake([ACCEPTED_URL => Http::response(ACCEPTED_RESPONSE, 200)]);

    (new MailchimpAudience)->subscribe(capturedSignup());

    Http::assertSentCount(1);
});

/**
 * The redelivery case, proved against what Mailchimp actually answers rather
 * than against a payload invented to match `alreadyOnTheList()`.
 *
 * Managed queues deliver at least once, so this is not an edge case, it is
 * Tuesday. Getting it wrong turns every redelivery into a failed job for
 * somebody who is perfectly well subscribed.
 */
it('reads the real duplicate refusal as success', function () {
    Http::fake([ACCEPTED_URL => Http::response(DUPLICATE_RESPONSE, 400)]);

    (new MailchimpAudience)->subscribe(capturedSignup());

    Http::assertSentCount(1);
});

/**
 * **A permanently deleted address is refused forever, and must not be retried.**
 *
 * The distinction matters because this arrives as a 400 exactly like Member
 * Exists does, and the two want opposite handling. Treating it as success would
 * report a subscription that will never exist. Treating it as temporary would
 * spend the job's whole six hour window on an answer that cannot change.
 *
 * Nothing our code sends can fix it, and neither can anybody here: Mailchimp
 * only lets that person back on through one of their own hosted forms. So the
 * job stops and says so, and the line in the log is the only way anybody finds
 * out. That is a real limit of single opt-in through the API, not a bug.
 */
it('gives up on an address Mailchimp has permanently deleted', function () {
    Http::fake([ACCEPTED_URL => Http::response(FORGOTTEN_RESPONSE, 400)]);

    $refusal = null;

    try {
        (new MailchimpAudience)->subscribe(capturedSignup());
    } catch (Throwable $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(PermanentFailure::class);
    expect($refusal->getMessage())->toContain('Forgotten Email Not Subscribed');
});
