<?php

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * How the Stripe client is bound.
 *
 * **Nothing here talks to Stripe**, and nothing here could prove Stripe accepts
 * anything. It proves one thing: that resolving the client bounds how long a
 * customer's request can be held open by somebody else's outage.
 *
 * Why that is worth a test rather than a comment. `PayOrderController` calls
 * Stripe synchronously, which is a stated departure from .claude/api.md under
 * hard rule 14, and the timeout is the whole of what makes that departure
 * survivable. stripe-php defaults to eighty seconds with thirty to connect, so
 * losing this binding silently restores a minute and a half of held worker per
 * customer and nothing else in the suite would notice.
 */
it('bounds how long a stripe call can hold a request open', function () {
    app(StripeClient::class);

    $http = ApiRequestor::httpClient();

    expect($http)->toBeInstanceOf(CurlClient::class)
        ->and($http->getTimeout())->toBe(10)
        ->and($http->getConnectTimeout())->toBe(5);
});

/**
 * **Well under the library's own defaults**, written as a comparison rather than
 * as two more literals, so this fails if somebody raises the numbers above
 * towards them rather than only if they delete the binding.
 */
it('is far below what the library would do left alone', function () {
    app(StripeClient::class);

    $http = ApiRequestor::httpClient();

    expect($http->getTimeout())->toBeLessThan(CurlClient::DEFAULT_TIMEOUT)
        ->and($http->getConnectTimeout())->toBeLessThan(CurlClient::DEFAULT_CONNECT_TIMEOUT);
});
