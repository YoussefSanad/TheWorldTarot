<?php

use App\Mail\GiftToRedeem;
use App\Mail\OrderPaid;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/*
| The headers a message carries once something has decided to send it.
|
| Its siblings are `MailTransportTest`, which is about our own side of sending,
| and `MailBodyTest`, which is about what a body says. This file is the third of
| those layers: the envelope as the transport receives it, read off the real
| Symfony message rather than off the `Envelope` this application built, because
| "carries no Reply-To" is a claim about headers and not about an intention.
| A subject reads the same either way, and is read here anyway, so that what is
| asserted is one message rather than two sources for one question.
|
| **This is the message we handed over, header for header. It is not evidence of
| what Resend does with it**, and nothing here should be read that way. Only
| Resend can answer that, and the answer comes from `mail:check` sending a real
| one. See .claude/testing.md, and the five days in M4 lost to a suite asserting
| our own implementation back to itself.
|
| It lives apart from `MailTransportTest` because it needs a database and that
| file does not. Eleven tests there are DB-free, and #47 briefly put all of them
| on migrations for the sake of the first test here.
*/

uses(RefreshDatabase::class);

/**
 * The one message that was sent, as the transport received it.
 *
 * Sole in effect rather than in name: every test in this file sends exactly one
 * message, and `first()` on a second would quietly answer about the wrong one.
 */
function theMessageSent(): Email
{
    $messages = Mail::mailer()->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    return $messages->first()->getOriginalMessage();
}

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

    $message = theMessageSent();

    expect($message->getFrom()[0]->getAddress())->toBe('noreply@example.test')
        ->and($message->getReplyTo())->toBeEmpty()
        ->and($message->getHeaders()->has('Reply-To'))->toBeFalse();
});

/**
 * The present, whose subject says what has actually arrived.
 *
 * Issue #82, and both halves of the line are why it is pinned whole. It said
 * "has sent you a reading" until 3 September 2026, and the signature has always
 * come first. **`GiftToRedeem`'s own docblock carries both arguments** and is
 * not restated here.
 *
 * **Pinned whole rather than by `str_contains`.** `SettleAGiftOrderTest` asks
 * only whether the signature is in there, which is the right question for the
 * path that sends this and is the wrong one for the copy: it passes on every
 * arrangement of these words, the ones putting the name last included, and it
 * passed on the wording this issue exists to replace. This is the line itself.
 */
it('tells the recipient a gift has arrived, under the name it was signed with', function () {
    $gift = aGiftOf(['signature' => 'Mum']);

    Mail::to($gift->recipient_email)->send(new GiftToRedeem($gift));

    expect(theMessageSent()->getSubject())->toBe('Mum has sent you a gift');
});
