<?php

use App\Enums\LocaleStatus;
use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use App\Support\TranslationCoverage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function spanishIsLive(): void
{
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();
}

function spanishNow(): LocaleStatus
{
    Locales::forget();

    return Locale::firstWhere('code', 'es')->status;
}

it('counts cards toward whether a language is finished', function () {
    Card::factory()->translatedInto('es')->create();

    expect(TranslationCoverage::isComplete('es'))->toBeTrue();

    Card::factory()->create();

    expect(TranslationCoverage::isComplete('es'))->toBeFalse();
});

it('counts cards and readings together, not one or the other', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->create();
    Product::factory()->create([
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
    ]);

    expect(TranslationCoverage::progress('es'))->toBe(['complete' => 2, 'total' => 3]);
});

it('names the card and the fields it is waiting for', function () {
    Card::factory()->create([
        'number' => 0,
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
    ]);

    $outstanding = TranslationCoverage::outstanding('es');

    expect($outstanding)->toHaveCount(1)
        ->and($outstanding->first()['label'])->toBe('The Fool')
        ->and($outstanding->first()['fields'])
        ->toBe(['short_description', 'long_description']);
});

it('calls a card by its number while it has no name', function () {
    Card::factory()->withoutCopy()->create(['number' => 7]);

    // She creates cards herself, so a card with nothing written on it is an
    // ordinary state, and a refusal reading " needs a name" would be useless.
    expect(TranslationCoverage::outstanding('es')->first()['label'])->toBe('Card 7');
});

it('ignores a card that is not published at all', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->unpublished()->create();

    // The developer-level switch, the only exclusion the gate makes.
    expect(TranslationCoverage::isComplete('es'))->toBeTrue();
});

it('counts a card whose english copy is still being written', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->withoutCopy()->create();

    // Unfinished in the original language is still unfinished. A card being set
    // up is not a card that does not count, or it reaches the website long
    // after anyone remembers it was never translated.
    expect(TranslationCoverage::isComplete('es'))->toBeFalse();
});

it('takes a live language down when a new card appears untranslated', function () {
    Card::factory()->translatedInto('es')->create();
    spanishIsLive();

    Log::spy();

    Card::factory()->create();

    expect(spanishNow())->toBe(LocaleStatus::Draft);

    // Loud, because the site just changed for every customer reading it in
    // Spanish.
    Log::shouldHaveReceived('warning')->once();
});

it('takes it down when a card translation is emptied', function () {
    $card = Card::factory()->translatedInto('es')->create();
    spanishIsLive();

    $card->setTranslation('short_description', 'es', '')->save();

    expect(spanishNow())->toBe(LocaleStatus::Draft);
});

it('leaves the language alone when the new card is translated', function () {
    Card::factory()->translatedInto('es')->create();
    spanishIsLive();

    Card::factory()->translatedInto('es')->create();

    expect(spanishNow())->toBe(LocaleStatus::Live);
});

it('refuses to publish a language while a card is untranslated, and says which', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');

    Card::factory()->translatedInto('es')->create(['name' => ['en' => 'The Magician']]);
    Card::factory()->create([
        'number' => 0,
        'name' => ['en' => 'The Fool'],
        'short_description' => ['en' => 'The first card.'],
        'long_description' => ['en' => 'The long one.'],
    ]);
    Locales::forget();

    Livewire::test(ListLocales::class)
        ->assertSee('1 of 2')
        ->callTableAction('publish', Locale::firstWhere('code', 'es'))
        ->assertNotified('Spanish is not finished yet');

    expect(spanishNow())->toBe(LocaleStatus::Draft);
});

it('lets a language go live once every card is translated', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');

    Card::factory()->translatedInto('es')->create();
    Card::factory()->translatedInto('es')->create();
    Locales::forget();

    Livewire::test(ListLocales::class)
        ->callTableAction('publish', Locale::firstWhere('code', 'es'))
        ->assertNotified('The site is now available in Spanish');

    expect(spanishNow())->toBe(LocaleStatus::Live);
});
