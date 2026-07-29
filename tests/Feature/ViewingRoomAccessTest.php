<?php

use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has nobody in the viewing room by default', function () {
    $customer = Customer::factory()->create();

    // The state that matters most. Everything about the paywall depends on this
    // being the default rather than something that has to be switched off.
    expect($customer->viewing_room_access_at)->toBeNull()
        ->and($customer->hasViewingRoomAccess())->toBeFalse();
});

it('records when somebody was let in, not merely that they were', function () {
    $customer = Customer::factory()->create();

    $customer->grantViewingRoomAccess();

    // A boolean answers whether they may watch. A date also answers when, which
    // is the question support actually gets asked.
    expect($customer->hasViewingRoomAccess())->toBeTrue()
        ->and($customer->viewing_room_access_at)->toBeInstanceOf(CarbonInterface::class)
        ->and(Schema::getColumnType('customers', 'viewing_room_access_at'))->toContain('timestamp');
});

it('keeps the date somebody first got in', function () {
    $customer = Customer::factory()->withViewingRoomAccess()->create();
    $granted = $customer->viewing_room_access_at;

    $this->travel(1)->days();
    $customer->grantViewingRoomAccess();

    // Granting twice happens: a duplicate webhook, support putting an order
    // right. It must not rewrite history.
    expect($customer->fresh()->viewing_room_access_at->timestamp)->toBe($granted->timestamp);
});

it('survives a customer being granted access twice at once', function () {
    $customer = Customer::factory()->create();

    $customer->grantViewingRoomAccess();
    $customer->fresh()->grantViewingRoomAccess();

    expect($customer->fresh()->hasViewingRoomAccess())->toBeTrue();
});

/*
 * Deliberately not tested here: whether the stamp is exposed over the API.
 *
 * It is visible today only through /api/v1/user, which is framework scaffolding
 * and explicitly outside the frontend contract, so asserting either way would be
 * pinning down something that is going to be replaced.
 *
 * The requirement itself is real and belongs to M5: the frontend needs to know
 * whether the signed-in customer holds the pass, or it cannot choose between
 * "enter the Viewing Room" and "buy the pass". What must never happen is access
 * being decided from that field, which is why step 9's endpoint refuses on the
 * server no matter what the browser believes.
 */
