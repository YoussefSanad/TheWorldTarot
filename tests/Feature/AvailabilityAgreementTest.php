<?php

use App\Availability\UnpriceableCurrency;
use App\Enums\LocaleStatus;
use App\Filament\Resources\Cards\Pages\EditCard;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\ClipFilmSource;

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

/**
 * One product in a named state. One per test, because keys come from a fixed
 * catalogue of six and a matrix of states would run out of them.
 */
function aProductThatIs(string $state): Product
{
    return match ($state) {
        'finished' => aFinishedProduct(),
        'unpublished' => aFinishedProduct(['is_published' => false]),
        'unpriced' => aFinishedProduct(['price_usd' => 0, 'price_eur' => 0, 'price_gbp' => 0]),
        'priced in one currency only' => aFinishedProduct(['price_eur' => 0, 'price_gbp' => 0]),
        'missing one currency' => aFinishedProduct(['price_gbp' => 0]),
        'unwritten' => Product::factory()->create([
            'name' => [], 'short_description' => [], 'long_description' => [],
        ]),
        'written in the original language only' => Product::factory()->create(),
        'written in the other language only' => Product::factory()->create([
            'name' => ['es' => 'Una Carta'],
            'short_description' => ['es' => 'Una pregunta'],
            'long_description' => ['es' => 'Una pregunta, y una carta que la responde.'],
        ]),
        'written in whitespace' => aFinishedProduct(['short_description' => ['en' => '   ', 'es' => '   ']]),
        'removed' => tap(aFinishedProduct(), fn (Product $product) => $product->delete()),
    };
}

/**
 * A product finished in Spanish as well, which ProductFactory has no state for.
 *
 * Its own rather than `translatedProduct` from LanguageWithdrawalTest, so this
 * file runs on its own and so a state added here cannot change a test there.
 *
 * @param  array<string, mixed>  $attributes
 */
function aFinishedProduct(array $attributes = []): Product
{
    $product = Product::factory()->make($attributes);

    foreach (Product::requiredTranslations() as $field) {
        $english = (string) $product->getTranslation($field, 'en', false);

        if ($english !== '' && $product->getTranslation($field, 'es', false) === '') {
            $product->setTranslation($field, 'es', $english.' (es)');
        }
    }

    $product->save();

    return $product;
}

dataset('product states', [
    'finished',
    'unpublished',
    'unpriced',
    'priced in one currency only',
    'missing one currency',
    'unwritten',
    'written in the original language only',
    'written in the other language only',
    'written in whitespace',
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

it('answers the same in the query and in PHP, whatever state a product is in', function (string $state) {
    $product = aProductThatIs($state);

    foreach (['en', 'es'] as $locale) {
        $byQuery = Product::withTrashed()->availableIn($locale)->whereKey($product->id)->exists();

        expect($byQuery)->toBe($product->isAvailableIn($locale), "the shop disagreed about a product {$state}, in {$locale}");
    }
})->with('product states');

it('always has something to say about a product it keeps off the website', function (string $state) {
    $product = aProductThatIs($state);

    foreach (['en', 'es'] as $locale) {
        expect($product->whatIsMissing($locale) === [])
            ->toBe($product->isAvailableIn($locale), "the panel could not explain a product {$state}, in {$locale}");
    }
})->with('product states');

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

it('keeps a removed record off the website in both renderings', function () {
    $card = aCardThatIs('removed');
    $product = aProductThatIs('removed');

    // Settled rather than inherited. The query says so itself instead of
    // leaning on the global scope, so asking `withTrashed` cannot let a removed
    // record back onto the website, and the predicate now agrees with it.
    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->isViewableIn('en'))->toBeFalse()
        ->and($product->isAvailableIn('en'))->toBeFalse()
        ->and(Card::withTrashed()->availableIn('en')->whereKey($card->id)->exists())->toBeFalse()
        ->and(Product::withTrashed()->availableIn('en')->whereKey($product->id)->exists())->toBeFalse();
});

it('refuses a currency the products table cannot price, in every rendering', function () {
    $product = aProductThatIs('finished');

    // A currency added to config without the migration that gives it a column.
    // The query used to die on a Postgres error and the predicate used to
    // quietly answer that nothing was priced, so the same mistake either broke
    // the shop or emptied it depending which caller asked first.
    config(['currencies.available' => config('currencies.available') + [
        'CHF' => ['symbol' => 'CHF', 'label' => 'Swiss Franc'],
    ]]);

    expect(fn () => Product::availableIn('en')->get())->toThrow(UnpriceableCurrency::class, 'price_chf')
        ->and(fn () => $product->isAvailableIn('en'))->toThrow(UnpriceableCurrency::class, 'price_chf')
        ->and(fn () => $product->whatIsMissing('en'))->toThrow(UnpriceableCurrency::class, 'price_chf');
});

it('reads the list to her in the order she reads it', function () {
    $card = Card::factory()->withoutCopy()->withoutImage()->create();

    // What she writes, then what she uploads. The order is the declaration's,
    // so it cannot drift as conditions are added.
    expect($card->whatIsMissing('en'))
        ->toBe(['a name', 'a short description', 'a full description', 'a picture']);

    expect(aProductThatIs('unpriced')->whatIsMissing('en'))
        ->toBe(['a price in every currency']);
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

it('serves the video for exactly the cards the declaration calls watchable', function (string $state) {
    $card = aCardThatIs($state);
    $passHolder = Customer::factory()->withViewingRoomAccess()->create();

    // Spanish has to be servable for the route to be reachable at all, and it
    // is flipped after the rows exist. Saving or removing a record that is not
    // translated takes a language straight back off the website, which is a
    // real rule and a different one from the one under test.
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();

    foreach (['en', 'es'] as $locale) {
        // The fourth rendering, and the one that was never asked. The endpoint
        // composed the rule at the call site, so it agreed with the
        // declaration only because the Viewing Room's adapter happened to
        // repeat the declaration's video condition word for word.
        $served = $this->actingAs($passHolder, 'sanctum')
            ->getJson("/api/v1/{$locale}/cards/{$card->id}/video")
            ->getStatusCode() === 200;

        expect($served)->toBe(
            $card->isViewableIn($locale),
            "the video route disagreed with the declaration about a card {$state}, in {$locale}",
        );
    }
})->with('card states');

it('moves the flag, the panel and the 404 together when a source narrows differently', function () {
    // A card the adapters in place today can play: its film is live while a
    // replacement transcodes. That is the case the two video columns exist
    // for, and the one a source asking the status rather than the id gets
    // wrong.
    $card = aCardThatIs('with a film being replaced');
    $passHolder = Customer::factory()->withViewingRoomAccess()->create();

    $flag = fn (): bool => collect($this->getJson('/api/v1/en/cards')->assertOk()->json())
        ->firstWhere('id', $card->id)['in_viewing_room'];

    $served = fn (): int => $this->actingAs($passHolder, 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->getStatusCode();

    $panel = fn (): array => $card->fresh()->whatTheViewingRoomIsWaitingFor('en');

    expect($flag())->toBeTrue()
        ->and($served())->toBe(200)
        ->and($panel())->toBe([]);

    // The morning a purpose-made clip ships.
    //
    // **The swap lands after a request has already been made, deliberately.**
    // The router caches a controller on the route once it has built one, so the
    // endpoint goes on holding the adapter it was given and would still play
    // this card if asked. What refuses it is the declaration alone, which is
    // exactly the authority this ticket moved the refusal to. Before that, the
    // endpoint composed the rule itself and served the film while
    // `in_viewing_room` and the panel had already stopped calling it watchable.
    config()->set('viewing-room.source', ClipFilmSource::class);

    expect($flag())->toBeFalse()
        ->and($served())->toBe(404)
        ->and($panel())->toBe(['a clip cut for the Viewing Room']);
});

it('shows the panel a source that narrows differently, in the source own words', function () {
    $card = aCardThatIs('with a film being replaced');

    config()->set('viewing-room.source', ClipFilmSource::class);

    Filament::setCurrentPanel('admin');

    // The wording finishes the same sentence every other condition finishes,
    // so a source's rule reads in her list rather than beside it.
    Livewire::actingAs(Admin::factory()->create(), 'admin')
        ->test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('Not in the Viewing Room yet')
        ->assertSee('a clip cut for the Viewing Room');
});
