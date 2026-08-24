<?php

use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * "Is this on the website in this language" is one rule with three renderings:
 * a query listings run through, a predicate a loaded record answers, and the
 * words the panel tells Jennifer. Before the declaration they were three
 * implementations kept in agreement by hand, and nothing here existed.
 *
 * Different callers ask different renderings. The Library listing takes
 * `in_viewing_room` from the predicate while the video route takes its 404 from
 * the query, so a disagreement between them does not show up as a failure
 * anywhere. It shows up as a card that is in the Library, says it is watchable,
 * and 404s when watched.
 *
 * These are the tests that would have caught that.
 */

/**
 * One card in a named state, so a failure says which state broke rather than
 * which line.
 */
function aCardThatIs(string $state): Card
{
    return match ($state) {
        'finished, waiting for a film' => Card::factory()->translatedInto('es')->create(),
        'finished, with a film ready' => Card::factory()->watchable()->translatedInto('es')->create(),
        'unpublished' => Card::factory()->unpublished()->translatedInto('es')->create(),
        'unpublished, with a film ready' => Card::factory()->unpublished()->watchable()->translatedInto('es')->create(),
        'without a picture' => Card::factory()->withoutImage()->translatedInto('es')->create(),
        'without a picture, with a film ready' => Card::factory()->withoutImage()->watchable()->translatedInto('es')->create(),
        'unwritten' => Card::factory()->withoutCopy()->create(),
        'written in the original language only' => Card::factory()->create(),
        'written in the other language only' => Card::factory()->withoutCopy()->create([
            'name' => ['es' => 'El Loco'],
            'short_description' => ['es' => 'Un comienzo'],
            'long_description' => ['es' => 'Un comienzo, y todo lo que viene despues.'],
        ]),
        'written in whitespace' => Card::factory()->translatedInto('es')->create([
            'short_description' => ['en' => '   ', 'es' => '   '],
        ]),
        'with a film in flight' => Card::factory()->videoProcessing()->translatedInto('es')->create(),
        'with a film that failed' => Card::factory()->videoFailed()->translatedInto('es')->create(),
        'with a film being replaced' => Card::factory()->videoBeingReplaced()->translatedInto('es')->create(),
        'removed' => tap(
            Card::factory()->watchable()->translatedInto('es')->create(),
            fn (Card $card) => $card->delete(),
        ),
    };
}

dataset('card states', [
    'finished, waiting for a film',
    'finished, with a film ready',
    'unpublished',
    'unpublished, with a film ready',
    'without a picture',
    'without a picture, with a film ready',
    'unwritten',
    'written in the original language only',
    'written in the other language only',
    'written in whitespace',
    'with a film in flight',
    'with a film that failed',
    'with a film being replaced',
    'removed',
]);

it('answers the same in the query and in PHP, whatever state a card is in', function (string $state) {
    $card = aCardThatIs($state);

    foreach (['en', 'es'] as $locale) {
        // `withTrashed`, deliberately. The query used to hold an opinion about
        // removed records that it never stated and the predicate did not share,
        // borrowed from the soft delete global scope, and asking through the
        // global scope is what kept that hidden.
        $byQuery = Card::withTrashed()->availableIn($locale)->whereKey($card->id)->exists();

        expect($byQuery)->toBe($card->isAvailableIn($locale), "the Library disagreed about a card {$state}, in {$locale}");
    }
})->with('card states');

it('answers the same about the Viewing Room in the query and in PHP', function (string $state) {
    $card = aCardThatIs($state);

    foreach (['en', 'es'] as $locale) {
        $byQuery = Card::withTrashed()->viewableIn($locale)->whereKey($card->id)->exists();

        expect($byQuery)->toBe($card->isViewableIn($locale), "the Viewing Room disagreed about a card {$state}, in {$locale}");
    }
})->with('card states');

it('always has something to say about a card it keeps off the website', function (string $state) {
    $card = aCardThatIs($state);

    foreach (['en', 'es'] as $locale) {
        // The failure this pins is silent: a condition added to the gate and
        // forgotten in the explanation leaves a card off the website with the
        // panel saying it is on.
        expect($card->whatIsMissing($locale) === [])
            ->toBe($card->isAvailableIn($locale), "the panel could not explain a card {$state}, in {$locale}");

        expect($card->whatTheViewingRoomIsWaitingFor($locale) === [])
            ->toBe($card->isViewableIn($locale), "the panel could not explain the Viewing Room about a card {$state}, in {$locale}");
    }
})->with('card states');

it('lists exactly what the predicate would let through', function () {
    foreach ([
        'finished, waiting for a film',
        'finished, with a film ready',
        'unpublished',
        'without a picture',
        'unwritten',
        'written in the original language only',
        'with a film in flight',
        'removed',
    ] as $state) {
        aCardThatIs($state);
    }

    // A row at a time proves the rule. A listing proves the query composes,
    // which is what actually reaches a visitor.
    foreach (['en', 'es'] as $locale) {
        $everyCard = Card::withoutGlobalScopes()->get();

        expect(Card::withTrashed()->availableIn($locale)->pluck('id')->sort()->values()->all())
            ->toBe($everyCard->filter(fn (Card $card): bool => $card->isAvailableIn($locale))
                ->pluck('id')->sort()->values()->all())
            ->and(Card::withTrashed()->viewableIn($locale)->pluck('id')->sort()->values()->all())
            ->toBe($everyCard->filter(fn (Card $card): bool => $card->isViewableIn($locale))
                ->pluck('id')->sort()->values()->all());
    }
});

it('keeps a removed card off the website in both renderings', function () {
    $card = aCardThatIs('removed');

    // Settled rather than inherited. The query says so itself instead of
    // leaning on the global scope, so asking `withTrashed` cannot let a removed
    // record back onto the website, and the predicate now agrees with it.
    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->isViewableIn('en'))->toBeFalse()
        ->and(Card::withTrashed()->availableIn('en')->whereKey($card->id)->exists())->toBeFalse();
});

it('reads the list to her in the order she reads it', function () {
    $card = Card::factory()->withoutCopy()->withoutImage()->create();

    // What she writes, then what she uploads. The order is the declaration's,
    // so it cannot drift as conditions are added.
    expect($card->whatIsMissing('en'))
        ->toBe(['a name', 'a short description', 'a full description', 'a picture']);
});

it('reads the Viewing Room its film first and the website last', function () {
    $card = Card::factory()->withoutImage()->create();

    // "To be on the website first" reads oddly anywhere but last, and it is the
    // one thing on the list she fixes on a different screen.
    expect($card->whatTheViewingRoomIsWaitingFor('en'))
        ->toBe(['a video', 'to be on the website first']);
});

it('tells her about one name, not two, when both languages are waiting for it', function () {
    $card = Card::factory()->withoutCopy()->create();

    // The original language and the language being read are two uses of one
    // condition. A card unwritten in both is waiting for one name.
    expect($card->whatIsMissing('es'))
        ->toBe(['a name', 'a short description', 'a full description']);
});
