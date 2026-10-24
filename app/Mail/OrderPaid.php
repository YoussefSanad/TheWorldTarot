<?php

namespace App\Mail;

use App\Enums\Fulfillment;
use App\Enums\ProductType;
use App\Enums\Sender;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\FrontendUrl;
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
 *
 * **A pass on the order adds a paragraph and a link into the Viewing Room.**
 * The pass is open the moment it is paid for, so the receipt is the first
 * place a buyer is told so and sent in. A customer with no password yet is also
 * told one is still to be chosen, in words only: the link to set it stays in
 * `SetPasswordLink`, and this message never carries it.
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
     * it, plus the pass paragraph and nothing else, and it stays for two reasons: a client that will not
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
        // The same question `MarkOrderPaid::grantsAccess()` asks, so the
        // receipt and the set-password invitation cannot disagree about
        // whether this order opens the Viewing Room. Loaded here so each line
        // costs no query of its own.
        $holdsAPass = $this->order->lines->loadMissing('product')->contains(
            fn (OrderLine $line): bool => $line->product?->fulfillment === Fulfillment::AccessGrant,
        );

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

                // Only the HTML part reads these four, and the text part
                // gained only the pass paragraph.
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

                // **Whether the order holds a Viewing Room pass**, which earns
                // the paragraph telling the buyer it is theirs and the link in.
                // A boolean for the same reason as the two above.
                'holdsAPass' => $holdsAPass,
                'viewingRoomUrl' => FrontendUrl::viewingRoom(),

                // **Only a customer with no password yet is told one is still
                // to come.** The sentence points at the separate invitation and
                // the receipt carries no set-password link, because support
                // can resend a receipt and a credential must not be resent.
                'needsPassword' => $holdsAPass && $this->order->customer->isUnclaimed(),

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
