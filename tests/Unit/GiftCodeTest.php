<?php

use App\Models\Gift;

/*
| The gift code itself: what it is drawn from, and how it is printed.
|
| **A unit test because `.claude/testing.md` names this one**: "Gift code
| generation, format, and redemption rules" is on its list of logic with
| branching or rules, isolated from the framework where practical. Minting is a
| pure draw and printing is pure string work, so neither needs a database, a
| settled order or a mail fake to be wrong in front of somebody.
|
| `tests/Feature/SettleAGiftOrderTest.php` owns the other half, which is when a
| code comes into existence and who is told about it. This file owns what one
| is.
|
| **Why the alphabet is worth a test of its own.** The recipient reads these
| twelve characters off a screen and types them into another one, there is no
| expiry to fall back on and there are no refunds, so a glyph anybody confuses
| is not a cosmetic fault. A drifted alphabet would still mint, still store,
| still send, and only fail in somebody's hands.
*/

it('mints twelve characters', function () {
    // Twelve of a thirty two character alphabet is five bits each and sixty in
    // total. That number is what closes the lookup endpoint's oracle: it
    // answers in full about a code it recognises, so what stops guessing is
    // that nobody can guess their way to one.
    expect(Gift::mintCode())->toHaveLength(Gift::CODE_LENGTH)
        ->and(Gift::CODE_LENGTH)->toBe(12);
});

it('draws from Crockford base32 and never from a glyph somebody mistypes', function () {
    // A hundred draws is twelve hundred characters, so a single wrong glyph in
    // the alphabet is found rather than sampled past.
    $drawn = implode('', array_map(fn (): string => Gift::mintCode(), range(1, 100)));

    expect($drawn)->toMatch('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]+$/')
        // Named rather than left to the character class above, so a failure
        // says which of the four came back. `I` and `L` are read as `1` and
        // `O` as `0` when somebody types a code in, and a code that genuinely
        // contained one would make that reading a lie.
        ->and($drawn)->not->toContain('I')
        ->and($drawn)->not->toContain('L')
        ->and($drawn)->not->toContain('O')
        // Crockford drops `U` as well, which is what stops a random draw
        // spelling something nobody wants in an inbox.
        ->and($drawn)->not->toContain('U');
});

it('uses every character of the alphabet, so none is quietly unreachable', function () {
    $drawn = implode('', array_map(fn (): string => Gift::mintCode(), range(1, 200)));

    // 2400 characters over 32 slots. A generator that could never emit its last
    // character, the classic off-by-one on the upper bound, passes every
    // assertion above and is caught here.
    foreach (str_split(Gift::CODE_ALPHABET) as $character) {
        expect($drawn)->toContain($character);
    }
});

it('draws a different code every time', function () {
    $codes = array_map(fn (): string => Gift::mintCode(), range(1, 50));

    // **Not derived from anything about the gift**, which is the whole of the
    // argument: the client's own `3CARD10021` names its value and can be
    // counted to, and the next number along is somebody else's present.
    expect(array_unique($codes))->toHaveCount(50);
});

it('prints in three groups of four, and stores none of the hyphens', function () {
    $gift = new Gift;
    $gift->code = 'K7M49PQR2XYZ';

    // The hyphens are presentation. Twelve characters in one run is a thing
    // people lose their place in halfway through, and the column holds a value
    // rather than a format.
    expect($gift->printedCode())->toBe('K7M4-9PQR-2XYZ')
        ->and($gift->code)->toBe('K7M49PQR2XYZ');
});

it('prints what it minted, whatever was minted', function () {
    $gift = new Gift;
    $gift->code = Gift::mintCode();

    expect($gift->printedCode())->toMatch('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/')
        ->and(str_replace('-', '', (string) $gift->printedCode()))->toBe($gift->code);
});

it('has no printed form before it is minted', function () {
    // Null between placement and settlement and never afterwards. Nothing can
    // be looked up with an unminted gift and nothing redeemed, so there is
    // nothing to print and no row of hyphens standing in for it.
    $gift = new Gift;

    expect($gift->isMinted())->toBeFalse()
        ->and($gift->printedCode())->toBeNull();
});

/*
| Reading a code back off a screen, which is the other half of choosing the
| alphabet above.
|
| **B7 owns this and B6 made it safe.** `API_CONTRACT.md` specifies the
| normalising under "Gifts": uppercase, strip anything that is not a letter or a
| digit, read `I` and `L` as `1` and `O` as `0`. That last clause is only
| unambiguous because `CODE_ALPHABET` contains none of those three, which is
| why the reading lives beside the alphabet rather than in the endpoint that
| calls it.
|
| Unit, for the same reason minting is: it is pure string work, and a normaliser
| that drops a character is wrong in somebody's hands long before it is wrong in
| a database.
*/

it('reads a code however it was typed', function () {
    // The three shapes the contract names, and they are one code.
    expect(Gift::normaliseCode('k7m4 9pqr 2xyz'))->toBe('K7M49PQR2XYZ')
        ->and(Gift::normaliseCode('K7M4-9PQR-2XYZ'))->toBe('K7M49PQR2XYZ')
        ->and(Gift::normaliseCode('k7m49pqr2xyz'))->toBe('K7M49PQR2XYZ');
});

it('strips whatever somebody pasted around it', function () {
    // A code arrives out of a mail, so it arrives with whatever came with it.
    expect(Gift::normaliseCode("  K7M4\u{00a0}9PQR\t2XYZ\n"))->toBe('K7M49PQR2XYZ')
        ->and(Gift::normaliseCode('K7M4.9PQR.2XYZ'))->toBe('K7M49PQR2XYZ')
        ->and(Gift::normaliseCode('“K7M4/9PQR/2XYZ”'))->toBe('K7M49PQR2XYZ');
});

it('reads the three glyphs that go wrong off a screen', function () {
    // `I` and `L` become `1`, `O` becomes `0`, in either case.
    expect(Gift::normaliseCode('I234567890AB'))->toBe('1234567890AB')
        ->and(Gift::normaliseCode('L234567890AB'))->toBe('1234567890AB')
        ->and(Gift::normaliseCode('1234567890OB'))->toBe('12345678900B')
        ->and(Gift::normaliseCode('il234567890o'))->toBe('112345678900');
});

it('leaves a minted code exactly as it was minted', function () {
    // **The property the whole substitution rests on.** Reading `I` as `1` is
    // safe only because no code can contain an `I`, so normalising one of ours
    // has to be the identity, printed or not. A glyph added to the alphabet
    // that this file's other cases also happen to substitute would corrupt
    // every code drawn from it, silently, in somebody's hands.
    foreach (range(1, 100) as $ignored) {
        $gift = new Gift;
        $gift->code = Gift::mintCode();

        expect(Gift::normaliseCode($gift->code))->toBe($gift->code)
            ->and(Gift::normaliseCode((string) $gift->printedCode()))->toBe($gift->code);
    }
});

it('reads nothing out of a string holding nothing to read', function () {
    // Not a code, and not an error either. Whatever comes back is looked up and
    // misses, because the difference between "malformed" and "unknown" is a way
    // of asking whether a guess got closer.
    expect(Gift::normaliseCode('----'))->toBe('')
        ->and(Gift::normaliseCode(''))->toBe('');
});
