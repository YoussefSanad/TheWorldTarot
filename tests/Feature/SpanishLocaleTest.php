<?php

use App\Enums\LocaleStatus;
use App\Enums\ProductKey;
use App\Models\Admin;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use App\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Locales::forget();

    Route::middleware('api')->get('/api/v1/{locale}/test-context', fn () => [
        'locale' => app(RequestContext::class)->locale,
    ]);
});

it('exists as a locale the panel can edit, without being public', function () {
    $spanish = Locale::where('code', 'es')->first();

    expect($spanish)->not->toBeNull()
        ->and($spanish->status)->toBe(LocaleStatus::Draft)
        ->and($spanish->english_name)->toBe('Spanish');
});

it('shows a spanish tab in the admin panel', function () {
    // The tab follows the registry, which is what makes adding a language a row
    // rather than a code change. Hard rule 4.
    expect(Locales::codes())->toContain('es');
});

it('keeps spanish out of the public language list', function () {
    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([['code' => 'en', 'label' => 'English']]);
});

it('never serves spanish while it is still a draft', function () {
    // Draft means a translation in progress. Nobody is served it, admin
    // included, because a half translated site is worse than an English one.
    $this->getJson('/api/v1/es/test-context')->assertNotFound();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/test-context')
        ->assertNotFound();
});

it('serves spanish to a signed-in admin once it is in preview', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Preview]);

    // This is how the real site gets reviewed on production before customers
    // can reach it.
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/test-context')
        ->assertJsonPath('locale', 'es');
});

it('still refuses spanish to the public while it is in preview', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Preview]);

    $this->getJson('/api/v1/es/test-context')->assertNotFound();

    // And it stays out of the language selector, so nobody finds it by accident.
    $this->getJson('/api/v1/languages')->assertJsonCount(1);
});

it('serves spanish to everyone once it goes live', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

    $this->getJson('/api/v1/es/test-context')->assertJsonPath('locale', 'es');

    $this->getJson('/api/v1/languages')
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
            ['code' => 'es', 'label' => 'Spanish'],
        ]);
});

it('stores spanish product copy alongside english', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card', 'es' => 'Una Carta'],
    ]);

    expect($product->fresh()->getTranslation('name', 'es'))->toBe('Una Carta')
        ->and($product->getTranslation('name', 'en'))->toBe('One Card');
});

it('addresses a product by the same key in every language', function () {
    $product = Product::factory()->create([
        'key' => ProductKey::OneCard,
        'name' => ['en' => 'One Card', 'es' => 'Una Carta'],
    ]);

    // Keys are never translated, so a product has one address rather than one
    // per language, and renaming it in Spanish cannot break a link.
    expect(Product::where('key', ProductKey::OneCard)->first()?->id)->toBe($product->id);
});
