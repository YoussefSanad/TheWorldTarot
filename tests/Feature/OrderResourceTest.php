<?php

use App\Actions\MarkOrderFulfilled;
use App\Actions\MarkOrderPaid;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
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

it('no longer shows what somebody asked, because that is the readings screen', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->create(), 1, 'Will the move be right for me?')
        ->create();

    // **Orders is money and a row is a purchase**, ADR 0006. The question moved
    // to Readings with a detail page of its own, because a gift bought in
    // September and redeemed in December makes "what money came in" and "what
    // work is owed" two different answers about one row.
    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertDontSee('Will the move be right for me?');
});

it('names a product with nothing left in its name, and names it the way the receipt does', function () {
    $order = Order::factory()->forProduct(aProductWithNoNameLeft())->create();

    // **The panel half of the assertion `MailBodyTest` makes about the same
    // fixture.** Both sides call `OrderLine::displayName()` now, so they cannot
    // drift, and both are pinned to the one string the client signed off rather
    // than to each other. `three-card` is the URL slug this column used to fall
    // back to, which is the disagreement that started this.
    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSee('3 CARD READING')
        ->assertDontSee('three-card');
});

/*
| Marking an order paid, which this screen no longer does.
|
| ~~Jennifer could mark a pending order paid by hand.~~ Struck through 22
| October 2026: money is confirmed by Stripe and nobody else, so an order is
| paid by the webhook or the sweep reaching `MarkOrderPaid`, never by a button.
*/

it('offers no way to mark an order paid', function () {
    $pending = pendingOrder();

    $component = Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertTableActionDoesNotExist('markAsPaid', record: $pending);

    // The name alone proves little, since the same button under another name
    // would pass it. The screen offers no per-row action of any kind.
    expect($component->instance()->getTable()->getRecordActions())->toBeEmpty();
});

/*
| Marking an order fulfilled, which this screen no longer does.
*/

it('no longer offers marking an order fulfilled', function () {
    $paid = Order::factory()->paid()->forProduct(Product::factory()->create())->create();

    // **She delivers a reading, not an order.** The action moved to Readings
    // and stamps `readings.delivered_at`; an order becoming fulfilled follows
    // from every reading on it having gone rather than from a button here.
    // `fulfilled_at` stays a column and stops being something anybody sets.
    Livewire::test(ListOrders::class)
        ->assertTableActionDoesNotExist('markAsFulfilled', record: $paid);
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

it('carries no badge, because readings waiting is the number she needs', function () {
    Order::factory()->paid()->forProduct(Product::factory()->create())->create();
    Order::factory()->paid()->forProduct(Product::factory()->create())->create();

    // The badge moved to Readings. Orders paid is not a number anybody acts on,
    // and a badge counting it would be permanently lit for orders that are
    // finished as far as this screen is concerned.
    expect(OrderResource::getNavigationBadge())->toBeNull();
});

it('keeps orders out of reach of anybody who is not an admin', function () {
    auth('admin')->logout();

    $this->get('/admin/orders')->assertRedirect('/admin/login');
});

/*
| One writer for an order being delivered.
|
| The column had two, one at payment and one on this screen, agreeing only
| because both wrote `now()`. What proves there is one now is not that they
| write the same date, which is exactly the agreement by hand that was the
| problem. It is that replacing the writer moves both.
*/

it('sends the payment path through the one writer', function () {
    // A date nobody arrives at by accident, from a writer bound over the real
    // one. The caller has to produce it, which is only true if it stamps no
    // date of its own.
    app()->bind(MarkOrderFulfilled::class, fn (): MarkOrderFulfilled => new class extends MarkOrderFulfilled
    {
        public function __invoke(Order $order): bool
        {
            $order->forceFill(['fulfilled_at' => Date::parse('2001-01-01 00:00:00')])->save();

            return true;
        }
    });

    // Nothing on it needs a person, so the money landing is what delivers it.
    $atPayment = pendingOrder(product: Product::factory()->pass()->create());
    app(MarkOrderPaid::class)($atPayment, paidBy($atPayment));

    // **The other half of this test moved to ReadingResourceTest**, which is
    // where the by-hand caller lives now. Both are still one writer and the
    // pair is still asserted, one file each.
    expect($atPayment->refresh()->fulfilled_at?->toDateString())->toBe('2001-01-01');
});

it('leaves the first date alone when an order is fulfilled again', function () {
    $order = Order::factory()->fulfilled()->forProduct(Product::factory()->create())->create();
    $first = $order->fulfilled_at;

    $this->travel(2)->days();

    // The panel hides the action on something already fulfilled, so this is the
    // writer being asked directly, which is what phase B's payment path will do.
    expect(app(MarkOrderFulfilled::class)($order))->toBeFalse()
        ->and($order->refresh()->fulfilled_at->eq($first))->toBeTrue();
});
