<?php

use App\Actions\MarkOrderPaid;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Mail\OrderPaid;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

function pendingOrder(?Customer $customer = null, ?Product $product = null): Order
{
    return Order::factory()
        ->for($customer ?? Customer::factory()->create())
        ->forProduct($product ?? Product::factory()->create())
        ->create();
}

it('lists orders, newest first', function () {
    $older = pendingOrder();
    $this->travel(1)->hour();
    $newer = pendingOrder();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('shows what somebody asked, since that is what the reading answers', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->create(), 1, 'Will the move be right for me?')
        ->create();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSee('Will the move be right for me?');
});

/*
| Marking an order paid by hand.
|
| The same transition a card payment will use, so the two cannot come to mean
| different things. This is also what makes a real pass holder without a
| payment processor, which is what the Viewing Room has never been walked
| against on staging.
*/

it('grants the pass when Jennifer marks a pass order paid', function () {
    $customer = Customer::factory()->create();
    $order = pendingOrder($customer, Product::factory()->pass()->create());

    Livewire::test(ListOrders::class)
        ->callTableAction('markAsPaid', $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($customer->fresh()->hasViewingRoomAccess())->toBeTrue();

    Mail::assertQueued(OrderPaid::class);
});

it('does not grant twice when it is marked paid twice', function () {
    $customer = Customer::factory()->create();
    $order = pendingOrder($customer, Product::factory()->pass()->create());

    Livewire::test(ListOrders::class)->callTableAction('markAsPaid', $order);

    $firstTime = $customer->fresh()->viewing_room_access_at;

    $this->travel(1)->day();

    // The action hides itself once an order is paid, so this is the belt and
    // braces underneath: even called directly, the transition happens once.
    app(MarkOrderPaid::class)($order->refresh());

    expect($customer->fresh()->viewing_room_access_at->eq($firstTime))->toBeTrue();

    Mail::assertQueued(OrderPaid::class, 1);
});

it('offers marking as paid only while an order is waiting for money', function () {
    $pending = pendingOrder();
    $paid = Order::factory()->paid()->forProduct(Product::factory()->create())->create();
    $cancelled = Order::factory()->cancelled()->forProduct(Product::factory()->create())->create();

    Livewire::test(ListOrders::class)
        ->assertTableActionVisible('markAsPaid', record: $pending)
        ->assertTableActionHidden('markAsPaid', record: $paid)
        ->assertTableActionHidden('markAsPaid', record: $cancelled);
});

/*
| Marking a reading as sent.
*/

it('records when Jennifer says the reading has gone', function () {
    $order = Order::factory()->paid()->forProduct(Product::factory()->create())->create();

    Livewire::test(ListOrders::class)
        ->callTableAction('markAsSent', $order);

    expect($order->refresh()->fulfilled_at)->not->toBeNull()
        ->and($order->isFulfilled())->toBeTrue();
});

it('offers marking as sent only on something paid for and not yet sent', function () {
    $pending = pendingOrder();
    $paid = Order::factory()->paid()->forProduct(Product::factory()->create())->create();
    $sent = Order::factory()->fulfilled()->forProduct(Product::factory()->create())->create();

    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('markAsSent', record: $pending)
        ->assertTableActionVisible('markAsSent', record: $paid)
        ->assertTableActionHidden('markAsSent', record: $sent);
});

/*
| What the panel will not do.
*/

it('cannot create, edit or delete an order', function () {
    $order = pendingOrder();

    expect(OrderResource::canCreate())->toBeFalse()
        ->and(OrderResource::canEdit($order))->toBeFalse()
        // An order is the record of a payment. Deleting one loses the only
        // account of money that changed hands.
        ->and(OrderResource::canDelete($order))->toBeFalse()
        ->and(OrderResource::canDeleteAny())->toBeFalse();
});

it('counts what is still owed in the sidebar', function () {
    Order::factory()->paid()->forProduct(Product::factory()->create())->create();
    Order::factory()->paid()->forProduct(Product::factory()->create())->create();
    Order::factory()->fulfilled()->forProduct(Product::factory()->create())->create();
    pendingOrder();

    // Paid and not yet sent. Not "all orders", which is a number that only
    // grows and tells her nothing.
    expect(OrderResource::getNavigationBadge())->toBe('2');
});

it('shows no badge when nothing is waiting', function () {
    Order::factory()->fulfilled()->forProduct(Product::factory()->create())->create();

    expect(OrderResource::getNavigationBadge())->toBeNull();
});

it('keeps orders out of reach of anybody who is not an admin', function () {
    auth('admin')->logout();

    $this->get('/admin/orders')->assertRedirect('/admin/login');
});
