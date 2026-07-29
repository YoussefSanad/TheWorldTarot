<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Card;
use App\Services\Bunny\BunnyStream;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Hands a freshly uploaded master to Bunny.
 *
 * Written for a queue that delivers at least once and may redeliver a job that
 * is still running, which is what Laravel Cloud's managed queues do. Two things
 * follow from that, and both matter more than they look:
 *
 * The video is created at Bunny **before** any bytes are sent, and its id is
 * stored immediately. A second run then uploads to the same video rather than
 * leaving Jennifer with three copies of The Fool in her library.
 *
 * The master is read from the private bucket, never from a local path. Workers
 * run in a different container from the web application, so a file saved beside
 * the request that uploaded it is not there when this runs.
 */
class SendCardVideoToBunny implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then the card is marked as failed and she is told.
     *
     * A transcode is not urgent enough to hammer, and a video that never
     * arrives has to look different from one still being prepared.
     */
    public int $tries = 3;

    /**
     * Long enough for a 40MB upload on a poor connection.
     *
     * If a real upload ever exceeds this, the queue redelivers the job while the
     * first one is still uploading. That is survivable rather than expensive,
     * which is the whole reason the video id is stored before the bytes go.
     */
    public int $timeout = 600;

    public function __construct(public Card $card) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BunnyStream $bunny): void
    {
        // Reloaded, because anything could have happened between the upload and
        // this running: another upload, a deletion, a replacement.
        $card = $this->card->fresh();

        if ($card === null || blank($card->video_master_path)) {
            return;
        }

        $disk = Storage::disk(config('filesystems.uploads'));

        if (! $disk->exists($card->video_master_path)) {
            // The row says there is a master and the bucket disagrees. Retrying
            // cannot help, so say so and stop rather than failing three times.
            Log::error('A card\'s video master is not in the bucket.', [
                'card' => $card->id,
                'path' => $card->video_master_path,
            ]);

            $card->update(['video_status' => VideoStatus::Failed]);

            return;
        }

        // Only ever created once per upload. On a redelivery this is already
        // set, and the same video is uploaded to again.
        if (! $card->hasIncomingVideo()) {
            $card->update([
                'bunny_incoming_video_id' => $bunny->createVideo($this->title($card)),
                'video_status' => VideoStatus::Processing,
            ]);
        }

        $stream = $disk->readStream($card->video_master_path);

        try {
            $bunny->uploadVideo($card->bunny_incoming_video_id, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Bunny has it. Whether it is watchable is Bunny's answer to give, and
        // the webhook is what asks.
        $card->update(['video_status' => VideoStatus::Processing]);
    }

    /**
     * Every attempt has failed, so tell her rather than leaving a card that
     * looks like it is still being prepared.
     *
     * The master is deliberately kept. It is the only copy that is not on her
     * laptop, and uploading it again is how she retries.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('A card\'s video never reached Bunny.', [
            'card' => $this->card->id,
            'reason' => $exception?->getMessage(),
        ]);

        $this->card->fresh()?->update(['video_status' => VideoStatus::Failed]);
    }

    /**
     * What the video is called inside Bunny's library.
     *
     * For her eyes when she opens their dashboard, so it says which card rather
     * than which uuid.
     */
    private function title(Card $card): string
    {
        return $card->number.'. '.$card->translationLabel();
    }
}
