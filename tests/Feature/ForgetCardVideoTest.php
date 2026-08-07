<?php

use App\Enums\VideoStatus;
use App\Jobs\ForgetCardVideo;
use App\Models\Card;
use App\Services\Bunny\BunnyRequestFailed;
use App\Services\Bunny\BunnyStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.bunny.key', 'a-key');
    config()->set('services.bunny.library_id', '1234');
    config()->set('services.bunny.api_url', 'https://video.bunnycdn.com');
});

/**
 * A card with a film, a master in the bucket, and nothing in flight.
 */
function cardWithAFilm(array $attributes = []): Card
{
    Storage::fake(config('filesystems.uploads'));

    $card = Card::factory()->watchable()->create($attributes);

    Storage::disk(config('filesystems.uploads'))->put($card->video_master_path, 'a film');

    return $card;
}

it('clears every video column when the film is removed', function () {
    Queue::fake();

    $card = cardWithAFilm();

    $card->update(['video_master_path' => null]);

    $card->refresh();

    // The symptom that started this. The row kept its status, so the list went
    // on saying "Ready to watch" about a card with nothing to watch, and it kept
    // its Bunny id, so the Viewing Room went on serving the film.
    expect($card->video_status)->toBeNull()
        ->and($card->bunny_video_id)->toBeNull()
        ->and($card->bunny_incoming_video_id)->toBeNull()
        ->and($card->video_master_path)->toBeNull()
        ->and($card->video_uploaded_at)->toBeNull()
        ->and($card->hasVideo())->toBeFalse()
        ->and($card->isViewableIn('en'))->toBeFalse()
        ->and($card->whatTheViewingRoomIsWaitingFor('en'))->toBe(['a video']);
});

it('leaves the card on the website when its film is removed', function () {
    Queue::fake();

    $card = cardWithAFilm();

    $card->update(['video_master_path' => null]);

    // Being on the website is copy, a picture and being published. No part of it
    // reads the video, so removing a film cannot take a card off the site, and
    // the panel's column is worked out per render rather than stored.
    expect($card->refresh()->isAvailableIn('en'))->toBeTrue()
        ->and($card->image_path)->not->toBeNull();
});

it('hands the film and the master to the queue rather than the request', function () {
    Queue::fake();

    $card = cardWithAFilm();
    $video = $card->bunny_video_id;
    $master = $card->video_master_path;

    $card->update(['video_master_path' => null]);

    // She saves an admin screen and is not left waiting on Bunny, which is the
    // same choice the upload path makes for the same reason.
    Queue::assertPushed(ForgetCardVideo::class, function (ForgetCardVideo $job) use ($video, $master, $card): bool {
        return $job->videoIds === [$video, null]
            && $job->masterPath === $master
            && $job->cardId === $card->id;
    });
});

it('throws away the incoming video too when a film is removed mid-transcode', function () {
    Queue::fake();

    $card = cardWithAFilm();
    $card->update(['bunny_incoming_video_id' => 'the-replacement', 'video_status' => VideoStatus::Processing]);

    $card->update(['video_master_path' => null]);

    // She uploaded a replacement, changed her mind, and removed the lot. Both
    // films are hers to lose and neither should stay in the library.
    Queue::assertPushed(ForgetCardVideo::class, function (ForgetCardVideo $job): bool {
        return in_array('the-replacement', $job->videoIds, strict: true);
    });
});

it('queues nothing when a card that never had a video is saved', function () {
    Queue::fake();

    $card = Card::factory()->create();

    $card->update(['name' => 'The Fool, renamed']);
    $card->update(['video_master_path' => null]);

    // Nothing to throw away is not a job worth queueing, and every ordinary edit
    // to every card would otherwise put one on the queue.
    Queue::assertNotPushed(ForgetCardVideo::class);
});

it('deletes the film from bunny and the master from the bucket', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    Storage::fake(config('filesystems.uploads'));
    Storage::disk(config('filesystems.uploads'))->put('masters/the-fool.mp4', 'a film');

    (new ForgetCardVideo(['the-film'], 'masters/the-fool.mp4', 1))->handle(app(BunnyStream::class));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'videos/the-film'));

    Storage::disk(config('filesystems.uploads'))->assertMissing('masters/the-fool.mp4');
});

it('is safe to run twice, because the queue delivers at least once', function () {
    // The second run finds a video Bunny has already deleted. That is the
    // outcome being asked for, not a failure, so it must not throw.
    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
    Storage::fake(config('filesystems.uploads'));

    $job = new ForgetCardVideo(['the-film'], 'masters/gone.mp4', 1);

    $job->handle(app(BunnyStream::class));

    expect(fn () => $job->handle(app(BunnyStream::class)))->not->toThrow(BunnyRequestFailed::class);
});

it('says which video and which file were left behind when it gives up', function () {
    // Anything other than a 404 is a real refusal. There is no state to correct,
    // and what survives is a film and a file somebody has to remove by hand, so
    // the failure has to name them.
    Http::fake(['*' => Http::response('no', 500)]);
    Storage::fake(config('filesystems.uploads'));
    Storage::disk(config('filesystems.uploads'))->put('masters/the-fool.mp4', 'a film');

    $job = new ForgetCardVideo(['the-film'], 'masters/the-fool.mp4', 7);

    expect(fn () => $job->handle(app(BunnyStream::class)))->toThrow(BunnyRequestFailed::class);

    // Bunny is asked first on purpose: the master is the only copy that is not
    // on her laptop, so a run that is going to fail fails before it is gone.
    Storage::disk(config('filesystems.uploads'))->assertExists('masters/the-fool.mp4');
});

it('deletes a master for a card whose video never reached bunny', function () {
    Http::fake();
    Storage::fake(config('filesystems.uploads'));
    Storage::disk(config('filesystems.uploads'))->put('masters/the-fool.mp4', 'a film');

    (new ForgetCardVideo([], 'masters/the-fool.mp4', 1))->handle(app(BunnyStream::class));

    Storage::disk(config('filesystems.uploads'))->assertMissing('masters/the-fool.mp4');
    Http::assertNothingSent();
});

it('throws away the master a replacement supersedes, and neither video', function () {
    Queue::fake();

    $card = cardWithAFilm();
    $video = $card->bunny_video_id;
    $old = $card->video_master_path;

    $card->update(['video_master_path' => 'masters/a-better-take.mp4']);

    // Filament clears the path and leaves the object, so every replacement
    // before this leaked a master. The film is a different matter: it keeps
    // playing until the replacement is ready, and promotion is what retires it.
    Queue::assertPushed(ForgetCardVideo::class, function (ForgetCardVideo $job) use ($old): bool {
        return $job->masterPath === $old && $job->videoIds === [];
    });

    expect($card->refresh()->bunny_video_id)->toBe($video)
        ->and($card->isViewableIn('en'))->toBeTrue();
});

it('throws away the film a promotion supersedes', function () {
    Queue::fake();

    $card = Card::factory()->videoBeingReplaced()->create();
    $superseded = $card->bunny_video_id;
    $replacement = $card->bunny_incoming_video_id;

    $card->promoteIncomingVideo();

    // The leak this closes: the id used to be dropped on the floor, so every
    // replacement left a film in the library that nothing pointed at.
    Queue::assertPushed(ForgetCardVideo::class, function (ForgetCardVideo $job) use ($superseded): bool {
        return $job->videoIds === [$superseded] && $job->masterPath === null;
    });

    // The order is the load-bearing part. The row points at its replacement
    // before anything exists to delete the old one, so a job running the instant
    // it is queued cannot take out a film the card is still using.
    expect($card->refresh()->bunny_video_id)->toBe($replacement);
});

it('throws nothing away when a first video is promoted', function () {
    Queue::fake();

    $card = Card::factory()->videoProcessing()->create();

    $card->promoteIncomingVideo();

    // Most promotions. There was no film before it, so there is nothing behind
    // it to clean up.
    Queue::assertNotPushed(ForgetCardVideo::class);

    expect($card->refresh()->isViewableIn('en'))->toBeTrue();
});
