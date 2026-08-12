<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Customer;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * The link somebody uses to choose a new password.
 *
 * Sent from support, because it is the one message in this system a confused
 * person is most likely to reply to.
 */
class PasswordResetLink extends QueuedMail
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
        return 'Reset your password';
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset', with: [
            'name' => $this->customer->name,
            'url' => FrontendUrl::resetPassword($this->customer->email, $this->token),
            'minutes' => config('auth.passwords.customers.expire'),
        ]);
    }
}
