<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Customer;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * Inviting somebody who has just bought something to finish their account.
 *
 * **Only ever sent to an account with no password**, and only once an order is
 * paid. Both conditions matter. Sending on an unpaid order would make placing
 * one a way to mail a stranger, and sending to a claimed account would be a
 * password link somebody else caused to arrive.
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
        return 'Finish setting up your account';
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
