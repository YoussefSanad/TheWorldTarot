<?php

namespace Tests\Fixtures;

use App\Contracts\FilmSource;
use App\Models\Card;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;

/**
 * A film source that signs nothing, for proving which adapter a caller got.
 *
 * **A fake proves a caller and never proves an implementation**, per
 * .claude/external-services.md. This exists so a test can name it in one config
 * key and watch that endpoint, and only that endpoint, start serving its URL.
 * It says nothing whatever about Bunny, and the pinned vector in
 * `BunnyStreamTest` remains the only thing that does.
 *
 * Shared rather than declared per test file, because the reveal and the Viewing
 * Room both need it and two copies would be two chances to make them differ.
 */
class FakeFilmSource implements FilmSource
{
    /**
     * Recognisable on sight, and impossible for the real adapter to produce.
     */
    public const URL = 'https://example.test/a-film-from-the-fake/playlist.m3u8';

    public function eligible(Builder $query): Builder
    {
        return $query;
    }

    public function playbackFor(Card $card): ?Playback
    {
        return new Playback(self::URL, now()->addMinutes(5));
    }
}
