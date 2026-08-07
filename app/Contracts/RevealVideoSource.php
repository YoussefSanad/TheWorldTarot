<?php

namespace App\Contracts;

use App\Models\Card;
use App\Services\Reveal\RevealPlayback;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the homepage reveal's video comes from.
 *
 * The reveal serves the full Living Tarot film today, which is the same film the
 * Viewing Room sells. That was decided knowingly on 2 August 2026, and it is
 * expected to change, so what plays is a decision behind this interface rather
 * than a line in a controller. Swapping it is one class and one line of
 * `config/reveal.php`, with no migration and no change to the endpoint, the
 * response shape, or anything the frontend does.
 *
 * Two methods, because a source decides both what it is able to play and how to
 * play it. A source that served a purpose-made clip would narrow the pool by its
 * own column and sign its own video, and neither half makes sense without the
 * other: drawing a card this source cannot play would produce a reveal with
 * nothing to show.
 *
 * See `docs/plans/reveal-video-plan.md`.
 */
interface RevealVideoSource
{
    /**
     * Narrow a card query to the ones this source can actually play.
     *
     * Applied on top of the language gate rather than instead of it, so a source
     * can never widen the pool to something that is not on the website.
     */
    public function eligible(Builder $query): Builder;

    /**
     * Permission for one viewer to watch, or null when this source cannot
     * produce one.
     *
     * Null is not an error. It is a card this source has nothing for, which the
     * caller decides what to do about.
     *
     * Took the viewer's address until 7 August 2026, when binding a token to it
     * turned out to refuse every visitor with IPv6. See `BunnyStream`.
     */
    public function playbackFor(Card $card): ?RevealPlayback;
}
