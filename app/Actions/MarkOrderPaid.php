<?php

namespace App\Actions;

use App\Enums\Fulfillment;
use App\Enums\OrderStatus;
use App\Mail\OrderPaid;
use App\Mail\ReadingToWrite;
use App\Mail\SetPasswordLink;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The one moment an order becomes paid, and everything that follows from it.
 *
 * **Everything downstream of payment happens here and nowhere else.** A person
 * settling an order in the panel and, in phase B, a webhook from a card
 * processor both arrive at this action, so fulfillment cannot come to differ
 * between them.
 *
 * ## Running twice
 *
 * It will. Managed queues deliver at least once, and a payment webhook is
 * redelivered as a matter of routine. Surviving that is not discipline here, it
 * is structure: the row is locked, the transition only happens if the order is
 * still pending, and everything else is inside the branch that only a real
 * transition reaches. A second delivery finds the work done and does nothing.
 *
 * ## Ordering, which is the part that is easy to get wrong
 *
 * Access is granted **inside** the transaction, so a failure halfway cannot
 * leave somebody holding a pass for an order that never got marked paid.
 *
 * Mail is queued **after** it commits, for the opposite reason: a message sent
 * inside a transaction that then rolls back has still been sent, and no amount
 * of rolling back unsends it.
 */
class MarkOrderPaid
{
    public function __construct(private readonly MarkOrderFulfilled $markFulfilled) {}

    /**
     * @return bool Whether this call is the one that changed anything. False
     *              means it was already paid, which is a success.
     */
    public function __invoke(Order $order): bool
    {
        $paid = DB::transaction(function () use ($order): bool {
            // Locked, so two settlements arriving together cannot both see a
            // pending order and both grant a pass.
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== OrderStatus::Pending) {
                return false;
            }

            $locked->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $this->fulfill($locked);

            return true;
        });

        if ($paid) {
            $this->tell($order->refresh()->load('lines.product', 'customer'));
        }

        return $paid;
    }

    /**
     * What being paid for actually delivers.
     *
     * Driven by the product's `fulfillment`, so the One Card Reading becoming
     * AI written in M7 is a data change here and not a new branch.
     */
    private function fulfill(Order $order): void
    {
        $order->load('lines.product', 'customer');

        foreach ($order->lines as $line) {
            if ($line->product?->fulfillment === Fulfillment::AccessGrant) {
                // Granting twice keeps the first date on purpose, so a
                // duplicate settlement cannot rewrite when somebody got in.
                $order->customer->grantViewingRoomAccess();
            }
        }

        // **An order nobody has to do anything about is already fulfilled.**
        // A pass is delivered by the grant just above, in the same instant the
        // money lands, so leaving it waiting would put work on Jennifer's list
        // that does not exist and cannot be done.
        //
        // Anything written by hand is the opposite: it is fulfilled when she
        // says it is, and an order holding one reading and one pass waits for
        // the reading, because half delivered is not delivered.
        //
        // The stamping itself is MarkOrderFulfilled's, which is the same writer
        // her "Mark as fulfilled" button reaches. What is decided here is only
        // whether this order is one of the ones that needs nobody.
        if (! $this->needsAPerson($order)) {
            ($this->markFulfilled)($order);
        }
    }

    /**
     * Whether anything on this order is waiting for Jennifer.
     *
     * Asked twice by two callers and answered once, because the two answers are
     * exact opposites and nothing would fail if they came apart. An order
     * fulfilled at payment that she is also told about is work on her list that
     * is already done, and one left waiting that she never hears about is a
     * customer who paid and receives nothing.
     */
    private function needsAPerson(Order $order): bool
    {
        return $order->lines->contains(
            fn ($line): bool => $line->product?->fulfillment === Fulfillment::ManualPdf,
        );
    }

    /**
     * Everybody who needs to know, once the payment is definitely recorded.
     */
    private function tell(Order $order): void
    {
        $customer = $order->customer;

        Mail::to($customer->email)->send(new OrderPaid($order));

        // **A claim link goes only to an account nobody has claimed.** Sending
        // one to an address that already has an account would mean a stranger
        // who knows your email can cause a set-a-password message to land in
        // your inbox. It hands them nothing, and it is still a phishing shaped
        // tool pointed at our own customers.
        if ($customer->password === null) {
            Mail::to($customer->email)->send(new SetPasswordLink(
                $customer,
                Password::broker('customers')->createToken($customer),
            ));
        }

        // One message for the whole order rather than one per line, so Jennifer
        // gets a single thing to work from instead of three that arrive
        // together and have to be reassembled.
        if ($this->needsAPerson($order)) {
            Mail::to(config('mail.notify.fulfillment'))->send(new ReadingToWrite($order));
        }
    }
}
