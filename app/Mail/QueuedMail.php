<?php

namespace App\Mail;

use App\Enums\Sender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The base every message in this application extends.
 *
 * It exists to make two rules structural rather than remembered, and it is
 * where a third is written down.
 *
 * **Nothing is ever sent inline.** `ShouldQueue` lives here, so a mailable
 * cannot be written without it by forgetting. Mail goes through a third party
 * that can be slow or down, and per .claude/api.md a request that waits on one
 * is a request that times out under load. This matters most at checkout, where
 * a customer who has paid must never see a failure because an email was slow.
 *
 * **Every message names its sender.** There is no default to fall through to,
 * so a new mailable has to decide which address it comes from rather than
 * inheriting whichever one happens to be configured globally.
 *
 * **Every message is text, and text does not HTML-escape.** This third one is
 * not structural in this class, and saying so is the point: it is carried by
 * the views and held by `MailBodyTest`, which is why it is written here, where
 * somebody adding a mailable is already reading. Every mailable here
 * renders `new Content(text: ...)`, so its view has no HTML context and the
 * escaping Blade applies to `{{ }}` is not a defence, it is corruption. Issue
 * #45: the `&` joining a password link's two query parameters left here as
 * `&amp;`, so the website read the address as null and refused every claim link
 * and every reset link this application had ever sent, and any customer with an
 * apostrophe in their name was greeted as `O&#039;Reilly`. Views in
 * `resources/views/mail/` interpolate with `{!! !!}` for that reason, and it is
 * safe there for the same reason it was wrong before: nothing renders them as
 * markup. **The day one of these is meant to be HTML, this rule is what has to
 * be revisited first.**
 *
 * A test asserts that everything in this namespace extends this class, and
 * three more that every message is text, that its view lives in that directory,
 * and that nothing in there escapes. A convention nothing checks is a
 * convention until the first time somebody is in a hurry.
 */
abstract class QueuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    abstract protected function sender(): Sender;

    abstract protected function subjectLine(): string;

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->sender()->address(),
            subject: $this->subjectLine(),
        );
    }
}
