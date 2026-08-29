<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductKey;
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
            | ~~Optional, because there is one way to pay for a reading today
            | and naming it would be ceremony.~~ **Two ways from 29 August
            | 2026**, `stripe` and `stripe_wallet`, so the field now carries a
            | real choice: the card button and the wallet button ask this
            | endpoint for different things and it cannot tell them apart
            | otherwise. It stays optional, because the shape is published and
            | narrowing it is a conversation with the frontend partner, and
            | leaving it out gets `payments.default` rather than a guess.
            |
            | **`GET /api/v1/payment-methods` is what tells a caller which
            | names exist here**, which is the list this rule validates against
            | one line below.
            |
            | **Validated against what this environment actually offers**, so a
            | typo is a 422 naming the field rather than an exception. The
            | registry refuses it a second time behind this, which is the layer
            | that catches a caller inside this application.
            */
            'method' => ['sometimes', 'string', Rule::in(array_keys(config('payments.methods', [])))],

            /*
            | Which reading page the checkout started from, so a cancelled one
            | lands back on it.
            |
            | **A product key. Not a path, and never a URL.** A caller-supplied
            | address is an open redirect available to anybody holding a pay
            | token, and allowlisting an origin to fix that leaves us building
            | the address anyway. `ProductKey`'s values are already the
            | frontend's reading-page slugs, so an allowlisted enum means no
            | string from the caller reaches an address at all.
            |
            | Anything outside the enum is a 422 naming this field, never a
            | quiet fall back to the index.
            */
            'return_to' => ['sometimes', Rule::enum(ProductKey::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'That is not a way to pay here.',
            'return_to' => 'That is not a reading you can return to.',
        ];
    }
}
