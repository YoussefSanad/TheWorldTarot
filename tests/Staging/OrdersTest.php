<?php

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Tests\Staging\Target;

/**
 * Placing an order against a deployed environment.
 *
 * **These tests need at least one product with a real price.** Since
 * 20 August 2026 the seeder gives every product a real price in every currency,
 * so a freshly seeded environment can sell immediately — this used to seed
 * zeros, and an order refuses any line priced at zero, so nothing could be
 * bought until Jennifer set prices by hand. The skip below stays regardless:
 * a price can still be cleared in the panel, and a test that says why it
 * skipped beats one failing as though the code were broken.
 *
 * Set `STAGING_READING_KEY` and `STAGING_PASS_KEY` if you want to use products
 * other than the defaults, and `STAGING_CURRENCY` for anything but pounds.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

function walkCurrency(): string
{
    $currency = getenv('STAGING_CURRENCY');

    return is_string($currency) && $currency !== '' ? $currency : 'GBP';
}

function readingKey(): string
{
    $key = getenv('STAGING_READING_KEY');

    return is_string($key) && $key !== '' ? $key : 'one-card';
}

function passKey(): string
{
    $key = getenv('STAGING_PASS_KEY');

    return is_string($key) && $key !== '' ? $key : 'viewing-room-pass';
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function placeOrder(array $lines, ?string $email = null): ResponseInterface
{
    return Target::client()->post('api/v1/orders', [
        'json' => [
            'name' => 'Order Walk',
            'email' => $email ?? Target::freshEmail('order'),
            'currency' => walkCurrency(),
            'lines' => $lines,
        ],
    ]);
}

/**
 * Skips rather than fails when the catalogue is not ready to sell.
 */
function requireSellable(ResponseInterface $response, string $key): void
{
    Target::assertNotThrottled($response, 'orders');

    if ($response->getStatusCode() !== 422) {
        return;
    }

    $errors = Target::json($response)['errors'] ?? [];

    foreach ($errors as $field => $messages) {
        if (str_contains((string) $field, 'product')) {
            Assert::markTestSkipped(
                $key.' cannot be bought in '.walkCurrency().' on this environment. '
                .'Publish it and give it a price above zero in the admin panel, then run this again.'
            );
        }
    }
}

it('records an order, and does not pretend it has been paid for', function (): void {
    $response = placeOrder([['product' => readingKey(), 'quantity' => 1]]);

    requireSellable($response, readingKey());

    expect($response->getStatusCode())->toBe(201);

    $order = Target::json($response);

    expect($order['status'])->toBe('pending')
        // **Placing an order collects nothing.** The token is how the frontend
        // pays for it, in a second call, and the frontend must not read 201 as
        // "paid". `payment_url` is gone: checkout happens on their page, so
        // there is no URL to send anybody to.
        ->and($order)->not->toHaveKey('payment_url')
        ->and($order['pay_token'])->toBeString()
        ->and(strlen($order['pay_token']))->toBeGreaterThanOrEqual(32)
        ->and($order['currency'])->toBe(walkCurrency())
        ->and($order['lines'])->toHaveCount(1)
        ->and($response->getHeaderLine('Cache-Control'))->toContain('no-store');

    // Integer minor units, hard rule 5, all the way out to the wire.
    expect($order['total_amount'])->toBeInt()
        ->and($order['lines'][0]['unit_amount'])->toBeInt()
        ->and($order['total_amount'])->toBeGreaterThan(0);
});

it('totals an order from its lines', function (): void {
    $response = placeOrder([
        ['product' => readingKey(), 'quantity' => 2],
    ]);

    requireSellable($response, readingKey());

    expect($response->getStatusCode())->toBe(201);

    $order = Target::json($response);
    $line = $order['lines'][0];

    expect($order['total_amount'])->toBe($line['unit_amount'] * $line['quantity']);
});

it('does not tell the buyer whose order it is', function (): void {
    $response = placeOrder([['product' => readingKey()]]);

    requireSellable($response, readingKey());

    // Echoing an email back would make this a way to confirm one.
    expect(Target::json($response))->not->toHaveKey('customer')
        ->and(Target::json($response))->not->toHaveKey('email');
});

it('refuses a question on something that does not take one', function (): void {
    $response = placeOrder([
        ['product' => passKey(), 'question' => 'Will this be refused?'],
    ]);

    requireSellable($response, passKey());

    expect($response->getStatusCode())->toBe(422)
        ->and(Target::json($response)['errors'])->toHaveKey('lines.0.question');
});

it('refuses the whole order when one line is not for sale', function (): void {
    // `rush-24h` is a real product key that is deliberately not seeded, so this
    // is the honest version of "something you cannot buy" rather than a made up
    // string that would fail validation for a different reason.
    $response = placeOrder([
        ['product' => readingKey()],
        ['product' => 'rush-24h'],
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(Target::json($response)['errors'])->toHaveKey('lines.1.product');
});

it('refuses a currency we do not sell in', function (): void {
    $response = Target::client()->post('api/v1/orders', [
        'json' => [
            'name' => 'Order Walk',
            'email' => Target::freshEmail('currency'),
            'currency' => 'JPY',
            'lines' => [['product' => readingKey()]],
        ],
    ]);

    // Refused rather than quietly falling back to dollars, which would charge
    // somebody a number they never saw.
    expect($response->getStatusCode())->toBe(422)
        ->and(Target::json($response)['errors'])->toHaveKey('currency');
});

it('refuses an order with no lines at all', function (): void {
    $response = placeOrder([]);

    Target::assertNotThrottled($response, 'orders');

    expect($response->getStatusCode())->toBe(422);
});

it('needs an email from somebody who is not signed in', function (): void {
    $response = Target::client()->post('api/v1/orders', [
        'json' => [
            'name' => 'Order Walk',
            'currency' => walkCurrency(),
            'lines' => [['product' => readingKey()]],
        ],
    ]);

    Target::assertNotThrottled($response, 'orders');

    expect($response->getStatusCode())->toBe(422)
        ->and(Target::json($response)['errors'])->toHaveKey('email');
});
