<?php

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

it('refuses a web address that would not work in a link', function () {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['slug' => 'One Card Reading!'])
        ->call('save')
        ->assertHasFormErrors(['slug']);
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

it('still requires the copy in the default language', function () {
    $product = Product::factory()->create();

    // The default language is the one everything falls back to, so it is never
    // allowed to be empty.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => '', 'short_description' => ''])
        ->call('save')
        ->assertHasFormErrors(['name', 'short_description']);
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
    Product::factory()->create(['key' => 'second', 'name' => ['en' => 'Second'], 'sort_order' => 2]);
    Product::factory()->create(['key' => 'first', 'name' => ['en' => 'First'], 'sort_order' => 1]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords(Product::orderBy('sort_order')->get(), inOrder: true);
});

it('keeps the pass out of nobody\'s way, listing every type together', function () {
    Product::factory()->create(['key' => 'a-reading']);
    Product::factory()->pass()->create(['key' => 'viewing-room-pass']);

    // One resource covers everything sellable, since they share every field
    // the client edits.
    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords(Product::all());

    expect(Product::ofType(ProductType::Pass)->count())->toBe(1);
});
