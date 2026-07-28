<?php

use App\Filament\Resources\Cards\CardResource;
use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Cards\Pages\EditCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Models\Admin;
use App\Models\Card;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

it('adds a card through the panel', function () {
    // The one thing she creates rather than edits, and a binding commitment to
    // people who already paid.
    Livewire::test(CreateCard::class)
        ->fillForm([
            'number' => 22,
            'name' => 'The Comet',
            'short_description' => 'A new one.',
            'long_description' => 'The longer meaning.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $card = Card::firstWhere('number', 22);

    expect($card)->not->toBeNull()
        ->and($card->getTranslation('name', 'en'))->toBe('The Comet')
        ->and($card->isAvailableIn('en'))->toBeTrue();
});

it('will not add a card with no name', function () {
    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 22, 'name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Card::count())->toBe(0);
});

it('will not add a second card with the same number', function () {
    Card::factory()->create(['number' => 3]);

    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 3, 'name' => 'The Empress'])
        ->call('create')
        ->assertHasFormErrors(['number']);

    expect(Card::where('number', 3)->count())->toBe(1);
});

it('lets a removed card give its number back', function () {
    Card::factory()->create(['number' => 3])->delete();

    // The panel must not refuse what the database allows. She removes a card
    // added by mistake and adds it again, which is the only way out of that
    // mistake she has.
    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 3, 'name' => 'The Empress'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Card::where('number', 3)->count())->toBe(1);
});

it('saves an edit through the panel', function () {
    $card = Card::factory()->create(['name' => ['en' => 'Old Name']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['name' => 'The Fool'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->getTranslation('name', 'en'))->toBe('The Fool');
});

it('persists a translation against the active language', function () {
    $card = Card::factory()->create(['name' => ['en' => 'The Fool']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->fillForm(['name' => 'El Loco'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The English value must survive editing the Spanish one.
    expect($card->refresh()->getTranslation('name', 'es'))->toBe('El Loco')
        ->and($card->getTranslation('name', 'en'))->toBe('The Fool');
});

it('allows a translation to be saved one field at a time', function () {
    $card = Card::factory()->create();

    // Translating is incremental. Completeness is the gate on going live, not
    // on saving.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->fillForm(['name' => 'El Loco', 'short_description' => '', 'long_description' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->getTranslation('name', 'es'))->toBe('El Loco');
});

it('switches language back and forth without complaining', function () {
    $card = Card::factory()->create();

    // A field level `required` fires here, because the switcher validates the
    // tab it is leaving against the language it is arriving at. The name is
    // required when adding a card, and never on a translation.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->assertHasNoErrors()
        ->set('activeLocale', 'en')
        ->assertHasNoErrors()
        ->set('activeLocale', 'es')
        ->assertHasNoErrors();
});

it('refuses to delete copy that already exists in the original language', function () {
    $card = Card::factory()->create(['name' => ['en' => 'The Fool']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($card->refresh()->getTranslation('name', 'en'))->toBe('The Fool');
});

it('says what a card is still waiting for', function () {
    $card = Card::factory()->create([
        'name' => ['en' => 'The Fool'],
        'short_description' => [],
        'long_description' => [],
    ]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('Not on the website yet')
        ->assertSee('a short description')
        ->assertSee('a full description');
});

it('says a finished card is on the website', function () {
    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('On the website');
});

it('removes a card without losing the row', function () {
    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->callAction('delete');

    // M4 attaches a video to the row, so it is kept rather than dropped.
    expect(Card::find($card->id))->toBeNull()
        ->and(Card::withTrashed()->find($card->id))->not->toBeNull();
});

it('offers no switch for taking a card off the website', function () {
    $card = Card::factory()->create();

    // Availability is calculated from the copy. A switch would be a second
    // answer to the same question.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldDoesNotExist('is_published');
});

it('offers no web address field', function () {
    $card = Card::factory()->create();

    // A card is a pop-up, not a page, so there is no link to keep stable.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldDoesNotExist('slug');
});

it('lists cards in deck order', function () {
    Card::factory()->create(['number' => 5]);
    Card::factory()->create(['number' => 1]);

    Livewire::test(ListCards::class)
        ->assertCanSeeTableRecords(Card::orderBy('number')->get(), inOrder: true);
});

it('drives the resource translatable locales from the registry', function () {
    expect(CardResource::getTranslatableLocales())->toBe(Locales::codes());
});
