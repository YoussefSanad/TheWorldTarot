<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Order;
use App\Models\Reading;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Collection;

/**
 * Telling Jennifer somebody is waiting for a reading.
 *
 * The system notifies and records. It does not fulfill: she writes every
 * reading herself and emails it as a PDF, per docs/client-context.md.
 *
 * Carries the customer's question, because that is the thing she cannot work
 * without and the reason this is a message rather than a row she has to notice.
 *
 * **Who it is from is built here rather than in the view**, since issue #52
 * made a buyer's name optional and the answer stopped being an interpolation.
 * The same change moved three greetings out of their views for that reason.
 */
class ReadingToWrite extends QueuedMail
{
    /**
     * **The readings are carried rather than read off the order**, since
     * 3 September 2026 and B6 (#72).
     *
     * An order is not the asking any more. A basket may hold a present and a
     * self purchase together, and only one of those is work: printing every
     * line would put a gifted reading in front of Jennifer with "No question
     * was asked" under it, which is a job she cannot do and nobody has asked
     * for. It would also have been a second answer to "which of these is
     * waiting for a person", derived in a view, disagreeing by hand with the
     * one `MarkOrderPaid` already gives.
     *
     * **The order stays for the header**, which is genuinely about the order:
     * its number, its total and who paid. Redemption in B7 is the caller that
     * makes those two different things, months apart.
     *
     * @param  Collection<int, Reading>  $readings  What was asked in this one instant
     */
    public function __construct(
        public readonly Order $order,
        public readonly Collection $readings,
    ) {}

    protected function sender(): Sender
    {
        return Sender::Fulfillment;
    }

    protected function subjectLine(): string
    {
        return 'A reading is waiting to be written';
    }

    public function content(): Content
    {
        return new Content(text: 'mail.reading-to-write', with: [
            'order' => $this->order,
            'readings' => $this->readings->loadMissing('orderLine.product'),
            'from' => $this->whoItIsFrom(),
        ]);
    }

    /**
     * The buyer, as a line she can read and reply to.
     *
     * **The address is the half that cannot go missing**, because it is how she
     * answers somebody. A buyer with no name gives `jennifer@example.com` on
     * its own rather than `<jennifer@example.com>`, which reads as a fault in
     * our software rather than as a person nobody asked.
     *
     * **Blank counts as no name, not just null.** `''` is what
     * `OrderController` wrote into a NOT NULL column before #52, so a row of
     * that shape can predate the migration that made the column nullable, and
     * a name pasted into a form arrives padded. `App\Support\Greeting` trims
     * for the same reason.
     */
    private function whoItIsFrom(): string
    {
        $customer = $this->order->customer;
        $name = trim((string) $customer->name);

        return $name === '' ? $customer->email : "{$name} <{$customer->email}>";
    }
}
