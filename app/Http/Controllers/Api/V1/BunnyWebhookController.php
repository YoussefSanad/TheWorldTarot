<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SyncCardVideo;
use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BunnyWebhookController extends Controller
{
    /**
     * Bunny telling us a video changed.
     *
     * **The payload is a hint, never data.** It names a video, and everything
     * that follows comes from asking Bunny directly, so a forged callback
     * achieves nothing whatever their signing scheme turns out to be. That is
     * also what makes it idempotent for free: the same callback twice asks the
     * same question and gets the same answer.
     *
     * Unauthenticated by necessity, since Bunny has no credentials of ours.
     * Rate limited, because it is a public endpoint that triggers an outbound
     * API call.
     *
     * Always answers 200, even for a payload it cannot use. A non-2xx tells
     * Bunny to try again, and nothing about a malformed body or an unknown video
     * improves on a retry. What it does instead is log, so a broken integration
     * is visible rather than silent.
     */
    public function __invoke(Request $request, SyncCardVideo $sync): JsonResponse
    {
        $videoId = $request->input('VideoGuid')
            ?? $request->input('videoGuid')
            ?? $request->input('video_guid');

        if (! is_string($videoId) || $videoId === '') {
            Log::warning('A Bunny callback arrived with no video in it.', [
                'keys' => array_keys($request->all()),
            ]);

            return response()->json(['received' => true]);
        }

        $card = Card::withTrashed()
            ->where('bunny_incoming_video_id', $videoId)
            ->orWhere('bunny_video_id', $videoId)
            ->first();

        if ($card === null) {
            // A video from another environment sharing a library, a card that
            // has since been deleted, or an upload that never finished. None of
            // them is an error here.
            Log::info('A Bunny callback named a video this site does not know.', [
                'video' => $videoId,
            ]);

            return response()->json(['received' => true]);
        }

        $sync($card);

        return response()->json(['received' => true]);
    }
}
