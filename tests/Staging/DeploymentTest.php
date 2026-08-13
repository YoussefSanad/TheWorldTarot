<?php

use Tests\Staging\Target;

/**
 * That the environment is itself, before anything is asked of it.
 *
 * Everything below is cheap and every one of them has been wrong in a deployed
 * environment at least once in this project's life.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

it('is up', function (): void {
    $response = Target::client()->get('up');

    expect($response->getStatusCode())->toBe(200);
});

it('is not running with debug on', function (): void {
    // A deliberately absent route. With APP_DEBUG on, Laravel answers a
    // detailed exception here, and the environment variables in a stack trace
    // are the whole application's keys.
    $response = Target::client()->get('api/v1/this-route-does-not-exist');
    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(404)
        ->and($body)->not->toContain('vendor/laravel')
        ->and($body)->not->toContain('APP_KEY')
        ->and(Target::json($response))->not->toHaveKey('trace');
})->skip(
    fn (): bool => Target::isLocal(),
    'Debug is meant to be on locally. This is a question about a deployed environment.'
);

it('answers json rather than html for an api error', function (): void {
    $response = Target::client()->get('api/v1/this-route-does-not-exist');

    expect($response->getHeaderLine('Content-Type'))->toContain('json');
});

it('keeps the api documentation behind an admin', function (): void {
    $response = Target::client()->get('docs/api');

    // 403 signed out. Anything else means the reference, which lists every
    // route including the ones nobody has been told about, is public.
    expect($response->getStatusCode())->toBe(403);
})->skip(
    fn (): bool => Target::isLocal(),
    'The reference is deliberately open locally. Only a deployed environment has to answer 403.'
);

it('sends the removed scaffolding route to nowhere', function (): void {
    $response = Target::client()->get('api/v1/user');

    expect($response->getStatusCode())->toBe(404);
});

it('serves the language list', function (): void {
    $response = Target::client()->get('api/v1/languages');
    $languages = Target::json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($languages)->not->toBeEmpty()
        ->and($languages[0])->toHaveKeys(['code', 'label']);
});

/**
 * CORS, which fails as something that looks like anything but CORS.
 *
 * The frontend cannot hold a session, or read a single response, unless its
 * exact origin is allowed with credentials. A missing entry here presents in a
 * browser as a network error with no explanation, and this is the cheapest
 * possible way to find out before somebody spends an afternoon on it.
 */
it('allows the website origin to make a credentialed request', function (): void {
    $response = Target::client()->request('OPTIONS', 'api/v1/languages', [
        'headers' => [
            'Origin' => Target::origin(),
            'Access-Control-Request-Method' => 'GET',
        ],
    ]);

    expect($response->getStatusCode())->toBeLessThan(300)
        ->and($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe(Target::origin())
        ->and($response->getHeaderLine('Access-Control-Allow-Credentials'))->toBe('true');
})->skip(
    fn (): bool => getenv('STAGING_ORIGIN') === false,
    'Set STAGING_ORIGIN to the exact origin the website will be served from, or this proves nothing.'
);

it('refuses an origin nobody registered', function (): void {
    $response = Target::client()->request('OPTIONS', 'api/v1/languages', [
        'headers' => [
            'Origin' => 'https://not-our-site.example.com',
            'Access-Control-Request-Method' => 'GET',
        ],
    ]);

    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->not->toBe('https://not-our-site.example.com');
});
