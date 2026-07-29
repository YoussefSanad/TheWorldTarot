<?php

use App\Enums\LocaleStatus;
use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Models\Admin;
use App\Models\Locale;
use App\Models\Product;
use App\Support\Locales;
use Database\Seeders\ProductSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('refuses to publish spanish while any reading is untranslated, seeded state and all', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');

    (new ProductSeeder)->run();

    // Exactly the screenshots: two readings priced and translated, the rest
    // seeded and untouched.
    Product::where('key', 'one-card')->update([
        'name' => ['en' => 'One Card', 'es' => 'Una Carta'],
        'short_description' => ['en' => 's', 'es' => 's'],
        'long_description' => ['en' => 'l', 'es' => 'l'],
        'price_usd' => 1000, 'price_eur' => 1000, 'price_gbp' => 1000,
    ]);
    Product::where('key', 'three-card')->update([
        'name' => ['en' => 'Three Card', 'es' => 'Tres Cartas'],
        'short_description' => ['en' => 's', 'es' => 's'],
        'long_description' => ['en' => 'l', 'es' => 'l'],
        'price_usd' => 3000, 'price_eur' => 3000, 'price_gbp' => 3000,
    ]);
    Locales::forget();

    Livewire::test(ListLocales::class)
        // Two of five now: the viewing room pass counts too, and Spanish cannot
        // go live until it is translated either.
        ->assertSee('2 of 5')
        ->callTableAction('publish', Locale::firstWhere('code', 'es'))
        ->assertNotified('Spanish is not finished yet');

    Locales::forget();
    expect(Locale::firstWhere('code', 'es')->status)->toBe(LocaleStatus::Draft);
});
