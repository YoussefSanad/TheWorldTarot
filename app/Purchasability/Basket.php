<?php

namespace App\Purchasability;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * What somebody is trying to buy, and in which currency.
 *
 * The subject the purchasability rules are asked about, built once from what
 * arrived so the catalogue is read once rather than once per rule. Two callers
 * build one: the form request, to turn refusals into a 422, and PlaceOrder, to
 * guard before it writes. They must build the same thing from the same input,
 * or the declaration is being asked two different questions and the agreement
 * this module exists for is gone.
 *
 * **Every line survives, including a nonsense one.** A line naming a key the
 * catalogue does not have keeps its place holding a null product, and a line
 * naming nothing at all holds an empty key. Dropping either would mean a basket
 * with fewer lines than the customer sent, which is how PlaceOrder would come
 * to write an order for a subset of what somebody asked for. They are refused
 * by a rule instead, which is where a refusal belongs.
 *
 * **It also knows who is buying, and finds out only if asked.** The pass rules
 * need a buyer and nothing else does, so the promise that an ordinary order
 * asks nothing of the customers table before it is placed is kept here, by the
 * lookup being lazy, rather than by every caller remembering a condition. See
 * `buyer()`.
 */
final class Basket
{
    private ?Customer $buyer = null;

    private bool $lookedForTheBuyer = false;

    /**
     * @param  array<int, Line>  $lines
     */
    private function __construct(
        public readonly string $currency,
        private readonly array $lines,
        private readonly ?Customer $signedIn,
        private readonly ?string $email,
    ) {}

    /**
     * A basket somebody has proved who they are for.
     *
     * @param  array<int|string, mixed>  $lines
     */
    public static function forCustomer(Customer $signedIn, string $currency, array $lines): self
    {
        return self::build($currency, $lines, $signedIn, null);
    }

    /**
     * A basket from somebody who has typed an address rather than signed in.
     *
     * The address is what the buyer is matched on, because the likeliest person
     * to buy the pass a second time is somebody who owns it, is not signed in,
     * and does not remember. It arrives already lowercased by
     * `prepareForValidation`, which is what makes the match here the same one
     * `Customer::firstOrCreate` makes a moment later, and it is lowercased
     * again rather than trusted, because PlaceOrder is a seam and nothing
     * reaching it can be assumed to have been through a form request.
     *
     * @param  array<int|string, mixed>  $lines
     */
    public static function forGuest(?string $email, string $currency, array $lines): self
    {
        return self::build($currency, $lines, null, $email);
    }

    /**
     * A basket from what the customer sent, exactly as it arrived.
     *
     * Tolerant of malformed input on purpose. This runs while a request is
     * still being validated, so a line can hold anything at all, and nothing
     * here is the rule that refuses a missing product key.
     *
     * @param  array<int|string, mixed>  $lines
     */
    private static function build(string $currency, array $lines, ?Customer $signedIn, ?string $email): self
    {
        $keys = collect($lines)
            ->map(fn (mixed $line): string => self::keyOf($line))
            ->filter(fn (string $key): bool => $key !== '');

        // Soft deleted products are excluded by the default scope, so a retired
        // product arrives as a line naming nothing, which is what it is. Old
        // orders go on referring to it through OrderLine, which asks with
        // withTrashed for exactly that reason.
        $products = $keys->isEmpty()
            ? new Collection
            : Product::whereIn('key', $keys->values())
                ->get()
                ->keyBy(fn (Product $product): string => $product->key->value);

        $built = [];

        foreach ($lines as $index => $line) {
            $key = self::keyOf($line);
            $question = is_array($line) ? ($line['question'] ?? null) : null;

            $built[] = new Line(
                index: (int) $index,
                key: $key,
                product: $key === '' ? null : $products->get($key),
                quantity: (int) (is_array($line) ? ($line['quantity'] ?? 1) : 1),
                question: is_string($question) ? $question : null,
            );
        }

        return new self($currency, $built, $signedIn, $email);
    }

    /**
     * @return array<int, Line>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Whether the person buying has proved who they are.
     *
     * Read by the rule that refuses a pass to somebody who holds it, which
     * tells a signed-in holder plainly and tells a guest what an unavailable
     * product is told. The reasoning for that difference travels with the rule
     * rather than with this.
     */
    public function isSignedIn(): bool
    {
        return $this->signedIn !== null;
    }

    /**
     * The account this order would belong to, if one exists yet.
     *
     * **Looked up at most once, and only if something asks.** The pass rules
     * are the only thing that asks, so an ordinary order still consults the
     * customers table nowhere before it is placed. That was a condition the
     * form request had to remember; it is a property of this class now, which
     * is why the laziness is here and not at the call site.
     *
     * Null is memoised as hard as a row is. A guest whose address has no
     * account is an answer, not a reason to ask again.
     */
    public function buyer(): ?Customer
    {
        if ($this->lookedForTheBuyer) {
            return $this->buyer;
        }

        $this->lookedForTheBuyer = true;

        if ($this->signedIn !== null) {
            return $this->buyer = $this->signedIn;
        }

        $email = mb_strtolower(trim((string) $this->email));

        return $this->buyer = $email === ''
            ? null
            : Customer::firstWhere('email', $email);
    }

    /**
     * The product key a line names, or nothing it can be asked about.
     */
    private static function keyOf(mixed $line): string
    {
        $key = is_array($line) ? ($line['product'] ?? null) : null;

        return is_string($key) ? $key : '';
    }
}
