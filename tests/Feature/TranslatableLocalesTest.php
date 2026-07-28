<?php

use App\Enums\LocaleStatus;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;

uses(RefreshDatabase::class);

beforeEach(fn () => Locales::forget());

it('drives the admin panel language tabs from the registry', function () {
    // The wiring in AdminPanelProvider, tested against the registry directly so
    // it survives resources coming and going.
    $plugin = SpatieTranslatablePlugin::make()->defaultLocales(Locales::codes());

    expect($plugin->getDefaultLocales())->toBe(['en', 'es']);
});

it('exposes a new language from a row, with no migration or code change', function () {
    Locale::create([
        'code' => 'fr',
        'english_name' => 'French',
        'native_name' => 'Français',
        'status' => LocaleStatus::Draft,
        'sort_order' => 2,
    ]);
    Locales::forget();

    $plugin = SpatieTranslatablePlugin::make()->defaultLocales(Locales::codes());

    // A third tab appears on every translatable resource purely because the
    // table gained a row. Hard rule 4.
    expect($plugin->getDefaultLocales())->toBe(['en', 'es', 'fr']);
});
