<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductKey;
use App\Services\Payments\PaymentMethods;
use Illuminate\Contracts\Validation\Validator;
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
     * A payment that names no method gets `payments.default`, which must be
     * offered. Where the registry is empty (no Stripe keys) it is not, and the
     * registry would throw UnknownPaymentMethod and answer 500. This is the
     * same refusal as naming an unknown method, a 422 on the field.
     *
     * **Two side effects, both accepted.** A mistyped PAYMENT_METHOD on a
     * deployed environment now gives a quiet 422 rather than a logged 500. And
     * with no keys, an already-paid order gets a 422 rather than
     * `nothing_to_pay`, because validation runs before `isPending()`.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('method') || app(PaymentMethods::class)->offers((string) config('payments.default'))) {
                    return;
                }

                $validator->errors()->add('method', $this->messages()['method.in']);
            },
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
