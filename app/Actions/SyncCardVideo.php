<?php

namespace App\Actions;

use App\Enums\VideoStatus;
use App\Models\Card;
use App\Services\Bunny\BunnyRequestFailed;
use App\Services\Bunny\BunnyStream;
use Illuminate\Support\Facades\Log;

/**
 * Brings a card's video into line with what Bunny says about it.
 *
 * The single place a video becomes watchable, used by both things that can
 * notice: the transcode callback, which is fast and unreliable, and the
 * scheduled sweep, which is slow and certain. Two callers, one rule, so a
 * callback and a sweep arriving together cannot leave a card in a state neither
 * of them intended.
 */
class SyncCardVideo
{
    public function __construct(private BunnyStream $bunny) {}

    /**
     * @return VideoStatus|null What Bunny said, or null when it could not be asked
     */
    public function __invoke(Card $card): ?VideoStatus
    {
        // Whichever video is in flight. A card with nothing in flight has
        // nothing to learn: its live video is already watchable.
        $videoId = $card->bunny_incoming_video_id;

        if (blank($videoId)) {
            return $card->video_status;
        }

        try {
            $status = $this->bunny->fetchStatus($videoId);
        } catch (BunnyRequestFailed $e) {
            // Asking failed, which says nothing about the video. Leave the card
            // alone: a sweep in five minutes asks again, and marking it failed
            // here would take a perfectly good video off the site.
            Log::warning('Could not ask Bunny about a card\'s video.', [
                'card' => $card->id,
                'video' => $videoId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        match ($status) {
            // The one moment a customer's video changes underneath them.
            VideoStatus::Ready => $card->promoteIncomingVideo(),

            // The live video, if there is one, keeps playing. She retries by
            // uploading again.
            VideoStatus::Failed => $card->update(['video_status' => VideoStatus::Failed]),

            default => $card->update(['video_status' => $status]),
        };

        return $status;
    }
}
