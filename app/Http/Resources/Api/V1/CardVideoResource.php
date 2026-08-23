<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Card;
use App\Support\Playback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Permission to watch one video, for a while.
 *
 * @mixin Card
 */
class CardVideoResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Card $card, private Playback $playback)
    {
        parent::__construct($card);
    }

    /**
     * Deliberately absent: Bunny's video id, and anything else about where the
     * file lives. The URL is the whole of what a player needs, and naming the
     * video elsewhere only helps somebody trying to reach it another way.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'card_id' => $this->id,

            // Signed and time limited. Not to be cached, stored, or put in a
            // URL somebody can share, which the contract says in plainer words.
            'url' => $this->playback->url,

            // So the player can ask for a fresh one before this dies, rather
            // than discovering it mid-video.
            'expires_at' => $this->playback->expiresAt->toIso8601String(),
        ];
    }
}
