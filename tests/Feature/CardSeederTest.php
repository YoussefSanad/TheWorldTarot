<?php

use App\Models\Card;
use App\Support\TranslationCoverage;
use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedCards(): void
{
    (new CardSeeder)->run();
}

it('creates the 22 major arcana, numbered from zero', function () {
    seedCards();

    expect(Card::count())->toBe(22)
        ->and(Card::orderBy('number')->pluck('number')->all())->toBe(range(0, 21))
        ->and(Card::firstWhere('number', 0)->getTranslation('name', 'en'))->toBe('The Fool')
        ->and(Card::firstWhere('number', 21)->getTranslation('name', 'en'))->toBe('The World');
});

it('gives every card a name of its own', function () {
    seedCards();

    // Read through the model rather than plucking the column, which comes back
    // as raw json and would compare 22 strings that all differ trivially.
    $names = Card::all()->map(fn (Card $card): string => $card->getTranslation('name', 'en'));

    expect($names->unique())->toHaveCount(22);
});

it('creates them with no copy, so nobody can see them yet', function () {
    seedCards();

    $card = Card::firstWhere('number', 0);

    // Availability is calculated, so a deck that has not been written is off
    // the website without anything having to be switched off.
    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->missingTranslations('en'))
        ->toBe(['short_description', 'long_description'])
        ->and(Card::availableIn('en')->count())->toBe(0);
});

it('can be run repeatedly without duplicating anything', function () {
    seedCards();
    seedCards();
    seedCards();

    // It runs on every deploy, so this is the property that matters most.
    expect(Card::count())->toBe(22);
});

it('never overwrites copy the client has written', function () {
    seedCards();

    Card::firstWhere('number', 0)->update([
        'name' => ['en' => 'The Fool, Renamed'],
        'short_description' => ['en' => 'Her words.'],
        'long_description' => ['en' => 'Her longer words.'],
    ]);

    seedCards();

    $card = Card::firstWhere('number', 0);

    // A card's name is a value, not structure. Unlike a product's fulfillment
    // mode, there is nothing here for a deploy to put back.
    expect($card->getTranslation('name', 'en'))->toBe('The Fool, Renamed')
        ->and($card->getTranslation('short_description', 'en'))->toBe('Her words.');
});

it('leaves a translation alone as well', function () {
    seedCards();

    Card::firstWhere('number', 0)->update([
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
    ]);

    seedCards();

    expect(Card::firstWhere('number', 0)->getTranslation('name', 'es'))->toBe('El Loco');
});

it('does not resurrect a card that was deleted', function () {
    seedCards();

    Card::firstWhere('number', 0)->delete();

    seedCards();

    // It matches the trashed row rather than adding a second card numbered
    // zero, and saving a trashed model does not restore it.
    expect(Card::withTrashed()->where('number', 0)->count())->toBe(1)
        ->and(Card::where('number', 0)->exists())->toBeFalse()
        ->and(Card::count())->toBe(21);
});

it('leaves every seeded card outstanding in a language, named', function () {
    seedCards();

    $outstanding = TranslationCoverage::outstanding('es');

    // The point of seeding names rather than blank rows: the languages screen
    // can say which card is waiting, from the moment the deck exists.
    expect($outstanding)->toHaveCount(22)
        ->and($outstanding->pluck('label'))->toContain('The Fool')
        ->and($outstanding->first()['fields'])
        ->toBe(['name', 'short_description', 'long_description']);
});
