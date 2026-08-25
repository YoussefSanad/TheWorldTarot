<?php

namespace Tests\Fixtures;

use App\Availability\Condition;
use App\Contracts\FilmSource;
use App\Enums\VideoStatus;
use App\Models\Card;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;

/**
 * A source that can play a narrower set of cards than the film adapters can.
 *
 * The `FilmSource` docblock advertises this adapter: a source serving a
 * purpose-made clip would narrow the pool by its own column. There is no clip
 * column to narrow by, so this stands in for one by asking the video status
 * instead of the video id, which is a genuinely different question and one the
 * codebase has been careful about before. A card whose film is being replaced
 * has a playable id and a status of `Processing`, so the two disagree about a
 * real row that a factory state already makes.
 *
 * It exists to fail the suite on the morning a real one ships. Every rendering
 * of "is this card in the Viewing Room" has to move when this is named in
 * `config/viewing-room.php`, and before #32 only the endpoint's pool did.
 *
 * A fake proves a caller and never proves an implementation, per
 * `.claude/external-services.md`. This says nothing about Bunny.
 */
class ClipFilmSource implements FilmSource
{
    /**
     * Recognisable on sight, and impossible for the real adapter to produce.
     */
    public const URL = 'https://example.test/a-purpose-made-clip/playlist.m3u8';

    /**
     * Only a card whose film has finished being prepared, in its own words.
     */
    public function whatItCanPlay(): Condition
    {
        return Condition::make(
            missing: 'a clip cut for the Viewing Room',
            query: fn (Builder $query) => $query->where('video_status', VideoStatus::Ready),
            record: fn (Card $card): bool => $card->video_status === VideoStatus::Ready,
        );
    }

    public function eligible(Builder $query): Builder
    {
        $this->whatItCanPlay()->constrain($query, app()->getLocale());

        return $query;
    }

    public function playbackFor(Card $card): ?Playback
    {
        return $this->whatItCanPlay()->isMetBy($card, app()->getLocale())
            ? new Playback(self::URL, now()->addMinutes(5))
            : null;
    }
}
