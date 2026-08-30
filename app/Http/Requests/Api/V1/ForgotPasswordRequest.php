<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalisesEmail;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    use NormalisesEmail;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Deliberately no `exists` rule. It would turn a validation error into
        // the answer to "does this person have an account", which is the exact
        // question this endpoint refuses to answer.
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
