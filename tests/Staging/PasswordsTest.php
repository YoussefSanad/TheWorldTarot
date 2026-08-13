<?php

use Tests\Staging\Target;

/**
 * Forgotten passwords, and claiming an account somebody bought their way into.
 *
 * **What a script cannot do here is read a mailbox**, so the half of this that
 * matters most is a manual check. Set `STAGING_EMAIL_DOMAIN` to a domain you
 * can actually receive at, run this file, and then go and look:
 *
 * - A message arrives at all, which proves the transport rather than our code
 * - It comes from `support@theworldtarot.com`
 * - It lands in an inbox rather than a spam folder, which is DNS and not code
 * - **Its link points at the website**, carrying `token` and `email`, since we
 *   render nothing a customer sees. A link pointing back at this API is the
 *   `FRONTEND_URL` misconfiguration this walk exists to catch
 *
 * Everything below is what can be proved from outside without a mailbox.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

it('answers a forgotten password request identically for everybody', function (): void {
    $client = Target::client();
    $email = Target::sharedAccount()['email'];

    $hasAccount = $client->post('api/v1/forgot-password', ['json' => ['email' => $email]]);
    $hasNoAccount = $client->post('api/v1/forgot-password', ['json' => ['email' => Target::freshEmail('nobody')]]);

    Target::assertNotThrottled($hasAccount, 'forgot-password');

    // Identical, or this endpoint answers "does this person have an account
    // here" to anybody who asks, about customers of a tarot site.
    expect($hasAccount->getStatusCode())->toBe(200)
        ->and($hasNoAccount->getStatusCode())->toBe(200)
        ->and((string) $hasNoAccount->getBody())->toBe((string) $hasAccount->getBody());

    Target::say('Check the inbox for '.$email.'. A reset link should have arrived, pointing at the website.');
});

it('refuses a reset token that was never issued', function (): void {
    $response = Target::client()->post('api/v1/reset-password', [
        'json' => [
            'token' => 'this token was never issued by anybody',
            'email' => Target::freshEmail('bad-token'),
            'password' => Target::password(),
            'password_confirmation' => Target::password(),
        ],
    ]);

    Target::assertNotThrottled($response, 'reset-password');

    expect($response->getStatusCode())->toBe(422);
});

it('refuses to set a first password on an account that already has one', function (): void {
    $client = Target::client();
    $email = Target::sharedAccount()['email'];

    // Even with a real token this would be refused, because the account has a
    // password already. A claim link is not a spare key to an account somebody
    // is using, and the refusal happens before the token is even considered.
    $response = $client->post('api/v1/set-password', [
        'json' => [
            'token' => 'irrelevant, the account is already claimed',
            'email' => $email,
            'password' => 'a completely different password',
            'password_confirmation' => 'a completely different password',
        ],
    ]);

    Target::assertNotThrottled($response, 'set-password');

    expect($response->getStatusCode())->toBe(422);

    // And the original password still works, which is the part that would
    // actually hurt if this were wrong.
    $login = $client->post('api/v1/login', [
        'json' => ['email' => $email, 'password' => Target::password(), 'device_name' => 'staging walk'],
    ]);

    expect($login->getStatusCode())->toBe(200);
});

/**
 * The defect this walk found, written as the behaviour we want.
 *
 * It fails today. `.claude/testing.md` is explicit that a failing test means
 * the code is wrong until proven otherwise, so it stays failing rather than
 * being softened into a description of the bug.
 */
it('does not spend the password reset budget on unrelated routes', function (): void {
    $client = Target::client();

    // Well inside the order endpoint's own limit of twenty a minute, and
    // nothing to do with passwords.
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $client->post('api/v1/orders', ['json' => []]);
    }

    $response = $client->post('api/v1/forgot-password', [
        'json' => ['email' => Target::freshEmail('unrelated')],
    ]);

    // A customer who placed an order and then forgot their password must not
    // be told to come back later.
    expect($response->getStatusCode())->toBe(200);
});

/**
 * Off by default because it rate limits **your whole address**, not one
 * account, so it can make the rest of this suite fail for the wrong reason.
 * Run it on its own with STAGING_THROTTLE_TESTS=1 when you want it.
 */
it('rate limits requests for a reset link', function (): void {
    $client = Target::client();
    $statuses = [];

    for ($attempt = 1; $attempt <= 7; $attempt++) {
        $statuses[] = $client->post('api/v1/forgot-password', [
            'json' => ['email' => Target::freshEmail('flood')],
        ])->getStatusCode();
    }

    // An unthrottled reset endpoint is an email bombing tool pointed at
    // whatever address the sender names, and it costs the sender nothing.
    expect(end($statuses))->toBe(429);
})->skip(
    fn (): bool => getenv('STAGING_THROTTLE_TESTS') !== '1',
    'Locks your address out of forgot-password for a minute. Set STAGING_THROTTLE_TESTS=1 to run it.'
);
