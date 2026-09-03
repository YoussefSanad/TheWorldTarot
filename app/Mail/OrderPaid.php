<?php

namespace App\Mail;

use App\Enums\ProductType;
use App\Enums\Sender;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling a customer their payment went through.
 *
 * Goes to everybody who buys, claimed account or not. The invitation to set a
 * password is a separate message, because a confirmation can be resent by
 * support at any time and a credential must not be.
 *
 * **From noreply@ since 30 August 2026, and it carries no Reply-To.** It came
 * from orders@ until then, which is now the mailbox the internal new order
 * notification arrives in. Adding a Reply-To pointing at support@ would make
 * this replyable again through a different door, which is the thing Jennifer
 * asked to close, so nothing here sets one and `MailTransportTest` reads the
 * sent headers to say so.
 */
class OrderPaid extends QueuedMail
{
    public function __construct(public readonly Order $order) {}

    protected function sender(): Sender
    {
        return Sender::NoReply;
    }

    protected function subjectLine(): string
    {
        return 'Your order is confirmed';
    }

    /**
     * **Multipart since issue #53, and the text part is not a leftover.**
     *
     * The designed body is the HTML one. The text one is what shipped before
     * it, unchanged, and it stays for two reasons: a client that will not
     * render markup still has to be able to read a receipt, and a message
     * offering no alternative part scores worse with the filters that decide
     * whether any of this arrives at all.
     *
     * **The two views obey opposite escaping rules and that is enforced by
     * path.** `mail.order-paid` is text and echoes raw, because escaping a text
     * body is what issue #45 cost. `mail.html.order-paid` is markup and
     * escapes, because not escaping markup is an injection. `MailBodyTest`
     * holds both, and `QueuedMail`'s docblock is where the rule is written down.
     */
    public function content(): Content
    {
        return new Content(
            html: 'mail.html.order-paid',
            text: 'mail.order-paid',
            with: [
                'name' => $this->order->customer->name,

                // **The present on each line, loaded rather than reached for.**
                // Both bodies branch on it since B6 (#72), so a lazy load here
                // would be one query per line of every receipt this application
                // sends.
                'lines' => $this->order->lines->loadMissing('gift'),
                'currency' => $this->order->currency,
                'total' => $this->order->total_amount,

                // Only the HTML part reads these four, and the text part is
                // deliberately left exactly as it was.
                'assets' => config('mail.assets.url'),
                'site' => rtrim((string) config('frontend.url'), '/'),

                // **Whether anything on this order is actually being written.**
                // The designs were three reading confirmations and this mailable
                // also confirms a Viewing Room pass, which is open the moment it
                // is paid for. A boolean rather than the heading itself, so the
                // copy stays in the view with the styling that sets it.
                //
                // **A gifted reading is not being written either**, added with
                // B6 (#72). Nobody has asked it a question yet and the answer
                // will not be sent to this buyer when they do, so "Your Reading
                // Is On Its Way" is the same defect the pass already had one
                // row further up: a heading that names something this person
                // is not waiting for. The fallback is the subject line the
                // message already carries, which is signed off copy.
                'awaitsReading' => $this->order->lines->contains(
                    fn (OrderLine $line): bool => $line->product_key->type() === ProductType::Reading
                        && $line->gift === null,
                ),

                // **Whether anything on this order was bought for somebody
                // else**, which is what earns the paragraph naming the address.
                // A boolean for the same reason as the one above: the copy
                // belongs in the view, and a text body is no place for a
                // closure over the lines.
                'holdsAGift' => $this->order->gifts()->isNotEmpty(),

                // The same lookup the panel's product table does. Falling back
                // to the code means an order in a currency somebody adds to
                // `currencies.available` without a symbol reads "USD 52.00"
                // rather than "52.00" with no unit at all.
                'symbol' => config(
                    'currencies.available.'.$this->order->currency.'.symbol',
                    $this->order->currency.' ',
                ),
            ],
        );
    }
}
