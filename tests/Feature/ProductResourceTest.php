<?php

use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Admin;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

it('saves an edit through the panel', function () {
    $product = Product::factory()->create(['name' => ['en' => 'Old Name']]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'One Card Reading'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->getTranslation('name', 'en'))->toBe('One Card Reading');
});

it('offers no way to create a product', function () {
    // Products are seeded. A create form would have to ask for a key, a type
    // and a fulfilment mode, none of which mean anything to the client.
    expect(ProductResource::canCreate())->toBeFalse()
        ->and(ProductResource::getPages())->not->toHaveKey('create');

    Livewire::test(ListProducts::class)
        ->assertActionDoesNotExist('create');
});

it('offers no way to delete a product', function () {
    $product = Product::factory()->create();

    // An order will reference a product from M5 onward. Taking one off sale is
    // unpublishing it, never deleting it.
    expect(ProductResource::canDelete($product))->toBeFalse()
        ->and(ProductResource::canDeleteAny())->toBeFalse();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

it('shows prices in major units and stores them in minor units', function () {
    $product = Product::factory()->create(['price_gbp' => 1900]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        // She sees 19.00, not 1900.
        ->assertFormSet(['price_gbp' => '19.00'])
        ->fillForm(['price_gbp' => '24.50'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->price_gbp)->toBe(2450);
});

it('stores an awkward amount without losing a penny', function (string $typed, int $stored) {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['price_eur' => $typed])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->price_eur)->toBe($stored);
})->with([
    ['19.99', 1999],
    ['0.05', 5],
    ['100.00', 10000],
    ['19.90', 1990],
]);

it('leaves a price untouched when the form is saved without changing it', function () {
    $product = Product::factory()->create([
        'price_usd' => 2500,
        'price_eur' => 2399,
        'price_gbp' => 1905,
    ]);

    // A round trip through the form must not drift a price by a penny.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->price_usd)->toBe(2500)
        ->and($product->price_eur)->toBe(2399)
        ->and($product->price_gbp)->toBe(1905);
});

it('refuses an amount that is not a price', function () {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['price_gbp' => 'nineteen pounds'])
        ->call('save')
        ->assertHasFormErrors(['price_gbp']);
});

it('refuses to publish a reading that has no real price', function () {
    // Seeded products start at zero in every currency. The database can only
    // insist a price exists, not that it means anything, so this is what stops
    // a reading going on sale for nothing.
    $product = Product::factory()->unpublished()->create([
        'price_usd' => 0,
        'price_eur' => 0,
        'price_gbp' => 0,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['is_published' => true])
        ->call('save')
        ->assertHasFormErrors(['price_usd', 'price_eur', 'price_gbp']);

    expect($product->refresh()->is_published)->toBeFalse();
});

it('allows a zero price while the reading stays off the website', function () {
    // An unfinished product must still be saveable, or the seeded state cannot
    // be worked on at all.
    $product = Product::factory()->unpublished()->create([
        'price_usd' => 0,
        'price_eur' => 0,
        'price_gbp' => 0,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Still being written'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('publishes once every price is set', function () {
    $product = Product::factory()->unpublished()->create([
        'price_usd' => 0,
        'price_eur' => 0,
        'price_gbp' => 0,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'price_usd' => '25.00',
            'price_eur' => '23.00',
            'price_gbp' => '19.00',
            'is_published' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->is_published)->toBeTrue()
        ->and($product->price_gbp)->toBe(1900);
});

it('refuses to publish when only one currency is missing a price', function () {
    $product = Product::factory()->unpublished()->create([
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 0,
    ]);

    // A British visitor would otherwise reach a reading priced at nothing.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['is_published' => true])
        ->call('save')
        ->assertHasFormErrors(['price_gbp'])
        ->assertHasNoFormErrors(['price_usd', 'price_eur']);
});

it('persists a translation against the active language', function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    $product = Product::factory()->create(['name' => ['en' => 'One Card']]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->set('activeLocale', 'it')
        ->fillForm(['name' => 'Una Carta'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The English value must survive editing the Italian one.
    expect($product->refresh()->getTranslation('name', 'it'))->toBe('Una Carta')
        ->and($product->getTranslation('name', 'en'))->toBe('One Card');
});

it('allows a translation to be saved one field at a time', function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    $product = Product::factory()->create();

    // Translating is incremental. Demanding every Italian field before anything
    // can be saved would make the panel useless for the job it exists to do.
    // Completeness is the gate on going live, not on saving.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->set('activeLocale', 'it')
        ->fillForm(['name' => 'Una Carta', 'short_description' => '', 'long_description' => '', 'slug' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->getTranslation('name', 'it'))->toBe('Una Carta');
});

it('saves a draft with copy still missing', function () {
    $product = Product::factory()->unpublished()->create();

    // Writing is incremental. A half finished reading has to be saveable, in
    // any language, or the panel is useless for the job it exists to do.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'One Card', 'short_description' => '', 'long_description' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('refuses to publish a reading with copy still missing', function () {
    $product = Product::factory()->unpublished()->create();

    // Completeness is demanded at the moment it goes on sale, not before.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'short_description' => '',
            'long_description' => '',
            'is_published' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['short_description', 'long_description']);

    expect($product->refresh()->is_published)->toBeFalse();
});

it('switches language back and forth without complaining', function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'es' => ['label' => 'Spanish', 'native' => 'Español'],
    ]);

    $product = Product::factory()->create();

    // Looking at an untranslated language and changing your mind must not be
    // treated as an attempt to empty the record. A field level `required` fires
    // here, because the switcher validates the tab it is leaving against the
    // language it is arriving at.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->set('activeLocale', 'es')
        ->assertHasNoErrors()
        ->set('activeLocale', 'en')
        ->assertHasNoErrors()
        ->set('activeLocale', 'es')
        ->assertHasNoErrors();
});

it('only offers publishing in the default language', function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'es' => ['label' => 'Spanish', 'native' => 'Español'],
    ]);

    $product = Product::factory()->create();

    // A reading is on sale or it is not. Publishing per language would be a
    // different meaning entirely, and one nobody asked for.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsEnabled('is_published')
        ->set('activeLocale', 'es')
        ->assertFormFieldIsDisabled('is_published');
});

it('drives the resource translatable locales from config', function () {
    expect(ProductResource::getTranslatableLocales())
        ->toBe(array_keys(config('localization.available')));
});

it('does not show structural fields to the client', function () {
    $product = Product::factory()->create();

    // key, type and fulfilment are developer concerns. Showing them invites a
    // change that breaks the frontend or the checkout.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldDoesNotExist('key')
        ->assertFormFieldDoesNotExist('type')
        ->assertFormFieldDoesNotExist('fulfilment');
});

it('lists products in their configured order', function () {
    Product::factory()->create(['key' => ProductKey::ThreeCard, 'sort_order' => 2]);
    Product::factory()->create(['key' => ProductKey::OneCard, 'sort_order' => 1]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords(Product::orderBy('sort_order')->get(), inOrder: true);
});

it('keeps the pass out of nobody\'s way, listing every type together', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->pass()->create();

    // One resource covers everything sellable, since they share every field
    // the client edits.
    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords(Product::all());

    expect(Product::ofType(ProductType::Pass)->count())->toBe(1);
});
