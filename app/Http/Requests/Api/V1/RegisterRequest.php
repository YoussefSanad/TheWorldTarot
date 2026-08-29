<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Making an account.
 *
 * Backend validation is authoritative, per .claude/api.md. The frontend
 * validates the same fields with Zod and this runs again regardless.
 */
class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Lowercased before the unique check, so Jennifer@ and jennifer@
            // cannot become two accounts that look identical in a support
            // conversation and behave differently at sign in.
            //
            // The refusal's wording is in messages() below, and it is the part
            // of this rule that had to be thought about.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:customers,email'],

            // Password::defaults() is set once in AppServiceProvider, so
            // registering and resetting can never disagree about what a good
            // password is.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],

            // Present means a native app is asking, and it gets a token back.
            // Absent means the website is asking, and it gets a session cookie.
            'device_name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            /*
             * **One sentence for two different situations, naming neither.**
             *
             * Somebody who bought a reading already has an account, made for
             * them at settlement with no password on it, and the claim link in
             * their receipt is how they get into it. Reaching this rule is
             * often exactly that person, and "already taken" left them with
             * nowhere to go: they cannot register, and they do not know they
             * can sign in.
             *
             * The tension, and the reason this took a decision rather than a
             * better sentence: any wording that told an unclaimed account
             * apart from a claimed one would answer "does this address have an
             * account here, and has somebody claimed it", which is the
             * question `/api/v1/login`, `/api/v1/forgot-password` and
             * `/api/v1/set-password` all refuse. So the claim path is offered
             * conditionally, on a condition only the reader can evaluate, and
             * the sentence reads correctly for a stranger who has bought
             * nothing and is simply guessing at addresses.
             */
            'email.unique' => 'We cannot make an account for that address. If you have bought a reading with it, ask for a link on the sign in page and choose a password there.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
