<?php

use Tests\Staging\Target;

/**
 * Member accounts, over the token path.
 *
 * The token path is used here because it needs no cookies, no CSRF and no
 * origin, so a failure is about accounts rather than about browsers. The cookie
 * path has a file of its own, and that is the one that has never been walked.
 *
 * **Accounts are shared wherever a test only needs somebody signed in.**
 * Registration allows ten a minute per address, and a suite that registers per
 * test exhausts that and then fails everything after it with a 429 that looks
 * like a bug. Only the two tests that genuinely need an account of their own,
 * because they lock one out or sign one out, make one.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

it('makes an account and signs it in, in one call', function (): void {
    $account = Target::sharedAccount();

    $me = Target::json(Target::client()->get('api/v1/me', ['headers' => Target::as($account)]));

    expect($me['email'])->toBe($account['email'])
        ->and($me['has_viewing_room_access'])->toBeFalse()
        ->and($me)->not->toHaveKey('password')
        ->and($me)->not->toHaveKey('remember_token');
});

it('never lets a personal response be cached', function (): void {
    $response = Target::client()->get('api/v1/me', [
        'headers' => Target::as(Target::sharedAccount()),
    ]);

    // A shared cache holding this serves one customer's name and address to
    // whoever asks next.
    expect($response->getHeaderLine('Cache-Control'))->toContain('no-store');
});

it('refuses an unknown email and a wrong password identically', function (): void {
    $client = Target::client();

    $wrongPassword = $client->post('api/v1/login', [
        'json' => ['email' => Target::sharedAccount()['email'], 'password' => 'not the right one at all'],
    ]);

    $unknownEmail = $client->post('api/v1/login', [
        'json' => ['email' => Target::freshEmail('nobody'), 'password' => 'not the right one at all'],
    ]);

    // **Byte for byte.** Any difference at all, in status, in wording, even in
    // which field carries the message, turns this endpoint into a way to ask
    // whether somebody has an account with a tarot site.
    expect($wrongPassword->getStatusCode())->toBe(422)
        ->and($unknownEmail->getStatusCode())->toBe(422)
        ->and((string) $unknownEmail->getBody())->toBe((string) $wrongPassword->getBody());
});

it('locks out a slow guess at one account, and says so as a 429', function (): void {
    // Its own account, because this one ends up locked out.
    $account = Target::register('throttle');
    $client = Target::client();
    $statuses = [];

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $statuses[] = $client->post('api/v1/login', [
            'json' => ['email' => $account['email'], 'password' => 'wrong every time'],
        ])->getStatusCode();
    }

    expect(end($statuses))->toBe(429);

    $response = $client->post('api/v1/login', [
        'json' => ['email' => $account['email'], 'password' => Target::password()],
    ]);

    // Not 422. The contract promises the frontend that rate limiting is a 429
    // carrying Retry-After, and a second way of saying it is a way for their
    // retry logic to miss it. The right password is refused too, which is what
    // makes the lockout worth anything.
    expect($response->getStatusCode())->toBe(429)
        ->and($response->getHeaderLine('Retry-After'))->not->toBeEmpty();
});

it('signs out only the credential that asked', function (): void {
    // Its own account, because this one gets signed out.
    $account = Target::register('logout');

    $second = Target::json(Target::client()->post('api/v1/login', [
        'json' => [
            'email' => $account['email'],
            'password' => Target::password(),
            'device_name' => 'a second device',
        ],
    ]));

    $logout = Target::client()->post('api/v1/logout', ['headers' => Target::as($account)]);

    expect($logout->getStatusCode())->toBe(204);

    $first = Target::client()->get('api/v1/me', ['headers' => Target::as($account)]);

    $other = Target::client()->get('api/v1/me', [
        'headers' => ['Authorization' => 'Bearer '.$second['token']],
    ]);

    // Signing out of a phone must not sign the same person out of a laptop.
    expect($first->getStatusCode())->toBe(401)
        ->and($other->getStatusCode())->toBe(200);
});

it('refuses a nonsense token', function (): void {
    $response = Target::client()->get('api/v1/me', [
        'headers' => ['Authorization' => 'Bearer 1|thisisnotatokenatall'],
    ]);

    expect($response->getStatusCode())->toBe(401);
});

/**
 * The wall between customers and staff.
 *
 * It has existed since M1, in the shape of two guards over two providers, and
 * until member accounts shipped there was no way to walk it from the customer
 * side. This is that walk.
 */
it('does not let a customer token into the admin panel', function (): void {
    $response = Target::client()->get('admin', [
        'headers' => Target::as(Target::sharedAccount()),
    ]);

    // The panel authenticates against the admin guard, which has never heard of
    // this person, so buying a pass cannot make somebody staff.
    //
    // The assertion is that they are turned away, not where they are sent. A
    // request carrying `Accept: application/json` is redirected to the API's
    // own login route rather than the panel's, because naming that route
    // `login` makes it the framework's app-wide `route('login')`. It is a wart
    // and not a leak: nothing reaches the panel either way, and no browser
    // sends that header while navigating.
    expect($response->getStatusCode())->toBe(302)
        ->and((string) $response->getBody())->not->toContain('filament');
});

it('sends a person who is not signed in to the panel login page', function (): void {
    // Shaped like a browser navigating, which is the only way anybody arrives
    // at the panel. No Accept header asking for json.
    $response = Target::client()->get('admin', [
        'headers' => ['Accept' => 'text/html'],
    ]);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->getHeaderLine('Location'))->toContain('/admin/login');

    $login = Target::client()->get('admin/login', ['headers' => ['Accept' => 'text/html']]);

    // The page she signs in on has to actually be there. QA_CHECKLIST section 2
    // opens with this and it is the first thing a broken deploy takes out.
    expect($login->getStatusCode())->toBe(200);
});
