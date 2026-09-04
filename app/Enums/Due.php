<?php

namespace App\Enums;

/**
 * Where a reading stands against the promise made when it was asked for.
 *
 * **Four states, declared here and nowhere else.** They existed before this
 * enum did, spread across three surfaces that each knew a subset:
 * `ReadingsTable` coloured three of them, `ReadingInfolist` coloured two, and
 * a private static in the table knew all four and told nobody. A reading due
 * but not yet late was orange on the list and neutral on the page that list
 * opens, and no test could notice because nothing declared the set.
 *
 * **Not backed, and never stored**, which is the same argument `ClaimOutcome`
 * makes. `docs/adr/0006-one-screen-per-question.md` refuses a `due_at` column
 * and a stored overdue flag, because a stored value is a second writer that
 * goes stale the day a delivery window changes. This is derived at read time
 * by `Reading::due()` from `dueAt()`, which is derived from
 * `ProductKey::deliveryWindow()`. A string value would be somewhere to put it.
 *
 * Adding a fifth state is one case here, and every `match` reading this then
 * says so by throwing rather than by quietly picking a default.
 */
enum Due
{
    /**
     * It has gone, and is therefore not late however long it took.
     *
     * `Reading::isOverdue()` says why in full: this is a question about work
     * outstanding, not a grade on work already done, and a finished row
     * staying red is permanent noise on the one screen that has to stay
     * readable.
     *
     * **Beats `Overdue` and loses to `NoWindow`**, which is the order
     * `Reading::due()` asks in and the only ordering that makes all four
     * mean one thing. Delivered late is delivered; delivered against no
     * promise at all was never due.
     */
    case Delivered;

    /** The promise has been missed and the reading is still owed. */
    case Overdue;

    /** Owed, promised, and the promise has not run out yet. */
    case Waiting;

    /**
     * The product it was bought under promises no turnaround.
     *
     * **Asked first, and so exactly "there is no promise here".** It does not
     * matter whether such a reading has gone: there was never a moment to be
     * early or late against, and a state that meant "no window unless it was
     * delivered" would be a state nobody could ask a straight question of.
     * `ReadingOnItsWay::promise()` is the caller that needs the straight
     * question, and it is why the order is this way round.
     *
     * **Defensive rather than reachable.** A pass grants access the moment it
     * is paid for and an add-on changes another line's wait, so neither has a
     * window, and neither has a reading either. `ProductKey::deliveryWindow()`
     * already means "nothing is delivered" by null rather than "unknown", and
     * this carries that meaning up to the screen instead of guessing.
     */
    case NoWindow;

    /**
     * The colour of the badge, which the list and the detail page both take
     * from here so they cannot come to disagree again.
     *
     * Neutral for the two states nothing is owed in, and the warning and
     * danger the panel uses everywhere else for the two it is.
     *
     * **Takes nothing and answers totally**, which is the shape
     * `OrderStatus::colour()` and `ProductKey::fulfillment()` already have. The
     * words a screen puts beside this need the moment as well as the state, so
     * they are `Reading::dueInWords()`, where both halves are in one hand and
     * cannot be passed in disagreeing with each other.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Delivered, self::NoWindow => 'gray',
            self::Overdue => 'danger',
            self::Waiting => 'warning',
        };
    }
}
