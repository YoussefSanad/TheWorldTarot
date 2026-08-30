<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalisesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    use NormalisesEmail;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],

            // The same Password::defaults() registering uses, so a password
            // acceptable in one place cannot be refused in the other.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
