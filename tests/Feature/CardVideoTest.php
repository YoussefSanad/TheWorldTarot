<?php

use App\Enums\VideoStatus;
use App\Models\Card;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('casts the video status both ways', function () {
    $card = Card::factory()->watchable()->create();

    expect($card->video_status)->toBe(VideoStatus::Ready)
        ->and($card->fresh()->video_status)->toBe(VideoStatus::Ready)
        ->and(DB::table('cards')->value('video_status'))->toBe('ready');
});

it('has no status at all until a video exists', function () {
    $card = Card::factory()->create();

    // Every card starts here and most of them stay here for weeks. Absence is
    // not a fifth state.
    expect($card->video_status)->toBeNull()
        ->and($card->hasVideo())->toBeFalse()
        ->and($card->isViewableIn('en'))->toBeFalse();
});

it('is in the viewing room when the video is ready', function () {
    $card = Card::factory()->watchable()->create();

    expect($card->isViewableIn('en'))->toBeTrue()
        ->and(Card::viewableIn('en')->pluck('id'))->toContain($card->id)
        ->and($card->whatTheViewingRoomIsWaitingFor('en'))->toBe([]);
});

it('keeps a card out of the viewing room while its first video is being prepared', function (string $state, string $expected) {
    $card = Card::factory()->{$state}()->create();

    // The state a card sits in for minutes after its first upload, and the one a
    // test about what customers can watch is most likely to forget.
    expect($card->isViewableIn('en'))->toBeFalse()
        ->and(Card::viewableIn('en')->count())->toBe(0)
        ->and($card->whatTheViewingRoomIsWaitingFor('en'))->toContain($expected);
})->with([
    'processing' => ['videoProcessing', 'its video to finish being prepared'],
    'failed' => ['videoFailed', 'its video uploading again, because the last one went wrong'],
]);

it('keeps playing the old video while a replacement is prepared', function () {
    $card = Card::factory()->videoBeingReplaced()->create();

    // The gap this design exists to prevent. She uploaded the wrong file, then
    // the right one, and nobody watching should notice anything at all.
    expect($card->isViewableIn('en'))->toBeTrue()
        ->and(Card::viewableIn('en')->pluck('id'))->toContain($card->id)
        ->and($card->whatTheViewingRoomIsWaitingFor('en'))->toBe([]);
});

it('leaves the old video playing when a replacement fails', function () {
    $card = Card::factory()->videoBeingReplaced()->create(['video_status' => VideoStatus::Failed]);

    // A failed upload must cost her a retry, not a card.
    expect($card->isViewableIn('en'))->toBeTrue()
        ->and($card->video_status)->toBe(VideoStatus::Failed);
});

it('swaps the replacement in only when it is ready', function () {
    $card = Card::factory()->videoBeingReplaced()->create();
    $old = $card->bunny_video_id;
    $new = $card->bunny_incoming_video_id;

    $card->promoteIncomingVideo();

    expect($card->fresh()->bunny_video_id)->toBe($new)
        ->and($card->fresh()->bunny_video_id)->not->toBe($old)
        ->and($card->fresh()->bunny_incoming_video_id)->toBeNull()
        ->and($card->fresh()->video_status)->toBe(VideoStatus::Ready)
        ->and($card->fresh()->isViewableIn('en'))->toBeTrue();
});

it('marks a newly uploaded master as waiting to be sent', function () {
    // Held, because uploading now dispatches the job that sends it, and the
    // queue runs inline in tests. This is the state at the moment of upload.
    Queue::fake();

    $card = Card::factory()->create();

    $card->update(['video_master_path' => 'masters/the-fool.mp4']);

    // However the file arrived, panel or console, a new master is a video
    // nobody has sent to Bunny yet.
    expect($card->fresh()->video_status)->toBe(VideoStatus::Pending)
        ->and($card->fresh()->video_uploaded_at)->not->toBeNull();
});

it('does not overrule a caller who says what the status is', function () {
    $card = Card::factory()->watchable()->create();

    // Which is how a factory builds a card that is already watchable, and how
    // the webhook records a status without looking like a fresh upload.
    expect($card->video_status)->toBe(VideoStatus::Ready);
});

it('says a card needs a video when it has none', function () {
    $card = Card::factory()->create();

    // The only thing the Viewing Room waits for now. A poster frame sat beside
    // it until 7 August 2026, when it left the system entirely.
    expect($card->whatTheViewingRoomIsWaitingFor('en'))->toBe(['a video']);
});

it('will not put a card in the viewing room that is not on the website', function () {
    $unwritten = Card::factory()->watchable()->withoutCopy()->create();
    $unpictured = Card::factory()->watchable()->withoutImage()->create();
    $unpublished = Card::factory()->watchable()->unpublished()->create();

    // A card with a finished video and no copy would otherwise be watchable and
    // absent from the Library, and a language is meant to show everything or
    // nothing.
    expect($unwritten->isViewableIn('en'))->toBeFalse()
        ->and($unpictured->isViewableIn('en'))->toBeFalse()
        ->and($unpublished->isViewableIn('en'))->toBeFalse()
        ->and(Card::viewableIn('en')->count())->toBe(0)
        ->and($unwritten->whatTheViewingRoomIsWaitingFor('en'))
        ->toContain('to be on the website first');
});

it('holds a card out of a language it is not translated into', function () {
    $card = Card::factory()->watchable()->create();

    expect($card->isViewableIn('en'))->toBeTrue()
        ->and($card->isViewableIn('es'))->toBeFalse()
        ->and(Card::viewableIn('es')->count())->toBe(0);
});

it('filters the viewing room in the query and in php identically', function () {
    Card::factory()->watchable()->create();
    Card::factory()->videoProcessing()->create();
    Card::factory()->create();
    Card::factory()->watchable()->withoutCopy()->create();

    // Two ways of asking the same question that disagree is how a video reaches
    // somebody who should not have it.
    $byQuery = Card::viewableIn('en')->pluck('id')->sort()->values();
    $byMethod = Card::all()
        ->filter(fn (Card $card): bool => $card->isViewableIn('en'))
        ->pluck('id')->sort()->values();

    expect($byQuery->all())->toBe($byMethod->all())
        ->and($byQuery)->toHaveCount(1);
});

it('refuses two cards sharing one bunny video', function () {
    Card::factory()->create(['bunny_video_id' => 'the-same-video']);

    // Two cards pointing at one video means an upload went to the wrong card,
    // and the second one silently shows the first one's film.
    expect(fn () => Card::factory()->create(['bunny_video_id' => 'the-same-video']))
        ->toThrow(QueryException::class);
});

it('allows any number of cards with no video yet', function () {
    Card::factory()->count(3)->create();

    // Postgres does not treat nulls as equal, which is the whole reason the
    // unique index above is safe on a nullable column.
    expect(Card::whereNull('bunny_video_id')->count())->toBe(3);
});

it('keeps the master on a different disk from the pictures', function () {
    $card = Card::factory()->watchable()->create();

    // The master is the asset the paywall protects and never gets a public URL,
    // so it must not share a bucket with anything that does.
    expect($card->video_master_path)->toStartWith('masters/')
        ->and(config('filesystems.uploads'))->not->toBe(config('filesystems.media'));
});

it('records when the video was uploaded, as a date', function () {
    $card = Card::factory()->watchable()->create();

    // A timestamp rather than a flag, so support can see when something
    // happened rather than only that it did.
    expect($card->video_uploaded_at)->toBeInstanceOf(CarbonInterface::class);
});
