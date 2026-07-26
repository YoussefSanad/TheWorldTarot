<?php

use App\Enums\LocaleState;
use App\Models\Admin;
use App\Models\Locale;
use App\Models\Product;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('api')->get('/api/v1/test-context', fn () => [
        'locale' => app(RequestContext::class)->locale,
    ]);
});

it('exists as a locale the panel can edit, without being public', function () {
    $spanish = Locale::where('code', 'es')->first();

    expect($spanish)->not->toBeNull()
        ->and($spanish->state)->toBe(LocaleState::Available)
        ->and($spanish->isConfigured())->toBeTrue();
});

it('shows a spanish tab in the admin panel', function () {
    // The tab follows config, which is what makes this a config change rather
    // than a code change. Hard rule 4.
    expect(array_keys(config('localization.available')))->toContain('es');
});

it('keeps spanish out of the public language list', function () {
    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([['code' => 'en', 'label' => 'English']]);
});

it('never serves spanish while it is only available', function () {
    // `available` means a translation in progress. Nobody is served it, admin
    // included, because a half translated site is worse than an English one.
    $this->getJson('/api/v1/test-context?locale=es')->assertJsonPath('locale', 'en');

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/test-context?locale=es')
        ->assertJsonPath('locale', 'en');
});

it('serves spanish to a signed-in admin once it is in preview', function () {
    Locale::where('code', 'es')->update(['state' => LocaleState::Preview]);

    // This is how the real site gets reviewed on production before customers
    // can reach it.
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/test-context?locale=es')
        ->assertJsonPath('locale', 'es');
});

it('still refuses spanish to the public while it is in preview', function () {
    Locale::where('code', 'es')->update(['state' => LocaleState::Preview]);

    $this->getJson('/api/v1/test-context?locale=es')->assertJsonPath('locale', 'en');

    // And it stays out of the language selector, so nobody finds it by accident.
    $this->getJson('/api/v1/languages')->assertJsonCount(1);
});

it('serves spanish to everyone once it goes live', function () {
    Locale::where('code', 'es')->update(['state' => LocaleState::Live]);

    $this->getJson('/api/v1/test-context?locale=es')->assertJsonPath('locale', 'es');

    $this->getJson('/api/v1/languages')
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
            ['code' => 'es', 'label' => 'Spanish'],
        ]);
});

it('stores spanish product copy alongside english', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card', 'es' => 'Una Carta'],
        'slug' => ['en' => 'one-card', 'es' => 'una-carta'],
    ]);

    expect($product->fresh()->getTranslation('name', 'es'))->toBe('Una Carta')
        ->and(Product::whereSlug('una-carta', 'es')->first()?->id)->toBe($product->id);
});

it('enforces slug uniqueness in spanish', function () {
    Product::factory()->create(['key' => 'first', 'slug' => ['es' => 'una-carta']]);

    // The index added with the locale has to work like the English one, or two
    // products end up answering to the same Spanish URL.
    expect(fn () => Product::factory()->create(['key' => 'second', 'slug' => ['es' => 'una-carta']]))
        ->toThrow(QueryException::class);
});
