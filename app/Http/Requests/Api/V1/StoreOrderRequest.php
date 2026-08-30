<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductKey;
use App\Models\Customer;
use App\Purchasability\Basket;
use App\Purchasability\Purchasability;
use App\Support\CurrencyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Placing an order.
 *
 * A basket rather than one thing, because the rush add-on is defined as
 * something bought alongside something else, and because a currency carries
 * from product page to cart to payment.
 */
class StoreOrderRequest extends FormRequest
{
    /**
     * How many distinct things one order may hold. There are seven products in
     * the whole catalogue, so this is generous and still bounded.
     */
    public const MAX_LINES = 10;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // **The guard is named, and it has to be.** This route carries no auth
        // middleware on purpose, since demanding an account before somebody can
        // buy costs sales at the worst moment. Nothing has resolved the caller
        // by the time this runs, so a bare `user()` asks the default guard,
        // which reads sessions and has never heard of a bearer token. Sanctum's
        // guard answers for both a cookie and a token. Found on staging on
        // 13 August 2026, see PlaceOrderTest.
        $guest = $this->user('sanctum') === null;

        return [
            // **Identity arrives as a pair or not at all.** A guest may send
            // neither, and a guest who sends an address must name whoever it
            // belongs to, because an account is created from that address and
            // a customer with no name is one every message has to hedge
            // around. Both are ignored for a signed-in customer, who always
            // gets their own account, so nobody can attach an order to
            // somebody else while authenticated as themselves.
            //
            // They were required, because the buyer's email arrived before the
            // order did. Checkout now happens on Stripe's hosted page, which
            // collects the email *after* the order exists, so requiring one
            // here would mean asking twice for the same address. Identity is
            // filled at settlement instead, from the Checkout Session. See
            // `docs/adr/0002-the-redirect-case-comes-back.md`.
            //
            // **This relaxes a requirement rather than removing a
            // capability.** An order arriving with a name and an email behaves
            // exactly as it always has.
            'name' => [Rule::requiredIf($guest && $this->filled('email')), 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            // Never re-detected. CLAUDE.md section 4: when a currency is
            // supplied explicitly the backend must not second guess it.
            'currency' => ['required', 'string', Rule::in(CurrencyResolver::supported())],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product' => ['required', Rule::enum(ProductKey::class)],
            'lines.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'lines.*.question' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The rules that need to look at the catalogue.
     *
     * Here rather than in the controller so a refusal comes back as a 422
     * naming the line that was wrong, which is what the frontend can actually
     * show somebody.
     *
     * **The rules themselves are not here.** They are declared once in
     * App\Purchasability and this pours them into the validator, so the same
     * list that refuses a customer is the list PlaceOrder guards on. A rule
     * added to one of them cannot be forgotten in the other, which is the whole
     * of why this file used to be twice as long.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lines = $this->input('lines');

                if (! is_array($lines) || $validator->errors()->hasAny(['lines', 'currency'])) {
                    return;
                }

                foreach (Purchasability::declared()->refusals($this->basket($lines)) as $refusal) {
                    // Nothing more about a field the validator has already
                    // refused. A line naming no product has been told that one
                    // is required, and being told as well that it is not
                    // something you can buy is noise on top of an answer.
                    if ($validator->errors()->has($refusal->key())) {
                        continue;
                    }

                    $validator->errors()->add($refusal->key(), $refusal->words);
                }
            },
        ];
    }

    /**
     * What is being bought, and who by.
     *
     * **The guard is named, and it has to be.** Nothing has resolved the caller
     * by the time a request is validated, so a bare `user()` asks the default
     * guard, which reads sessions and has never heard of a bearer token. A
     * customer carrying one arrived here as a guest. Found on staging on
     * 13 August 2026, see PlaceOrderTest.
     *
     * **The same two paths OrderController resolves, minus the creating.** A
     * guest hands over the address they typed and the basket matches on it if
     * anything asks, which is what makes that match the same one
     * `Customer::firstOrCreate` will make a moment later.
     *
     * @param  array<int|string, mixed>  $lines
     */
    private function basket(array $lines): Basket
    {
        $currency = (string) $this->input('currency');
        $signedIn = $this->user('sanctum');

        if ($signedIn instanceof Customer) {
            return Basket::forCustomer($signedIn, $currency, $lines);
        }

        $email = $this->input('email');

        return Basket::forGuest(is_string($email) ? $email : null, $currency, $lines);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $email = mb_strtolower(trim($this->input('email')));

            // **An empty string is nobody, not a malformed address.** A form
            // that posts every field it has sends `""` for one the customer
            // left alone, and refusing that with "must be a valid email" would
            // be refusing an order that is now allowed to carry no address at
            // all.
            $this->merge(['email' => $email === '' ? null : $email]);
        }

        if (is_string($this->input('name')) && trim($this->input('name')) === '') {
            $this->merge(['name' => null]);
        }

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper(trim($this->input('currency')))]);
        }
    }
}
