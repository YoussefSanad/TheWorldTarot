<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking what a code is worth, without spending it.
 *
 * **The code is in the body, and that is the point of the endpoint.** It is a
 * bearer credential, and a path segment or a query string lands in every access
 * log and every proxy between the browser and this application, which is why
 * this is not a `GET /gifts/{code}`. `ReadPaymentStatusRequest` takes the pay
 * token the same way and for the same reason.
 */
class LookUpGiftRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
            | Required, and nothing beyond that.
            |
            | **No length, no format, no character class**, which is the rule
            | `ReadPaymentStatusRequest` states for the pay token and the same
            | argument applies here. A rule describing what a real code looks
            | like would answer 422 for a shape no gift has and 404 for a shape
            | some gift might, and the difference between those two answers is a
            | way of asking whether a guess got closer. Anything that is a
            | string is normalised, looked up, and misses.
            */
            'code' => ['required', 'string'],
        ];
    }
}
