<?php

use App\Enums\LocaleStatus;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Locales::forget());

it('ships english live and spanish still in draft', function () {
    expect(Locale::firstWhere('code', 'en')->status)->toBe(LocaleStatus::Live)
        ->and(Locale::firstWhere('code', 'es')->status)->toBe(LocaleStatus::Draft);
});

it('carries the names the config file used to hold', function () {
    $spanish = Locale::firstWhere('code', 'es');

    expect($spanish->english_name)->toBe('Spanish')
        ->and($spanish->native_name)->toBe('Español');
});

it('takes the default language from laravel, not a bespoke config file', function () {
    expect(Locales::default())->toBe(config('app.fallback_locale'))
        ->and(Locales::defaultName())->toBe('English');
});

it('keeps the default language fixed while a request is served in another', function () {
    app()->setLocale('es');

    // Regression test. setLocale() writes config('app.locale'), so reading that
    // would make Spanish the default for a Spanish request. Every completeness
    // check exempts the default language, so the whole gate would switch off
    // for exactly the visitors it exists to protect.
    expect(Locales::default())->toBe('en');
});

it('lists only live locales as live', function () {
    expect(Locales::live()->pluck('code')->all())->toBe(['en']);

    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();

    expect(Locales::live()->pluck('code')->all())->toBe(['en', 'es']);
});

it('offers every language to the panel, drafts included', function () {
    // A draft is precisely the language being translated, so its tab has to be
    // there. Draft withholds public visibility, not editing.
    expect(Locales::codes())->toBe(['en', 'es']);
});

it('adds a language with a row, with no migration and no code change', function () {
    Locale::create([
        'code' => 'fr',
        'english_name' => 'French',
        'native_name' => 'Français',
        'status' => LocaleStatus::Draft,
        'sort_order' => 2,
    ]);
    Locales::forget();

    expect(Locales::codes())->toBe(['en', 'es', 'fr'])
        ->and(Locales::live()->pluck('code')->all())->toBe(['en']);
});

it('decides who a language may be served to', function () {
    $locale = Locale::firstWhere('code', 'es');

    // Draft is nobody, admins included: a half translated site is worse than an
    // English one.
    expect($locale->isServableTo(isAdmin: false))->toBeFalse()
        ->and($locale->isServableTo(isAdmin: true))->toBeFalse();

    $locale->update(['status' => LocaleStatus::Preview]);
    expect($locale->isServableTo(isAdmin: false))->toBeFalse()
        ->and($locale->isServableTo(isAdmin: true))->toBeTrue();

    $locale->update(['status' => LocaleStatus::Live]);
    expect($locale->isServableTo(isAdmin: false))->toBeTrue()
        ->and($locale->isServableTo(isAdmin: true))->toBeTrue();
});

it('orders languages the way the client arranged them', function () {
    Locale::create([
        'code' => 'fr',
        'english_name' => 'French',
        'native_name' => 'Français',
        'status' => LocaleStatus::Draft,
        'sort_order' => -1,
    ]);
    Locales::forget();

    expect(Locales::codes())->toBe(['fr', 'en', 'es']);
});
