<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Customer;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * Inviting somebody who has just bought their way into the Viewing Room to
 * choose a password for it.
 *
 * **Only ever sent for an order that grants access**, only to an account with
 * no password, and only once that order is paid. All three conditions matter.
 * Sending on an unpaid order would make placing one a way to mail a stranger,
 * and sending to a claimed account would be a password link somebody else
 * caused to arrive. The first is issue #51: a reading buyer has nothing to sign
 * in for yet, so asking them for a password is admin about an account they did
 * not ask for. They still reach one through "forgot password" whenever they
 * want it.
 */
class SetPasswordLink extends QueuedMail
{
    public function __construct(
        public readonly Customer $customer,
        private readonly string $token,
    ) {}

    protected function sender(): Sender
    {
        return Sender::Support;
    }

    protected function subjectLine(): string
    {
        return 'Your Viewing Room pass is ready';
    }

    public function content(): Content
    {
        return new Content(text: 'mail.set-password', with: [
            'name' => $this->customer->name,
            'url' => FrontendUrl::setPassword($this->customer->email, $this->token),
            'minutes' => config('auth.passwords.customers.expire'),
        ]);
    }
}
