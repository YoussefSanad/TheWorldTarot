<?php

use App\Enums\LocaleStatus;
use App\Enums\ProductKey;
use App\Filament\Resources\Locales\LocaleResource;
use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Models\Admin;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

function spanish(): Locale
{
    Locales::forget();

    return Locale::firstWhere('code', 'es');
}

function fullyTranslated(ProductKey $key = ProductKey::OneCard): Product
{
    return Product::factory()->create([
        'key' => $key,
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
    ]);
}

it('lists the languages with how far each has got', function () {
    fullyTranslated();
    Product::factory()->create(['key' => ProductKey::ThreeCard]);

    Livewire::test(ListLocales::class)
        ->assertCanSeeTableRecords(Locale::all())
        ->assertSee('Spanish')
        ->assertSee('1 of 2');
});

it('refuses to publish a language while anything is untranslated', function () {
    fullyTranslated();
    Product::factory()->create([
        'key' => ProductKey::ThreeCard,
        'name' => ['en' => 'Three Card Reading'],
    ]);

    Livewire::test(ListLocales::class)
        ->callTableAction('publish', spanish());

    // Refused, and it stays exactly where it was.
    expect(spanish()->status)->toBe(LocaleStatus::Draft);
});

it('says what is missing rather than that something is wrong', function () {
    fullyTranslated();
    Product::factory()->create([
        'key' => ProductKey::ThreeCard,
        'name' => ['en' => 'Three Card Reading'],
    ]);

    Livewire::test(ListLocales::class)
        ->callTableAction('publish', spanish())
        ->assertNotified('Spanish is not finished yet');
});

it('publishes a language once everything is translated', function () {
    fullyTranslated();

    Livewire::test(ListLocales::class)
        ->callTableAction('publish', spanish());

    expect(spanish()->status)->toBe(LocaleStatus::Live);
});

it('lets the client review a language before customers see it', function () {
    // Never gated: the point of reviewing is to look at it before it is done.
    Product::factory()->create(['key' => ProductKey::ThreeCard]);

    Livewire::test(ListLocales::class)
        ->callTableAction('preview', spanish());

    expect(spanish()->status)->toBe(LocaleStatus::Preview);
});

it('takes a language down immediately, with nothing to satisfy first', function () {
    fullyTranslated();
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

    // If something is wrong on the Spanish site, waiting is the wrong answer.
    Livewire::test(ListLocales::class)
        ->callTableAction('withdraw', spanish());

    expect(spanish()->status)->toBe(LocaleStatus::Draft);
});

it('keeps the translations when a language is taken down', function () {
    $product = fullyTranslated();
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

    Livewire::test(ListLocales::class)->callTableAction('withdraw', spanish());

    expect($product->fresh()->getTranslation('name', 'es'))->toBe('Lectura de Una Carta');
});

it('offers none of this for the site\'s own language', function () {
    $english = Locale::firstWhere('code', 'en');

    // English is the original. There is nothing to publish, review or withdraw.
    Livewire::test(ListLocales::class)
        ->assertTableActionHidden('publish', $english)
        ->assertTableActionHidden('preview', $english)
        ->assertTableActionHidden('withdraw', $english);
});

it('offers no way to add or delete a language here', function () {
    // A language needs its wording shipped in the website's code first, so
    // adding one is a developer's job.
    expect(LocaleResource::canCreate())->toBeFalse()
        ->and(LocaleResource::canDeleteAny())->toBeFalse();
});
