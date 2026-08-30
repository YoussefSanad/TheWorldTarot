<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * One address, however it was typed, before any rule looks at it.
 *
 * **Every endpoint that takes an email as an identity has to agree about this,
 * exactly, and the agreement is what matters rather than the rule.** Lowercase
 * and trim is one defensible choice among several. Registering with one choice
 * and signing in with another is not a worse choice, it is a lockout: the row
 * was stored `jennifer@example.com`, the sign in looks up `Jennifer@Example.com`
 * and finds nothing, and the customer is told their credentials do not match an
 * account that exists. Nothing logs it, because nothing went wrong.
 *
 * It was written out four times before this trait existed, in `RegisterRequest`,
 * `LoginRequest`, `ForgotPasswordRequest` and `ResetPasswordRequest`. Four
 * copies of a rule whose only requirement is that all four say the same thing.
 *
 * `prepareForValidation` rather than a cast or a mutator, because it has to
 * happen before validation rather than after it. `RegisterRequest` applies
 * `unique:customers,email` and a `lowercase` rule, and both are answers about
 * the normalised value or they are answers about nothing.
 *
 * The address is only touched when one was sent as a string. A missing or
 * malformed field is left exactly as it arrived, so `required` and `email`
 * report on what the customer actually typed.
 */
trait NormalisesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
