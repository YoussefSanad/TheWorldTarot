<?php

use App\Services\Payments\ManualSettlement;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Which buttons the reading page draws.
 *
 * **The frontend cannot infer this**, which is the argument `PaymentMethods`
 * has carried since `051c6a1` with no production caller. It was right and
 * abstract then and it is concrete now: whether the wallet button exists at all
 * depends on whether this environment configured Stripe, and the panel draws
 * one button or two on that answer.
 *
 * Flat and with no language segment, beside `/currencies` and `/languages`,
 * because it carries no copy and varies by nothing about the visitor.
 */
it('answers what a customer may press here, in the order config declares it', function () {
    config()->set('payments.methods', [
        'manual' => ManualSettlement::class,
        'stripe' => StripeCheckout::class,
        'stripe_wallet' => StripeWallet::class,
    ]);

    $this->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertExactJson(['methods' => ['stripe', 'stripe_wallet']]);
});

/**
 * **`manual` is offered, to a person in the panel, everywhere and permanently.**
 * It is not something a customer can press, and drawing a button for it would
 * be a button that settles nothing.
 */
it('never offers hand settlement, which belongs to a person in the panel', function () {
    config()->set('payments.methods', ['manual' => ManualSettlement::class]);

    $this->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertExactJson(['methods' => []]);
});

/**
 * **An object rather than a bare array**, matching `/currencies`, because
 * gifting brings `gift_code` and likely a second key with it.
 */
it('answers an object, so a second key can join it without a breaking change', function () {
    $this->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonStructure(['methods']);
});

/**
 * A method this environment did not configure is simply absent, which is the
 * whole point: the same code deployed twice answers differently.
 */
it('leaves out a method this environment did not configure', function () {
    config()->set('payments.methods', [
        'manual' => ManualSettlement::class,
        'stripe' => StripeCheckout::class,
    ]);

    $this->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertExactJson(['methods' => ['stripe']]);
});

it('is readable without authentication, since anybody may buy', function () {
    $this->getJson('/api/v1/payment-methods')->assertOk();
});
