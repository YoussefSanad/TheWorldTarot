<?php

namespace App\Services\Film;

use App\Availability\Condition;
use App\Contracts\FilmSource;
use App\Models\Card;
use App\Services\Bunny\BunnyStream;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;

/**
 * The card's full Living Tarot film, signed for as long as its caller says.
 *
 * Everything two adapters were doing identically. `FullFilmRevealVideo` and
 * `ViewingRoomFilm` were the same class twice: with comments stripped they
 * differed in the namespace, the name, and one config key. An architecture
 * review asked whether the duplication was earning anything, and the answer is
 * recorded in `docs/adr/0001-one-film-implementation-two-named-adapters.md`.
 *
 * **What the two names are actually protecting is a layer above this class, and
 * it is untouched.** The reveal is public and the Viewing Room is sold.
 * Changing what anybody can get must never reach into what somebody paid for,
 * and lengthening what a paying viewer gets must never lengthen a public
 * credential. That is held by two config files naming their own class, and two
 * env-backed expiry keys carrying their own reasoning. Both remain
 * independently replaceable whatever sits behind them.
 *
 * **Inheritance rather than composition, for two subclasses with no behaviour
 * of their own, and that is the cost taken knowingly.** The alternative that
 * avoids it is two pass-through adapters of ten lines each, which is the
 * shallow module the exercise exists not to build. The obvious collapse, one
 * class taking its expiry key as a constructor argument, was declined: today
 * `config/reveal.php` holds a class name and that name fully determines what
 * happens, and an argument would move the wiring into `AppServiceProvider`,
 * which is supposed to treat the class as opaque.
 *
 * Nothing stops a subclass overriding more than its expiry key, and nothing is
 * expected to. The day one needs to, it should stop being a subclass and become
 * its own adapter against `FilmSource`, which is what the seam is for and which
 * costs nothing now that this exists.
 *
 * ## The vendor failure this does not hide
 *
 * **Bunny's video zone is IPv4 only and this API is not, so nothing here takes
 * a viewer's address.** Binding a token to one was added on 3 August 2026 and
 * removed on 7 August, having never worked once: a visitor with IPv6 had a
 * token signed for their v6 address and then reached the CDN over v4, the only
 * way there is, so Bunny hashed a different address and refused. That is every
 * visitor with working IPv6, on every film, every time. What limits a copied
 * link is its life, and `ZoneSecurityIncludeHashRemoteIP` is off on the zone to
 * match. The two must move together or everybody is refused.
 *
 * Documented here rather than buried, because an interface does not abstract
 * away a vendor's failure modes and pretending otherwise is worse than having
 * no interface. See `BunnyStream` and `.claude/external-services.md`.
 */
abstract class LivingTarotFilm implements FilmSource
{
    public function __construct(protected BunnyStream $bunny) {}

    /**
     * Which config key holds how long this caller's credential lives.
     *
     * The only thing a subclass declares. One key each is what keeps the reveal
     * and the Viewing Room from being the same decision, and keeps changing
     * either from being a code change.
     */
    abstract protected function expiryKey(): string;

    /**
     * Cards with a film somebody could actually watch.
     *
     * The card's Living Tarot film being ready, which is the condition
     * `Card::viewingRoom()` also reads, so the declaration and whatever plays
     * cannot come to different answers about what a watchable card is. A source
     * serving a preview or a purpose-made clip would declare its own condition
     * against its own column, and would not be a subclass of this.
     *
     * `bunny_video_id` only ever holds a ready video, so a card having a
     * replacement prepared stays playable and keeps playing what it has. That
     * is what the two video columns exist for: replacing a film must never take
     * a card out of the Viewing Room while it transcodes.
     */
    public function whatItCanPlay(): Condition
    {
        return Card::filmReadyToPlay();
    }

    /**
     * The same declaration as a query.
     *
     * Applied on top of the language gate rather than instead of it, so a
     * source can never widen the pool to something that is not on the website.
     */
    public function eligible(Builder $query): Builder
    {
        // The locale the request is being read in. This condition ignores it,
        // as most conditions do, and one that cared would be asking about the
        // language the visitor is actually being served.
        $this->whatItCanPlay()->constrain($query, app()->getLocale());

        return $query;
    }

    /**
     * Permission for one viewer to watch, or null when there is nothing to
     * play.
     *
     * Null is not an error. It is a card this source has nothing for, which the
     * caller decides what to do about: a 404 in the Viewing Room, and an empty
     * pool at the reveal.
     *
     * **The guard asks the condition rather than the column.** It asked
     * `Card::isPlayable()` directly until 25 August 2026, which is the answer
     * `filmReadyToPlay()` gives and not the same question. Two statements of
     * one rule agreeing by coincidence is what #28 and #32 spent three tickets
     * ending, and this was the last of them: a source narrowing to something
     * else would have gone on signing films for cards `Card::viewingRoom()`
     * had already stopped calling watchable.
     */
    public function playbackFor(Card $card): ?Playback
    {
        // The locale the request is being read in, as `eligible()` reads it.
        // This condition ignores it, as most conditions do.
        if (! $this->whatItCanPlay()->isMetBy($card, app()->getLocale())) {
            return null;
        }

        $expiresAt = now()->addSeconds(config($this->expiryKey()));

        return new Playback(
            $this->bunny->playbackUrl($card->bunny_video_id, $expiresAt),
            $expiresAt,
        );
    }
}
