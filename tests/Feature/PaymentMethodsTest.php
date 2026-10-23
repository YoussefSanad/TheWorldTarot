<?php

use App\Contracts\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Payments\PaymentInstruction;
use App\Services\Payments\PaymentMethods;
use App\Services\Payments\UnknownPaymentMethod;

/**
 * The registry of ways money can arrive.
 *
 * **Nothing here proves anything about any payment processor**, and it is not
 * trying to. It proves that a name resolves to the class config names, and that
 * a name config does not name is refused. See .claude/external-services.md.
 *
 * ~~Written around hand settlement.~~ **Rewritten 22 October 2026** around
 * `stripe` and a test-only gateway, because the `manual` method was retired.
 */
function offering(array $methods, string $default = 'stripe'): PaymentMethods
{
    config()->set('payments.methods', $methods);
    config()->set('payments.default', $default);

    return app(PaymentMethods::class);
}

it('resolves each configured method to the class config names', function () {
    $methods = offering([
        'stripe' => FakeInvoicing::class,
        'invoice' => FakeInvoicingToo::class,
    ]);

    expect($methods->resolve('stripe'))->toBeInstanceOf(FakeInvoicing::class)
        ->and($methods->resolve('invoice'))->toBeInstanceOf(FakeInvoicingToo::class);
});

it('lists what this environment offers, in the order config declares it', function () {
    $methods = offering([
        'stripe' => FakeInvoicing::class,
        'invoice' => FakeInvoicingToo::class,
    ]);

    expect($methods->offered())->toBe(['stripe', 'invoice'])
        ->and($methods->offers('stripe'))->toBeTrue()
        ->and($methods->offers('invoice'))->toBeTrue()
        ->and($methods->offers('stripe_wallet'))->toBeFalse();
});

/**
 * **What a customer may press is now everything that is offered.**
 *
 * ~~`offeredToCustomers()` was `offered()` less `manual`.~~ **Struck 22 October
 * 2026**: hand settlement was retired, so there is nothing to subtract. The
 * gateway below is deliberately registered under the old name, which is the only
 * way to see the filter gone: while any exclusion by that name remained, it
 * would be dropped from the list.
 */
it('lists what a customer may press, which is everything offered', function () {
    $methods = offering([
        'stripe' => FakeInvoicing::class,
        'invoice' => FakeInvoicingToo::class,
    ]);

    expect($methods->offeredToCustomers())->toBe($methods->offered())
        ->and($methods->offeredToCustomers())->toBe(['stripe', 'invoice']);
});

it('has no exclusion by name left in what a customer may press', function () {
    $methods = offering(['manual' => FakeInvoicing::class]);

    expect($methods->offeredToCustomers())->toBe(['manual']);
});

it('answers an empty list where nothing is configured', function () {
    $methods = offering([]);

    expect($methods->offered())->toBe([])
        ->and($methods->offeredToCustomers())->toBe([]);
});

/**
 * Keyed by position, not by the keys config happened to use, so the endpoint
 * that renders this answers a JSON array rather than an object.
 */
it('answers a list rather than a map, whatever config was keyed by', function () {
    $methods = offering([
        'stripe' => FakeInvoicing::class,
        'stripe_wallet' => FakeInvoicingToo::class,
    ]);

    expect(array_keys($methods->offeredToCustomers()))->toBe([0, 1]);
});

/**
 * **The assertion this file exists for.**
 *
 * config/newsletter.php falls through to Mailchimp for an unrecognised driver,
 * so a typo cannot quietly stop collecting subscribers. Money runs the other
 * way, and a typo must never quietly start charging cards or settle an order
 * nobody paid for. Refusing is the safe direction.
 */
it('refuses a method it does not recognise, rather than substituting one', function () {
    $methods = offering(['stripe' => FakeInvoicing::class]);

    expect(fn () => $methods->resolve('stirpe'))
        ->toThrow(UnknownPaymentMethod::class);
});

it('says what it does offer when it refuses, so a typo is obvious', function () {
    $methods = offering(['stripe' => FakeInvoicing::class]);

    $message = null;

    try {
        $methods->resolve('stirpe');
    } catch (UnknownPaymentMethod $refusal) {
        $message = $refusal->getMessage();
    }

    expect($message)->toBeString()
        ->and($message)->toContain('stirpe')
        ->and($message)->toContain('stripe');
});

/**
 * A method this environment does not offer is refused exactly like one that does
 * not exist at all. Locally there are no Stripe keys, so naming Stripe here has
 * to fail rather than resolve into something that will fail later, further away.
 */
it('refuses a method another environment offers but this one does not', function () {
    $methods = offering(['invoice' => FakeInvoicing::class], default: 'invoice');

    expect(fn () => $methods->resolve('stripe'))
        ->toThrow(UnknownPaymentMethod::class);
});

/**
 * **An environment with no Stripe keys offers an empty registry**, and every
 * name is then refused, the default included. This is what `/pay` answers 422 on.
 */
it('refuses everything, the default included, when the registry is empty', function () {
    $methods = offering([], default: 'stripe');

    expect(fn () => $methods->resolve())->toThrow(UnknownPaymentMethod::class)
        ->and(fn () => $methods->resolve('stripe'))->toThrow(UnknownPaymentMethod::class)
        ->and(fn () => $methods->resolve('manual'))->toThrow(UnknownPaymentMethod::class);
});

it('uses the configured default when the caller does not name a method', function () {
    $methods = offering([
        'stripe' => FakeInvoicing::class,
        'invoice' => FakeInvoicingToo::class,
    ], default: 'invoice');

    expect($methods->resolve())->toBeInstanceOf(FakeInvoicingToo::class);
});

it('refuses a default naming a method that is not offered', function () {
    $methods = offering(['stripe' => FakeInvoicing::class], default: 'gift_code');

    expect(fn () => $methods->resolve())->toThrow(UnknownPaymentMethod::class);
});

/**
 * The container binding, which is what a caller that does not choose gets.
 */
it('hands the default method to anything asking for a gateway', function () {
    offering([
        'stripe' => FakeInvoicing::class,
        'invoice' => FakeInvoicingToo::class,
    ], default: 'invoice');

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeInvoicingToo::class);
});

/**
 * What this repository actually ships with, since the file above is where a
 * mistake would be and a test that only ever reads its own config proves
 * nothing about it.
 */
it('ships with no manual entry and no manual enum case', function () {
    $shipped = require config_path('payments.php');

    expect($shipped['methods'])->not->toHaveKey('manual')
        ->and(array_column(PaymentMethod::cases(), 'value'))->not->toContain('manual');
});

/**
 * A fake proves a caller and never proves an implementation.
 * See .claude/external-services.md.
 */
class FakeInvoicing implements PaymentGateway
{
    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        return PaymentInstruction::nothingToPay();
    }
}

class FakeInvoicingToo extends FakeInvoicing {}
