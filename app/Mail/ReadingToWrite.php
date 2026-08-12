<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Order;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling Jennifer somebody is waiting for a reading.
 *
 * The system notifies and records. It does not fulfill: she writes every
 * reading herself and emails it as a PDF, per docs/client-context.md.
 *
 * Carries the customer's question, because that is the thing she cannot work
 * without and the reason this is a message rather than a row she has to notice.
 */
class ReadingToWrite extends QueuedMail
{
    public function __construct(public readonly Order $order) {}

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
            'customer' => $this->order->customer,
        ]);
    }
}
