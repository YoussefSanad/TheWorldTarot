<?php

use App\Enums\LocaleState;
use App\Models\Admin;
use App\Models\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);
});

it('returns the agreed shape for the only live language', function () {
    // The exact contract in .claude/api.md: a bare array, not wrapped in data,
    // with these three keys and no others.
    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
        ]);
});

it('lists a second language once it goes live', function () {
    Locale::create(['code' => 'it', 'state' => LocaleState::Live->value, 'sort_order' => 1]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertExactJson([
            ['code' => 'en', 'label' => 'English'],
            ['code' => 'it', 'label' => 'Italian'],
        ]);
});

it('hides a language that is still being translated', function () {
    Locale::create(['code' => 'it', 'state' => LocaleState::Available->value, 'sort_order' => 1]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'it']);
});

it('hides a language that is only in preview, even from an admin', function () {
    // Preview is reviewed through an explicit ?locale=, never by appearing in
    // the public selector. An admin seeing it here would mean customers do too,
    // since the frontend renders this list for everyone.
    Locale::create(['code' => 'it', 'state' => LocaleState::Preview->value, 'sort_order' => 1]);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'it']);
});

it('hides a live locale whose code is absent from config', function () {
    // Config always wins over database state.
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
    ]);
    Locale::create(['code' => 'it', 'state' => LocaleState::Live->value, 'sort_order' => 1]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['code' => 'it']);
});

it('orders languages by sort order', function () {
    Locale::create(['code' => 'it', 'state' => LocaleState::Live->value, 'sort_order' => -1]);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonPath('0.code', 'it')
        ->assertJsonPath('1.code', 'en');
});

it('takes the label from config rather than the database', function () {
    config()->set('localization.available.en.label', 'English (UK)');

    $this->getJson('/api/v1/languages')
        ->assertJsonPath('0.label', 'English (UK)');
});

it('is readable without authentication', function () {
    $this->getJson('/api/v1/languages')->assertOk();
});
