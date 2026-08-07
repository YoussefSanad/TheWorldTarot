<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Card
 */
class CardResource extends JsonResource
{
    public static $wrap = null;

    /**
     * The same shape from every card endpoint, long copy included.
     *
     * Readings split their copy, so a listing does not carry four long
     * descriptions nobody asked for. Cards do not, because the Library opens a
     * card in a pop-up on a page that already holds the whole deck: splitting
     * it would save a few kilobytes and cost a round trip every time somebody
     * opens a card.
     *
     * Translatable values come out in the locale the middleware resolved, since
     * it has already set the application locale for this request. Cast to
     * string, or the generated specification describes them as arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // The address of a single card. There is no slug: a card is never
            // reached by a URL a person reads.
            'id' => $this->id,

            // The number on the card, which is also the order the deck is
            // shown in. The Fool is 0.
            'number' => $this->number,

            'name' => (string) $this->name,
            'short_description' => (string) $this->short_description,
            'long_description' => (string) $this->long_description,

            // Always a real address, never null: a card with no picture is not
            // on the website, so it never reaches this resource. The same
            // picture in every language.
            'image' => $this->imageUrl(),

            // Whether there is a film to watch.
            //
            // Here rather than behind the paywall because the Viewing Room is
            // advertised: the site shows all twenty-two, locked, and invites
            // people to buy the pass. Knowing a film exists is the marketing.
            // Watching it is what needs paying for, and that is refused by the
            // endpoint that serves the URL, whatever this says.
            //
            // A poster frame sat beside this until 7 August 2026. It was a
            // second per-card upload gating the Viewing Room and the draw, and
            // the card's own picture is what the frontend rests a player on.
            'in_viewing_room' => $this->isViewableIn(app()->getLocale()),
        ];
    }
}
