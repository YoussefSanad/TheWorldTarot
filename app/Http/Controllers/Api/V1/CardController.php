<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CardCollection;
use App\Http\Resources\Api\V1\CardResource;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
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
     * One card, drawn at random, for the reveal on the homepage.
     *
     * Stateless on purpose. It has no idea who is asking, so the rule that a
     * visitor gets one reveal per session belongs to the frontend, in a cookie.
     * This will hand the same person a hundred cards if they ask for them.
     */
    public function random(): JsonResponse
    {
        $card = Card::availableIn(app()->getLocale())
            ->inRandomOrder()
            ->first();

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        return (new CardResource($card))
            ->response()
            // The one endpoint here that must never be cached. A CDN or a
            // browser holding this would reveal the same card to everybody,
            // which is the only thing a reveal must not do.
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
