<?php

use App\Support\CurrencyResolver;

it('maps an EU country to euro', function () {
    expect(CurrencyResolver::forCountry('DE'))->toBe('EUR')
        ->and(CurrencyResolver::forCountry('IT'))->toBe('EUR');
});

it('maps every EU member state to euro, including the ones outside the eurozone', function () {
    // The commercial simplification in config/currencies.php. A Swedish visitor
    // is quoted in euro rather than adding a fourth currency to price by hand.
    expect(CurrencyResolver::forCountry('SE'))->toBe('EUR')
        ->and(CurrencyResolver::forCountry('PL'))->toBe('EUR')
        ->and(CurrencyResolver::forCountry('DK'))->toBe('EUR');
});

it('maps the united kingdom to pounds', function () {
    expect(CurrencyResolver::forCountry('GB'))->toBe('GBP');
});

it('falls back to the default currency for a country it does not map', function () {
    expect(CurrencyResolver::forCountry('JP'))->toBe('USD')
        ->and(CurrencyResolver::forCountry('AU'))->toBe('USD');
});

it('falls back to the default when cloudflare cannot identify the country', function () {
    // XX is Cloudflare's unknown, T1 is a Tor exit node. Neither is a country.
    expect(CurrencyResolver::forCountry('XX'))->toBe('USD')
        ->and(CurrencyResolver::forCountry('T1'))->toBe('USD')
        ->and(CurrencyResolver::forCountry(null))->toBe('USD')
        ->and(CurrencyResolver::forCountry(''))->toBe('USD');
});

it('accepts a country code in any case', function () {
    expect(CurrencyResolver::forCountry('gb'))->toBe('GBP');
});

it('falls back when a mapped currency is no longer available', function () {
    // Removing a currency from the available list must take it out of
    // circulation everywhere, rather than leaving country mappings selling in
    // a currency that has no price rows behind it.
    config()->set('currencies.available', [
        'USD' => ['symbol' => '$', 'label' => 'US Dollar'],
    ]);

    expect(CurrencyResolver::forCountry('GB'))->toBe('USD');
});

it('honours an explicit currency without re-detecting from the country', function () {
    // A visitor in Germany who chose dollars keeps dollars. Re-detecting here
    // would overwrite a deliberate choice on every request.
    expect(CurrencyResolver::resolve('USD', 'DE'))->toBe('USD')
        ->and(CurrencyResolver::resolve('usd', 'DE'))->toBe('USD');
});

it('detects from the country when the explicit currency is absent or unsupported', function () {
    expect(CurrencyResolver::resolve(null, 'GB'))->toBe('GBP')
        ->and(CurrencyResolver::resolve('', 'GB'))->toBe('GBP')
        ->and(CurrencyResolver::resolve('JPY', 'GB'))->toBe('GBP')
        ->and(CurrencyResolver::resolve('nonsense', 'GB'))->toBe('GBP');
});

it('reports which currencies are supported', function () {
    expect(CurrencyResolver::isSupported('EUR'))->toBeTrue()
        ->and(CurrencyResolver::isSupported('eur'))->toBeTrue()
        ->and(CurrencyResolver::isSupported('JPY'))->toBeFalse()
        ->and(CurrencyResolver::isSupported(null))->toBeFalse();
});
