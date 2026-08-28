<?php

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
        ->toStartWith('https://staging.theworldtarot.com/set-password/?');
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
