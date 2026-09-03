<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductKey;
use App\Models\Customer;
use App\Purchasability\Basket;
use App\Purchasability\Purchasability;
use App\Support\CurrencyResolver;
use App\Support\Locales;
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
            // belongs to, because an account is created from that address.
            //
            // ~~A customer with no name is one every message has to hedge
            // around.~~ **Struck 30 August 2026, issue #52.** No message hedges
            // now: `App\Support\Greeting` opens "Hello," and Jennifer's
            // notification gives the address alone. The rule below stays,
            // because a name somebody chose to give is still worth having, but
            // it is no longer the thing standing between a buyer and a
            // readable receipt.
            //
            // Both are ignored for a signed-in customer, who always
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

            // **The gift object, and whether the line may carry one at all is
            // not decided here.** That is a fact about the product, so it is a
            // purchasability rule like every other, declared once and asked by
            // both the till and the seam. These four are only the shape.
            //
            // **An empty object is refused, and the two rules below are what
            // refuse it.** `required_with` counts an empty array as an absent
            // sibling, so nothing here would fire on `"gift": {}` by itself.
            // What makes it fire is `fillInTheGiftLanguage`, which has put a
            // locale in the object before any rule reads it. That method is
            // load-bearing for this and not only for the language, and its own
            // docblock says so.
            'lines.*.gift' => ['sometimes', 'nullable', 'array'],

            // Where the code is sent, and the one address in this transaction
            // nothing can check. The buyer never receives the code, so a typo
            // is unrecoverable and nothing here can tell that the mail arrived
            // nowhere.
            'lines.*.gift.recipient_email' => ['required_with:lines.*.gift', 'string', 'email', 'max:255'],

            // Who it is from, and not a name. An unsolicited mail carrying a
            // code and a link from an unknown brand is shaped exactly like
            // phishing, and a named sender is the strongest signal it is real,
            // which is why this is required and the message is not.
            'lines.*.gift.signature' => ['required_with:lines.*.gift', 'string', 'max:255'],

            'lines.*.gift.message' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // The language the recipient's mail is written in, defaulted to the
            // one the order was placed in by `prepareForValidation`. **Live
            // languages only**, the list `/languages` answers: a gift rendered
            // in a half translated language is a mail nobody can read, sent to
            // somebody who never asked for it.
            'lines.*.gift.locale' => ['sometimes', 'nullable', 'string', Rule::in(self::giftableLocales())],
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
     * The languages a gift may be written in.
     *
     * **Live locales, which is what `/languages` answers**, rather than every
     * code the panel can edit. A language still being translated is one the
     * public site will not serve, and a gift mail is the one message in this
     * system sent to somebody who never chose to hear from us.
     *
     * Falls back to the default alone when the registry has no live rows, the
     * same shape `Locales::codes()` uses, so a system with an empty registry
     * still accepts its own language rather than refusing every gift.
     *
     * @return array<int, string>
     */
    private static function giftableLocales(): array
    {
        $codes = Locales::live()->pluck('code')->all();

        return $codes === [] ? [Locales::default()] : $codes;
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

        $this->fillInTheGiftLanguage();
    }

    /**
     * A gift with no language stated is written in the one the order was placed
     * in.
     *
     * **Defaulted here rather than at the seam**, so what the validator checks
     * and what gets stored are the same value. A default applied after
     * validation is a value nothing ever validated.
     *
     * **Load-bearing beyond the language, and this is the part that is easy to
     * take out by accident.** `required_with` treats an empty array as an
     * absent sibling, so `"gift": {}` would meet neither required field, and
     * `Basket` reads an empty object as no gift at all, so the rule refusing a
     * present on something that may not be given away would not see one
     * either. Both cases leave with a 201: a giftable product mints a row
     * carrying no recipient address, and the buyer never receives the code, so
     * an unaddressed gift is unrecoverable. Defaulting the locale is what makes
     * the object non-empty before any rule is asked. Two tests in
     * `GiftALineTest` go red if this stops running, and they are the only thing
     * holding it.
     *
     * The application locale is what the middleware resolved for this request,
     * which is the language the buyer was reading when they bought. Nothing
     * about the recipient is knowable at this point, and a forwarded gift is
     * enough to part them, so the buyer's language is a starting point the
     * frontend is expected to let them change rather than a fact about who
     * receives it.
     */
    private function fillInTheGiftLanguage(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line) || ! is_array($line['gift'] ?? null)) {
                continue;
            }

            if (blank($line['gift']['locale'] ?? null)) {
                $lines[$index]['gift']['locale'] = app()->getLocale();
            }
        }

        $this->merge(['lines' => $lines]);
    }
}
