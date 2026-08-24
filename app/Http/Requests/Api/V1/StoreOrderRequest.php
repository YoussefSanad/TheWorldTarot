<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Models\Customer;
use App\Models\Product;
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
        // Somebody signed in is already known. Their own account is used and
        // anything they typed is ignored, so nobody can attach an order to
        // another person while authenticated as themselves.
        //
        // **The guard is named, and it has to be.** This route carries no auth
        // middleware on purpose, since demanding an account before somebody can
        // buy costs sales at the worst moment. Nothing has resolved the caller
        // by the time this runs, so a bare `user()` asks the default guard,
        // which reads sessions and has never heard of a bearer token. Sanctum's
        // guard answers for both a cookie and a token. Found on staging on
        // 13 August 2026, see PlaceOrderTest.
        $guest = $this->user('sanctum') === null;

        return [
            'name' => [Rule::requiredIf($guest), 'string', 'max:255'],
            'email' => [Rule::requiredIf($guest), 'string', 'email', 'max:255'],

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

                $currency = (string) $this->input('currency');

                // Soft deleted products are excluded by the default scope, so a
                // retired product cannot be bought while old orders go on
                // referring to it.
                $products = Product::whereIn('key', collect($lines)->pluck('product')->filter())
                    ->get()
                    ->keyBy(fn (Product $product): string => $product->key->value);

                // Who is buying, looked up only when the basket holds something
                // that grants access. An ordinary order asks nothing of the
                // customers table before it is placed, and that stays true.
                $buyer = $products->contains(
                    fn (Product $product): bool => $product->fulfillment === Fulfillment::AccessGrant
                ) ? $this->buyer() : null;

                // Whether this basket already holds something that grants
                // access, so the same pass on two lines is caught as well as
                // two on one. **Counted rather than keyed by product**, because
                // PlaceOrder refuses more than one access grant on an order
                // however it is spelled, and a rule the form request states
                // more narrowly than the action enforces it is a 500 waiting
                // for the day a second access-granting product exists.
                $granting = false;

                foreach ($lines as $index => $line) {
                    $key = is_array($line) ? ($line['product'] ?? null) : null;

                    if (! is_string($key)) {
                        continue;
                    }

                    $product = $products->get($key);

                    // One bad line refuses the whole order. Silently dropping
                    // it would charge somebody for a basket they did not agree
                    // to, which is worse than making them try again.
                    if ($product === null || ! $product->is_published || $product->priceIn($currency) === null || $product->priceIn($currency) <= 0) {
                        $validator->errors()->add(
                            "lines.{$index}.product",
                            'That is not something you can buy right now.',
                        );

                        continue;
                    }

                    if (filled($line['question'] ?? null) && ! $product->allows_question) {
                        $validator->errors()->add(
                            "lines.{$index}.question",
                            'This one does not take a question.',
                        );
                    }

                    // **The pass never expires, so it is the one thing here
                    // that cannot be sold to the same person twice.** Every
                    // other product is a new thing each time it is bought. A
                    // second pass delivers nothing at all, because
                    // grantViewingRoomAccess keeps the first date on purpose,
                    // so it is money taken for a refund conversation.
                    //
                    // The rule follows fulfillment rather than the product key,
                    // which is the same question MarkOrderPaid asks when it
                    // decides whether to grant access. Neither has to learn
                    // about a second access-granting product if one arrives.
                    if ($product->fulfillment !== Fulfillment::AccessGrant) {
                        continue;
                    }

                    if ((int) ($line['quantity'] ?? 1) > 1) {
                        $validator->errors()->add(
                            "lines.{$index}.quantity",
                            'One of these is all anybody can hold.',
                        );
                    }

                    if ($granting) {
                        $validator->errors()->add(
                            "lines.{$index}.product",
                            'Only one of these can be on an order.',
                        );
                    }

                    $granting = true;

                    if ($buyer === null || ! $buyer->hasViewingRoomAccess()) {
                        continue;
                    }

                    // **Somebody signed in is told plainly. A guest is told
                    // what an unavailable product is told, and the difference
                    // is deliberate.**
                    //
                    // **It narrows the leak rather than closing it, and that is
                    // worth being honest about.** Refusing a guest at all means
                    // a holder gets a 422 where anybody else gets a 201, so the
                    // status alone still answers "does this address hold a
                    // pass" to whoever asks. What the shared wording buys is
                    // that it does not confirm why, so a refusal is also what
                    // an unpublished or unpriced pass looks like. The leak is
                    // accepted knowingly: a customer charged twice for a thing
                    // they own forever is a worse outcome than somebody
                    // learning an address owns a pass, and the likeliest double
                    // purchase of all is a holder who is not signed in. Closing
                    // it properly means not offering the pass to that person in
                    // the first place, which is the frontend's to do with
                    // `has_viewing_room_access`.
                    $validator->errors()->add(
                        "lines.{$index}.product",
                        $this->user('sanctum') !== null
                            ? 'You already have this, and it does not expire.'
                            : 'That is not something you can buy right now.',
                    );
                }
            },
        ];
    }

    /**
     * The account this order would belong to, if one exists yet.
     *
     * **The same two paths OrderController resolves, minus the creating**, and
     * the guard is named here for the reason recorded there: nothing has
     * resolved the caller by the time a request is validated, so a bare
     * `user()` asks the default guard and a customer carrying a bearer token
     * arrives as a guest.
     *
     * A guest is matched on the address they typed, because the likeliest
     * person to buy the pass a second time is somebody who owns it, is not
     * signed in, and does not remember. `prepareForValidation` has already
     * lowercased it, which is what makes that match the same one
     * `Customer::firstOrCreate` will make a moment later.
     */
    private function buyer(): ?Customer
    {
        $signedIn = $this->user('sanctum');

        if ($signedIn instanceof Customer) {
            return $signedIn;
        }

        $email = $this->input('email');

        return is_string($email) && $email !== ''
            ? Customer::firstWhere('email', $email)
            : null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper(trim($this->input('currency')))]);
        }
    }
}
