<?php

use App\Enums\VideoStatus;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Jobs\ForgetCardVideo;
use App\Jobs\SendCardVideoToBunny;
use App\Models\Admin;
use App\Models\Card;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Issue #120: a card's film uploaded straight from the Cards list.
 *
 * The action only saves a path. Everything after that is the card's own hooks,
 * so these tests check the action reaches them rather than re-testing Bunny.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');

    // Held, or the inline queue sends the film to Bunny before the assertion.
    Queue::fake();
    Storage::fake(config('filesystems.uploads'));
});

function aFilm(string $name = 'the-fool.mp4', int $kilobytes = 100, string $mime = 'video/mp4'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

it('adds a first video from the list and sends it to be prepared', function () {
    $card = Card::factory()->create();

    Livewire::test(ListCards::class)
        ->assertTableActionHasLabel('uploadVideo', 'Add video', $card)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm()]])
        ->assertHasNoTableActionErrors();

    $card->refresh();

    Storage::disk(config('filesystems.uploads'))->assertExists($card->video_master_path);

    expect($card->video_master_path)->toStartWith('masters/')
        ->and($card->video_status)->toBe(VideoStatus::Pending)
        ->and($card->video_uploaded_at)->not->toBeNull();

    Queue::assertPushed(SendCardVideoToBunny::class, fn (SendCardVideoToBunny $job): bool => $job->card->is($card));
});

it('replaces a ready video without touching the one customers are watching', function () {
    $card = Card::factory()->watchable()->create();
    $live = $card->bunny_video_id;
    $oldMaster = $card->video_master_path;

    Livewire::test(ListCards::class)
        ->assertTableActionHasLabel('uploadVideo', 'Replace video', $card)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm('the-fool-v2.mp4')]])
        ->assertHasNoTableActionErrors();

    $card->refresh();

    // The live film keeps playing until the replacement is ready.
    expect($card->bunny_video_id)->toBe($live)
        ->and($card->isPlayable())->toBeTrue()
        ->and($card->video_master_path)->not->toBe($oldMaster)
        ->and($card->video_status)->toBe(VideoStatus::Pending);

    Queue::assertPushed(SendCardVideoToBunny::class);

    // The trap in CHECKPOINT: a file field leaves the master it replaces in the
    // bucket. The card's hook is what throws it away, and the action reaches it.
    Queue::assertPushed(ForgetCardVideo::class, fn (ForgetCardVideo $job): bool => $job->masterPath === $oldMaster
        && $job->videoIds === []);
});

it('can replace a video while the last one is still being prepared', function () {
    // Nothing waits on a job: a card mid-transcode can be given another film.
    $card = Card::factory()->videoProcessing()->create();

    Livewire::test(ListCards::class)
        ->assertTableActionVisible('uploadVideo', $card)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm()]])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(SendCardVideoToBunny::class);
});

it('refuses a file that is not a video, with the form\'s message', function () {
    $card = Card::factory()->create();

    Livewire::test(ListCards::class)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm('the-fool.pdf', 100, 'application/pdf')]])
        ->assertHasTableActionErrors(['video_master_path' => 'That is not a video file. An MP4 or a QuickTime file is expected.']);

    expect($card->refresh()->video_master_path)->toBeNull();
    Queue::assertNothingPushed();
});

it('refuses a video over 200MB, with the form\'s message', function () {
    $card = Card::factory()->create();

    Livewire::test(ListCards::class)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm(kilobytes: 204801)]])
        ->assertHasTableActionErrors(['video_master_path' => 'That file is too large. Videos should be under 200MB.']);

    expect($card->refresh()->video_master_path)->toBeNull();
    Queue::assertNothingPushed();
});

it('will not upload nothing, so the list can never remove a video', function () {
    $card = Card::factory()->watchable()->create();
    $master = $card->video_master_path;

    Livewire::test(ListCards::class)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => []])
        ->assertHasTableActionErrors(['video_master_path' => 'required']);

    expect($card->refresh()->video_master_path)->toBe($master);
    Queue::assertNothingPushed();
});

it('never offers a removed card a new video', function () {
    $card = Card::factory()->create();
    $card->delete();

    // Removed cards are not listed, so Filament cannot find the row to act on.
    // The action's own `hidden()` is the second guard behind that one.
    expect(fn () => Livewire::test(ListCards::class)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm()]]))
        ->toThrow(ActionNotResolvableException::class);

    expect($card->refresh()->video_master_path)->toBeNull();
    Queue::assertNothingPushed();
});

it('keeps the list refreshing only while a film is on its way', function () {
    Card::factory()->watchable()->create();

    Livewire::test(ListCards::class)->assertDontSeeHtml('wire:poll');

    Card::factory()->videoProcessing()->create();

    Livewire::test(ListCards::class)->assertSeeHtml('wire:poll.10s');
});

it('lets a 40MB film through to the field', function () {
    // Livewire's default limit is 12MB, which refused every real film before
    // the field's own 200MB rule was reached.
    $card = Card::factory()->create();

    Livewire::test(ListCards::class)
        ->callTableAction('uploadVideo', $card, data: ['video_master_path' => [aFilm(kilobytes: 40 * 1024)]])
        ->assertHasNoTableActionErrors();

    expect($card->refresh()->video_status)->toBe(VideoStatus::Pending);
});
