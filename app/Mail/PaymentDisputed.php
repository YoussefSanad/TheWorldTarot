<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Order;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling Jennifer a customer has disputed a payment with their bank.
 *
 * **Chargebacks arrive whether or not refunds are offered.** There are no
 * refunds, decided 25 August 2026, and that decision has no effect at all on
 * somebody's ability to take their money back through their card issuer.
 *
 * This changes nothing in the system on purpose. Nothing is revoked and no
 * order moves. What it does is mean a chargeback is something she learns about
 * rather than discovers, which is the whole of what was asked for.
 */
class PaymentDisputed extends QueuedMail
{
    public function __construct(
        public readonly string $disputeId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly ?Order $order = null,
    ) {}

    protected function sender(): Sender
    {
        return Sender::Fulfillment;
    }

    protected function subjectLine(): string
    {
        return 'A customer has disputed a payment';
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payment-disputed', with: [
            'disputeId' => $this->disputeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'order' => $this->order,
        ]);
    }
}
