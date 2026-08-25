<?php

namespace App\Purchasability;

use App\Enums\Fulfillment;
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
     * No more than one pass on a line.
     *
     * **The pass never expires, so it is the one thing in the catalogue that
     * cannot be sold to the same person twice.** Every other product is a new
     * thing each time it is bought. A second pass delivers nothing at all,
     * because grantViewingRoomAccess keeps the first date on purpose, so it is
     * money taken for a refund conversation.
     *
     * The three rules below follow a product's fulfillment rather than its key,
     * which is the same question MarkOrderPaid asks when it decides whether to
     * grant access. None of them has to learn about a second access-granting
     * product if one arrives.
     */
    public static function oneAccessGrantPerLine(): Rule
    {
        return Rule::aboutTheProduct(
            met: fn (Product $product, Line $line): bool => ! self::grantsAccess($product) || $line->quantity <= 1,
            field: 'quantity',
            words: 'One of these is all anybody can hold.',
            fault: fn (Line $line): string => "Cannot place an order for more than one [{$line->key}].",
        );
    }

    /**
     * And no more than one across the basket, however it is spelled.
     *
     * Counted across the whole basket rather than keyed by product, so the same
     * pass on two lines is caught as well as two on one, and so would two
     * different access-granting products. A rule stated more narrowly than the
     * seam enforces it is a 500 waiting for the day a second one exists.
     *
     * The second one is the refused one. The first is the one they meant.
     */
    public static function oneAccessGrantPerBasket(): Rule
    {
        return Rule::aboutTheProduct(
            met: function (Product $product, Line $line, Basket $basket): bool {
                if (! self::grantsAccess($product)) {
                    return true;
                }

                foreach ($basket->lines() as $earlier) {
                    if ($earlier->index >= $line->index) {
                        break;
                    }

                    if ($earlier->product !== null && self::grantsAccess($earlier->product)) {
                        return false;
                    }
                }

                return true;
            },
            field: 'product',
            words: 'Only one of these can be on an order.',
            fault: fn (Line $line): string => "Cannot place an order for more than one [{$line->key}].",
        );
    }

    /**
     * And none at all to somebody who already holds what it grants.
     *
     * **Somebody signed in is told plainly. A guest is told what an unavailable
     * product is told, and the difference is deliberate.**
     *
     * **It narrows the leak rather than closing it, and that is worth being
     * honest about.** Refusing a guest at all means a holder gets a 422 where
     * anybody else gets a 201, so the status alone still answers "does this
     * address hold a pass" to whoever asks. What the shared wording buys is
     * that it does not confirm why, so a refusal is also what an unpublished or
     * unpriced pass looks like. The leak is accepted knowingly: a customer
     * charged twice for a thing they own forever is a worse outcome than
     * somebody learning an address owns a pass, and the likeliest double
     * purchase of all is a holder who is not signed in. Closing it properly
     * means not offering the pass to that person in the first place, which is
     * the frontend's to do with `has_viewing_room_access`.
     *
     * The buyer is asked of the basket, which looks them up at most once and
     * only because this rule asked. An ordinary order consults the customers
     * table nowhere.
     */
    public static function notAlreadyHeld(): Rule
    {
        return Rule::aboutTheProduct(
            met: fn (Product $product, Line $line, Basket $basket): bool => ! self::grantsAccess($product)
                || ! ($basket->buyer()?->hasViewingRoomAccess() ?? false),
            field: 'product',
            words: fn (Line $line, Basket $basket): string => $basket->isSignedIn()
                ? 'You already have this, and it does not expire.'
                : self::NOT_FOR_SALE,
            fault: fn (Line $line, Basket $basket): string => "Customer [{$basket->buyer()?->getKey()}] already holds the access [{$line->key}] grants.",
        );
    }

    /**
     * Whether buying this delivers access rather than a thing.
     *
     * Fulfillment rather than the product key, in one place, so the three rules
     * above cannot come to disagree about what they are even about.
     */
    private static function grantsAccess(Product $product): bool
    {
        return $product->fulfillment === Fulfillment::AccessGrant;
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
