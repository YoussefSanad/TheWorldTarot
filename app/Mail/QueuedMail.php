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
 * **Every message carries a text part, and a text part does not HTML-escape.**
 * This third one is not structural in this class, and saying so is the point:
 * it is carried by the views and held by `MailBodyTest`, which is why it is
 * written here, where somebody adding a mailable is already reading. A text
 * view has no HTML context, so the escaping Blade applies to `{{ }}` is not a
 * defence, it is corruption. Issue #45: the `&` joining a password link's two
 * query parameters left here as `&amp;`, so the website read the address as
 * null and refused every claim link and every reset link this application had
 * ever sent, and any customer with an apostrophe in their name was greeted as
 * `O&#039;Reilly`. Views in `resources/views/mail/` interpolate with `{!! !!}`
 * for that reason, and it is safe there for the same reason it was wrong
 * before: nothing renders them as markup.
 *
 * **Until 1 September 2026 that paragraph read "Every message is text", and
 * every mailable rendered `new Content(text: ...)` and nothing else.** It ended
 * on the line "the day one of these is meant to be HTML, this rule is what has
 * to be revisited first". Issue #53 was that day: the purchase confirmation is
 * a designed document now. The rule was revisited rather than dropped, and what
 * it became is a boundary drawn by path:
 *
 * - `resources/views/mail/` is text. It echoes with `{!! !!}` and must not
 *   escape, exactly as before
 * - `resources/views/mail/html/` is markup. It echoes with `{{ }}` and must
 *   escape, because a customer's own name and their own typed question both
 *   reach that document and an unescaped `<` there is an injection rather than
 *   a cosmetic bug
 *
 * **A message may add an HTML part and may never drop its text part**, so the
 * raw echo next door stays safe for the same reason it always was. `OrderPaid`
 * is the only mailable that is multipart today.
 *
 * A test asserts that everything in this namespace extends this class, and four
 * more that every message has a text part, that markup comes only from the
 * checked directory, that its views live inside `resources/views/mail/`, and
 * that each side of the boundary escapes the way its side requires. A
 * convention nothing checks is a convention until the first time somebody is in
 * a hurry.
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
