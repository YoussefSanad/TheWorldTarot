<?php

namespace App\Jobs;

use App\Services\Bunny\BunnyStream;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Throws away a video a card no longer has: the film at Bunny, and the master
 * in the private bucket.
 *
 * Queued, because removing a video is a save on an admin screen and she should
 * not wait on a third party to be told it worked. The upload path made the same
 * choice for the same reason.
 *
 * **This carries strings and never a card, and that is the whole trick.** By the
 * time it runs, the row has already forgotten what it used to point at, so a job
 * holding a `Card` would reload it and find nothing to delete. What is being
 * deleted is captured at the moment of the save and travels with the job.
 *
 * Written for a queue that delivers at least once, per CLAUDE.md section 2, and
 * it survives that for free: a Bunny video that is already gone answers 404,
 * which the client counts as done, and deleting an absent object from a bucket
 * is a no-op. Nothing it touches is read by anything else by the time it runs.
 */
class ForgetCardVideo implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then a log line naming what is left behind.
     *
     * There is no state to correct on failure. The card is already right, and
     * what survives is a film in her library and a file in a bucket that
     * somebody has to remove by hand, so the log has to say which.
     */
    public int $tries = 3;

    /**
     * @param  array<int, string|null>  $videoIds  Bunny's ids for the live video, the incoming one, or both
     * @param  string|null  $masterPath  The master in the private bucket
     * @param  int|null  $cardId  For the log alone. The row it names may no longer hold any of this
     */
    public function __construct(
        public array $videoIds = [],
        public ?string $masterPath = null,
        public ?int $cardId = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Whether there is anything here worth queueing.
     *
     * Asked by the caller rather than answered by an early return, so a card
     * that never had a video does not put an empty job on the queue.
     */
    public function hasSomethingToForget(): bool
    {
        return $this->videos() !== [] || filled($this->masterPath);
    }

    public function handle(BunnyStream $bunny): void
    {
        foreach ($this->videos() as $videoId) {
            $bunny->deleteVideo($videoId);
        }

        // Bunny first, deliberately. The master is the only copy that is not on
        // her laptop, so if this run is going to fail it should fail before the
        // irreplaceable half is gone rather than after.
        if (filled($this->masterPath)) {
            Storage::disk(config('filesystems.uploads'))->delete($this->masterPath);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A card\'s old video could not be thrown away.', [
            'card' => $this->cardId,
            'videos' => $this->videos(),
            'master' => $this->masterPath,
            'reason' => $exception?->getMessage(),
        ]);
    }

    /**
     * The ids actually worth asking about.
     *
     * Nulls, because a card removed before Bunny ever saw it has none of these,
     * and duplicates, because the live video and the incoming one are the same
     * id for the moment between a promotion and the row being written.
     *
     * @return array<int, string>
     */
    private function videos(): array
    {
        return array_values(array_unique(array_filter(
            $this->videoIds,
            fn (?string $id): bool => filled($id),
        )));
    }
}
