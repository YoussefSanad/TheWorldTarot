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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
