<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Order;
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

    public function content(): Content
    {
        return new Content(text: 'mail.order-paid', with: [
            'name' => $this->order->customer->name,
            'lines' => $this->order->lines,
            'currency' => $this->order->currency,
            'total' => $this->order->total_amount,
        ]);
    }
}
