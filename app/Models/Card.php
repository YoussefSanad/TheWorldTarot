<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Models\Concerns\HasTranslationCompleteness;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * One card of the World Tarot.
 *
 * The 22 Major Arcana are seeded, but unlike products a card is a row Jennifer
 * creates herself. That is a binding FAQ commitment to people who already paid
 * for the pass, not a convenience.
 *
 * Addressed by id, never a slug. A card is a modal opened from the Library, so
 * its identifier is never read by anyone.
 */
#[Fillable([
    'number', 'name', 'short_description', 'long_description', 'image_path',
    'is_published', 'bunny_video_id', 'video_status', 'video_master_path',
    'poster_path', 'video_uploaded_at',
])]
class Card extends Model
{
    use HasFactory, HasTranslationCompleteness, HasTranslations, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['name', 'short_description', 'long_description'];

    /**
     * What a language needs before this card can be shown in it.
     *
     * The same field names as a product, deliberately, so one wording list
     * describes what is missing across both and the two cannot drift.
     *
     * @return array<int, string>
     */
    public static function requiredTranslations(): array
    {
        return ['name', 'short_description', 'long_description'];
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_published' => 'boolean',
            'video_status' => VideoStatus::class,
            'video_uploaded_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * What a visitor reading the Library in this language can actually see.
     *
     * A card needs its copy and its picture. The Library shows the card, so one
     * without a picture is not something anyone can be shown, in the same way a
     * reading without a price is not something anyone can buy.
     */
    public function scopeAvailableIn(Builder $query, string $locale): Builder
    {
        return $query->published()
            ->whereNotNull('image_path')
            // The original language is the source. A card that is not finished
            // in it is not finished at all, so it appears nowhere rather than
            // existing in Spanish and not in English.
            ->completeIn(Locales::default())
            ->completeIn($locale);
    }

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /**
     * What a pass holder reading the site in this language can watch.
     *
     * Built on being in the Library rather than beside it, deliberately. A card
     * with a finished video and no written copy would otherwise appear in the
     * Viewing Room and not the Library, which contradicts the rule the whole
     * site runs on: a language shows everything or nothing.
     */
    public function scopeViewableIn(Builder $query, string $locale): Builder
    {
        return $query->availableIn($locale)
            ->where('video_status', VideoStatus::Ready)
            ->whereNotNull('poster_path');
    }

    public function isViewableIn(string $locale): bool
    {
        return $this->isAvailableIn($locale)
            && $this->video_status === VideoStatus::Ready
            && $this->hasPoster();
    }

    public function hasVideo(): bool
    {
        return filled($this->bunny_video_id) || filled($this->video_master_path);
    }

    public function hasPoster(): bool
    {
        return filled($this->poster_path);
    }

    /**
     * Why this card is not in the Viewing Room yet, in her words. Empty when it
     * is.
     *
     * Deliberately separate from what keeps it out of the Library, since a card
     * can be finished for readers and unfinished for watchers.
     *
     * @return array<int, string>
     */
    public function whatTheViewingRoomIsWaitingFor(string $locale): array
    {
        $missing = [];

        if (! $this->hasVideo()) {
            $missing[] = 'a video';
        } elseif ($this->video_status !== VideoStatus::Ready) {
            $missing[] = 'its video to finish being prepared';
        }

        if (! $this->hasPoster()) {
            $missing[] = 'a poster frame';
        }

        // Last, because it reads oddly first and because it is the one thing
        // she fixes on a different screen.
        if (! $this->isAvailableIn($locale)) {
            $missing[] = 'to be on the website first';
        }

        return $missing;
    }

    /**
     * Where the poster is served from. A still rather than the thing the
     * paywall protects, so it sits with the pictures.
     */
    public function posterUrl(): ?string
    {
        if (! $this->hasPoster()) {
            return null;
        }

        return Storage::disk(config('filesystems.media'))->url($this->poster_path);
    }

    /**
     * Where the picture is served from.
     *
     * Rows hold the path alone, so which bucket it lives in is configuration
     * and moving it never touches the data.
     */
    public function imageUrl(): ?string
    {
        if (! $this->hasImage()) {
            return null;
        }

        return Storage::disk(config('filesystems.media'))->url($this->image_path);
    }

    /**
     * Why this card is not on the website yet, in her words.
     *
     * @return array<int, string>
     */
    public function whatIsMissing(string $locale): array
    {
        $missing = $this->missingCopy($locale);

        if (! $this->hasImage()) {
            $missing[] = 'a picture';
        }

        return $missing;
    }

    /**
     * A card she has created but not named yet is the ordinary state of a card
     * for as long as it takes to write its copy, and the language gate has to
     * be able to point at it while it is in that state.
     */
    public function translationLabel(): string
    {
        $name = (string) $this->getTranslation('name', Locales::default(), false);

        return $name !== '' ? $name : 'Card '.$this->number;
    }

    public function isAvailableIn(string $locale): bool
    {
        return $this->is_published
            && $this->hasImage()
            && $this->isCompleteIn(Locales::default())
            && $this->isCompleteIn($locale);
    }
}
