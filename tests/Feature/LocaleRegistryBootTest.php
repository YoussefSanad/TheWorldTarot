<?php

use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('survives being read when the database is unreachable', function () {
    Locales::forget();

    // The panel is built while the framework boots, so this code runs during
    // package:discover, config:cache and every other artisan command, including
    // ones that run before a database exists. CI found this the hard way:
    // composer install fell over on a SQLite file that was never created.
    Schema::shouldReceive('hasTable')
        ->andThrow(new RuntimeException('no database here'));

    expect(Locales::all())->toBeEmpty()
        ->and(Locales::codes())->toBe([config('app.fallback_locale')]);
});

it('still reports a usable default language with no registry', function () {
    Locales::forget();

    DB::shouldReceive('connection')->andThrow(new RuntimeException('no database here'));

    // The panel must render a language tab even with nothing to read, or the
    // whole admin is unreachable during an outage.
    expect(Locales::default())->toBe('en')
        ->and(Locales::defaultName())->toBe('en');
});
