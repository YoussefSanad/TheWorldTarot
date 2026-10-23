<?php

use App\Enums\Recording;

/**
 * Which settlement outcomes a person has to be shown.
 *
 * **A conditional business rule, so it gets a unit test**, per the first
 * section of .claude/testing.md. `ReconcileOrders` asks `fault()` rather than
 * listing the cases itself, so this is the only place the answer is decided and
 * proving it through two Stripe-mocked settlements proves it twice over for
 * two of the six.
 *
 * **The cases are pinned as a literal list on purpose.** Asserting
 * `Recording::cases()` back at itself would agree with the enum whatever the
 * enum said, and a seventh outcome is exactly the event this file exists to
 * make loud rather than let through.
 */
it('holds seven outcomes and no more', function () {
    // ~~Six outcomes~~ **Seven from 23 October 2026**: `PaidTwice`, a second
    // distinct Stripe payment for an order that was already paid.
    expect(Recording::cases())->toBe([
        Recording::Settled,
        Recording::AlreadySettled,
        Recording::NotSucceededYet,
        Recording::NotOurs,
        Recording::AmountMismatch,
        Recording::BuyerUnknown,
        Recording::PaidTwice,
    ]);
});

/**
 * Somebody has been charged and this application refused to settle it. Both of
 * these reach `Log::error` in `RecordStripePayment`, and until 4 September 2026
 * that log line was the only place either one could be seen.
 */
it('calls a refused settlement a fault', function () {
    // `PaidTwice` joins them: a customer has been charged a second time and a
    // person has to refund it by hand in Stripe, so the sweep must be loud.
    expect(Recording::AmountMismatch->fault())->toBeTrue()
        ->and(Recording::BuyerUnknown->fault())->toBeTrue()
        ->and(Recording::PaidTwice->fault())->toBeTrue();
});

/**
 * **A redelivery is not a fault and neither is a card still being authorised.**
 * The sweep runs every five minutes against orders that are waiting by
 * definition, so treating a no-op as something to shout about would bury the
 * two above it in noise on the tick they finally appear.
 */
it('calls a settlement and the three no-ops something other than a fault', function () {
    expect(Recording::Settled->fault())->toBeFalse()
        ->and(Recording::AlreadySettled->fault())->toBeFalse()
        ->and(Recording::NotSucceededYet->fault())->toBeFalse()
        ->and(Recording::NotOurs->fault())->toBeFalse();
});

/**
 * **The `match` inside `fault()` carries no default arm**, so a case added
 * without a decision raises `UnhandledMatchError` rather than inheriting "not a
 * fault". This walks every case to prove there is no gap in it today, which is
 * the assertion the three tests above cannot make between them once a seventh
 * exists.
 */
it('answers for every case it holds', function () {
    foreach (Recording::cases() as $outcome) {
        expect($outcome->fault())->toBeBool();
    }
});
