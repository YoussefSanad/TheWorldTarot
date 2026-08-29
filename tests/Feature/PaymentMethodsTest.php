<?php

use App\Contracts\PaymentGateway;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Payments\PaymentInstruction;
use App\Services\Payments\ManualSettlement;
use App\Services\Payments\PaymentMethods;
use App\Services\Payments\UnknownPaymentMethod;

/**
 * The registry of ways money can arrive.
 *
 * **Nothing here proves anything about any payment processor**, and it is not
 * trying to. It proves that a name resolves to the class config names, and that
 * a name config does not name is refused. See .claude/external-services.md.
 */
function offering(array $methods, string $default = 'manual'): PaymentMethods
{
    config()->set('payments.methods', $methods);
    config()->set('payments.default', $default);

    return app(PaymentMethods::class);
}

it('resolves each configured method to the class config names', function () {
    $methods = offering([
        'manual' => ManualSettlement::class,
        'invoice' => FakeInvoicing::class,
    ]);

    expect($methods->resolve('manual'))->toBeInstanceOf(ManualSettlement::class)
        ->and($methods->resolve('invoice'))->toBeInstanceOf(FakeInvoicing::class);
});

it('lists what this environment offers, in the order config declares it', function () {
    $methods = offering([
        'manual' => ManualSettlement::class,
        'invoice' => FakeInvoicing::class,
    ]);

    expect($methods->offered())->toBe(['manual', 'invoice'])
        ->and($methods->offers('manual'))->toBeTrue()
        ->and($methods->offers('invoice'))->toBeTrue()
        ->and($methods->offers('stripe'))->toBeFalse();
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
    $methods = offering(['manual' => ManualSettlement::class]);

    expect(fn () => $methods->resolve('stirpe'))
        ->toThrow(UnknownPaymentMethod::class);
});

it('says what it does offer when it refuses, so a typo is obvious', function () {
    $methods = offering(['manual' => ManualSettlement::class]);

    try {
        $methods->resolve('stirpe');
    } catch (UnknownPaymentMethod $refusal) {
        expect($refusal->getMessage())->toContain('stirpe')
            ->and($refusal->getMessage())->toContain('manual');
    }
});

/**
 * A method this environment does not offer is refused exactly like one that does
 * not exist at all. Locally there are no Stripe keys, so naming Stripe here has
 * to fail rather than resolve into something that will fail later, further away.
 */
it('refuses a method another environment offers but this one does not', function () {
    $methods = offering(['manual' => ManualSettlement::class]);

    expect(fn () => $methods->resolve('stripe'))
        ->toThrow(UnknownPaymentMethod::class);
});

it('uses the configured default when the caller does not name a method', function () {
    $methods = offering([
        'manual' => ManualSettlement::class,
        'invoice' => FakeInvoicing::class,
    ], default: 'invoice');

    expect($methods->resolve())->toBeInstanceOf(FakeInvoicing::class);
});

it('refuses a default naming a method that is not offered', function () {
    $methods = offering(['manual' => ManualSettlement::class], default: 'gift_code');

    expect(fn () => $methods->resolve())->toThrow(UnknownPaymentMethod::class);
});

/**
 * The container binding, which is what a caller that does not choose gets.
 */
it('hands the default method to anything asking for a gateway', function () {
    offering([
        'manual' => ManualSettlement::class,
        'invoice' => FakeInvoicing::class,
    ], default: 'invoice');

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeInvoicing::class);
});

/**
 * What this repository actually ships with, since the file above is where a
 * mistake would be and a test that only ever reads its own config proves
 * nothing about it.
 */
it('offers hand settlement everywhere, because support needs it after launch', function () {
    $shipped = require config_path('payments.php');

    expect($shipped['methods'])->toHaveKey('manual')
        ->and($shipped['methods']['manual'])->toBe(ManualSettlement::class);
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
