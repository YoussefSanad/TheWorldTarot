<?php

use App\Payments\BuyerIdentity;
use App\Support\Greeting;

/*
| How a message opens when nobody ever told us the reader's name.
|
| Issue #52. The name in a receipt was never asked for by this application: it
| is whatever Stripe collected, which on the hosted page is a text field the
| buyer types and on the wallet road is a field that may not arrive at all.
| A real captured receipt opened "Hello Card Holder Name,".
|
| **A placeholder cannot be detected and this does not try.** Card
| authorisation carries no name check, so "Card Holder Name" and "Jennifer
| Eisenberger" are the same kind of value: free text nobody verified. A
| blocklist would catch the one already seen and not the next one. What is
| fixed here is the case that has a right answer, which is having no name.
*/

it('opens with the name somebody actually gave', function (?string $name, string $line) {
    expect(Greeting::line($name))->toBe($line);
})->with([
    // The apostrophe is not decoration. #45 sent every one of these as
    // `O&#039;Reilly` for a month, so a greeting is the one line in this
    // application where a punctuation mark has already gone wrong once.
    ["Miss Jodie O'Reilly", "Hello Miss Jodie O'Reilly,"],
    ['Jennifer Eisenberger', 'Hello Jennifer Eisenberger,'],

    // **The three shapes of nobody, and they are not one shape.** `null` is a
    // column that was never filled, `''` is what `OrderController` wrote into
    // a NOT NULL column before this issue, and whitespace is what a paste into
    // a name field produces. Rows of the second kind survive the migration, so
    // the greeting has to answer for them rather than assume they were cleaned.
    [null, 'Hello,'],
    ['', 'Hello,'],
    ['   ', 'Hello,'],

    ['  Jennifer  ', 'Hello Jennifer,'],
]);

/**
 * The defect this replaced, pinned where it can actually go red.
 *
 * **This was written first against `Greeting` and asserted nothing.** It said
 * `Greeting::line(null)` does not contain "jennifer", which is true of any
 * implementation that does not invent a name out of thin air: `Greeting` is
 * never handed an address, so it could not have produced one whatever it did.
 * The dataset above already covers `null`. `.claude/testing.md` calls a test
 * that agrees with the code whatever the code does the failure this project
 * has paid for twice, and a duplicate row dressed as a rule is a small one.
 *
 * `BuyerIdentity` is the class that held the address and the temptation. It
 * invented `jennifer` from `jennifer@example.com` on the reasoning that
 * "Hi jennifer" beats "Hi ,", which was true until the nameless greeting read
 * properly. Deleting the fallback here goes red; deleting it in `Greeting`
 * could not.
 */
it('never invents a name out of the address it was given', function () {
    $buyer = BuyerIdentity::from('jennifer@example.com', null);

    expect($buyer->name)->toBeNull()
        ->and($buyer->email)->toBe('jennifer@example.com')
        ->and(Greeting::line($buyer->name))->toBe('Hello,');
});

it('keeps a name that did arrive, unverified and all', function () {
    // The placeholder from the receipt in #52. It is used, deliberately:
    // nothing separates a typed placeholder from a typed name, so refusing it
    // would mean refusing every name this application has.
    $buyer = BuyerIdentity::from('jennifer@example.com', 'Card Holder Name');

    expect($buyer->name)->toBe('Card Holder Name')
        ->and(Greeting::line($buyer->name))->toBe('Hello Card Holder Name,');
});

it('reads a blank name from either road as no name', function (?string $collected) {
    expect(BuyerIdentity::from('jennifer@example.com', $collected)?->name)->toBeNull();
})->with([null, '', '   ']);
