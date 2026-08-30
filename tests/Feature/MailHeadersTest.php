<?php

use App\Mail\OrderPaid;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/*
| The headers a message carries once something has decided to send it.
|
| Its siblings are `MailTransportTest`, which is about our own side of sending,
| and `MailBodyTest`, which is about what a body says. This file is the third of
| those layers: the envelope as the transport receives it, read off the real
| Symfony message rather than off the `Envelope` this application built, because
| "carries no Reply-To" is a claim about headers and not about an intention.
|
| **This is the message we handed over, header for header. It is not evidence of
| what Resend does with it**, and nothing here should be read that way. Only
| Resend can answer that, and the answer comes from `mail:check` sending a real
| one. See .claude/testing.md, and the five days in M4 lost to a suite asserting
| our own implementation back to itself.
|
| It lives apart from `MailTransportTest` because it needs a database and that
| file does not. Eleven tests there are DB-free, and #47 briefly put all of them
| on migrations for the sake of this one.
*/

uses(RefreshDatabase::class);

/**
 * The confirmation, as it actually leaves.
 *
 * Jennifer, 30 August 2026: it must not invite or allow a reply. **A Reply-To
 * pointing at support@ would satisfy the letter of that and reopen the door she
 * asked to close**, by a different name, so its absence is pinned here rather
 * than left to nobody thinking to add one.
 *
 * The address is set here and asserted as a literal. Reading it back through
 * `config('mail.senders.noreply')` would be reading the key the implementation
 * reads, and would agree with it whatever it held.
 */
it('sends the confirmation from noreply, with no header offering a way back', function () {
    config()->set('mail.senders', [
        'support' => 'support@example.test',
        'noreply' => 'noreply@example.test',
        'fulfillment' => 'fulfillment@example.test',
    ]);

    $order = Order::factory()->forProduct(Product::factory()->create())->create();

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($message->getFrom()[0]->getAddress())->toBe('noreply@example.test')
        ->and($message->getReplyTo())->toBeEmpty()
        ->and($message->getHeaders()->has('Reply-To'))->toBeFalse();
});
