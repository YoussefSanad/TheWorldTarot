<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Spending a code, which is the moment somebody asks for the reading.
 *
 * The code travels in the body for the reason `LookUpGiftRequest` gives. What
 * is different here is the three fields beside it, and each of them is a
 * decision rather than a form.
 */
class RedeemGiftRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // As on the lookup, and for the same reason: nothing here describes
            // the shape of a real code.
            'code' => ['required', 'string'],

            /*
            | **Required, unlike the question on a purchase.**
            | `StoreOrderRequest` accepts a reading line with no question,
            | because a buyer may be buying for somebody who has not asked yet
            | and a gift line is exactly that case. This is that later moment:
            | it is the asking, every giftable product allows a question, and a
            | reading without one is not a reading.
            |
            | The same 2000 characters a buyer's question gets, so a person who
            | typed a long question into the wrong box is not told two different
            | things by two screens.
            */
            'question' => ['required', 'string', 'max:2000'],

            /*
            | **Asked for rather than inherited from `gifts.recipient_email`.**
            | A forwarded gift is enough to part the recipient from the querent,
            | and inheriting silently would deliver the reading to whoever
            | forwarded it.
            |
            | **Not put through `NormalisesEmail`, deliberately.** That trait
            | exists because an address used as an identity has to be looked up
            | with the same spelling it was stored with, or somebody is locked
            | out of an account that exists. Nothing looks a querent up: they
            | get no customer row, by the decision in the `readings` migration,
            | so this address is only ever written and then written to.
            */
            'querent_email' => ['required', 'string', 'email', 'max:255'],

            // Optional, exactly as a buyer's name has been since #52. Nothing
            // on this road hedges around its absence: `App\Support\Greeting`
            // opens "Hello,".
            'querent_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
