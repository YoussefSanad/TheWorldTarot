<?php

use App\Enums\LocaleStatus;
use App\Models\Admin;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Locales::forget());

it('returns the agreed shape for the only live language', function () {
    // The exact contract: a bare array, not wrapped in data, with these two
    // keys and no others.
    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
        ]);
});

it('lists a second language once it goes live', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
            ['code' => 'es', 'label' => 'Spanish'],
        ]);
});

it('hides a language that is still being translated', function () {
    // Spanish ships as a draft, so this is the state on day one.
    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'es']);
});

it('hides a language that is only in preview, even from an admin', function () {
    // Preview is reviewed through the locale in the URL, never by appearing in
    // the public selector. An admin seeing it here would mean customers do too,
    // since the frontend renders this list for everyone.
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Preview]);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'es']);
});

it('drops a language from the list the moment it is pulled', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    $this->getJson('/api/v1/languages')->assertJsonCount(2);

    // The kill switch: a status change taking effect on the next request, with
    // no deploy. It only works end to end because the frontend is required to
    // build its language switcher from this endpoint.
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Draft]);

    $this->getJson('/api/v1/languages')
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'es']);
});

it('orders languages by sort order', function () {
    Locale::where('code', 'es')->update([
        'status' => LocaleStatus::Live,
        'sort_order' => -1,
    ]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonPath('0.code', 'es')
        ->assertJsonPath('1.code', 'en');
});

it('takes the label from the registry', function () {
    Locale::where('code', 'en')->update(['english_name' => 'English (UK)']);

    $this->getJson('/api/v1/languages')
        ->assertJsonPath('0.label', 'English (UK)');
});

it('is readable without authentication', function () {
    $this->getJson('/api/v1/languages')->assertOk();
});
