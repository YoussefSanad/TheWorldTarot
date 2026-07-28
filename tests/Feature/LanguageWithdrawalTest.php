<?php

use App\Enums\LocaleStatus;
use App\Enums\ProductKey;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function translatedProduct(ProductKey $key = ProductKey::OneCard, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'key' => $key,
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 1900,
    ], $attributes));
}

function spanishStatus(): LocaleStatus
{
    Locales::forget();

    return Locale::firstWhere('code', 'es')->status;
}

function spanishLive(): void
{
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();
}

it('takes a language off the website when untranslated content appears on it', function () {
    translatedProduct();
    spanishLive();

    // The rule: a language shows everything or nothing. A Spanish visitor must
    // never be quietly shown a shorter catalogue than an English one.
    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading'],
        'short_description' => ['en' => 'Three cards.'],
        'long_description' => ['en' => 'The long one.'],
    ]);

    expect(spanishStatus())->toBe(LocaleStatus::Draft);
});

it('takes it down for a half translated reading too', function () {
    translatedProduct();
    spanishLive();

    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading', 'es' => 'Lectura de Tres Cartas'],
        'long_description' => ['en' => 'The long one.'],
    ]);

    expect(spanishStatus())->toBe(LocaleStatus::Draft);
});

it('takes it down when a translation is emptied on an existing reading', function () {
    $product = translatedProduct();
    spanishLive();

    // Deleting a Spanish description is the same problem arriving by a
    // different route.
    $product->setTranslation('short_description', 'es', '')->save();

    expect(spanishStatus())->toBe(LocaleStatus::Draft);
});

it('leaves the language alone when the new reading is translated', function () {
    translatedProduct();
    spanishLive();

    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading', 'es' => 'Lectura de Tres Cartas'],
    ]);

    expect(spanishStatus())->toBe(LocaleStatus::Live);
});

it('ignores a reading that is not on the website anyway', function () {
    translatedProduct();
    spanishLive();

    // No price, so nobody can see it in any language. It cannot leave a
    // language short of anything.
    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading'],
        'price_usd' => 0,
        'price_eur' => 0,
        'price_gbp' => 0,
    ]);

    expect(spanishStatus())->toBe(LocaleStatus::Live);
});

it('takes the reading off every language when the original copy is emptied', function () {
    $product = translatedProduct();
    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading', 'es' => 'Lectura de Tres Cartas'],
    ]);
    spanishLive();

    // Reported from the panel: emptying the English name left the reading
    // visible in Spanish and invisible in English, so Spanish had nothing
    // missing and stayed up. The original language is the source: unfinished
    // there means unfinished everywhere.
    $product->setTranslation('name', 'en', '')->save();

    expect($product->fresh()->isAvailableIn('en'))->toBeFalse()
        ->and($product->fresh()->isAvailableIn('es'))->toBeFalse();

    $this->getJson('/api/v1/en/products')->assertJsonCount(1);
    $this->getJson('/api/v1/es/products')->assertJsonCount(1);
});

it('leaves the language up when the reading it removed was the untranslated one', function () {
    translatedProduct();
    spanishLive();

    // Nothing a customer can reach is untranslated, so Spanish has no reason to
    // come down.
    $orphan = translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading'],
    ]);
    expect(spanishStatus())->toBe(LocaleStatus::Draft);

    spanishLive();
    $orphan->setTranslation('name', 'en', '')->save();

    expect(spanishStatus())->toBe(LocaleStatus::Live);
});

it('never touches the site\'s own language', function () {
    // English is the original. It cannot be left behind by its own content.
    translatedProduct(ProductKey::ThreeCard, ['name' => ['en' => 'Three Card Reading']]);

    Locales::forget();

    expect(Locale::firstWhere('code', 'en')->status)->toBe(LocaleStatus::Live);
});

it('stops serving that language over the api the moment it happens', function () {
    translatedProduct();
    spanishLive();

    $this->getJson('/api/v1/es/products')->assertOk()->assertJsonCount(1);

    translatedProduct(ProductKey::ThreeCard, ['name' => ['en' => 'Three Card Reading']]);

    // Not a shorter Spanish list. No Spanish site at all until it is finished.
    $this->getJson('/api/v1/es/products')->assertNotFound();
    $this->getJson('/api/v1/languages')->assertJsonCount(1);
});

it('keeps english serving throughout', function () {
    translatedProduct();
    spanishLive();

    translatedProduct(ProductKey::ThreeCard, [
        'name' => ['en' => 'Three Card Reading'],
        'short_description' => ['en' => 'Three cards.'],
        'long_description' => ['en' => 'The long one.'],
    ]);

    // Adding a reading must never cost the English site anything.
    $this->getJson('/api/v1/en/products')->assertOk()->assertJsonCount(2);
});

it('can be put back once the translation is written', function () {
    translatedProduct();
    spanishLive();

    $second = translatedProduct(ProductKey::ThreeCard, ['name' => ['en' => 'Three Card Reading']]);
    expect(spanishStatus())->toBe(LocaleStatus::Draft);

    $second->setTranslation('name', 'es', 'Lectura de Tres Cartas')->save();
    spanishLive();

    expect(spanishStatus())->toBe(LocaleStatus::Live);
    $this->getJson('/api/v1/es/products')->assertOk()->assertJsonCount(2);
});
