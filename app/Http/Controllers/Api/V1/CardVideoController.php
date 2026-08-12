<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CardVideoResource;
use App\Models\Card;
use App\Services\Bunny\BunnyStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CardVideoController extends Controller
{
    /**
     * A signed, expiring URL for one Living Tarot video.
     *
     * The paywall. An audit of the old site found video anyone could download
     * without paying, and that finding is the reason this project exists, so
     * this is the most important handful of lines in the milestone.
     *
     * **403 rather than 404 for a customer without a pass.** The Viewing Room is
     * advertised on the website and its existence is not a secret. Hiding it
     * would only confuse somebody who has paid and is signed in as the wrong
     * person.
     *
     * **404 for a card with no watchable video**, including one still being
     * prepared, because there is genuinely nothing to serve.
     */
    public function __invoke(Request $request, BunnyStream $bunny, string $locale, int $id): JsonResponse
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

        // Filtered in the query, so a card that is unpublished, unwritten,
        // unpictured, untranslated into this language or still transcoding
        // cannot be reached by knowing its id.
        $card = Card::viewableIn(app()->getLocale())->whereKey($id)->first();

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        $expiresAt = now()->addSeconds(config('services.bunny.playback_ttl'));

        // Signed and short lived. It was bound to the asker's address until
        // 7 August 2026, which refused every viewer with IPv6 rather than only
        // the people it was meant to.
        $url = $bunny->playbackUrl($card->bunny_video_id, $expiresAt);

        return (new CardVideoResource($card, $url, $expiresAt))
            ->response()
            // A credential with a timer. Anything holding on to it, a CDN, a
            // browser cache, a service worker, turns two hours of exposure into
            // however long it keeps it.
            ->header('Cache-Control', 'no-store');
    }
}
