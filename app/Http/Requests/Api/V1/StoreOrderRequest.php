<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductKey;
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
                }
            },
        ];
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
