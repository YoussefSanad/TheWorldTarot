<?php

namespace App\Services\Reveal;

use App\Contracts\RevealVideoSource;
use App\Models\Card;
use App\Services\Bunny\BunnyStream;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reveal plays the card's full Living Tarot film.
 *
 * **This is the same film the Viewing Room sells, served without a pass.**
 * Decided 2 August 2026 with the consequence stated: the reveal is public, the
 * one per visitor limit lives in a frontend cookie, so nothing on this side
 * stops somebody drawing repeatedly until they have the deck. What signing still
 * buys is the audit's actual finding, that no film is reachable by guessing an
 * address, and that a copied link is dead within half an hour.
 *
 * The reasoning, and the alternatives that were declined, are in
 * `docs/plans/reveal-video-plan.md`. If that trade stops being acceptable, this
 * class is the thing to replace, and nothing else has to move.
 */
class FullFilmRevealVideo implements RevealVideoSource
{
    public function __construct(private BunnyStream $bunny) {}

    /**
     * Cards with a film somebody could actually watch.
     *
     * The same condition the Viewing Room uses, and reused rather than restated
     * so the two cannot drift into disagreeing about what a watchable card is.
     * `bunny_video_id` only ever holds a ready video, so a card having a
     * replacement prepared stays drawable and keeps playing what it has.
     */
    public function eligible(Builder $query): Builder
    {
        return $query->whereNotNull('bunny_video_id');
    }

    public function playbackFor(Card $card): ?RevealPlayback
    {
        if (! $card->isPlayable()) {
            return null;
        }

        // Its own key, deliberately, holding the same half hour the Viewing Room
        // uses. The same figure today and not the same decision: shortening what
        // the public gets must never reach into what a paying viewer gets, and
        // one key each is what keeps that from being a code change.
        $expiresAt = now()->addSeconds(config('services.bunny.reveal_ttl'));

        return new RevealPlayback(
            $this->bunny->playbackUrl($card->bunny_video_id, $expiresAt),
            $expiresAt,
        );
    }
}
