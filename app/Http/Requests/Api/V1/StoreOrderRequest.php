<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Models\Customer;
use App\Purchasability\Basket;
use App\Purchasability\Line;
use App\Purchasability\Purchasability;
use App\Purchasability\Refusal;
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
     * **The rules themselves are not here.** They are declared once in
     * App\Purchasability and this pours them into the validator, so the same
     * list that refuses a customer is the list PlaceOrder guards on. What is
     * still stated here is the half about the pass, deliberately, because those
     * rules carry the most reasoning of any of them and are moved on their own.
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

                $basket = Basket::of((string) $this->input('currency'), $lines);
                $refusals = Purchasability::declared()->refusals($basket);

                foreach ($refusals as $refusal) {
                    // Nothing more about a field the validator has already
                    // refused. A line naming no product has been told that one
                    // is required, and being told as well that it is not
                    // something you can buy is noise on top of an answer.
                    if ($validator->errors()->has($refusal->key())) {
                        continue;
                    }

                    $validator->errors()->add($refusal->key(), $refusal->words);
                }

                $this->refusePassesAlreadyHeld($validator, $basket, $refusals);
            },
        ];
    }

    /**
     * The rules about the pass, which are the half of purchasability still
     * written twice.
     *
     * **Left here on purpose.** They have the most stated reasoning behind them
     * of anything in this file and they join the declaration on their own, in
     * the ticket that follows this one. Folding them in early would have meant
     * moving that reasoning twice.
     *
     * @param  array<int, Refusal>  $refusals
     */
    private function refusePassesAlreadyHeld(Validator $validator, Basket $basket, array $refusals): void
    {
        // A line the declaration has already refused outright says nothing
        // more. Somebody who cannot buy the thing at all does not also need to
        // hear how many of it they may have.
        $refused = collect($refusals)
            ->filter(fn (Refusal $refusal): bool => $refusal->field === 'product')
            ->map(fn (Refusal $refusal): int => $refusal->line)
            ->all();

        $lines = collect($basket->lines())->reject(
            fn (Line $line): bool => in_array($line->index, $refused, true)
                || $line->product?->fulfillment !== Fulfillment::AccessGrant,
        );

        if ($lines->isEmpty()) {
            return;
        }

        // Who is buying, looked up only when the basket holds something that
        // grants access. An ordinary order asks nothing of the customers table
        // before it is placed, and that stays true.
        $buyer = $this->buyer();

        // Whether this basket already holds something that grants access, so
        // the same pass on two lines is caught as well as two on one.
        // **Counted rather than keyed by product**, because PlaceOrder refuses
        // more than one access grant on an order however it is spelled, and a
        // rule the form request states more narrowly than the action enforces
        // it is a 500 waiting for the day a second access-granting product
        // exists.
        $granting = false;

        foreach ($lines as $line) {
            // **The pass never expires, so it is the one thing here that cannot
            // be sold to the same person twice.** Every other product is a new
            // thing each time it is bought. A second pass delivers nothing at
            // all, because grantViewingRoomAccess keeps the first date on
            // purpose, so it is money taken for a refund conversation.
            //
            // The rule follows fulfillment rather than the product key, which
            // is the same question MarkOrderPaid asks when it decides whether
            // to grant access. Neither has to learn about a second
            // access-granting product if one arrives.
            if ($line->quantity > 1) {
                $validator->errors()->add(
                    "lines.{$line->index}.quantity",
                    'One of these is all anybody can hold.',
                );
            }

            if ($granting) {
                $validator->errors()->add(
                    "lines.{$line->index}.product",
                    'Only one of these can be on an order.',
                );
            }

            $granting = true;

            if ($buyer === null || ! $buyer->hasViewingRoomAccess()) {
                continue;
            }

            // **Somebody signed in is told plainly. A guest is told what an
            // unavailable product is told, and the difference is deliberate.**
            //
            // **It narrows the leak rather than closing it, and that is worth
            // being honest about.** Refusing a guest at all means a holder gets
            // a 422 where anybody else gets a 201, so the status alone still
            // answers "does this address hold a pass" to whoever asks. What the
            // shared wording buys is that it does not confirm why, so a refusal
            // is also what an unpublished or unpriced pass looks like. The leak
            // is accepted knowingly: a customer charged twice for a thing they
            // own forever is a worse outcome than somebody learning an address
            // owns a pass, and the likeliest double purchase of all is a holder
            // who is not signed in. Closing it properly means not offering the
            // pass to that person in the first place, which is the frontend's
            // to do with `has_viewing_room_access`.
            $validator->errors()->add(
                "lines.{$line->index}.product",
                $this->user('sanctum') !== null
                    ? 'You already have this, and it does not expire.'
                    : 'That is not something you can buy right now.',
            );
        }
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
