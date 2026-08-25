<?php

namespace App\Purchasability;

/**
 * One reason a basket cannot be bought, keyed to the line that was wrong.
 *
 * Two audiences, declared together, because they are two ways of saying one
 * thing and splitting them is how they come apart.
 *
 * `words` is what the customer reads, on the field they can do something
 * about. `fault` is what an exception says to whoever wrote the caller. A
 * customer naming a product that does not exist deserves a 422 with the line
 * named; the same basket arriving at PlaceOrder from a path that never
 * validated is a fault in that path and says so, in a sentence a developer can
 * act on rather than one written for a checkout.
 */
final class Refusal
{
    private function __construct(
        public readonly int $line,
        public readonly string $field,
        public readonly string $words,
        public readonly string $fault,
    ) {}

    public static function of(int $line, string $field, string $words, string $fault): self
    {
        return new self($line, $field, $words, $fault);
    }

    /**
     * Where a validator hangs the message. The shape the frontend already reads
     * errors at, so nothing there learns a new one.
     */
    public function key(): string
    {
        return "lines.{$this->line}.{$this->field}";
    }
}
