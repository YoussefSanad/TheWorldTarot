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
 * It exists to make two rules structural rather than remembered.
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
 * A test asserts that everything in this namespace extends this class, because
 * a convention nothing checks is a convention until the first time somebody is
 * in a hurry.
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
