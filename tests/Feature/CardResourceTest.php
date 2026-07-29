<?php

use App\Enums\VideoStatus;
use App\Filament\Resources\Cards\CardResource;
use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Cards\Pages\EditCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Models\Admin;
use App\Models\Card;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
});

it('adds a card through the panel', function () {
    // The one thing she creates rather than edits, and a binding commitment to
    // people who already paid.
    Livewire::test(CreateCard::class)
        ->fillForm([
            'number' => 22,
            'name' => 'The Comet',
            'short_description' => 'A new one.',
            'long_description' => 'The longer meaning.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $card = Card::firstWhere('number', 22);

    expect($card)->not->toBeNull()
        ->and($card->getTranslation('name', 'en'))->toBe('The Comet')
        // Written but not pictured, so not on the website yet.
        ->and($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->whatIsMissing('en'))->toBe(['a picture']);
});

it('puts a card on the website once its picture is uploaded', function () {
    Storage::fake(config('filesystems.media'));

    $card = Card::factory()->withoutImage()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['image_path' => [UploadedFile::fake()->image('the-fool.jpg')]])
        ->call('save')
        ->assertHasNoFormErrors();

    $card->refresh();

    expect($card->hasImage())->toBeTrue()
        ->and($card->isAvailableIn('en'))->toBeTrue();

    Storage::disk(config('filesystems.media'))->assertExists($card->image_path);
});

it('says a card is waiting for its picture', function () {
    $card = Card::factory()->withoutImage()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('Not on the website yet')
        ->assertSee('a picture');
});

it('keeps the picture out of the language tabs', function () {
    $card = Card::factory()->create(['image_path' => 'cards/the-fool.jpg']);

    // The same artwork in every language, so it is uploaded once, on the
    // original. It is also the only safe arrangement: a file field on a
    // translation tab comes back empty from the language switcher, and saving
    // then deleted the picture.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldExists('image_path')
        ->set('activeLocale', 'es')
        ->assertFormFieldDoesNotExist('image_path')
        ->fillForm(['name' => 'El Loco'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->image_path)->toBe('cards/the-fool.jpg')
        ->and($card->getTranslation('name', 'es'))->toBe('El Loco');
});

it('will not add a card with no name', function () {
    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 22, 'name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Card::count())->toBe(0);
});

it('will not add a second card with the same number', function () {
    Card::factory()->create(['number' => 3]);

    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 3, 'name' => 'The Empress'])
        ->call('create')
        ->assertHasFormErrors(['number']);

    expect(Card::where('number', 3)->count())->toBe(1);
});

it('lets a removed card give its number back', function () {
    Card::factory()->create(['number' => 3])->delete();

    // The panel must not refuse what the database allows. She removes a card
    // added by mistake and adds it again, which is the only way out of that
    // mistake she has.
    Livewire::test(CreateCard::class)
        ->fillForm(['number' => 3, 'name' => 'The Empress'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Card::where('number', 3)->count())->toBe(1);
});

it('saves an edit through the panel', function () {
    $card = Card::factory()->create(['name' => ['en' => 'Old Name']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['name' => 'The Fool'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->getTranslation('name', 'en'))->toBe('The Fool');
});

it('persists a translation against the active language', function () {
    $card = Card::factory()->create(['name' => ['en' => 'The Fool']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->fillForm(['name' => 'El Loco'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The English value must survive editing the Spanish one.
    expect($card->refresh()->getTranslation('name', 'es'))->toBe('El Loco')
        ->and($card->getTranslation('name', 'en'))->toBe('The Fool');
});

it('allows a translation to be saved one field at a time', function () {
    $card = Card::factory()->create();

    // Translating is incremental. Completeness is the gate on going live, not
    // on saving.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->fillForm(['name' => 'El Loco', 'short_description' => '', 'long_description' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->getTranslation('name', 'es'))->toBe('El Loco');
});

it('switches language back and forth without complaining', function () {
    $card = Card::factory()->create();

    // A field level `required` fires here, because the switcher validates the
    // tab it is leaving against the language it is arriving at. The name is
    // required when adding a card, and never on a translation.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->set('activeLocale', 'es')
        ->assertHasNoErrors()
        ->set('activeLocale', 'en')
        ->assertHasNoErrors()
        ->set('activeLocale', 'es')
        ->assertHasNoErrors();
});

it('refuses to delete copy that already exists in the original language', function () {
    $card = Card::factory()->create(['name' => ['en' => 'The Fool']]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($card->refresh()->getTranslation('name', 'en'))->toBe('The Fool');
});

it('says what a card is still waiting for', function () {
    $card = Card::factory()->create([
        'name' => ['en' => 'The Fool'],
        'short_description' => [],
        'long_description' => [],
    ]);

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('Not on the website yet')
        ->assertSee('a short description')
        ->assertSee('a full description');
});

it('says a finished card is on the website', function () {
    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('On the website');
});

it('removes a card without losing the row', function () {
    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->callAction('delete');

    // M4 attaches a video to the row, so it is kept rather than dropped.
    expect(Card::find($card->id))->toBeNull()
        ->and(Card::withTrashed()->find($card->id))->not->toBeNull();
});

it('uploads a video to the private disk and marks it waiting to be sent', function () {
    Storage::fake(config('filesystems.media'));
    Storage::fake(config('filesystems.uploads'));

    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['video_master_path' => [UploadedFile::fake()->create('the-fool.mp4', 100, 'video/mp4')]])
        ->call('save')
        ->assertHasNoFormErrors();

    $card->refresh();

    // The master goes where the public cannot reach it, and it is not on the
    // pictures disk.
    Storage::disk(config('filesystems.uploads'))->assertExists($card->video_master_path);
    Storage::disk(config('filesystems.media'))->assertMissing($card->video_master_path);

    expect($card->video_status)->toBe(VideoStatus::Pending)
        ->and($card->video_uploaded_at)->not->toBeNull()
        ->and($card->isViewableIn('en'))->toBeFalse();
});

it('uploads a poster to the public disk', function () {
    Storage::fake(config('filesystems.media'));
    Storage::fake(config('filesystems.uploads'));

    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->fillForm(['poster_path' => [UploadedFile::fake()->image('the-fool.jpg')]])
        ->call('save')
        ->assertHasNoFormErrors();

    // A still rather than the thing the paywall protects, so it sits with the
    // pictures and needs no signing.
    Storage::disk(config('filesystems.media'))->assertExists($card->refresh()->poster_path);
});

it('keeps the video and poster fields off the language tabs', function () {
    $card = Card::factory()->watchable()->create();
    $master = $card->video_master_path;

    // The trap that already cost a card picture once: a file field on a
    // translation tab comes back empty and the save deletes the file.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldExists('video_master_path')
        ->set('activeLocale', 'es')
        ->assertFormFieldDoesNotExist('video_master_path')
        ->assertFormFieldDoesNotExist('poster_path')
        ->fillForm(['name' => 'El Loco'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->video_master_path)->toBe($master)
        ->and($card->poster_path)->not->toBeNull();
});

it('says what the viewing room is waiting for', function () {
    $card = Card::factory()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('Not in the Viewing Room yet')
        ->assertSee('a video');
});

it('says a finished card is in the viewing room', function () {
    $card = Card::factory()->watchable()->create();

    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertSee('In the Viewing Room too');
});

it('shows the video status in the list', function () {
    Card::factory()->videoProcessing()->create();

    // Being prepared and having failed look identical unless the list says
    // which, and one of them needs her to do something.
    Livewire::test(ListCards::class)
        ->assertSee('Being prepared');
});

it('offers no switch for taking a card off the website', function () {
    $card = Card::factory()->create();

    // Availability is calculated from the copy. A switch would be a second
    // answer to the same question.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldDoesNotExist('is_published');
});

it('offers no web address field', function () {
    $card = Card::factory()->create();

    // A card is a pop-up, not a page, so there is no link to keep stable.
    Livewire::test(EditCard::class, ['record' => $card->getRouteKey()])
        ->assertFormFieldDoesNotExist('slug');
});

it('lists cards in deck order', function () {
    Card::factory()->create(['number' => 5]);
    Card::factory()->create(['number' => 1]);

    Livewire::test(ListCards::class)
        ->assertCanSeeTableRecords(Card::orderBy('number')->get(), inOrder: true);
});

it('drives the resource translatable locales from the registry', function () {
    expect(CardResource::getTranslatableLocales())->toBe(Locales::codes());
});
