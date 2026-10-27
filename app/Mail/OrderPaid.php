<?php

namespace App\Mail;

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Enums\Sender;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling a customer their payment went through.
 *
 * Goes to everybody who buys, claimed account or not.
 *
 * **One message, since issue #108.** A buyer of a pass with no password yet used
 * to get this receipt and, beside it, a `SetPasswordLink` mail, and two mails
 * for one payment read as one too many. `MarkOrderPaid` now mints the token and
 * hands it here, and the receipt carries "Set your password" as its only call to
 * action. A receipt built without a token (a claimed customer, a repeat order
 * while an earlier link is still live, or support resending one) carries no
 * credential and sends the buyer to the sign in page for a new link.
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
 * told one is still to be chosen. With a token the link to set it is the button,
 * and the Viewing Room button is left out, because the link's `next` lands them
 * in the room once the password is set.
 */
class OrderPaid extends QueuedMail
{
    public function __construct(
        public readonly Order $order,
        private readonly ?string $token = null,
        private readonly ?ProductKey $product = null,
    ) {}

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

        // A message queued before `token` and `product` existed unserializes
        // with those properties uninitialized, and a constructor default does
        // not run on unserialize. `??` reads an uninitialized property as null
        // instead of throwing, so those in-flight messages still send, as a
        // receipt with no link.
        $token = $this->token ?? null;
        $product = $this->product ?? null;

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
                // to be chosen.** Without a token the sentence sends them to the
                // sign in page for a new link and promises none in another email.
                'needsPassword' => $holdsAPass && $this->order->customer->isUnclaimed(),

                // **The credential, present only when `MarkOrderPaid` minted
                // one for this send.** Null means the receipt carries no link
                // and keeps the Viewing Room button. Gated on `needsPassword`
                // as well, so a token handed to a claimed customer or to an
                // order with no pass is never printed.
                'setPasswordUrl' => $token !== null && $holdsAPass && $this->order->customer->isUnclaimed()
                    ? FrontendUrl::setPassword($this->order->customer->email, $token, $product)
                    : null,
                'minutes' => config('auth.passwords.customers.expire'),

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
