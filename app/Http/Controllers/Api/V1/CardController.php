<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\FilmSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CardCollection;
use App\Http\Resources\Api\V1\CardResource;
use App\Http\Resources\Api\V1\RevealCardResource;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CardController extends Controller
{
    /**
     * The Library.
     *
     * Every card on the website in this language, in deck order, carrying
     * everything a card needs when it opens. One request renders the whole
     * Library, and opening a card costs nothing after that.
     */
    public function index(): CardCollection
    {
        $cards = Card::availableIn(app()->getLocale())
            ->orderBy('number')
            ->get();

        return new CardCollection($cards);
    }

    /**
     * The draw. One card, taken at random, with its film, for the homepage.
     *
     * Stateless on purpose. It has no idea who is asking, so the rule that a
     * visitor gets one reveal per session belongs to the frontend, in a cookie.
     * This will hand the same person a hundred cards if they ask for them.
     *
     * **Which means it will hand out a hundred films.** This endpoint is public
     * and serves the same Living Tarot film the Viewing Room sells, decided
     * 2 August 2026 with the consequence written down rather than discovered:
     * there is no server-side limit here to lean on, so nothing stops a visitor
     * collecting the deck. What signing still buys is that no film is reachable
     * by guessing an address, and that a copied link is dead within half an
     * hour. The reasoning and the declined alternatives are in
     * docs/plans/reveal-video-plan.md.
     *
     * What plays is `App\Contracts\FilmSource`, so replacing the full
     * film with a preview or a purpose-made clip changes a config line and
     * nothing here.
     */
    public function draw(Request $request, FilmSource $source): JsonResponse
    {
        // The source narrows the pool to what it can actually play, on top of
        // the language gate and never instead of it. Drawing a card it has
        // nothing for would produce a reveal with nothing to show.
        $card = $source->eligible(Card::availableIn(app()->getLocale()))
            ->inRandomOrder()
            ->first();

        // Signed and short lived, and no longer bound to who asked: the video
        // zone is IPv4 only and this API is not, so a token carrying the asker's
        // address refused every visitor with IPv6.
        $playback = $card === null
            ? null
            : $source->playbackFor($card);

        // No fallback to a card without a film, decided 2 August 2026 on the
        // basis that one will not exist in the system. So an empty pool is the
        // 404 this endpoint already gave when nothing was on the website, and
        // a freshly seeded environment 404s here until a card has a film.
        //
        // Which reason it was, in words, because the two are indistinguishable
        // from outside and the empty body this used to send read like a routing
        // mistake. It cost an afternoon on staging on 7 August 2026. The extra
        // query only ever runs on the way to a 404, and the frontend treats any
        // 404 here as nothing to draw, so this tells a person and changes
        // nothing for the site.
        if ($playback === null) {
            throw new NotFoundHttpException(
                Card::availableIn(app()->getLocale())->exists()
                    ? 'No card on the website has a film to play yet.'
                    : 'There are no cards on the website in this language yet.'
            );
        }

        return (new RevealCardResource($card, $playback))
            ->response()
            // Never cached, now for two reasons rather than one. A CDN or a
            // browser holding this would reveal the same card to everybody,
            // which is the only thing a reveal must not do, and it now carries
            // a credential with a timer rather than only a card.
            ->header('Cache-Control', 'no-store');
    }

    /**
     * One card, by id.
     *
     * Anything not on the website in this language is a 404 rather than a 403.
     * Whether an unwritten card exists is not public information.
     *
     * M4 hangs the signed video URL and the pass check off this endpoint, which
     * is why it exists at all while the listing carries everything.
     */
    public function show(string $locale, int $id): CardResource
    {
        // Route parameters reach a controller in the order they appear in the
        // path, not by matching names, so the language segment has to be taken
        // even though the middleware has already dealt with it.
        $card = Card::availableIn(app()->getLocale())
            ->whereKey($id)
            ->first();

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        return new CardResource($card);
    }
}
