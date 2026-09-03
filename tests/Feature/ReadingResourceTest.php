<?php

use App\Actions\DeliverReading;
use App\Actions\MarkOrderFulfilled;
use App\Enums\ProductKey;
use App\Filament\Resources\Readings\Pages\ListReadings;
use App\Filament\Resources\Readings\Pages\ViewReading;
use App\Filament\Resources\Readings\ReadingResource;
use App\Models\Admin;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reading;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

/**
 * Jennifer's working screen.
 *
 * `docs/adr/0006-one-screen-per-question.md`. Orders answered three questions
 * at once and a gift bought in September and redeemed in December makes those
 * three answers disagree for three months. This is the screen that answers one
 * of them: what work is owed, and what has gone.
 */
function theProduct(ProductKey $key): Product
{
    return Product::firstWhere('key', $key) ?? Product::factory()->create(['key' => $key]);
}

/**
 * A reading somebody is waiting for, on an order that has been paid for.
 *
 * The order is paid because an unpaid one has no reading: `AskForReading` is
 * reached at settlement, and asking before the money arrives is the thing
 * ADR 0004 exists to make impossible.
 */
function aReading(
    ProductKey $key = ProductKey::MonthAhead,
    ?CarbonInterface $askedAt = null,
    ?Order $order = null,
    ?Gift $gift = null,
    string $question = 'Will the move be right for me?',
): Reading {
    $order ??= Order::factory()->paid()->create();
    $product = theProduct($key);

    $line = $order->lines()->create([
        'product_id' => $product->id,
        'product_key' => $product->key,
        'unit_amount' => $product->price_usd,
        'quantity' => 1,
        'question' => $gift === null ? $question : null,
    ]);

    return Reading::factory()->create([
        'order_line_id' => $line->id,
        'gift_id' => $gift?->id,
        'question' => $question,
        'asked_at' => $askedAt ?? now(),
    ]);
}

/*
| The gate. An order holding two readings is fulfilled only when both have gone.
*/

it('fulfills an order only once every reading on it has gone', function () {
    $order = Order::factory()->paid()->create();
    $first = aReading(ProductKey::MonthAhead, order: $order);
    $second = aReading(ProductKey::InDepth, order: $order);

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $first);

    // **Half delivered is not delivered**, which is what MarkOrderFulfilled's
    // docblock has said since it was written. She delivers a reading; the order
    // becoming fulfilled follows from it.
    expect($first->refresh()->isDelivered())->toBeTrue()
        ->and($order->refresh()->isFulfilled())->toBeFalse();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $second);

    expect($order->refresh()->isFulfilled())->toBeTrue();
});

it('does not fulfill an order that still owes a gift nobody has redeemed', function () {
    $order = Order::factory()->paid()->create();
    $reading = aReading(ProductKey::MonthAhead, order: $order);

    $gifted = $order->lines()->create([
        'product_id' => theProduct(ProductKey::ThreeCard)->id,
        'product_key' => ProductKey::ThreeCard,
        'unit_amount' => 5200,
        'quantity' => 1,
        'question' => null,
    ]);
    Gift::factory()->create(['order_line_id' => $gifted->id]);

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $reading);

    // **An order holding an unredeemed gift is not delivered, because it
    // plainly is not.** ADR 0004 says so and this is where it lands. The
    // reading beside it going out is not the order being finished.
    expect($order->refresh()->isFulfilled())->toBeFalse();
});

it('keeps the first date when a reading is delivered twice', function () {
    $reading = aReading();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $reading);
    $first = $reading->refresh()->delivered_at;

    $this->travel(2)->days();

    // The screen hides the action on something already delivered, so this is
    // the writer asked directly, which is what a PDF upload will do later.
    expect(app(DeliverReading::class)($reading))->toBeFalse()
        ->and($reading->refresh()->delivered_at->eq($first))->toBeTrue();
});

/*
| Due, which is computed and never stored.
*/

it('has no column to store a due date in', function () {
    // A stored `due_at` is a second writer for a value that already has one,
    // and it goes stale the day a window changes. ADR 0006 refuses it by name.
    expect(Schema::hasColumn('readings', 'due_at'))->toBeFalse();
});

it('computes due from the product delivery window, so two asked together fall apart', function () {
    $asked = Date::parse('2026-09-03 09:00:00');

    // The same instant, two windows. `month-ahead` promises 24 hours and
    // `in-depth` promises 48, so nothing derived from `asked_at` alone can be
    // right about both.
    $quick = aReading(ProductKey::MonthAhead, askedAt: $asked);
    $slow = aReading(ProductKey::InDepth, askedAt: $asked);

    expect($quick->dueAt()?->toDateTimeString())->toBe('2026-09-04 09:00:00')
        ->and($slow->dueAt()?->toDateTimeString())->toBe('2026-09-05 09:00:00');
});

it('goes overdue on its own window and not on the clock alone', function () {
    $asked = now();
    $quick = aReading(ProductKey::MonthAhead, askedAt: $asked);
    $slow = aReading(ProductKey::InDepth, askedAt: $asked);

    $this->travel(30)->hours();

    // **This is what proves due is derived.** Thirty hours is late for one
    // promise and early for the other, and a stored date written at asking
    // could not tell them apart without being rewritten.
    expect($quick->isOverdue())->toBeTrue()
        ->and($slow->isOverdue())->toBeFalse();
});

it('is never overdue once it has gone', function () {
    $reading = aReading(ProductKey::MonthAhead, askedAt: now());
    $this->travel(30)->hours();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $reading);

    // Overdue is a question about work outstanding. A reading that has gone is
    // not late, however long it took.
    expect($reading->refresh()->isOverdue())->toBeFalse();
});

/*
| The list.
*/

it('lists readings oldest ask first, which is the opposite of orders', function () {
    $older = aReading(askedAt: now()->subDays(2));
    $newer = aReading(askedAt: now());

    // Orders is newest first, because what she opens it to find is whatever
    // just came in. This screen answers the opposite question, and sorting it
    // the same way would bury exactly the row that matters.
    Livewire::test(ListReadings::class)
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

it('sorts by due rather than by when it was asked', function () {
    // Asked first and due last, because it is the reading with the longer
    // promise. Sorting the two lists identically would mean due was `asked_at`
    // wearing a different label.
    $slow = aReading(ProductKey::InDepth, askedAt: Date::parse('2026-09-03 09:00:00'));
    $quick = aReading(ProductKey::MonthAhead, askedAt: Date::parse('2026-09-03 10:00:00'));

    Livewire::test(ListReadings::class)
        ->sortTable('due')
        ->assertCanSeeTableRecords([$quick, $slow], inOrder: true);
});

it('shows the querent, the reading and the first line of the question', function () {
    aReading(ProductKey::MonthAhead, question: "Should I take the job?\nThere is more to it than that.");

    Livewire::test(ListReadings::class)
        ->assertSee('Should I take the job?')

        // The rest of it belongs on the detail page. A question runs to 2000
        // characters and stopped fitting a wrapped table cell.
        ->assertDontSee('There is more to it than that.');
});

it('marks the readings that came from a gift', function () {
    $gift = Gift::factory()->create();
    $gifted = aReading(gift: $gift);
    $bought = aReading();

    Livewire::test(ListReadings::class)
        ->assertCanSeeTableRecords([$gifted, $bought])
        ->assertSee('Gift');
});

it('offers marking delivered only on something still waiting', function () {
    $waiting = aReading();
    $gone = aReading();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $gone);

    Livewire::test(ListReadings::class)
        ->assertTableActionVisible('markDelivered', record: $waiting)
        ->assertTableActionHidden('markDelivered', record: $gone->refresh());
});

/*
| The detail page, which is the first one in this panel.
*/

it('carries the whole question, the signature and the buyer message on its own page', function () {
    $gift = Gift::factory()->create([
        'signature' => 'Mum',
        'message' => 'Thought of you on your birthday.',
    ]);

    $reading = aReading(
        gift: $gift,
        question: "Should I take the job?\nThere is more to it than that, and it runs on.",
    );

    Livewire::test(ViewReading::class, ['record' => $reading->getRouteKey()])
        ->assertSee('Should I take the job?')
        ->assertSee('There is more to it than that, and it runs on.')

        // The two things a gift adds that a purchase does not have. She is
        // writing for somebody she has never heard from, and the signature is
        // who they were given it by.
        ->assertSee('Mum')
        ->assertSee('Thought of you on your birthday.');
});

it('shows the querent address on the detail page, since that is who it goes to', function () {
    $reading = aReading();

    Livewire::test(ViewReading::class, ['record' => $reading->getRouteKey()])
        ->assertSee($reading->querent_email);
});

/*
| The badge, and what the panel will not do.
*/

it('counts readings still waiting in the sidebar', function () {
    aReading();
    aReading();
    $gone = aReading();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $gone);

    // Readings waiting is the number she actually needs. Orders paid is not,
    // which is why the badge moved here.
    expect(ReadingResource::getNavigationBadge())->toBe('2');
});

it('shows no badge when nothing is waiting', function () {
    $gone = aReading();
    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $gone);

    expect(ReadingResource::getNavigationBadge())->toBeNull();
});

it('cannot create, edit or delete a reading', function () {
    $reading = aReading();

    // A reading arrives by somebody asking. A hand made one would be work
    // nobody bought, and an editable one would be a record you can change.
    expect(ReadingResource::canCreate())->toBeFalse()
        ->and(ReadingResource::canEdit($reading))->toBeFalse()
        ->and(ReadingResource::canDelete($reading))->toBeFalse()
        ->and(ReadingResource::canDeleteAny())->toBeFalse();
});

it('keeps readings out of reach of anybody who is not an admin', function () {
    auth('admin')->logout();

    $this->get('/admin/readings')->assertRedirect('/admin/login');
});

/*
| One writer for an order being delivered.
|
| The column had two, one at payment and one in the panel, agreeing only
| because both wrote `now()`. What proves there is one is not that they write
| the same date. It is that replacing the writer moves both.
*/

it('reaches the same writer the payment path reaches', function () {
    app()->bind(MarkOrderFulfilled::class, fn (): MarkOrderFulfilled => new class extends MarkOrderFulfilled
    {
        public function __invoke(Order $order): bool
        {
            $order->forceFill(['fulfilled_at' => Date::parse('2001-01-01 00:00:00')])->save();

            return true;
        }
    });

    $reading = aReading();

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $reading);

    expect($reading->orderLine->order->refresh()->fulfilled_at?->toDateString())->toBe('2001-01-01');
});

/*
| The shape of the action, which is the trap this unit carries.
*/

it('delivers through an action rather than through the screen', function () {
    $reading = aReading();

    // **The button is one adapter on this seam and the PDF upload will be
    // another**, ADR 0006. Replacing the action has to move what the screen
    // does, or the upload arrives as a second writer for `delivered_at` and
    // the pair MarkOrderFulfilled exists to prevent is back one screen along.
    app()->bind(DeliverReading::class, fn (): DeliverReading => new class(app(MarkOrderFulfilled::class)) extends DeliverReading
    {
        public function __invoke(Reading $reading): bool
        {
            $reading->forceFill(['delivered_at' => Date::parse('2001-01-01 00:00:00')])->save();

            return true;
        }
    });

    Livewire::test(ListReadings::class)->callTableAction('markDelivered', $reading);

    expect($reading->refresh()->delivered_at?->toDateString())->toBe('2001-01-01');
});
