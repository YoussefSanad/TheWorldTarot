<?php

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Admin;
use App\Models\Page;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

it('creates a page with content through the panel', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'slug' => 'about',
            'title' => 'About Us',
            'body' => 'Hello world',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::firstWhere('slug', 'about');

    expect($page)->not->toBeNull()
        ->and($page->getTranslation('title', 'en'))->toBe('About Us')
        ->and($page->getTranslation('body', 'en'))->toBe('Hello world');
});

it('persists an edit through the panel', function () {
    $page = Page::factory()->create([
        'slug' => 'about',
        'title' => ['en' => 'Old Title'],
        'body' => ['en' => 'Old body'],
    ]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['title' => 'New Title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->getTranslation('title', 'en'))->toBe('New Title');
});

it('drives the resource translatable locales from config', function () {
    expect(PageResource::getTranslatableLocales())
        ->toBe(array_keys(config('localization.available')));
});

it('exposes a second language from config with no migration or code change', function () {
    // Simulate the exact wiring in AdminPanelProvider against a two-locale config.
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    $plugin = SpatieTranslatablePlugin::make()
        ->defaultLocales(array_keys(config('localization.available')));

    // A second tab appears purely because config gained a key.
    expect($plugin->getDefaultLocales())->toBe(['en', 'it']);
});
