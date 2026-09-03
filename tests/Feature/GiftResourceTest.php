<?php

use App\Enums\ProductKey;
use App\Filament\Resources\Gifts\GiftResource;
use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Readings\ReadingResource;
use App\Mail\GiftToRedeem;
use App\Models\Admin;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

/**
 * The liability screen.
 *
 * `docs/adr/0005-gift-codes-do-not-expire.md` accepts an obligation that never
 * lapses and says the cost of accepting it is that somebody has to be able to
 * see it. This is that screen, and every case below is a way of asking whether
 * it can be seen.
 */
function theGiftedProduct(ProductKey $key): Product
{
    return Product::firstWhere('key', $key) ?? Product::factory()->create(['key' => $key]);
}

/**
 * A present somebody has been sent and has not spent.
 *
 * **Paid by default**, because an unpaid gift order has no code and a codeless
 * gift is not on this screen at all. The unminted case says so itself.
 */
function aGiftOnTheBooks(
    ProductKey $key = ProductKey::MonthAhead,
    ?CarbonInterface $boughtAt = null,
    ?string $code = null,
    ?string $recipient = null,
    ?string $signature = null,
    bool $minted = true,
    bool $redeemed = false,
): Gift {
    $boughtAt ??= now();
    $product = theGiftedProduct($key);

    $order = Order::factory()->paid()->create(['created_at' => $boughtAt, 'paid_at' => $boughtAt]);

    $line = $order->lines()->create([
        'product_id' => $product->id,
        'product_key' => $product->key,
        'unit_amount' => $product->price_usd,
        'quantity' => 1,
        'question' => null,
        'created_at' => $boughtAt,
    ]);

    $factory = Gift::factory();

    if (! $minted) {
        $factory = $factory->unminted();
    }

    if ($redeemed) {
        $factory = $factory->redeemed();
    }

    $overrides = [
        'order_line_id' => $line->id,
        'created_at' => $boughtAt,
        'updated_at' => $boughtAt,
    ];

    // Only what the caller actually named. Passing null for the rest would beat
    // the factory state that set them, so `unminted()` would arrive already
    // overwritten by a code of null being written twice, and the default
    // address and signature would be lost to every caller that did not care.
    foreach (['recipient_email' => $recipient, 'signature' => $signature] as $column => $value) {
        if ($value !== null) {
            $overrides[$column] = $value;
        }
    }

    if ($minted && $code !== null) {
        $overrides['code'] = $code;
    }

    return $factory->create($overrides);
}

/*
| The gate. It opens on outstanding, it says how long, and it resends.
*/

it('opens on what is still outstanding and leaves the spent ones off', function () {
    $outstanding = aGiftOnTheBooks();
    $spent = aGiftOnTheBooks(redeemed: true);

    // **Defaulting to outstanding is the whole point of the screen.** A
    // redeemed gift is an obligation discharged, and a screen that opens on
    // everything ever gifted answers a question about sales rather than about
    // what is owed.
    Livewire::test(ListGifts::class)
        ->assertCanSeeTableRecords([$outstanding])
        ->assertCanNotSeeTableRecords([$spent]);
});

it('says how long each one has been outstanding', function () {
    aGiftOnTheBooks(boughtAt: now()->subMonths(3));

    // The column that turns a liability into something anybody can act on,
    // ADR 0006. A date is a thing to work out; "3 months" is a thing to notice.
    Livewire::test(ListGifts::class)->assertSee('3 months');
});

it('resends the same mail the settlement sent, to the address it was sent to', function () {
    $gift = aGiftOnTheBooks(recipient: 'given@example.com');

    Livewire::test(ListGifts::class)->callTableAction('resend', $gift);

    // **The same mailable, and not a second one shaped like it.** A recipient
    // whose address bounced is the case this screen exists for, and the mail
    // she sends again has to be the one carrying the code, the link and the
    // signature it was signed with.
    Mail::assertQueued(GiftToRedeem::class, fn (GiftToRedeem $mail): bool => $mail->gift->is($gift)
        && $mail->hasTo('given@example.com'));
});

it('keeps the badge neutral, because an unredeemed gift is not a task', function () {
    aGiftOnTheBooks();
    aGiftOnTheBooks();
    aGiftOnTheBooks(redeemed: true);

    // **Neutral and not warning**, ADR 0006. A badge permanently orange because
    // three gifts are legitimately outstanding teaches somebody to stop reading
    // badges, and the one it costs them is the readings badge, which means
    // something.
    expect(GiftResource::getNavigationBadge())->toBe('2')
        ->and(GiftResource::getNavigationBadgeColor())->toBe('gray')
        ->and(GiftResource::getNavigationBadgeColor())->not->toBe('warning');
});

it('shows no badge when nothing is outstanding', function () {
    aGiftOnTheBooks(redeemed: true);

    expect(GiftResource::getNavigationBadge())->toBeNull();
});

/*
| The row.
*/

it('carries the code, the reading, who it went to, who it is from and when it was bought', function () {
    aGiftOnTheBooks(
        key: ProductKey::InDepth,
        boughtAt: now()->subDays(2),
        code: 'ABCD2345EFGH',
        recipient: 'given@example.com',
        signature: 'Mum',
    );

    Livewire::test(ListGifts::class)
        // **Printed in threes and stored in one run.** The hyphens are the
        // model's, so the panel and the recipient's mail print one credential
        // the same way and she can read one off against the other.
        ->assertSee('ABCD-2345-EFGH')
        ->assertSee(theGiftedProduct(ProductKey::InDepth)->name)
        ->assertSee('given@example.com')
        ->assertSee('Mum')
        ->assertSee(now()->subDays(2)->format('j M Y'));
});

it('names a reading that has since been retired', function () {
    $gift = aGiftOnTheBooks(key: ProductKey::ThreeCard);
    $retired = $gift->orderLine->product;
    $retired->delete();

    // **A code for a withdrawn product still redeems**, so a row that could not
    // say what was owed would be a liability nobody can read. Products soft
    // delete precisely so an order goes on referring to them, and `OrderLine`'s
    // own `withTrashed` is what carries the name through.
    Livewire::test(ListGifts::class)->assertSee($retired->name);
});

it('finds a gift by the code somebody read back, hyphens and all', function () {
    $found = aGiftOnTheBooks(code: 'ABCD2345EFGH');
    $other = aGiftOnTheBooks(code: 'ZYXW9876VTSR');

    // She is given the code the way the recipient sees it, out of a mail or
    // over the phone. Storing it ungrouped and searching it grouped would mean
    // the one string anybody ever quotes finds nothing.
    Livewire::test(ListGifts::class)
        ->searchTable('abcd-2345-efgh')
        ->assertCanSeeTableRecords([$found])
        ->assertCanNotSeeTableRecords([$other]);
});

it('finds nothing when the search has nothing readable in it', function () {
    $first = aGiftOnTheBooks();
    $second = aGiftOnTheBooks();

    // **A `like` on an empty needle matches every row**, which is the opposite
    // of the rule `Gift::normaliseCode` states for its own callers: nonsense
    // normalises to nonsense, is looked up, and misses. Without the clause in
    // `scopeContainingTypedCode` a stray keystroke answers with the whole
    // liability.
    Livewire::test(ListGifts::class)
        ->searchTable('???')
        ->assertCanNotSeeTableRecords([$first, $second]);
});

it('puts the longest outstanding at the top', function () {
    $recent = aGiftOnTheBooks(boughtAt: now()->subDays(2));
    $ancient = aGiftOnTheBooks(boughtAt: now()->subMonths(8));

    // The opposite of Orders and the same as Readings. What she opens this
    // screen to find is the present nobody has claimed, and that is the oldest
    // row, not the newest.
    Livewire::test(ListGifts::class)
        ->assertCanSeeTableRecords([$ancient, $recent], inOrder: true);
});

/*
| What is not on this screen, and why each absence is deliberate.
*/

it('leaves an abandoned checkout off, because nothing was taken and nobody was told', function () {
    $sent = aGiftOnTheBooks();
    $abandoned = aGiftOnTheBooks(minted: false);

    // **A codeless gift is not a liability.** No money arrived, no code exists
    // and no mail went anywhere, so counting it as outstanding would inflate
    // the one number on this screen with checkouts nobody completed.
    Livewire::test(ListGifts::class)
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$abandoned]);

    expect(GiftResource::getNavigationBadge())->toBe('1');
});

it('will not resend a code that has already been spent', function () {
    $spent = aGiftOnTheBooks(redeemed: true);

    // The link in that mail leads to a code that no longer resolves, so sending
    // it again tells somebody they have a present and then refuses it.
    Livewire::test(ListGifts::class)
        ->filterTable('redeemed_at', true)
        ->assertTableActionHidden('resend', record: $spent);
});

it('cannot create, edit or delete a gift', function () {
    $gift = aGiftOnTheBooks();

    // **No address editing, and not because it is hard**, ADR 0006. The
    // original mail is in an inbox and its code still works, so changing the
    // address hands a second person a live credential rather than moving one.
    // That case is revoke and reissue, which is a different feature.
    expect(GiftResource::canCreate())->toBeFalse()
        ->and(GiftResource::canEdit($gift))->toBeFalse()
        ->and(GiftResource::canDelete($gift))->toBeFalse()
        ->and(GiftResource::canDeleteAny())->toBeFalse();
});

it('has no hand-issued codes to offer, because a gift is a line somebody bought', function () {
    // The panel half. `docs/plans/gifting-plan.md` wanted Jennifer able to
    // issue one as an apology, and ADR 0006 refused it: a hand-issued gift has
    // no purchase behind it.
    Livewire::test(ListGifts::class)->assertActionDoesNotExist('create');

    // The schema half, which is what actually makes it impossible. B5 landed
    // the constraint and this is the screen that would have needed it relaxed.
    $column = DB::selectOne(
        "select is_nullable from information_schema.columns where table_name = 'gifts' and column_name = 'order_line_id'"
    );

    expect($column->is_nullable)->toBe('NO');
});

/*
| Where it sits, and who may reach it.
*/

it('is third of the three screens, after readings and orders', function () {
    // One screen per question, and this is the third question: what obligation
    // is outstanding. It goes below the two she opens every day.
    expect(GiftResource::getNavigationSort())
        ->toBeGreaterThan(OrderResource::getNavigationSort())
        ->toBeGreaterThan(ReadingResource::getNavigationSort());
});

it('keeps gifts out of reach of anybody who is not an admin', function () {
    auth('admin')->logout();

    $this->get('/admin/gifts')->assertRedirect('/admin/login');
});
