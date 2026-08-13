<?php

use GuzzleHttp\Cookie\CookieJar;
use Tests\Staging\Target;

/**
 * Cookie authentication across two subdomains, which nothing has ever walked.
 *
 * It was configured in M1 and there was nothing to sign in to until M5, so on
 * the day this was written no customer had ever held a session. It is named in
 * `docs/plans/milestone-5-plan.md` as the riskiest unproven thing in the
 * milestone, and the plan says a test suite cannot prove it.
 *
 * **That is still true, and this file is the closest a script gets.** Read what
 * it does and does not prove before trusting it.
 *
 * Proved here, and all of it is server-side configuration that a browser cannot
 * work around:
 *
 * - The CSRF cookie is issued at all
 * - **The session cookie is scoped to the shared root domain**, which is the
 *   single setting the whole arrangement rests on. Scoped to the API host
 *   alone, the website is simply never signed in, and nothing says why
 *   `SESSION_DOMAIN` is unset in `.env.example`, so an environment that has not
 *   set it deliberately is wrong
 * - The session cookie is `Secure` and `HttpOnly` over https
 * - A request carrying the site's origin is treated as stateful, which is what
 *   `SANCTUM_STATEFUL_DOMAINS` decides. If it is not, registering without a
 *   device name answers 422 saying this origin cannot hold a session, and that
 *   is the failure this file is most likely to catch
 * - A session survives from one request to the next
 * - A write without the CSRF header is refused with 419
 *
 * **Not proved here, and it needs a real browser on the real origin.** How the
 * browser itself treats the cookie: `SameSite` behaviour on a cross-site
 * navigation, third party cookie blocking, private-mode partitioning, and
 * whether the frontend's own `fetch` sends `credentials: "include"` on every
 * call including the first. curl believes whatever it is told. A browser does
 * not, and the two share a root domain here precisely so that it agrees.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

/**
 * Everything a browser would send from the website, minus the browser.
 *
 * The origin is what makes Sanctum treat the request as stateful, so leaving it
 * off does not weaken this test, it tests something else entirely.
 *
 * @return array<string, string>
 */
function asWebsite(CookieJar $jar): array
{
    $headers = [
        'Origin' => Target::origin(),
        'Referer' => Target::origin().'/',
    ];

    $xsrf = $jar->getCookieByName('XSRF-TOKEN');

    if ($xsrf !== null) {
        // What axios does for you and fetch does not. The cookie arrives
        // percent-encoded and the header wants it raw.
        $headers['X-XSRF-TOKEN'] = urldecode((string) $xsrf->getValue());
    }

    return $headers;
}

it('issues a csrf cookie to the website', function (): void {
    $jar = new CookieJar;
    $response = Target::client($jar)->get('sanctum/csrf-cookie', [
        'headers' => ['Origin' => Target::origin()],
    ]);

    expect($response->getStatusCode())->toBeLessThan(300)
        ->and($jar->getCookieByName('XSRF-TOKEN'))->not->toBeNull();
});

it('scopes the session cookie to the shared root domain', function (): void {
    $jar = new CookieJar;
    Target::client($jar)->get('sanctum/csrf-cookie', [
        'headers' => ['Origin' => Target::origin()],
    ]);

    $session = Target::sessionCookie($jar);

    expect($session)->not->toBeNull('No session cookie was issued at all.');

    $apiHost = (string) parse_url(Target::url(), PHP_URL_HOST);
    $siteHost = (string) parse_url(Target::origin(), PHP_URL_HOST);

    // Only meaningful when the two really are siblings. Locally they are not,
    // and there is nothing to prove.
    if ($apiHost === $siteHost || ! str_contains($apiHost, '.')) {
        expect(true)->toBeTrue();

        return;
    }

    $root = implode('.', array_slice(explode('.', $apiHost), -2));

    // **This is the setting the whole arrangement rests on.** A cookie scoped
    // to the API host alone is sent back to the API and never to the website,
    // so the site is permanently signed out with no error anywhere.
    expect(ltrim((string) $session['Domain'], '.'))->toBe($root)
        ->and($siteHost)->toEndWith($root);
});

it('protects the session cookie the way a credential should be protected', function (): void {
    $jar = new CookieJar;
    Target::client($jar)->get('sanctum/csrf-cookie', [
        'headers' => ['Origin' => Target::origin()],
    ]);

    $session = Target::sessionCookie($jar);

    expect($session)->not->toBeNull('No session cookie was issued at all.');
    expect($session['HttpOnly'])->toBeTrue('A session cookie readable by JavaScript is a session cookie an XSS can steal.');

    if (str_starts_with(Target::url(), 'https://')) {
        expect($session['Secure'])->toBeTrue('A session cookie without Secure can leak over a plain http request.');
    }
});

it('signs somebody in with a cookie and no token at all', function (): void {
    $jar = new CookieJar;
    $client = Target::client($jar);

    $client->get('sanctum/csrf-cookie', ['headers' => ['Origin' => Target::origin()]]);

    $email = Target::freshEmail('cookie');

    $response = $client->post('api/v1/register', [
        'headers' => asWebsite($jar),
        'json' => [
            'name' => 'Cookie Walk',
            'email' => $email,
            'password' => Target::password(),
            'password_confirmation' => Target::password(),
            // No device_name. This is the website asking, so the answer is a
            // session rather than a token.
        ],
    ]);

    $body = Target::json($response);

    // The single most likely failure in this file, and it says exactly what is
    // wrong: the origin is not on the stateful list, so no session could be
    // created and the API offered a token instead.
    if ($response->getStatusCode() === 422 && isset($body['errors']['device_name'])) {
        $this->fail(
            Target::origin().' is not treated as stateful by '.Target::url().'. '
            .'Add it to SANCTUM_STATEFUL_DOMAINS, host only and no scheme, and keep CORS_ALLOWED_ORIGINS in step.'
        );
    }

    expect($response->getStatusCode())->toBe(201)
        ->and($body)->not->toHaveKey('token');

    // The session survives into the next request, which is the whole point.
    $me = $client->get('api/v1/me', ['headers' => asWebsite($jar)]);

    expect($me->getStatusCode())->toBe(200)
        ->and(Target::json($me)['email'])->toBe($email);
});

it('refuses a write that arrives without the csrf header', function (): void {
    $jar = new CookieJar;
    $client = Target::client($jar);

    $client->get('sanctum/csrf-cookie', ['headers' => ['Origin' => Target::origin()]]);

    // Signed in with the shared account rather than a new one, because
    // registering costs a slot in a ten a minute limit and this test is about
    // what happens next.
    $client->post('api/v1/login', [
        'headers' => asWebsite($jar),
        'json' => [
            'email' => Target::sharedAccount()['email'],
            'password' => Target::password(),
        ],
    ]);

    // Everything a browser sends except the one header that proves the request
    // came from a page rather than from somebody else's page.
    $response = $client->post('api/v1/logout', [
        'headers' => [
            'Origin' => Target::origin(),
            'Referer' => Target::origin().'/',
        ],
    ]);

    expect($response->getStatusCode())->toBe(419);
});

it('ends the session on the way out', function (): void {
    $jar = new CookieJar;
    $client = Target::client($jar);

    $client->get('sanctum/csrf-cookie', ['headers' => ['Origin' => Target::origin()]]);

    $client->post('api/v1/login', [
        'headers' => asWebsite($jar),
        'json' => [
            'email' => Target::sharedAccount()['email'],
            'password' => Target::password(),
        ],
    ]);

    $logout = $client->post('api/v1/logout', ['headers' => asWebsite($jar)]);

    expect($logout->getStatusCode())->toBe(204);

    $me = $client->get('api/v1/me', ['headers' => asWebsite($jar)]);

    expect($me->getStatusCode())->toBe(401);
});
