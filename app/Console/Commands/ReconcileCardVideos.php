<?php

namespace App\Console\Commands;

use App\Actions\SyncCardVideo;
use App\Enums\VideoStatus;
use App\Models\Card;
use Illuminate\Console\Command;

/**
 * Asks Bunny about every video still in flight.
 *
 * The webhook is the fast path and it is not a reliable one. A callback lost in
 * transit, or arriving mid-deploy, would otherwise leave a card at "being
 * prepared" until somebody noticed, and one stuck card is a twenty-second of the
 * Viewing Room missing.
 *
 * So the callback makes it quick and this makes it certain. Cheap: one small API
 * call per card in flight, and almost always none at all.
 */
class ReconcileCardVideos extends Command
{
    protected $signature = 'cards:reconcile-videos';

    protected $description = 'Ask Bunny about any card video still being prepared';

    public function handle(SyncCardVideo $sync): int
    {
        // Failed is deliberately not swept. Bunny has given its answer, and
        // retrying is her uploading the file again rather than us asking the
        // same question every five minutes forever.
        $inFlight = Card::withTrashed()
            ->whereNotNull('bunny_incoming_video_id')
            ->whereIn('video_status', [VideoStatus::Pending, VideoStatus::Processing])
            ->get();

        if ($inFlight->isEmpty()) {
            $this->components->info('Nothing is waiting on Bunny.');

            return self::SUCCESS;
        }

        foreach ($inFlight as $card) {
            $status = $sync($card);

            $this->components->twoColumnDetail(
                $card->translationLabel(),
                $status?->label() ?? 'Bunny could not be asked',
            );
        }

        return self::SUCCESS;
    }
}
