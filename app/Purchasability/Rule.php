<?php

namespace App\Purchasability;

use App\Models\Product;
use Closure;

/**
 * One thing a basket needs before it can be bought.
 *
 * The unit App\Availability\Condition is, asked of a basket rather than of a
 * record on the website. A rule holds how to ask it, which field on the line is
 * at fault, what to say to the customer and what to say to whoever wrote a
 * caller that never validated. They are constructor arguments rather than
 * optional extras, so a rule cannot be added to the guard and forgotten in the
 * refusal, which is the failure this module exists to make impossible.
 *
 * A rule is asked of one line at a time and handed the whole basket as well,
 * because several of them are not questions about a line on its own. The same
 * pass on two lines is the worked example: nothing about either line is wrong
 * by itself, and the second one is the one refused.
 *
 * The wording is the customer's, and it is a whole sentence rather than
 * something finishing "it needs ...". It is read at a checkout, not in a list
 * in the panel.
 */
final class Rule
{
    /**
     * @param  Closure(Line, Basket): bool  $met
     * @param  Closure(Line, Basket): string  $words
     * @param  Closure(Line, Basket): string  $fault
     */
    private function __construct(
        private readonly Closure $met,
        private readonly string $field,
        private readonly Closure $words,
        private readonly Closure $fault,
    ) {}

    /**
     * The question, the field it hangs on, and both wordings, declared
     * together.
     *
     * @param  Closure(Line, Basket): bool  $met  Whether the line satisfies it.
     * @param  string  $field  Which field on the line a customer can act on.
     * @param  string|Closure(Line, Basket): string  $words  What the customer reads.
     * @param  string|Closure(Line, Basket): string  $fault  What an exception says.
     */
    public static function make(Closure $met, string $field, string|Closure $words, string|Closure $fault): self
    {
        return new self($met, $field, self::sentence($words), self::sentence($fault));
    }

    /**
     * A rule about the product on a line, met by a line naming no product at
     * all.
     *
     * Not a special case, a consequence. A line naming something the catalogue
     * does not have is refused once, by the rule that owns that question, and
     * somebody who mistyped one key should not also be told the thing they did
     * not buy takes no question. Without this every rule below the first would
     * have to remember to say so itself, which is a rule restated in five
     * places and exactly what this module exists to stop.
     *
     * @param  Closure(Product, Line, Basket): bool  $met
     * @param  string|Closure(Line, Basket): string  $words
     * @param  string|Closure(Line, Basket): string  $fault
     */
    public static function aboutTheProduct(Closure $met, string $field, string|Closure $words, string|Closure $fault): self
    {
        return self::make(
            met: fn (Line $line, Basket $basket): bool => $line->product === null
                || $met($line->product, $line, $basket),
            field: $field,
            words: $words,
            fault: $fault,
        );
    }

    /**
     * The rule as a predicate, asked of one line of a basket.
     */
    public function isMetBy(Line $line, Basket $basket): bool
    {
        return (bool) ($this->met)($line, $basket);
    }

    /**
     * The rule as a refusal, for a line that does not meet it.
     */
    public function refusalOf(Line $line, Basket $basket): Refusal
    {
        return Refusal::of(
            line: $line->index,
            field: $this->field,
            words: ($this->words)($line, $basket),
            fault: ($this->fault)($line, $basket),
        );
    }

    /**
     * @param  string|Closure(Line, Basket): string  $words
     * @return Closure(Line, Basket): string
     */
    private static function sentence(string|Closure $words): Closure
    {
        return is_string($words) ? fn (): string => $words : $words;
    }
}
