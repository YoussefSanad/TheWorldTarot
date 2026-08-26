<?php

use App\Enums\PaymentInstructionType;
use App\Payments\PaymentInstruction;

/**
 * What the customer does next, as the frontend reads it.
 *
 * **Our own shape, so asserting it against our own intent is exactly right**,
 * per .claude/testing.md. These objects are published in section 9 of
 * API_CONTRACT.md and the frontend partner is building against them, which is
 * what makes the key names worth pinning rather than a detail.
 *
 * The values are pinned as literal strings here on purpose. Asserting
 * `PaymentInstructionType::ClientSecret->value` back at itself would agree with
 * the enum whatever the enum said, and the published contract is the string.
 */
it('names a client secret as a client secret', function () {
    expect(PaymentInstruction::clientSecret('pi_1_secret_2')->toArray())
        ->toBe(['type' => 'client_secret', 'client_secret' => 'pi_1_secret_2']);
});

it('says there is nothing to pay, and says nothing else', function () {
    expect(PaymentInstruction::nothingToPay()->toArray())
        ->toBe(['type' => 'nothing_to_pay']);
});

/**
 * **Two cases, and only two.** The redirect case was removed on 26 August 2026
 * having never had a caller. This pins the absence, so adding a case is a
 * deliberate act that updates the contract rather than something that arrives
 * quietly.
 */
it('has exactly the cases the contract publishes', function () {
    expect(array_map(
        fn (PaymentInstructionType $type): string => $type->value,
        PaymentInstructionType::cases(),
    ))->toBe(['nothing_to_pay', 'client_secret']);
});

/**
 * **Every answer carries a type**, which is the whole reason this replaced a
 * nullable string. The frontend reads a field name rather than inferring from
 * the shape of what came back.
 */
it('always says which kind of answer it is', function (PaymentInstruction $instruction) {
    expect($instruction->toArray())->toHaveKey('type')
        ->and($instruction->toArray()['type'])->toBeString()
        ->and($instruction->toArray()['type'])->not->toBe('');
})->with([
    fn () => PaymentInstruction::nothingToPay(),
    fn () => PaymentInstruction::clientSecret('pi_1_secret_2'),
]);

/**
 * A secret belongs in one response and nowhere else, so nothing that is not the
 * secret case carries the key at all.
 */
it('carries no empty fields for the cases that do not have them', function () {
    expect(PaymentInstruction::nothingToPay()->toArray())->not->toHaveKey('client_secret')
        ->and(array_keys(PaymentInstruction::clientSecret('s')->toArray()))->toBe(['type', 'client_secret']);
});
