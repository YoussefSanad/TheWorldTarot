<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\FilmSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CardVideoResource;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CardVideoController extends Controller
{
    /**
     * What a pass buys, named in config/viewing-room.php.
     *
     * On the constructor rather than the method it is used in, because the
     * container cannot tell two callers apart when an interface arrives as a
     * method argument, and the reveal's controller has to be handed a different
     * adapter of this same interface. See AppServiceProvider.
     */
    public function __construct(private FilmSource $films) {}

    /**
     * A signed, expiring URL for one Living Tarot video.
     *
     * The Viewing Room, and the paywall. An audit of the old site found video
     * anyone could download without paying, and that finding is the reason this
     * project exists, so this is the most important handful of lines in the
     * milestone.
     *
     * **What plays is `App\Contracts\FilmSource`, named in
     * config/viewing-room.php.** This asked Bunny for a signed URL itself until
     * that seam was widened to cover it: it reached for the card's video id,
     * picked an expiry out of config, and knew which vendor was on the other
     * end, while the public reveal did none of that. The half that charges for
     * the film was the half doing it by hand. What is left here is the part
     * that is genuinely this endpoint's own: who may watch, and what a card
     * with nothing to play answers.
     *
     * **403 rather than 404 for a customer without a pass.** The Viewing Room is
     * advertised on the website and its existence is not a secret. Hiding it
     * would only confuse somebody who has paid and is signed in as the wrong
     * person.
     *
     * **404 for a card with no watchable video**, including one still being
     * prepared, because there is genuinely nothing to serve.
     */
    public function __invoke(Request $request, string $locale, int $id): JsonResponse
    {
        // Decided on the server, always, whatever the browser believes about
        // what it should be showing.
        //
        // **Before the card is looked up, and that order is load bearing.**
        // Checking the card first would answer 404 for a card with no film and
        // 403 for one that has it, which tells somebody who has not paid
        // exactly which films exist. The pass question comes first so every
        // card looks identical from outside the Viewing Room.
        Gate::authorize('enter-viewing-room');

        // **One question, asked of the declaration.** This composed the rule at
        // the call site until #32: the language gate, narrowed by whatever the
        // source could play, assembled here. That was a fourth rendering of a
        // rule declared in `Card::viewingRoom()`, and it agreed with the other
        // three only because the Viewing Room's adapter repeated the
        // declaration's video condition word for word. A source narrowing by
        // its own column would have left `in_viewing_room` and the panel saying
        // a card was watchable while this answered 404.
        //
        // The gate is still never replaced, only narrowed, because the
        // declaration is built on the Library's declaration entire. A card that
        // is unpublished, unwritten, unpictured or untranslated into this
        // language cannot be reached by knowing its id.
        $card = Card::viewableIn(app()->getLocale())->whereKey($id)->first();

        // Null is not an error from a source. It is a card it has nothing for,
        // and this endpoint turns that into the same 404 an unknown id gets.
        $playback = $card === null
            ? null
            : $this->films->playbackFor($card);

        if ($playback === null) {
            throw new NotFoundHttpException;
        }

        return (new CardVideoResource($card, $playback))
            ->response()
            // A credential with a timer. Anything holding on to it, a CDN, a
            // browser cache, a service worker, turns half an hour of exposure
            // into however long it keeps it.
            ->header('Cache-Control', 'no-store');
    }
}
