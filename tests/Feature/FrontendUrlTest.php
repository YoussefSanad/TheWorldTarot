<?php

use App\Enums\ProductKey;
use App\Support\FrontendUrl;

beforeEach(function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');
});

it('sends somebody to the website and never to this API', function () {
    // Hard rule 1: this API renders nothing a customer can use, so a link back
    // here is a dead end.
    expect(FrontendUrl::resetPassword('jane@example.com', 'a-token'))
        ->toStartWith('https://staging.theworldtarot.com/reset-password/?')
        ->and(FrontendUrl::setPassword('jane@example.com', 'a-token'))
        ->toStartWith('https://staging.theworldtarot.com/set-password/?')
        ->and(FrontendUrl::redeem('K7M4-9PQR-2XYZ'))
        ->toStartWith('https://staging.theworldtarot.com/redeem/?');
});

it('carries the token and the address the reset is verified against', function () {
    expect(FrontendUrl::resetPassword('jane@example.com', 'a-token'))
        ->toBe('https://staging.theworldtarot.com/reset-password/?token=a-token&email=jane%40example.com');
});

it('ends a page in a slash however the environment spelled it', function (string $configured) {
    // The website is a static export built with `trailingSlash`, so the
    // slashless form is a redirect, and these two links carry a single-use
    // token that a scanner following that redirect can spend.
    config()->set('frontend.paths.set_password', $configured);

    expect(FrontendUrl::setPassword('jane@example.com', 'a-token'))
        ->toStartWith('https://staging.theworldtarot.com/set-password/?');
})->with([
    '/set-password',
    '/set-password/',
    'set-password',
]);

/*
| Where a recipient spends the code they were given. B6, issue #72.
*/

it('carries the code in the query string, in the form it is printed in', function () {
    // **A query parameter and not a path segment**, because the website is a
    // static export and a path per code would have to be pre-rendered at build
    // time. The printed form goes in, hyphens and all, because the same
    // characters are printed underneath the link for somebody typing them: two
    // spellings of one credential in one message is a person wondering which is
    // real. `/gifts/lookup` strips the separators before it looks anything up.
    expect(FrontendUrl::redeem('K7M4-9PQR-2XYZ'))
        ->toBe('https://staging.theworldtarot.com/redeem/?code=K7M4-9PQR-2XYZ');
});

it('ends the redemption page in a slash however the environment spelled it', function (string $configured) {
    config()->set('frontend.paths.redeem', $configured);

    expect(FrontendUrl::redeem('K7M4-9PQR-2XYZ'))
        ->toStartWith('https://staging.theworldtarot.com/redeem/?');
})->with([
    '/redeem',
    '/redeem/',
    'redeem',
]);

/*
| Where Stripe returns the customer, built here and never sent by the caller.
*/

/**
 * **`{CHECKOUT_SESSION_ID}` is Stripe's placeholder and stays unencoded.**
 * Stripe substitutes the id into the address literally, so a percent-encoded
 * brace is a confirmation page handed the string rather than the session.
 */
it('sends a completed checkout back with the placeholder stripe fills in', function () {
    expect(FrontendUrl::checkoutComplete())
        ->toBe('https://staging.theworldtarot.com/checkout/complete/?session_id={CHECKOUT_SESSION_ID}');
});

/**
 * A cancelled checkout lands back on the page it started from, and **the page
 * is named by a `ProductKey` rather than by a string the caller composed**. The
 * enum's values are already the frontend's reading-page slugs, so there is no
 * caller text that reaches an address at all.
 */
it('sends a cancelled checkout back to the reading it started from', function () {
    expect(FrontendUrl::readings(ProductKey::MonthAhead))
        ->toBe('https://staging.theworldtarot.com/readings/month-ahead/');
});

it('sends a cancelled checkout that named no reading to the index', function () {
    expect(FrontendUrl::readings())->toBe('https://staging.theworldtarot.com/readings/');
});
