<?php

use App\Enums\LocaleStatus;
use App\Models\Admin;
use App\Models\Locale;
use App\Support\Locales;
use App\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Locales::forget();

    // A route inside the api group, which is where the middleware is applied.
    Route::middleware('api')->get('/api/v1/{locale}/test-context', function () {
        $context = app(RequestContext::class);

        return [
            'locale' => $context->locale,
            'currency' => $context->currency,
            'app_locale' => app()->getLocale(),
        ];
    });
});

/**
 * Spanish ships in the registry as a draft, so these tests move it rather than
 * inventing a language.
 */
function spanishAt(LocaleStatus $status): void
{
    Locale::where('code', 'es')->update(['status' => $status]);
    Locales::forget();
}

it('serves a live locale to anyone', function () {
    spanishAt(LocaleStatus::Live);

    $this->getJson('/api/v1/es/test-context')
        ->assertOk()
        ->assertJsonPath('locale', 'es');
});

it('refuses a preview locale to the public', function () {
    spanishAt(LocaleStatus::Preview);

    // Not a fallback. A language that is not public has no address.
    $this->getJson('/api/v1/es/test-context')->assertNotFound();
});

it('serves a preview locale to an authenticated admin', function () {
    spanishAt(LocaleStatus::Preview);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/test-context')
        ->assertOk()
        ->assertJsonPath('locale', 'es');
});

it('refuses a locale that is still being translated, even to an admin', function () {
    // Draft means work in progress. Preview is the state for reviewing it.
    spanishAt(LocaleStatus::Draft);

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/test-context')
        ->assertNotFound();
});

it('answers 404 for a language that is not a language', function () {
    $this->getJson('/api/v1/zz/test-context')->assertNotFound();
});

it('ignores a language that is not in the registry at all', function () {
    // The table is the whole registry, so a code with no row is simply not a
    // language this site has, and its URLs do not exist.
    $this->getJson('/api/v1/fr/test-context')->assertNotFound();
});

it('sets the application locale, not just the context object', function () {
    spanishAt(LocaleStatus::Live);

    // This is what makes translated content and lang files resolve correctly
    // for the rest of the request.
    $this->getJson('/api/v1/es/test-context')
        ->assertJsonPath('app_locale', 'es');
});

it('never infers the locale from an accept-language header', function () {
    spanishAt(LocaleStatus::Live);

    $this->getJson('/api/v1/en/test-context', ['Accept-Language' => 'it-IT,it;q=0.9'])
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('detects the currency from the cloudflare country header', function () {
    $this->getJson('/api/v1/en/test-context', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('currency', 'GBP');

    $this->getJson('/api/v1/en/test-context', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('currency', 'EUR');

    $this->getJson('/api/v1/en/test-context', ['CF-IPCountry' => 'JP'])
        ->assertJsonPath('currency', 'USD');
});

it('falls back to the default currency when no country header is present', function () {
    $this->getJson('/api/v1/en/test-context')->assertJsonPath('currency', 'USD');
});

it('honours an explicit currency and does not re-detect from the country', function () {
    $this->getJson('/api/v1/en/test-context?currency=USD', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('currency', 'USD');
});

it('honours the frontend override cookie when no currency is in the query', function () {
    // Sent as a browser would, not as getJson would: the JSON test helper drops
    // cookies entirely, and an Origin header is what makes Sanctum treat this
    // as a stateful frontend request, which is the path production takes.
    $this->withUnencryptedCookie('currency', 'GBP')
        ->get('/api/v1/en/test-context?x=1', [
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
        ->get('/api/v1/en/test-context', [
            'Origin' => 'http://localhost:3000',
            'CF-IPCountry' => 'GB',
        ])
        ->assertJsonPath('currency', 'USD');
});

it('detects from the country when the supplied currency is not one we sell in', function () {
    $this->getJson('/api/v1/en/test-context?currency=JPY', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('currency', 'GBP');
});

it('resolves locale and currency independently of each other', function () {
    spanishAt(LocaleStatus::Live);

    // A Spanish speaker paying in pounds is an ordinary case, so the two must
    // not be derived from one another.
    $this->getJson('/api/v1/es/test-context', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('locale', 'es')
        ->assertJsonPath('currency', 'GBP');
});
