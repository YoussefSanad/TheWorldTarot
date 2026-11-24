<?php

namespace App\Mail;

use App\Enums\PasswordLink;
use App\Enums\ProductKey;
use App\Enums\Sender;
use App\Models\Customer;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * The welcome email: sent after the receipt to everybody who has just bought
 * their way into the Viewing Room, with a secure link to set a password for
 * future visits. Issue #135, 24 November 2026.
 *
 * **The name is historical.** This class was the set-password invitation
 * (#106), unused from #108, when the link moved into the receipt, until #135
 * moved it back out at the client's request. It was revived rather than
 * replaced because its tests (the link's shape, its escaping, `next`) still
 * describe it. `CONTEXT.md` calls the thing a **Welcome email**.
 *
 * **Only ever sent for an order that grants access**, and only once that order
 * is paid, by `MarkOrderPaid`. Sending on an unpaid order would make placing
 * one a way to mail a stranger. A reading buyer gets none (#51): they have
 * nothing to sign in for, and reach a password through "forgot password"
 * whenever they want one.
 *
 * ~~Only to an account with no password.~~ **Every pass buyer, since #135.**
 * A buyer whose account already has a password gets the same email, same
 * subject and same words, with the reset page behind the button, because
 * `/set-password` refuses an account that already has one. Which page is
 * decided once, when `MarkOrderPaid` mints the token under the customer lock,
 * and carried here as `PasswordLink`. This mail prints that kind and never
 * re-decides from the account as it stands when the queue sends it.
 *
 * **From noreply@ with no Reply-To since #135.** ~~From support@~~, which it
 * was as the invitation. It now goes out beside the receipt for the same
 * payment, and the client asked that the receipt close replies (`OrderPaid`'s
 * docblock), so a welcome from support@ would reopen that door one message
 * later. `MailHeadersTest` reads the sent headers to say so.
 *
 * **The copy is a draft for the client.** Written 24 November 2026 from the
 * points #135 lists: a welcome, lifetime access already theirs, a secure link
 * for future visits, sixty minutes, then "Forgotten your password?" on the sign
 * in page (the website's own label, `src/content/login.ts` in the frontend).
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
        private readonly PasswordLink $link = PasswordLink::Set,
    ) {}

    protected function sender(): Sender
    {
        return Sender::NoReply;
    }

    protected function subjectLine(): string
    {
        return 'Welcome to the Viewing Room';
    }

    public function content(): Content
    {
        // A message queued before `product` existed unserializes with the
        // property uninitialized, and a constructor default does not run on
        // unserialize. `??` reads an uninitialized property as null instead of
        // throwing, so those in-flight messages still send. The same for
        // `link`, added by #135: a message queued before it could only have
        // carried a set-password token.
        $product = $this->product ?? null;
        $link = $this->link ?? PasswordLink::Set;

        $url = $link === PasswordLink::Reset
            ? FrontendUrl::resetPassword($this->customer->email, $this->token, $product)
            : FrontendUrl::setPassword($this->customer->email, $this->token, $product);

        return new Content(html: 'mail.html.set-password', text: 'mail.set-password', with: [
            'name' => $this->customer->name,
            'url' => $url,
            'minutes' => config('auth.passwords.customers.expire'),
            'assets' => config('mail.assets.url'),
            'site' => rtrim((string) config('frontend.url'), '/'),
        ]);
    }
}
