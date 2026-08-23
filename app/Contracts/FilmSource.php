<?php

namespace App\Contracts;

use App\Models\Card;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a Living Tarot film comes from, and permission to play one.
 *
 * **Named after what it produces rather than after its first caller.** This was
 * `RevealVideoSource` while the homepage reveal was the only thing crossing it,
 * and the name was accurate and too narrow: what a source actually does is
 * decide which cards it can play and hand back a signed URL that expires. The
 * Viewing Room mints the identical artifact from the identical film and had no
 * seam at all, which is the asymmetry this rename exists to close.
 *
 * The adapter is named in config, so replacing what plays is a new class and
 * one line. No migration, no change to any endpoint, and nothing for the
 * frontend to do. See `.claude/external-services.md`.
 *
 * Two methods, because a source decides both what it is able to play and how to
 * play it. A source that served a purpose-made clip would narrow the pool by
 * its own column and sign its own video, and neither half makes sense without
 * the other: offering a card this source cannot play produces a film with
 * nothing to show.
 *
 * ## What this interface does not abstract away
 *
 * Bunny's video zone is IPv4 only and this API is not, which is why nothing
 * here takes a viewer's address. An interface does not hide a vendor's failure
 * modes and pretending otherwise is worse than having no interface, so the fact
 * is documented here rather than buried. See `BunnyStream`.
 *
 * See `docs/plans/reveal-video-plan.md`.
 */
interface FilmSource
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
    public function playbackFor(Card $card): ?Playback;
}
