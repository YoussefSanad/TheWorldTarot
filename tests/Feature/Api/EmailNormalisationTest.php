<?php

use App\Mail\PasswordResetLink;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/**
 * One address, however it was typed, reaches one account.
 *
 * **This is the rule that cannot be allowed to drift**, and until this file
 * existed nothing held it still. It was written four times, once in each of
 * `RegisterRequest`, `LoginRequest`, `ForgotPasswordRequest` and
 * `ResetPasswordRequest`, and the suite would have stayed green with any one of
 * the four missing.
 *
 * What that costs is not cosmetic. Registration lowercases and stores, so a
 * sign in that stopped lowercasing looks up `Jennifer@Example.com`, finds
 * nothing, and answers the same "those details do not match" a wrong password
 * gets. The customer is locked out of an account that exists, by an address
 * they typed correctly, and no log anywhere records anything unusual.
 *
 * So each endpoint is asked here rather than the trait being unit tested. A
 * test of `NormalisesEmail` in isolation proves the trait works and says
 * nothing about whether a request still uses it, which is the failure actually
 * worth catching.
 *
 * Mixed case and surrounding space together, because a pasted address commonly
 * carries both and the two are one rule.
 */
const TYPED = '  Jennifer@Example.COM ';

const STORED = 'jennifer@example.com';

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
    Mail::fake();
});

it('stores the normalised address when somebody registers', function () {
    $this->postJson('/api/v1/register', [
        'name' => 'Jennifer',
        'email' => TYPED,
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'device_name' => 'a phone',
    ])->assertCreated();

    expect(Customer::firstWhere('email', STORED))->not->toBeNull();
});

it('signs in an account that was registered in another case', function () {
    Customer::factory()->create([
        'email' => STORED,
        'password' => 'a-long-enough-password',
    ]);

    $this->postJson('/api/v1/login', [
        'email' => TYPED,
        'password' => 'a-long-enough-password',
    ])->assertOk();
});

it('refuses a second registration that differs only in case', function () {
    Customer::factory()->create(['email' => STORED]);

    // The uniqueness rule runs against the normalised value, so this is refused
    // by `email.unique` rather than being allowed through as a new address.
    // Two rows for one address is the thing normalisation exists to prevent.
    //
    // **The message is asserted, not just the 422.** `RegisterRequest` also
    // carries a `lowercase` rule, so without normalisation this address is
    // refused anyway, with an email error, for an entirely different reason.
    // Asserting the status alone left this the one test in the file that stayed
    // green when the rule was stripped out.
    $this->postJson('/api/v1/register', [
        'name' => 'Jennifer',
        'email' => TYPED,
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'device_name' => 'a phone',
    ])->assertStatus(422)->assertJsonValidationErrors([
        'email' => 'We cannot make an account for that address. If you have bought a reading with it, ask for a link on the sign in page and choose a password there.',
    ]);
});

it('finds the account behind a differently cased forgotten-password request', function () {
    $customer = Customer::factory()->create(['email' => STORED]);

    $this->postJson('/api/v1/forgot-password', ['email' => TYPED])->assertOk();

    // This endpoint answers 200 whatever happened, deliberately, so the status
    // proves nothing on its own. The mail is the only evidence the address was
    // matched to an account at all.
    Mail::assertQueued(
        PasswordResetLink::class,
        fn ($mail): bool => $mail->hasTo($customer->email),
    );
});

it('resets a password against a token issued for the normalised address', function () {
    $customer = Customer::factory()->create([
        'email' => STORED,
        'password' => 'the-old-password',
    ]);

    // The broker keys its token on the address it was given. Reset while
    // normalising differently and the token is looked up under a key nothing
    // wrote, which surfaces as an invalid-token error on a link that is fine.
    $token = Password::broker('customers')->createToken($customer);

    $this->postJson('/api/v1/reset-password', [
        'token' => $token,
        'email' => TYPED,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    $this->postJson('/api/v1/login', [
        'email' => STORED,
        'password' => 'a-brand-new-password',
    ])->assertOk();
});
