<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Card;
use App\Support\Playback;
use Illuminate\Http\Request;

/**
 * The drawn card, plus permission to watch its film.
 *
 * **A separate class from `CardResource` on purpose, and it must stay separate.**
 * The Library listing carries every card and is meant to be cached, so a signed
 * URL reaching it would be a leak and a caching bug at once. Keeping the
 * credential on a resource the listing does not use makes that impossible rather
 * than something we remember not to do. Adding a video field to `CardResource`
 * to save this file would undo the whole arrangement.
 *
 * Everything the card endpoints already return, unchanged, so one component on
 * the frontend renders a card wherever it came from.
 *
 * @mixin Card
 */
class RevealCardResource extends CardResource
{
    public function __construct(Card $card, private Playback $playback)
    {
        parent::__construct($card);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [

            // Never null. A card with nothing to play is never drawn, so the
            // frontend does not have to hold a case that cannot happen.
            'video' => [

                // Signed and time limited, and pointing at the full Living
                // Tarot film. Not to be cached, stored, or put anywhere it can
                // be shared, which the contract says in plainer words.
                'url' => $this->playback->url,

                // So a player can ask for a fresh reveal before this dies
                // rather than discovering it mid-film.
                'expires_at' => $this->playback->expiresAt->toIso8601String(),
            ],
        ]);
    }
}
