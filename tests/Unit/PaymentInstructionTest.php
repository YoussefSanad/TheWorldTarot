<?php

use App\Payments\PaymentInstruction;

/**
 * What the customer does next, as the frontend reads it.
 *
 * **Our own shape, so asserting it against our own intent is exactly right**,
 * per .claude/testing.md. These three objects are published in section 9 of
 * API_CONTRACT.md and the frontend partner is building against them, which is
 * what makes the key names worth pinning rather than a detail.
 */
it('names a client secret as a client secret', function () {
    expect(PaymentInstruction::clientSecret('pi_1_secret_2')->toArray())
        ->toBe(['type' => 'client_secret', 'client_secret' => 'pi_1_secret_2']);
});

it('says there is nothing to pay, and says nothing else', function () {
    expect(PaymentInstruction::nothingToPay()->toArray())
        ->toBe(['type' => 'nothing_to_pay']);
});

it('names a redirect as a redirect', function () {
    expect(PaymentInstruction::redirectTo('https://pay.example.test/1')->toArray())
        ->toBe(['type' => 'redirect', 'url' => 'https://pay.example.test/1']);
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
    fn () => PaymentInstruction::redirectTo('https://pay.example.test/1'),
]);

/**
 * A secret belongs in one response and nowhere else, so nothing that is not the
 * secret case carries the key at all.
 */
it('carries no empty fields for the cases that do not have them', function () {
    expect(PaymentInstruction::nothingToPay()->toArray())->not->toHaveKey('client_secret')
        ->and(PaymentInstruction::nothingToPay()->toArray())->not->toHaveKey('url')
        ->and(PaymentInstruction::clientSecret('s')->toArray())->not->toHaveKey('url')
        ->and(PaymentInstruction::redirectTo('u')->toArray())->not->toHaveKey('client_secret');
});
