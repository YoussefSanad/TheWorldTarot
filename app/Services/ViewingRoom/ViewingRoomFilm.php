<?php

namespace App\Services\ViewingRoom;

use App\Services\Film\LivingTarotFilm;

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
 *
 * **What is in the Viewing Room is decided by `Card::viewingRoom()`, which asks
 * this adapter what it can play.** The endpoint composed that rule at the call
 * site until #32, so the declaration and what actually served the film agreed
 * only because both spelled the video rule the same way.
 *
 * **What it does is `LivingTarotFilm`, and all this declares is its expiry
 * key.** The two adapters were the same class twice, collapsed 25 August 2026
 * and reasoned in `docs/adr/0001-one-film-implementation-two-named-adapters.md`.
 * The name survives because the reasoning above is attached to it and belongs
 * on the name it describes rather than merged into a third one.
 * `config/viewing-room.php` still names a bare class that fully determines what
 * happens.
 */
class ViewingRoomFilm extends LivingTarotFilm
{
    /**
     * Its own key, deliberately, holding the same half hour the reveal uses.
     *
     * The same figure today and not the same decision: lengthening what a
     * paying viewer gets must never lengthen a public credential, and one key
     * each is what keeps that from being a code change.
     */
    protected function expiryKey(): string
    {
        return 'services.bunny.playback_ttl';
    }
}
