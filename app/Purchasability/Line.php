<?php

namespace App\Purchasability;

use App\Models\Product;

/**
 * One thing somebody is trying to buy, with the catalogue row it names already
 * found.
 *
 * The row is nullable and that is the point. A line naming a key the catalogue
 * does not have is a real state a basket can be in, arriving either from
 * somebody typing an address by hand or from a product Jennifer has since
 * retired, and it is refused by a rule rather than by an early return.
 *
 * `index` is where the line sat in what the customer sent, kept so a refusal
 * can be hung back on the field they can do something about. It survives lines
 * being skipped, which is why it is carried rather than counted.
 */
final class Line
{
    public function __construct(
        public readonly int $index,
        public readonly string $key,
        public readonly ?Product $product,
        public readonly int $quantity,
        public readonly ?string $question,
    ) {}
}
