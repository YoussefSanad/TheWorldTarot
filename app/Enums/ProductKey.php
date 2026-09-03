<?php

namespace App\Enums;

/**
 * Which product a row is, and the segment its page lives at.
 *
 * The value is the URL: /en/readings/one-card and /es/lecturas/one-card are the
 * same product. Identifiers are never translated and never editable, so a link
 * cannot be broken by renaming a reading, and no redirect layer is needed. The
 * SEO cost of an English word in a Spanish URL was accepted knowingly on
 * 26 July 2026. See .claude/translation.md section 9.
 *
 * This is the whole catalogue, fixed for the life of the site. The rows arrive
 * by milestone: the four readings now, the viewing room pass in M4, the rush
 * add-on in M5. A case without a row simply 404s, which is why the catalogue
 * can be declared up front while the rows are not.
 */
enum ProductKey: string
{
    case OneCard = 'one-card';
    case ThreeCard = 'three-card';
    case MonthAhead = 'month-ahead';
    case InDepth = 'in-depth';

    // Not seeded yet. See the milestones above.
    case ViewingRoomPass = 'viewing-room-pass';
    case Rush24h = 'rush-24h';

    /**
     * The four readings, which are the only products that exist today and the
     * only ones the seeder creates.
     *
     * @return array<int, self>
     */
    public static function readings(): array
    {
        return [self::OneCard, self::ThreeCard, self::MonthAhead, self::InDepth];
    }

    /**
     * Everything the seeder creates today: the four readings and the viewing
     * room pass, which arrives with M4. The rush add-on waits for M5.
     *
     * @return array<int, self>
     */
    public static function seedable(): array
    {
        return [...self::readings(), self::ViewingRoomPass];
    }

    /**
     * What kind of thing this is, which the frontend maps presentation to.
     *
     * Declared here rather than in the seeder because it is structure, and
     * structure belongs to code. The seeder re-applies it on every run, so a
     * value corrected here reaches production on the next deploy.
     */
    public function type(): ProductType
    {
        return match ($this) {
            self::ViewingRoomPass => ProductType::Pass,
            self::Rush24h => ProductType::Addon,
            default => ProductType::Reading,
        };
    }

    /**
     * How an order for it is completed. Internal, never exposed.
     */
    public function fulfillment(): Fulfillment
    {
        return match ($this) {
            // Grants access rather than delivering anything. M5 stamps the
            // customer at payment, and M4 reads that stamp to open the door.
            self::ViewingRoomPass => Fulfillment::AccessGrant,
            default => Fulfillment::ManualPdf,
        };
    }

    /**
     * Whether a customer may attach a question when buying.
     *
     * Every reading accepts one and none demands one. A pass and an add-on have
     * nothing to ask about.
     */
    public function allowsQuestion(): bool
    {
        return $this->type() === ProductType::Reading;
    }

    /**
     * Whether this may be bought for somebody else.
     *
     * **Three of the four readings, and not `one-card`.** Decided 2 September
     * 2026 and recorded in `docs/CHECKPOINT.md`. A one card reading is the
     * cheapest thing here and a present is not what it is for; the pass and the
     * rush are not readings at all, so there is nothing to give and nobody to
     * redeem it.
     *
     * **Independent of `allowsQuestion()`, and `one-card` is what proves it.**
     * It takes a question and cannot be gifted, so anything deriving either
     * from the other is wrong on the product most people buy first.
     *
     * A gift is refused rather than ignored on a line that answers false here.
     * Quietly dropping it charges somebody for a present that was never sent.
     */
    public function isGiftable(): bool
    {
        return match ($this) {
            self::ThreeCard, self::MonthAhead, self::InDepth => true,
            self::OneCard, self::ViewingRoomPass, self::Rush24h => false,
        };
    }

    /**
     * The English name a product starts life with.
     *
     * **Taken verbatim from the homepage tiles**, which are the copy the client
     * signed off and which the website has been showing all along. Seeding
     * anything else would have meant the day this went live was the day the
     * homepage silently reworded itself.
     *
     * **Set in capitals because the tile is.** `ProductCard` prints the name as
     * it is given, with no `text-transform` anywhere in the frontend's CSS, so
     * capitals here are what keeps the rendered page identical. The cost is
     * that everything else reading `name` shouts too — the product column in
     * the admin's order table, and page titles, which are derived from the name
     * rather than stored. If that reads badly, the fix is a `uppercase` class on
     * the tile and title case here, not a second name field.
     *
     * Copy belongs to the client from the first edit onward. This is only ever
     * used to fill a field nobody has typed into yet.
     */
    public function defaultName(): string
    {
        return match ($this) {
            self::OneCard => '1 CARD READING',
            self::ThreeCard => '3 CARD READING',
            self::MonthAhead => 'MONTH AHEAD',
            self::ViewingRoomPass => 'VIEWING ROOM',

            // No tile on the homepage, so no signed-off wording to copy. These
            // two follow the pattern of the ones that do.
            self::InDepth => 'IN DEPTH READING',
            self::Rush24h => '24 HOUR RUSH',
        };
    }

    /**
     * Names an earlier version of the seeder wrote itself.
     *
     * **These are not edits and must not be protected like edits.** Until
     * 20 August 2026 the seeder named products from a list of its own invention
     * — 'One Card Reading' and so on — while the homepage had always said
     * '1 CARD READING'. Now that the tiles are the source of truth, a database
     * seeded before that date holds a name nobody chose, and the fill-only-what
     * -is-empty rule would protect it forever.
     *
     * So a name matching one of these is treated as the blank it effectively
     * is. Anything else, including copy someone has since typed over the top of
     * it, is left alone.
     *
     * **Adding to this list is how a default name gets corrected in future.**
     * Changing `defaultName` alone only reaches databases that have never been
     * seeded, which is no database that matters.
     *
     * The risk is the obvious one: if Jennifer ever renames a product to exactly
     * one of these strings, the next deploy takes it back. Judged worth it, on
     * the grounds that these are developer inventions nobody has reason to type.
     *
     * @return array<int, string>
     */
    public function supersededNames(): array
    {
        return match ($this) {
            self::OneCard => ['One Card Reading'],
            self::ThreeCard => ['Three Card Reading'],
            self::MonthAhead => ['Month Ahead Reading'],
            self::InDepth => ['In Depth Reading'],
            self::ViewingRoomPass => ['Viewing Room Pass'],
            self::Rush24h => ['24 Hour Rush'],
        };
    }

    /**
     * The line under the product name in the confirmation email's gold box.
     *
     * **Copy that arrived with the designs and exists nowhere else**, issue
     * #53. It is not `defaultShortDescription()`: the tile for `month-ahead`
     * says "What's in Store? 5 Card Forecast" and the email says "A Clear Path
     * Forward", which is a different register for a different moment. Somebody
     * reading the tile is deciding whether to buy. Somebody reading this has
     * already paid.
     *
     * **Here rather than in a column, and that is worth revisiting.** CLAUDE.md
     * section 4 says values belong to the client and structure belongs to code,
     * and by that rule this is a value and Jennifer should own it. It is in
     * code today because #53 asked for the emails to be wired up and not for a
     * migration, a seeder change and two panel fields. **The day she asks to
     * reword one is the signal to move these to columns**, not to edit this
     * file on her behalf.
     *
     * **`one-card` had no design in the package.** The three that did are
     * verbatim. Its own follows their shape and is the only invented string
     * here, so it is the one to put in front of her first.
     */
    public function emailSubtitle(): string
    {
        return match ($this) {
            self::MonthAhead => 'A Clear Path Forward',
            self::ThreeCard => 'Past • Present • Future',
            self::InDepth => 'The Full Picture',

            self::OneCard => 'A Single Message',
            self::ViewingRoomPass => 'The Complete Collection',
            self::Rush24h => 'Straight to the Front',
        };
    }

    /**
     * How long the confirmation tells a buyer to wait.
     *
     * **The first time this promise exists anywhere in the system.** Nothing
     * models a turnaround, no job measures one, and nothing tells Jennifer when
     * one has been missed. This is a sentence in an email, and putting it in
     * front of a paying customer is where it starts being a commitment somebody
     * keeps by hand.
     *
     * **It contradicts the rush add-on, and that is not resolved here.**
     * `Rush24h`'s own copy sells delivery "within twenty-four hours instead of
     * seventy-two", so the standing promise it was written against is 72 hours.
     * The designs say 24 for the two cheaper readings and 48 for `in-depth`,
     * which would leave a 24 hour rush add-on selling somebody a wait they
     * already have. The designs are the newer artifact and the one Jennifer
     * signed off, so they win here, and **M5's rush add-on cannot ship against
     * these numbers until she settles which promise is real.** Raised on #53
     * rather than split silently.
     *
     * The window alone rather than the whole sentence, so "Delivery within"
     * lives once, in the view, beside the styling that makes it a line.
     *
     * **Null is not "unknown", it is "nothing is delivered"**, and the view
     * omits the whole row for it rather than printing a blank one. A pass
     * grants access the moment it is paid for and an add-on changes another
     * line's wait rather than having one of its own, so neither has a delivery
     * window in the sense this method means.
     *
     * Returning a word instead was the first attempt and it was wrong in a way
     * worth recording: `'immediately'` reads perfectly here and renders
     * "DELIVERY WITHIN IMMEDIATELY" in the box, because the sentence is
     * completed in the view. A value that only makes sense at one of its two
     * call sites is the shape to distrust.
     */
    public function deliveryWindow(): ?string
    {
        return match ($this) {
            self::InDepth => '48 hours',
            self::MonthAhead, self::ThreeCard => '24 hours',

            // No design, so no signed-off number. The two cheaper readings are
            // the nearest precedent, and the shortest reading carrying the
            // longest wait would need explaining.
            self::OneCard => '24 hours',

            self::ViewingRoomPass, self::Rush24h => null,
        };
    }

    public function defaultSortOrder(): int
    {
        return match ($this) {
            self::OneCard => 1,
            self::ThreeCard => 2,
            self::MonthAhead => 3,
            self::InDepth => 4,
            self::ViewingRoomPass => 5,
            self::Rush24h => 6,
        };
    }

    /**
     * The English short description a product starts life with. One line, shown
     * on the tile under the name.
     *
     * **The homepage tiles again, verbatim**, with each tile's two hard-wrapped
     * lines joined by a space — the break is a layout decision and does not
     * belong in the copy. `In Depth` and the rush add-on have no tile, so theirs
     * are the only invented ones here.
     */
    public function defaultShortDescription(): string
    {
        return match ($this) {
            self::OneCard => 'A Single Message from the Tarot',
            self::ThreeCard => 'One Question, Three Cards',
            self::MonthAhead => 'What’s in Store? 5 Card Forecast',
            self::InDepth => 'The Fullest Reading, Card by Card',
            self::ViewingRoomPass => 'Complete Cinematic Collection',
            self::Rush24h => 'Your Reading Within 24 Hours',
        };
    }

    /**
     * The English long copy a product starts life with, for its own page.
     */
    public function defaultLongDescription(): string
    {
        return match ($this) {
            self::OneCard => 'One card, drawn for your question and read in full. A focused message for when you want clarity on a single situation rather than a map of everything around it. Your reading arrives presented on the original artwork and delivered by email.',
            self::ThreeCard => 'Three cards read as one story: what stands behind your question, what surrounds it now, and where it is heading. The spread most people picture when they think of tarot, and the right depth for a question you have been carrying a while.',
            self::MonthAhead => 'Five cards for the month in front of you — its shape, its turning point, and what it is asking of you. A forecast to keep and return to as the weeks unfold, rather than an answer to a single question.',
            self::InDepth => 'The fullest reading offered here: a long, unhurried interpretation that takes your question apart and follows every thread the cards open. For the questions that deserve more than a spread and a summary.',
            self::ViewingRoomPass => 'One pass into the Viewing Room, where all twenty-two Major Arcana play as the films they were made to be, each filmed in the place that inspired it. Yours to return to whenever you like.',
            self::Rush24h => 'Moves your reading to the front of the queue, written and delivered within twenty-four hours instead of seventy-two.',
        };
    }

    /**
     * The prices a product starts life with, in integer minor units, keyed by
     * currency code.
     *
     * **Typed in per currency and never converted.** There is no base price and
     * no exchange rate anywhere in this system, so these are three independent
     * decisions that happen to be written next to each other. See
     * .claude/database.md.
     *
     * **USD is the homepage's own pricing**, so a seeded database and the
     * bundled tiles agree to the cent. EUR and GBP have never appeared on the
     * website in any form, so those are starting numbers rather than signed-off
     * ones and want a look before launch. So does `in-depth`, which has no tile
     * and therefore no price anybody has agreed to.
     *
     * Starting values only. The client owns prices from the first edit onward,
     * and the seeder never touches one that has been set.
     *
     * @return array<string, int>
     */
    public function defaultPrices(): array
    {
        return match ($this) {
            self::OneCard => ['USD' => 1200, 'EUR' => 1200, 'GBP' => 1000],
            self::ThreeCard => ['USD' => 5200, 'EUR' => 5000, 'GBP' => 4400],
            self::MonthAhead => ['USD' => 7500, 'EUR' => 7000, 'GBP' => 6300],
            self::InDepth => ['USD' => 12000, 'EUR' => 11500, 'GBP' => 10200],
            self::ViewingRoomPass => ['USD' => 2900, 'EUR' => 2700, 'GBP' => 2400],
            self::Rush24h => ['USD' => 1500, 'EUR' => 1400, 'GBP' => 1200],
        };
    }
}
