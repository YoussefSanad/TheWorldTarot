<?php

namespace App\Purchasability;

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
 */
final class Basket
{
    /**
     * @param  array<int, Line>  $lines
     */
    private function __construct(
        public readonly string $currency,
        private readonly array $lines,
    ) {}

    /**
     * A basket from what the customer sent, exactly as it arrived.
     *
     * Tolerant of malformed input on purpose. This runs while a request is
     * still being validated, so a line can hold anything at all, and nothing
     * here is the rule that refuses a missing product key.
     *
     * @param  array<int|string, mixed>  $lines
     */
    public static function of(string $currency, array $lines): self
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

        return new self($currency, $built);
    }

    /**
     * @return array<int, Line>
     */
    public function lines(): array
    {
        return $this->lines;
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
