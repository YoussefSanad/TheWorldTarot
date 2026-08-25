<?php

namespace App\Services\Reveal;

use App\Services\Film\LivingTarotFilm;

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
 *
 * **What it does is `LivingTarotFilm`, and all this declares is its expiry
 * key.** The two adapters were the same class twice, collapsed 25 August 2026
 * and reasoned in `docs/adr/0001-one-film-implementation-two-named-adapters.md`.
 * The name survives because the reasoning above is attached to it and belongs
 * on the name it describes rather than merged into a third one.
 * `config/reveal.php` still names a bare class that fully determines what
 * happens.
 */
class FullFilmRevealVideo extends LivingTarotFilm
{
    /**
     * Its own key, deliberately, holding the same half hour the Viewing Room
     * uses.
     *
     * The same figure today and not the same decision: shortening what the
     * public gets must never reach into what a paying viewer gets, and one key
     * each is what keeps that from being a code change.
     */
    protected function expiryKey(): string
    {
        return 'services.bunny.reveal_ttl';
    }
}
