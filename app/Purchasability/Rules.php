<?php

namespace App\Purchasability;

use App\Models\Product;

/**
 * The rules a basket is held to, each stated once.
 *
 * Here rather than on Product, because none of them is a fact about a product
 * on its own. "Priced in the currency being charged" needs the basket to know
 * which currency that is, and the pass rules need to know who is buying. A rule
 * that is genuinely about a row belongs in App\Availability\Conditions with the
 * others.
 */
final class Rules
{
    /**
     * **One message for a product that is unpublished, unpriced or retired, and
     * that is deliberate.** They are three different facts and the customer is
     * told one thing, because the three are indistinguishable from outside and
     * saying which would answer questions nobody buying anything needs answered:
     * whether a key exists at all, and what Jennifer has switched off this week.
     *
     * The developer-facing half is not merged. Each rule below says exactly
     * which fact it was, because whoever reads that sentence is holding a stack
     * trace and needs it.
     */
    private const NOT_FOR_SALE = 'That is not something you can buy right now.';

    /**
     * Something we actually sell.
     *
     * A key nobody seeded, a key somebody invented, and a product Jennifer has
     * retired are one rule: the catalogue was asked and had nothing to give
     * back. Retired arrives here through the soft delete scope rather than as a
     * branch, which is what keeps an old order referring to a product that can
     * no longer be bought.
     */
    public static function soldHere(): Rule
    {
        return Rule::make(
            met: fn (Line $line): bool => $line->product !== null,
            field: 'product',
            words: self::NOT_FOR_SALE,
            fault: fn (Line $line): string => "Cannot place an order for unknown product [{$line->key}].",
        );
    }

    /**
     * Switched on.
     *
     * The same question App\Availability\Conditions::published asks of the
     * website, asked of a checkout instead. Two renderings of one fact rather
     * than one rule in two modules: unpublishing a product takes it off the
     * shop and refuses it at the till, and neither has to be remembered.
     */
    public static function published(): Rule
    {
        return Rule::aboutTheProduct(
            met: fn (Product $product): bool => (bool) $product->is_published,
            field: 'product',
            words: self::NOT_FOR_SALE,
            fault: fn (Line $line): string => "Product [{$line->key}] is not published.",
        );
    }

    /**
     * Priced in the currency this order is being charged in.
     *
     * Prices are three NOT NULL columns, so this cannot come from the
     * catalogue. It comes from a caller naming a currency this system does not
     * sell in, where `priceIn` returns null rather than falling back, because
     * charging somebody a number from the wrong currency is worse than refusing
     * them.
     */
    public static function pricedInTheCurrencyCharged(): Rule
    {
        return Rule::aboutTheProduct(
            met: fn (Product $product, Line $line, Basket $basket): bool => $product->priceIn($basket->currency) !== null,
            field: 'product',
            words: self::NOT_FOR_SALE,
            fault: fn (Line $line, Basket $basket): string => "Product [{$line->key}] has no price in {$basket->currency}.",
        );
    }

    /**
     * And priced at something real.
     *
     * A seeded product starts at zero in every currency, which the database
     * accepts: NOT NULL can insist a price exists, not that it means anything.
     * Nothing is ever sold for nothing.
     *
     * Met by a currency the product cannot be priced in at all, which is the
     * rule above's to refuse. One thing wrong, one thing said.
     */
    public static function pricedAtSomethingReal(): Rule
    {
        return Rule::aboutTheProduct(
            met: function (Product $product, Line $line, Basket $basket): bool {
                $price = $product->priceIn($basket->currency);

                return $price === null || $price > 0;
            },
            field: 'product',
            words: self::NOT_FOR_SALE,
            fault: fn (Line $line, Basket $basket): string => "Product [{$line->key}] is priced at nothing in {$basket->currency}.",
        );
    }

    /**
     * A question only on something that takes one.
     *
     * Every reading accepts one and none demands one. A pass and an add-on have
     * nothing to ask about, and `allows_question` is where that is recorded.
     *
     * The only rule here that hangs on a field other than the product, because
     * it is the only one where the thing the customer can change is the
     * question rather than what they are buying.
     */
    public static function aQuestionOnlyWhereOneIsTaken(): Rule
    {
        return Rule::aboutTheProduct(
            met: fn (Product $product, Line $line): bool => blank($line->question) || $product->allows_question,
            field: 'question',
            words: 'This one does not take a question.',
            fault: fn (Line $line): string => "Product [{$line->key}] does not take a question.",
        );
    }
}
