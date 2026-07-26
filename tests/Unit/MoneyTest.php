<?php

use App\Support\Money;

it('converts what the client types into minor units', function (string $typed, int $stored) {
    expect(Money::toMinor($typed))->toBe($stored);
})->with([
    ['19.00', 1900],
    ['19.99', 1999],   // the classic float rounding case
    ['0.05', 5],
    ['0.5', 50],       // one decimal means fifty pence, not five
    ['100.00', 10000],
    ['100', 10000],
    ['19.90', 1990],   // trailing zero survives
    ['0', 0],
    [' 19.00 ', 1900], // stray whitespace from a paste
    ['19,99', 1999],   // a comma from a European keyboard
]);

it('converts minor units back into something readable', function (int $stored, string $shown) {
    expect(Money::toMajor($stored))->toBe($shown);
})->with([
    [1900, '19.00'],
    [1999, '19.99'],
    [5, '0.05'],
    [50, '0.50'],
    [10000, '100.00'],
    [0, '0.00'],
]);

it('survives a round trip in both directions', function (string $typed) {
    expect(Money::toMajor(Money::toMinor($typed)))->toBe($typed);
})->with(['19.00', '19.99', '0.05', '100.00', '0.50']);

it('refuses anything that is not an amount', function (string $rubbish) {
    expect(fn () => Money::toMinor($rubbish))->toThrow(InvalidArgumentException::class);
})->with([
    '19.999',   // more precision than a currency has
    'nineteen',
    '',
    '-19.00',   // a negative price is not a price
    '19.00.00',
    '1 9',
]);
