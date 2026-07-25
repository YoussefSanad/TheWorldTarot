<?php

use App\Enums\LocaleState;
use App\Models\Admin;
use App\Models\Locale;
use App\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Italian is declared in config for these tests only, so its state in the
    // locales table is what decides who is served it.
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    // A route inside the api group, which is where the middleware is applied.
    Route::middleware('api')->get('/api/v1/test-context', function () {
        $context = app(RequestContext::class);

        return [
            'locale' => $context->locale,
            'currency' => $context->currency,
            'app_locale' => app()->getLocale(),
        ];
    });
});

function italianAt(LocaleState $state): void
{
    Locale::create(['code' => 'it', 'state' => $state->value, 'sort_order' => 1]);
}

it('serves a live locale to anyone', function () {
    italianAt(LocaleState::Live);

    $this->getJson('/api/v1/test-context?locale=it')
        ->assertOk()
        ->assertJsonPath('locale', 'it');
});

it('refuses a preview locale to the public and falls back to the default', function () {
    italianAt(LocaleState::Preview);

    $this->getJson('/api/v1/test-context?locale=it')
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('serves a preview locale to an authenticated admin', function () {
    italianAt(LocaleState::Preview);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/test-context?locale=it')
        ->assertOk()
        ->assertJsonPath('locale', 'it');
});

it('refuses a locale that is still being translated, even to an admin', function () {
    // Available means work in progress. Preview is the state for reviewing it.
    italianAt(LocaleState::Available);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/test-context?locale=it')
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('falls back for an unknown or absent locale without erroring', function () {
    $this->getJson('/api/v1/test-context?locale=zz')->assertOk()->assertJsonPath('locale', 'en');
    $this->getJson('/api/v1/test-context?locale=')->assertOk()->assertJsonPath('locale', 'en');
    $this->getJson('/api/v1/test-context')->assertOk()->assertJsonPath('locale', 'en');
});

it('ignores a locale row whose code is absent from config', function () {
    // Config always wins. A live row for an undeclared code is not servable.
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
    ]);
    italianAt(LocaleState::Live);

    $this->getJson('/api/v1/test-context?locale=it')
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('sets the application locale, not just the context object', function () {
    italianAt(LocaleState::Live);

    // This is what makes translated content and lang files resolve correctly
    // for the rest of the request.
    $this->getJson('/api/v1/test-context?locale=it')
        ->assertJsonPath('app_locale', 'it');
});

it('never infers the locale from an accept-language header', function () {
    italianAt(LocaleState::Live);

    $this->getJson('/api/v1/test-context', ['Accept-Language' => 'it-IT,it;q=0.9'])
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('detects the currency from the cloudflare country header', function () {
    $this->getJson('/api/v1/test-context', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('currency', 'GBP');

    $this->getJson('/api/v1/test-context', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('currency', 'EUR');

    $this->getJson('/api/v1/test-context', ['CF-IPCountry' => 'JP'])
        ->assertJsonPath('currency', 'USD');
});

it('falls back to the default currency when no country header is present', function () {
    $this->getJson('/api/v1/test-context')->assertJsonPath('currency', 'USD');
});

it('honours an explicit currency and does not re-detect from the country', function () {
    $this->getJson('/api/v1/test-context?currency=USD', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('currency', 'USD');
});

it('honours the frontend override cookie when no currency is in the query', function () {
    // Sent as a browser would, not as getJson would: the JSON test helper drops
    // cookies entirely, and an Origin header is what makes Sanctum treat this
    // as a stateful frontend request, which is the path production takes.
    $this->withUnencryptedCookie('currency', 'GBP')
        ->get('/api/v1/test-context?x=1', [
            'Origin' => 'http://localhost:3000',
            'CF-IPCountry' => 'DE',
        ])
        ->assertJsonPath('currency', 'GBP');
});

it('reads the currency cookie even though the frontend does not encrypt it', function () {
    // Regression test. The cookie is written by Next.js in plain text, so
    // without an EncryptCookies exception Laravel discards it as undecryptable
    // and silently re-detects, overriding the customer's explicit choice.
    // This only fails on a stateful request, which is why Origin is set.
    $this->withUnencryptedCookie('currency', 'USD')
        ->get('/api/v1/test-context', [
            'Origin' => 'http://localhost:3000',
            'CF-IPCountry' => 'GB',
        ])
        ->assertJsonPath('currency', 'USD');
});

it('detects from the country when the supplied currency is not one we sell in', function () {
    $this->getJson('/api/v1/test-context?currency=JPY', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('currency', 'GBP');
});

it('resolves locale and currency independently of each other', function () {
    italianAt(LocaleState::Live);

    // An Italian speaker paying in pounds is an ordinary case, so the two must
    // not be derived from one another.
    $this->getJson('/api/v1/test-context?locale=it', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('locale', 'it')
        ->assertJsonPath('currency', 'GBP');
});
