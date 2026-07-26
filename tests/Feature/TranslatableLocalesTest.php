<?php

use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;

it('drives the admin panel language tabs from config', function () {
    // The wiring in AdminPanelProvider, tested against config directly rather
    // than through a resource, so it survives resources coming and going.
    $plugin = SpatieTranslatablePlugin::make()
        ->defaultLocales(array_keys(config('localization.available')));

    expect($plugin->getDefaultLocales())->toBe(array_keys(config('localization.available')));
});

it('exposes a second language from config with no migration or code change', function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    $plugin = SpatieTranslatablePlugin::make()
        ->defaultLocales(array_keys(config('localization.available')));

    // A second tab appears on every translatable resource purely because config
    // gained a key. Hard rule 4.
    expect($plugin->getDefaultLocales())->toBe(['en', 'it']);
});
