<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Signing in.
 *
 * Carries its own rate limiting rather than leaning on the route's, because the
 * two protect against different things. The route limit is per address and
 * stops one machine hammering the endpoint. This one is per address **and**
 * email, which is what stops a slow guess at one account, and what stops an
 * attacker locking a real customer out by failing their sign in on purpose.
 */
class LoginRequest extends FormRequest
{
    /**
     * How many wrong answers before the pair is locked out for a minute.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],

            // No Password rules here on purpose. Applying the strength rules to
            // a sign in tells an attacker which passwords are not worth trying,
            // and would lock out anybody whose password predates a rule change.
            'password' => ['required', 'string'],

            'device_name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * A 429 rather than the framework's usual 422 on this one.
     *
     * Laravel's own starter kits raise a validation error here, which renders
     * as 422 with the message on the email field. That is wrong for this API:
     * API_CONTRACT.md section 7 tells the frontend partner that being rate
     * limited is a 429 carrying `Retry-After`, and a second way of saying the
     * same thing is a way for their retry logic to miss it.
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw new ThrottleRequestsException(
            'Too many sign in attempts. Try again in '.$seconds.' seconds.',
            null,
            ['Retry-After' => $seconds],
        );
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
