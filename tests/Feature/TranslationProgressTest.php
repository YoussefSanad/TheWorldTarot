<?php

use App\Filament\Resources\Cards\Pages\ListCards;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use App\Support\TranslationCoverage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

it('counts one kind of thing at a time', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->create();
    Card::factory()->create();
    Product::factory()->create();

    // The languages screen answers whether Spanish can go live. This answers
    // how much of the deck is left, which is a different question.
    expect(TranslationCoverage::progressFor(Card::class, 'es'))
        ->toBe(['complete' => 1, 'total' => 3])
        ->and(TranslationCoverage::progressFor(Product::class, 'es'))
        ->toBe(['complete' => 0, 'total' => 1]);
});

it('adds up to the same total as the languages screen', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->create();
    Product::factory()->create();

    // Two counts of the same thing that could disagree would be worse than
    // one, so the whole is built from the parts.
    expect(TranslationCoverage::progress('es'))->toBe(['complete' => 1, 'total' => 3]);
});

it('shows how far spanish has got through the cards', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->create();
    Locales::forget();

    Livewire::test(ListCards::class)
        ->assertSee('Spanish: 1 of 2 translated');
});

it('moves as translations are written', function () {
    $card = Card::factory()->create();
    Card::factory()->translatedInto('es')->create();
    Locales::forget();

    Livewire::test(ListCards::class)->assertSee('Spanish: 1 of 2 translated');

    $card->setTranslation('name', 'es', 'El Loco')
        ->setTranslation('short_description', 'es', 'Corto')
        ->setTranslation('long_description', 'es', 'Largo')
        ->save();

    Livewire::test(ListCards::class)->assertSee('Spanish: 2 of 2 translated');
});

it('shows the same line above the readings', function () {
    Product::factory()->create();
    Locales::forget();

    Livewire::test(ListProducts::class)
        ->assertSee('Spanish: 0 of 1 translated');
});

it('says nothing when there is only one language', function () {
    Locale::where('code', 'es')->delete();
    Locales::forget();

    Card::factory()->create();

    // "English: 22 of 22" is a line that can only ever say one thing.
    Livewire::test(ListCards::class)
        ->assertDontSee('translated');
});

it('ignores a card nobody has published', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->unpublished()->create();
    Locales::forget();

    // The same exclusion the language gate makes, so the count and the gate
    // cannot tell her different things.
    Livewire::test(ListCards::class)
        ->assertSee('Spanish: 1 of 1 translated');
});
