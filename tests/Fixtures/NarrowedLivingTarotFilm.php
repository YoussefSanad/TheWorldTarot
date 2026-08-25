<?php

namespace Tests\Fixtures;

use App\Availability\Condition;
use App\Enums\VideoStatus;
use App\Models\Card;
use App\Services\Film\LivingTarotFilm;
use Illuminate\Database\Eloquent\Builder;

/**
 * A subclass that changes what it can play, to prove the guard follows.
 *
 * `LivingTarotFilm` says in writing that nothing stops a subclass overriding
 * more than its expiry key, and that the day one needs to it should become its
 * own adapter instead. This is not that day and this is not a production shape.
 * It exists to ask one question the real subclasses cannot: when the condition
 * and `Card::isPlayable()` disagree, which one does `playbackFor` obey?
 *
 * The answer has to be the condition. `whatItCanPlay()` is what the Viewing
 * Room declaration reads, so a guard answering the other question is the
 * fourth-rendering problem #32 closed, reopened from inside the adapter.
 *
 * Narrows to a finished film the way a purpose-made clip would, by asking the
 * status rather than the id. A card whose film is being replaced has a playable
 * id and a status of `Processing`, so the two disagree about a real row.
 */
class NarrowedLivingTarotFilm extends LivingTarotFilm
{
    protected function expiryKey(): string
    {
        return 'services.bunny.playback_ttl';
    }

    public function whatItCanPlay(): Condition
    {
        return Condition::make(
            missing: 'a film that has finished being prepared',
            query: fn (Builder $query) => $query->where('video_status', VideoStatus::Ready),
            record: fn (Card $card): bool => $card->video_status === VideoStatus::Ready,
        );
    }
}
