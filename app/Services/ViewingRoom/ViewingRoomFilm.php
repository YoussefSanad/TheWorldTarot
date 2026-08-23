<?php

namespace App\Services\ViewingRoom;

use App\Contracts\FilmSource;
use App\Models\Card;
use App\Services\Bunny\BunnyStream;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Viewing Room plays the card's full Living Tarot film.
 *
 * **This is the paywall's half of the seam, and the reason the seam was worth
 * widening.** An audit of the old site found video anybody could download
 * without paying, and that finding is why this project exists. What signs a
 * paying viewer's film used to be assembled in the controller: it reached for
 * the card's video id, picked an expiry out of config, and called Bunny itself,
 * while the public reveal did none of that because it crossed an interface. The
 * half that charges for the film was the half with no seam.
 *
 * The same film the reveal serves, which is on record as a knowing decision
 * rather than an accident. See `FullFilmRevealVideo` and
 * `docs/plans/reveal-video-plan.md`. The two are separate adapters so that
 * changing what the public gets is not a change to what a customer bought.
 *
 * **This does not decide who may watch.** That is `enter-viewing-room`, asked by
 * the controller before a card is ever looked up, so every card looks identical
 * from outside the Viewing Room.
 */
class ViewingRoomFilm implements FilmSource
{
    public function __construct(private BunnyStream $bunny) {}

    /**
     * Cards with a film a pass holder could actually watch.
     *
     * Composed with the language gate by the caller, never instead of it, which
     * is what `Card::viewableIn` says in one scope: a card in the Library that
     * also has a ready film. Paying for the pass does not reach past the gate.
     *
     * `bunny_video_id` only ever holds a ready video, so a card having a
     * replacement prepared stays watchable and keeps playing what it has.
     */
    public function eligible(Builder $query): Builder
    {
        return $query->whereNotNull('bunny_video_id');
    }

    public function playbackFor(Card $card): ?Playback
    {
        if (! $card->isPlayable()) {
            return null;
        }

        // Its own key, deliberately, holding the same half hour the reveal uses.
        // The same figure today and not the same decision: lengthening what a
        // paying viewer gets must never lengthen a public credential, and one
        // key each is what keeps that from being a code change.
        $expiresAt = now()->addSeconds(config('services.bunny.playback_ttl'));

        return new Playback(
            $this->bunny->playbackUrl($card->bunny_video_id, $expiresAt),
            $expiresAt,
        );
    }
}
