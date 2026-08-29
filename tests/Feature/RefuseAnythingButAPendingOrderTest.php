<?php

use App\Enums\OrderStatus;
use App\Payments\OrderCannotBeCharged;
use App\Payments\RefuseAnythingButAPendingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * The two things that stop a payment being started at all.
 *
 * **A collaborator both Stripe gateways call**, since 29 August 2026. It was a
 * private method inside `StripeCheckout` while there was one Stripe gateway,
 * and there are two: a hosted Checkout Session and a wallet's own
 * PaymentIntent. A shared base class was declined, because it would have pulled
 * the `StripeClient` constructor into a parent and made the two roads look more
 * alike than they are. See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 *
 * StripeCheckoutTest and StripeWalletTest both prove their gateway calls this
 * before asking Stripe for anything. This file is where the rules themselves
 * live, so neither of those has to be the place a rule is read from.
 */
it('lets a pending order whose total agrees with its lines through', function () {
    $order = orderWorth(2500);

    app(RefuseAnythingButAPendingOrder::class)($order);

    // No exception is the assertion. Pest wants one anyway, so this says the
    // order came out the far side untouched.
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * The endpoint answers `nothing_to_pay` before it gets this far, so reaching
 * here with a settled order is a caller that skipped the endpoint.
 */
it('refuses an order that is not pending, because there is nothing to collect', function () {
    $order = orderWorth(2500);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    expect(fn () => app(RefuseAnythingButAPendingOrder::class)($order->refresh()))
        ->toThrow(OrderCannotBeCharged::class);
});

/**
 * **And says so loudly**, decided 26 August 2026 as finding 14 of the phase B
 * review, and unchanged by the guard moving out of `StripeCheckout`.
 *
 * Prices are tax inclusive by decision, so nothing rounds and a mismatch means
 * something wrote an order badly. Refusing silently would protect this customer
 * and leave the next one to find it again.
 */
it('refuses an order whose total disagrees with its own lines, and shouts', function () {
    Log::spy();

    $order = orderWorth(2500);
    $order->forceFill(['total_amount' => 100])->save();

    expect(fn () => app(RefuseAnythingButAPendingOrder::class)($order->refresh()))
        ->toThrow(OrderCannotBeCharged::class);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context['order_id'] === $order->id
            && $context['total_amount'] === 100
            && $context['total_of_lines'] === 2500
            && $context['currency'] === 'USD'
    );
});
