<?php

namespace App\Mail;

use App\Enums\Sender;
use Illuminate\Mail\Mailables\Content;

/**
 * A message whose only job is to prove that mail actually leaves.
 *
 * Not a throwaway. `Mail::fake()` proves we built a mailable and proves nothing
 * about Resend accepting it, which is the trap that cost this project five days
 * in M4 and the reason .claude/testing.md exists. The only authority on whether
 * this application can send is the service that does the sending, so proving it
 * takes a real message arriving in a real inbox.
 *
 * Sent by `mail:check`, which stays after this milestone because the question
 * "is mail working right now" gets asked again.
 */
class TransportCheck extends QueuedMail
{
    public function __construct(public readonly string $environment) {}

    protected function sender(): Sender
    {
        return Sender::Support;
    }

    protected function subjectLine(): string
    {
        return 'Mail transport check, '.$this->environment;
    }

    public function content(): Content
    {
        return new Content(text: 'mail.transport-check');
    }
}
