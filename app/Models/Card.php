<?php

namespace App\Models;

use App\Availability\Availability;
use App\Availability\Condition;
use App\Availability\Conditions;
use App\Contracts\FilmSource;
use App\Enums\VideoStatus;
use App\Jobs\ForgetCardVideo;
use App\Jobs\SendCardVideoToBunny;
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
    'is_published', 'bunny_video_id', 'bunny_incoming_video_id', 'video_status',
    'video_master_path', 'video_uploaded_at',
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

    /**
     * What this save is throwing away, captured before the row forgets it.
     *
     * The columns are cleared during `saving` and the job that acts on them is
     * dispatched during `saved`, by which point there is nothing left to read.
     * So it is held here, for the length of one save, and cleared as it is used.
     */
    protected ?ForgetCardVideo $discarded = null;

    /**
     * Everything that happens to a card because its master changed.
     *
     * On the model rather than on the edit screen, so it holds however the file
     * arrived: the panel, a console command, a test.
     *
     * Three things a changed master can mean, and they are told apart by what
     * was there before:
     *
     * - **Nothing, then a file.** A first upload. The card is waiting to be sent
     * - **A file, then a different file.** A replacement. The old master is
     *   thrown away and both video ids are left alone, because the old film is
     *   still playing and must keep playing until the new one is ready
     * - **A file, then nothing.** She removed it. Every video column is cleared
     *   and the film and the master are both thrown away
     *
     * The first two must not overrule a caller who said what the status is,
     * which is how a factory builds a card that is already watchable.
     */
    protected static function booted(): void
    {
        static::saving(function (self $card): void {
            if (! $card->isDirty('video_master_path')) {
                return;
            }

            $card->discarded = blank($card->video_master_path)
                ? $card->forgetVideoEntirely()
                : $card->forgetSupersededMaster();

            if (filled($card->video_master_path) && ! $card->isDirty('video_status')) {
                $card->video_status = VideoStatus::Pending;
                $card->video_uploaded_at = now();
            }
        });

        // Both after the transaction commits, or a worker can pick a job up
        // before the row it describes exists.
        static::saved(function (self $card): void {
            // First, because a save that removes a video sets the status to
            // null and so cannot reach the line below.
            $card->dispatchDiscardedVideo();

            // Sending it is the queue's job, so she is never left waiting on a
            // 40MB upload to a third party.
            if ($card->wasChanged('video_master_path') && $card->video_status === VideoStatus::Pending) {
                SendCardVideoToBunny::dispatch($card)->afterCommit();
            }
        });
    }

    /**
     * She removed the film. The card goes back to having no video at all.
     *
     * Every video column, not only the master, which is the whole point. The row
     * kept its status before this existed, which is why the panel went on saying
     * "Ready to watch" about a card with nothing to watch, and it kept
     * `bunny_video_id`, which is why the film stayed in Bunny's library and the
     * Viewing Room went on serving it.
     *
     * Absence rather than a fifth status, matching a card that never had a
     * video: `video_status` is null, and the list column already says "None yet"
     * for that.
     */
    private function forgetVideoEntirely(): ForgetCardVideo
    {
        $discarded = new ForgetCardVideo(
            videoIds: [$this->bunny_video_id, $this->bunny_incoming_video_id],
            masterPath: $this->getOriginal('video_master_path'),
            cardId: $this->id,
        );

        $this->bunny_video_id = null;
        $this->bunny_incoming_video_id = null;
        $this->video_status = null;
        $this->video_uploaded_at = null;

        return $discarded;
    }

    /**
     * A new master over an old one. Only the old file goes.
     *
     * The video ids are deliberately untouched. The film Bunny already has keeps
     * playing while the replacement transcodes, which is what the two columns
     * exist for, and the one it replaces is thrown away at the moment of
     * promotion instead.
     *
     * Filament leaves the superseded object in the bucket, since a file field
     * with no `deleteUploadedFileUsing` only clears the path. Every replacement
     * before this leaked one.
     */
    private function forgetSupersededMaster(): ForgetCardVideo
    {
        return new ForgetCardVideo(
            masterPath: $this->getOriginal('video_master_path'),
            cardId: $this->id,
        );
    }

    /**
     * Hands whatever this save threw away to the queue, once.
     */
    private function dispatchDiscardedVideo(): void
    {
        $discarded = $this->discarded;
        $this->discarded = null;

        // The job that was built during `saving` is the one that goes, rather
        // than a new one built from arguments repeated here. There is nothing
        // left on the row to rebuild it from anyway.
        if ($discarded?->hasSomethingToForget()) {
            dispatch($discarded)->afterCommit();
        }
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
     * What a card needs before it is on the website in a language.
     *
     * Declared once and rendered three ways below: the query the Library
     * lists through, the predicate a loaded card answers, and the words the
     * panel tells Jennifer. They were three separate implementations kept in
     * agreement by hand, answering different callers, with nothing asserting
     * they agreed.
     *
     * A card needs its copy and its picture. The Library shows the card, so one
     * without a picture is not something anyone can be shown, in the same way a
     * reading without a price is not something anyone can buy.
     *
     * The order is the order she reads them in: what she writes, then what she
     * uploads, then the two states she does not set from this screen.
     */
    public static function availability(): Availability
    {
        return Availability::of(
            Conditions::finishedInTheOriginalLanguage(),
            Conditions::finishedInTheLanguageAsked(),
            Condition::make(
                missing: 'a picture',
                query: fn (Builder $query) => $query->whereNotNull('image_path'),
                record: fn (self $card): bool => $card->hasImage(),
            ),
            Conditions::published(),
            Conditions::notRemoved(),
        );
    }

    /**
     * What a visitor reading the Library in this language can actually see.
     */
    public function scopeAvailableIn(Builder $query, string $locale): Builder
    {
        return static::availability()->constrain($query, $locale);
    }

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /**
     * The card's Living Tarot film is ready for somebody to watch.
     *
     * Its own condition, and named, because it belongs to the card rather than
     * to whatever is playing the film. `LivingTarotFilm` declares it as what it
     * can play, and that is now the only thing that asks: `Card::viewingRoom()`
     * reaches it through the source rather than restating it, so a source
     * playing something else moves the declaration with it. The rule was
     * written out in three places and agreed by hand, which is the arrangement
     * this module exists to end.
     *
     * An adapter that played something else, a preview or a purpose-made clip,
     * would declare its own condition against its own column rather than
     * return this one. That is the seam working, and it is why this lives here
     * as a condition a source may name rather than as something a source
     * inherits.
     */
    public static function filmReadyToPlay(): Condition
    {
        return Condition::make(
            missing: fn (self $card): string => match (true) {
                ! $card->hasVideo() => 'a video',
                // Something has been uploaded and nothing is watchable yet,
                // which is the first upload still being prepared or one
                // that failed. A replacement never lands here, because the
                // old video is playable.
                $card->video_status === VideoStatus::Failed => 'its video uploading again, because the last one went wrong',
                default => 'its video to finish being prepared',
            },
            // Does not look at the status, and that is the point.
            // `bunny_video_id` only ever holds a video that is ready, so a
            // replacement being prepared cannot take a card off the site
            // while it transcodes.
            query: fn (Builder $query) => $query->whereNotNull('bunny_video_id'),
            record: fn (self $card): bool => $card->isPlayable(),
        );
    }

    /**
     * The adapter `config/viewing-room.php` names.
     *
     * Typed rather than annotated, because this is the one place a `FilmSource`
     * is resolved outside `AppServiceProvider` and nothing else would check it.
     * The video endpoint gets the same adapter through a contextual binding, so
     * PHP's own check on the controller's constructor refuses a config value
     * naming something that is not a source. This route around the container
     * had no equivalent, and there is no static analyser here, so a wrong key
     * surfaced as an undefined method deep inside the declaration instead of at
     * the seam it came from.
     */
    private static function filmSource(): FilmSource
    {
        return app(config('viewing-room.source'));
    }

    /**
     * What a card additionally needs before it is in the Viewing Room.
     *
     * Built on the Library declaration rather than beside it, deliberately, and
     * on the whole of it as one condition rather than on a copy of its
     * conditions. A card with a finished video and no written copy would
     * otherwise appear in the Viewing Room and not the Library, which
     * contradicts the rule the whole site runs on: a language shows everything
     * or nothing.
     *
     * Deliberately a second declaration and not more conditions on the first.
     * What keeps a card out of the Library and what keeps it out of the Viewing
     * Room are different questions, and a card is routinely finished for
     * readers and unfinished for watchers.
     *
     * **The film condition is the source's own, asked rather than assumed.**
     * It used to be stated here and stated again in the adapter, so the
     * declaration and the endpoint that serves the film agreed only because
     * both spelled the rule the same way. A source serving a purpose-made clip
     * narrows the pool by its own column, and the morning one shipped
     * `in_viewing_room` would have started lying with the whole suite green.
     *
     * **The source is resolved here rather than handed in, and that is the
     * settled answer to a question with no better one.** Four callers read this
     * declaration and three of them have nowhere to get a source: the panel
     * screen, the card resource and the availability check all run with no
     * container context of their own. So it is resolved from
     * `config/viewing-room.php`, the same key the video endpoint's adapter is
     * bound from in `AppServiceProvider`, which is what stops the two being
     * handed different films. A bare `app(FilmSource::class)` remains an error,
     * deliberately: the reveal's adapter is not a thing to pick up by accident.
     *
     * @see FilmSource::whatItCanPlay()
     */
    public static function viewingRoom(): Availability
    {
        return Availability::of(
            self::filmSource()->whatItCanPlay(),
            // Last, because it reads oddly first and because it is the one
            // thing she fixes on a different screen.
            self::availability()->asCondition('to be on the website first'),
        );
    }

    /**
     * What a pass holder reading the site in this language can watch.
     */
    public function scopeViewableIn(Builder $query, string $locale): Builder
    {
        return static::viewingRoom()->constrain($query, $locale);
    }

    public function isViewableIn(string $locale): bool
    {
        return static::viewingRoom()->satisfiedBy($this, $locale);
    }

    /**
     * There is a video customers can watch right now.
     */
    public function isPlayable(): bool
    {
        return filled($this->bunny_video_id);
    }

    /**
     * A replacement is being prepared while the old one keeps playing.
     */
    public function hasIncomingVideo(): bool
    {
        return filled($this->bunny_incoming_video_id);
    }

    public function hasVideo(): bool
    {
        return $this->isPlayable()
            || $this->hasIncomingVideo()
            || filled($this->video_master_path);
    }

    /**
     * The prepared video takes over from the one it was replacing.
     *
     * The only place `bunny_video_id` is ever written after the first upload, so
     * there is one moment where a customer's video changes underneath them and
     * it is this one.
     *
     * **It is also the moment the old film stops being anybody's.** Until
     * 7 August 2026 the superseded id was dropped on the floor, so every
     * replacement ever done left a film in Bunny's library that nothing pointed
     * at. Cleaning it up makes promotion destructive, which is the cost taken
     * knowingly: this runs from a webhook and a scheduled sweep rather than from
     * anything Jennifer does, so a promotion firing on a card it should not have
     * used to be recoverable and no longer is.
     *
     * **The order is load-bearing.** The row is saved pointing at its
     * replacement before anything is queued to delete the old one, so a job that
     * runs the instant it is dispatched cannot delete a film the card still
     * points at.
     */
    public function promoteIncomingVideo(): void
    {
        $superseded = $this->bunny_video_id;

        $this->forceFill([
            'bunny_video_id' => $this->bunny_incoming_video_id,
            'bunny_incoming_video_id' => null,
            'video_status' => VideoStatus::Ready,
        ])->save();

        // Null on a first upload, which is most promotions. There was nothing
        // before it to throw away.
        if (filled($superseded)) {
            ForgetCardVideo::dispatch([$superseded], cardId: $this->id)->afterCommit();
        }
    }

    /**
     * Why this card is not in the Viewing Room yet, in her words. Empty when it
     * is.
     *
     * @return array<int, string>
     */
    public function whatTheViewingRoomIsWaitingFor(string $locale): array
    {
        return static::viewingRoom()->unmetBy($this, $locale);
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
        return static::availability()->unmetBy($this, $locale);
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
        return static::availability()->satisfiedBy($this, $locale);
    }
}
