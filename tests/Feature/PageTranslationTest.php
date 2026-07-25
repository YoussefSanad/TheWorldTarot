<?php

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores and returns a value per locale', function () {
    $page = Page::create([
        'slug' => 'about',
        'title' => ['en' => 'About', 'it' => 'Chi siamo'],
        'body' => ['en' => 'Hello', 'it' => 'Ciao'],
    ]);

    expect($page->getTranslation('title', 'en'))->toBe('About')
        ->and($page->getTranslation('title', 'it'))->toBe('Chi siamo')
        ->and($page->getTranslation('body', 'it'))->toBe('Ciao');
});

it('falls back to the default locale when a translation is missing', function () {
    $page = Page::create([
        'slug' => 'about',
        'title' => ['en' => 'Only English'],
        'body' => ['en' => 'Body'],
    ]);

    // 'it' has no value, so the fallback locale (en) is returned.
    expect($page->getTranslation('title', 'it'))->toBe('Only English');
});
