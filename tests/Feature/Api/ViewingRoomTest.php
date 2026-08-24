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
        'playback_ttl' => 1800,
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
        // Half an hour, cut from two on 7 August 2026. Long enough for a film
        // and a pause, and short enough to be worth something now the window is
        // the only thing limiting a copied link.
        ->and(now()->diffInMinutes($response->json('expires_at')))->toEqualWithDelta(30, 1);
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

it('answers 403 for every card id, so nobody can map the Viewing Room', function () {
    $watchable = Card::factory()->watchable()->create();
    $processing = Card::factory()->videoProcessing()->create();
    $unpublished = Card::factory()->watchable()->unpublished()->create();

    $customer = Customer::factory()->create();

    // **The pass is asked about before the card is ever looked up, and that
    // order is the whole point.** Looking the card up first would answer 404 for
    // a card with no film and 403 for one that has it, which hands somebody who
    // has not paid a way to enumerate which films exist, one id at a time, from
    // outside the Viewing Room. Every id has to look identical, including one
    // that is not a card at all.
    foreach ([$watchable->id, $processing->id, $unpublished->id, 9999] as $id) {
        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/en/cards/{$id}/video")
            ->assertForbidden();
    }
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

it('limits how fast one pass holder can mint signed urls', function () {
    $card = Card::factory()->watchable()->create();
    $customer = passHolder();

    // **The same limit as the reveal, because it is the same artifact.** A
    // thirty minute signed URL to the same film, and a pass buys the right to
    // watch rather than the right to sign the deck repeatedly. Without this the
    // paid route was the unthrottled one and the free route was not.
    collect(range(1, 30))->each(
        fn () => $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/en/cards/{$card->id}/video")
            ->assertOk()
    );

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertStatus(429);
});

it('counts the viewing room and the reveal in separate buckets', function () {
    $card = Card::factory()->watchable()->create();
    $customer = passHolder();

    // The third argument to throttle is what keeps these apart. Sharing a
    // counter would mean watching films used up somebody else's reveals, which
    // is the defect tests/Staging found once already and this is the route pair
    // most likely to reintroduce it.
    collect(range(1, 30))->each(
        fn () => $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/en/cards/{$card->id}/video")
            ->assertOk()
    );

    $this->getJson('/api/v1/en/cards/draw')->assertOk();
});
