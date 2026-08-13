<?php

use App\Mail\PasswordResetLink;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
    Mail::fake();
});

function customerWhoForgot(): Customer
{
    return Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'the-old-password',
    ]);
}

/**
 * The token as the customer receives it, which is the only place it exists in
 * readable form. The table stores a hash of it.
 */
function resetTokenFor(Customer $customer): string
{
    return Password::broker('customers')->createToken($customer);
}

it('sends a reset link to somebody who has an account', function () {
    $customer = customerWhoForgot();

    $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])
        ->assertOk();

    Mail::assertQueued(
        PasswordResetLink::class,
        fn (PasswordResetLink $mail): bool => $mail->hasTo($customer->email),
    );
});

it('points the link at the website, never at this API', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');
    customerWhoForgot();

    $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])->assertOk();

    Mail::assertQueued(PasswordResetLink::class, function (PasswordResetLink $mail): bool {
        $rendered = $mail->render();

        // This API renders nothing a customer can use, per hard rule 1. A link
        // back here is a dead end, and it is what the framework does by default.
        return str_contains($rendered, 'https://staging.theworldtarot.com/reset-password?')
            && str_contains($rendered, 'token=')
            && ! str_contains($rendered, config('app.url').'/reset-password');
    });
});

it('answers an unknown address exactly as it answers a known one, and sends nothing', function () {
    customerWhoForgot();

    $known = $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com']);
    $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']);

    // Any difference at all makes this a way of asking whether somebody has an
    // account here, which is the question it exists to refuse.
    expect($known->status())->toBe($unknown->status())
        ->and($known->json())->toBe($unknown->json());

    Mail::assertQueued(PasswordResetLink::class, 1);
});

it('changes the password and lets them sign in with the new one', function () {
    $customer = customerWhoForgot();
    $token = resetTokenFor($customer);

    $this->postJson('/api/v1/reset-password', [
        'token' => $token,
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    expect(Hash::check('a-brand-new-password', $customer->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
    ])->assertOk();
});

it('refuses the old password afterwards', function () {
    $customer = customerWhoForgot();

    $this->postJson('/api/v1/reset-password', [
        'token' => resetTokenFor($customer),
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'the-old-password',
    ])->assertStatus(422);
});

it('refuses a token that has already been used', function () {
    $customer = customerWhoForgot();
    $token = resetTokenFor($customer);

    $payload = [
        'token' => $token,
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ];

    $this->postJson('/api/v1/reset-password', $payload)->assertOk();

    // Single use. A link sitting in an inbox forever is a spare key.
    $this->postJson('/api/v1/reset-password', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('refuses a token that has expired', function () {
    $customer = customerWhoForgot();
    $token = resetTokenFor($customer);

    $this->travel(config('auth.passwords.customers.expire') + 1)->minutes();

    $this->postJson('/api/v1/reset-password', [
        'token' => $token,
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('refuses a token invented from nothing', function () {
    customerWhoForgot();

    $this->postJson('/api/v1/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(422);
});

it('refuses a real token belonging to somebody else', function () {
    $customer = customerWhoForgot();
    $other = Customer::factory()->create(['email' => 'someone@example.com']);

    $this->postJson('/api/v1/reset-password', [
        'token' => resetTokenFor($other),
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(422);

    expect(Hash::check('the-old-password', $customer->fresh()->password))->toBeTrue();
});

it('holds the new password to the same rules as registering', function () {
    $customer = customerWhoForgot();

    $this->postJson('/api/v1/reset-password', [
        'token' => resetTokenFor($customer),
        'email' => 'jennifer@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('signs every device out when the password changes', function () {
    $customer = customerWhoForgot();
    $customer->createToken('phone');
    $customer->createToken('laptop');

    expect($customer->tokens()->count())->toBe(2);

    $this->postJson('/api/v1/reset-password', [
        'token' => resetTokenFor($customer),
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    // Somebody resetting a password often believes another person has it.
    // Leaving a signed in phone would make the reset a gesture.
    expect($customer->tokens()->count())->toBe(0);
});

it('rate limits requests for a link', function () {
    customerWhoForgot();

    foreach (range(1, 6) as $ignored) {
        $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])
            ->assertOk();
    }

    $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])
        ->assertStatus(429);
});

/**
 * The limit belongs to this endpoint and to no other.
 *
 * **This is a regression test for a real failure on staging**, found on 13
 * August 2026 by `tests/Staging`. Laravel keys a guest's rate limiter on the
 * domain and the IP and never on the route, so before each throttle was given
 * its own prefix in `routes/api.php`, every throttled route in the application
 * incremented one shared counter. Six requests to `/api/v1/orders` then refused
 * the first `/api/v1/forgot-password` of the minute.
 *
 * What that cost a customer: buying something used up the budget for
 * remembering their password, and the only symptom was a 429 from an endpoint
 * they had not called.
 *
 * It survived the suite this file lives in because a test here makes one or two
 * requests against a cache that is thrown away afterwards. It needs more than
 * one endpoint in one test to appear at all, which is what this does.
 */
it('does not spend the reset budget on unrelated endpoints', function () {
    customerWhoForgot();

    // Well within the order endpoint's own allowance, and nothing to do with
    // passwords. These are refused as invalid, which still counts as a request.
    foreach (range(1, 6) as $ignored) {
        $this->postJson('/api/v1/orders', [])->assertStatus(422);
    }

    $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])
        ->assertOk();
});

it('queues the reset mail rather than sending it during the request', function () {
    customerWhoForgot();

    $this->postJson('/api/v1/forgot-password', ['email' => 'jennifer@example.com'])->assertOk();

    Mail::assertQueued(PasswordResetLink::class);
    Mail::assertNotSent(PasswordResetLink::class);
});
