<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reading back what happened to the money for an order.
 *
 * **The token is in the body, and that is the point of the endpoint.** It is a
 * credential, and a path segment lands in every access log between the browser
 * and this application, which is why this is not a `GET /orders/{pay_token}`.
 */
class ReadPaymentStatusRequest extends FormRequest
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
            | **No length, no format, no character class.** A rule describing
            | what a real token looks like would answer 422 for a shape no order
            | has and 404 for a shape some order might, and the difference
            | between those two answers is a way of asking whether a guess got
            | closer. Anything that is a string reaches the lookup and every one
            | that is not ours comes back the same 404.
            */
            'pay_token' => ['required', 'string'],
        ];
    }
}
