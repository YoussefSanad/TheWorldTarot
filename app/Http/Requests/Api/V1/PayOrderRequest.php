<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a payment for an order that already exists.
 *
 * There is almost nothing to validate, because the order carries everything
 * about what is owed and the token in the path carries the authority to pay it.
 * What is left is which way the customer chose to pay.
 */
class PayOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
            | Optional, because there is one way to pay for a reading today and
            | naming it would be ceremony. It exists now rather than with
            | gifting because the shape is the contract, and adding a field to a
            | published endpoint later is a conversation with the frontend
            | partner that this avoids.
            |
            | **Validated against what this environment actually offers**, so a
            | typo is a 422 naming the field rather than an exception. The
            | registry refuses it a second time behind this, which is the layer
            | that catches a caller inside this application.
            */
            'method' => ['sometimes', 'string', Rule::in(array_keys(config('payments.methods', [])))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'That is not a way to pay here.',
        ];
    }
}
