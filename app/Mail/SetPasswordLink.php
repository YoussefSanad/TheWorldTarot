<?php

namespace App\Mail;

use App\Enums\ProductKey;
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
 *
 * Multipart: a designed HTML part and the text part. The link is a credential
 * and is never logged.
 */
class SetPasswordLink extends QueuedMail
{
    public function __construct(
        public readonly Customer $customer,
        private readonly string $token,
        private readonly ?ProductKey $product = null,
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
        // A message queued before `product` existed unserializes with the
        // property uninitialized, and a constructor default does not run on
        // unserialize. `??` reads an uninitialized property as null instead of
        // throwing, so those in-flight messages still send.
        $product = $this->product ?? null;

        return new Content(html: 'mail.html.set-password', text: 'mail.set-password', with: [
            'name' => $this->customer->name,
            'url' => FrontendUrl::setPassword($this->customer->email, $this->token, $product),
            'minutes' => config('auth.passwords.customers.expire'),
            'assets' => config('mail.assets.url'),
            'site' => rtrim((string) config('frontend.url'), '/'),
        ]);
    }
}
