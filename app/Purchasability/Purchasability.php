<?php

namespace App\Purchasability;

use InvalidArgumentException;

/**
 * Everything a basket needs before it can be bought, declared once.
 *
 * "Can this be bought" was answered twice. The form request stated it as prose
 * on a numbered line, so a customer got a 422 they could act on. PlaceOrder
 * stated it again as exceptions, so the one action that turns money into a
 * record could not be made to write a nonsense order. Both derived the same
 * facts, both said so in their own comments, and neither would have noticed the
 * other drifting.
 *
 * The rules are stated here and both answers are rendered from them: refusals
 * keyed to the line that was wrong, and a predicate. Adding a rule changes both
 * at once, which is the point, and is the same treatment the language gate was
 * already fixed into in App\Availability.
 *
 * Order is the order a customer meets them in, and it decides which fault a
 * guard reports first. Nothing else depends on it.
 */
final class Purchasability
{
    /**
     * @var array<int, Rule>
     */
    private readonly array $rules;

    private function __construct(Rule ...$rules)
    {
        $this->rules = $rules;
    }

    public static function of(Rule ...$rules): self
    {
        return new self(...$rules);
    }

    /**
     * What a basket needs, as this system declares it.
     *
     * Composed here rather than on a model, because a basket is not a row and
     * there is nothing for it to hang off. One list, so the two callers cannot
     * be asking different questions.
     */
    public static function declared(): self
    {
        return self::of(
            Rules::soldHere(),
            Rules::published(),
            Rules::pricedInTheCurrencyCharged(),
            Rules::pricedAtSomethingReal(),
            Rules::aQuestionOnlyWhereOneIsTaken(),
            Rules::oneAccessGrantPerLine(),
            Rules::oneAccessGrantPerBasket(),
            Rules::notAlreadyHeld(),
        );
    }

    /**
     * The declaration as refusals, for a customer who has to be told what to
     * change.
     *
     * Every rule is asked of every line, so a basket wrong in two places is
     * refused in two places rather than one at a time. Empty exactly when
     * `permits` is true.
     *
     * **At most one refusal per field per line**, which is what makes a
     * customer's half of this readable. Unpublished, unpriced and retired are
     * three rules that all hang on the product and all say the same sentence,
     * and being told it three times is not three times as useful. The first one
     * wins, so the order rules are declared in is also the order they are
     * reported in.
     *
     * **And nothing at all about a line once the product itself is refused.**
     * Somebody who cannot buy the thing does not also need to hear that it
     * takes no question and that one is all anybody can hold: the product is
     * the field they have to change, and the rest of the line is moot until
     * they do. This is the rule the form request used to keep as a `continue`
     * after adding its product error, and it is a promise about the wording a
     * customer sees rather than an optimisation, which is why it lives with the
     * declaration now rather than at a call site that could forget it.
     *
     * @return array<int, Refusal>
     */
    public function refusals(Basket $basket): array
    {
        $refusals = [];

        foreach ($basket->lines() as $line) {
            $spoken = [];

            foreach ($this->rules as $rule) {
                if ($rule->isMetBy($line, $basket)) {
                    continue;
                }

                $refusal = $rule->refusalOf($line, $basket);

                if (isset($spoken[$refusal->field])) {
                    continue;
                }

                $spoken[$refusal->field] = true;
                $refusals[] = $refusal;

                // The product is the field everything else on this line hangs
                // off. Refuse it and the line is answered.
                if ($refusal->field === 'product') {
                    break;
                }
            }
        }

        return $refusals;
    }

    /**
     * The declaration as a predicate, for a caller that only needs to know
     * whether to go on.
     */
    public function permits(Basket $basket): bool
    {
        return $this->refusals($basket) === [];
    }

    /**
     * The predicate with its reason attached, for the seam that must not write
     * an order it was never asked for.
     *
     * Nothing here that `permits` does not answer. What it adds is a sentence
     * to fail with, which is the whole of why PlaceOrder held its own copy of
     * these rules: a bare false is not something anybody can act on, and
     * reaching product->id on null was how that failure used to read.
     *
     * Anything arriving at that seam has already been validated, so this firing
     * is a fault in the caller and says so in the caller's language rather than
     * the customer's.
     */
    public function guard(Basket $basket): void
    {
        $refusals = $this->refusals($basket);

        if ($refusals !== []) {
            throw new InvalidArgumentException($refusals[0]->fault);
        }
    }
}
