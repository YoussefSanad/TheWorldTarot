<?php

use App\Enums\LocaleStatus;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();

    config()->set('services.bunny', [
        'key' => 'an-api-key',
        'library_id' => '12345',
        'token_key' => 'a-token-key',
        'cdn_hostname' => 'vz-example.b-cdn.net',
        'api_url' => 'https://video.bunnycdn.com',
        'upload_timeout' => 300,
        'playback_ttl' => 7200,
    ]);
});

function passHolder(): Customer
{
    return Customer::factory()->withViewingRoomAccess()->create();
}

it('gives a pass holder a signed url that expires', function () {
    $card = Card::factory()->watchable()->create();

    $response = $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk();

    expect($response->json('card_id'))->toBe($card->id)
        ->and($response->json('url'))->toContain($card->bunny_video_id)
        ->and($response->json('url'))->toContain('token=')
        ->and($response->json('url'))->toContain('expires=')
        ->and($response->json('expires_at'))->not->toBeNull()
        // Two hours, so nobody is cut off mid-video and a copied link is
        // worthless by the evening.
        ->and(now()->diffInMinutes($response->json('expires_at')))->toEqualWithDelta(120, 1);
});

it('refuses anybody who is not signed in', function () {
    $card = Card::factory()->watchable()->create();

    // The audit finding, in one assertion: no account, no payment, no video.
    $this->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertUnauthorized();
});

it('refuses a signed in customer who has not bought the pass', function () {
    $card = Card::factory()->watchable()->create();

    // 403 rather than 404: the Viewing Room is advertised, and hiding it would
    // only confuse somebody who paid and is signed in as the wrong person.
    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertForbidden();
});

it('decides on the server, whatever the browser was told', function () {
    $card = Card::factory()->watchable()->create();
    $customer = Customer::factory()->create();

    // Access control is enforced here, always. Never rely on the frontend not
    // rendering a link, per .claude/api.md.
    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertForbidden();

    $customer->grantViewingRoomAccess();

    $this->actingAs($customer->fresh(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk();
});

it('has nothing to serve for a card whose video is not ready', function (string $state) {
    $card = Card::factory()->{$state}()->create();

    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertNotFound();
})->with([
    'still being prepared' => 'videoProcessing',
    'the upload failed' => 'videoFailed',
]);

it('has nothing to serve for a card nobody has filmed', function () {
    // The state twenty-two cards are in right now, and the one the parameters
    // above cannot express, since it is the absence of every video state.
    $card = Card::factory()->create();

    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertNotFound();
});

it('has nothing to serve for a card that is not on the website', function () {
    $unpublished = Card::factory()->watchable()->unpublished()->create();
    $unwritten = Card::factory()->watchable()->withoutCopy()->create();

    // Being watchable is built on being in the Library, so a card nobody can
    // read is a card nobody can watch either.
    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$unpublished->id}/video")
        ->assertNotFound();

    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$unwritten->id}/video")
        ->assertNotFound();
});

it('is a 404 for a card that does not exist', function () {
    $this->actingAs(passHolder(), 'sanctum')
        ->getJson('/api/v1/en/cards/9999/video')
        ->assertNotFound();
});

it('serves nothing in a language that is not public', function () {
    $card = Card::factory()->watchable()->create();

    // Spanish is a draft. A pass buys access to the Viewing Room, not to a
    // language that is not finished.
    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/es/cards/{$card->id}/video")
        ->assertNotFound();
});

it('serves nothing for a card that is not translated into the language asked for', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();

    $card = Card::factory()->watchable()->create();

    $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/es/cards/{$card->id}/video")
        ->assertNotFound();
});

it('refuses to be cached, since the answer is a credential', function () {
    $card = Card::factory()->watchable()->create();

    $response = $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk();

    // A CDN or a service worker holding this turns two hours of exposure into
    // however long it keeps it.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('never says where the file actually lives', function () {
    $card = Card::factory()->watchable()->create();

    $response = $this->actingAs(passHolder(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk();

    // The signed URL is the whole of what a player needs. The master's path and
    // the private bucket are nobody's business.
    expect($response->json())->toHaveKeys(['card_id', 'url', 'expires_at'])
        ->and($response->json())->not->toHaveKey('video_master_path')
        ->and($response->json('url'))->not->toContain($card->video_master_path);
});

it('works under a bearer token as well as a session', function () {
    $card = Card::factory()->watchable()->create();

    // Both paths are configured from day one, so any protected route has to
    // work under both. Native apps are a stated direction.
    Sanctum::actingAs(passHolder());

    $this->getJson("/api/v1/en/cards/{$card->id}/video")->assertOk();
});

it('signs each card differently', function () {
    $first = Card::factory()->watchable()->create();
    $second = Card::factory()->watchable()->create();
    $customer = passHolder();

    $tokenFor = fn (Card $card): string => (string) str(
        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/en/cards/{$card->id}/video")->json('url')
    )->after('token=')->before('&');

    // A token that worked for another card would make one purchase a key to
    // every film.
    expect($tokenFor($first))->not->toBe($tokenFor($second));
});

it('does not let an admin session into the viewing room', function () {
    $card = Card::factory()->watchable()->create();

    // The admin guard is for the panel. Being able to run the site is not the
    // same as having bought the pass, and the two guards must not blur.
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertUnauthorized();
});
