<?php

use App\Enums\LocaleState;
use App\Models\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds english as a live locale', function () {
    $en = Locale::where('code', 'en')->first();

    expect($en)->not->toBeNull()
        ->and($en->state)->toBe(LocaleState::Live);
});

it('treats a locale whose code is absent from config as nonexistent', function () {
    // 'zz' is not declared in config/localization.php, so config wins: it is
    // filtered out of both the configured and live scopes regardless of state.
    Locale::create(['code' => 'zz', 'state' => LocaleState::Live->value]);

    expect(Locale::configured()->pluck('code'))->not->toContain('zz')
        ->and(Locale::live()->pluck('code'))->not->toContain('zz')
        ->and(Locale::live()->pluck('code'))->toContain('en');
});

it('reports whether a locale is configured', function () {
    expect(Locale::where('code', 'en')->first()->isConfigured())->toBeTrue();

    $undeclared = Locale::create(['code' => 'zz', 'state' => LocaleState::Available->value]);
    expect($undeclared->isConfigured())->toBeFalse();
});
