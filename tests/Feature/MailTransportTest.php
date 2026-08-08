<?php

use App\Enums\Sender;
use App\Mail\TransportCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/*
| What these prove, and what they cannot.
|
| Everything here is about our own side: that a message is queued rather than
| sent inline, that it carries the sender it should, and that the convention is
| structural rather than remembered. That is the right thing to assert, because
| we define all of it.
|
| **None of it says Resend will accept a message, and none of it should be read
| that way.** Only Resend can answer that, and the answer comes from `mail:check`
| sending a real one. See .claude/testing.md, and the five days in M4 that were
| lost to a suite asserting our own implementation back to itself.
*/

it('queues a message rather than sending it inline', function () {
    Mail::fake();

    $this->artisan('mail:check', ['email' => 'someone@example.com'])
        ->assertSuccessful();

    // Queued, not sent. A request must never wait on a third party, and at
    // checkout a slow send must never surface as a failed payment.
    Mail::assertQueued(TransportCheck::class);
    Mail::assertNotSent(TransportCheck::class);
});

it('sends on the spot when asked to, so a dead transport can be told from a stopped worker', function () {
    Mail::fake();

    $this->artisan('mail:check', ['email' => 'someone@example.com', '--now' => true])
        ->assertSuccessful();

    Mail::assertSent(TransportCheck::class);
});

it('sends the check to the address it was given', function () {
    Mail::fake();

    $this->artisan('mail:check', ['email' => 'jennifer@example.com'])->assertSuccessful();

    Mail::assertQueued(
        TransportCheck::class,
        fn (TransportCheck $mail): bool => $mail->hasTo('jennifer@example.com'),
    );
});

it('refuses something that is not an email address, and sends nothing', function () {
    Mail::fake();

    $this->artisan('mail:check', ['email' => 'not-an-address'])
        ->assertFailed();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('warns when the mailer would send nothing, rather than reporting a silent success', function () {
    Mail::fake();

    // The test environment runs the `array` mailer, which delivers nowhere.
    // A green result here would otherwise read as proof that mail works.
    $this->artisan('mail:check', ['email' => 'someone@example.com'])
        ->expectsOutputToContain('nothing will leave this machine')
        ->assertSuccessful();
});

it('resolves every sender to its configured address', function () {
    config()->set('mail.senders', [
        'support' => 'support@example.test',
        'orders' => 'orders@example.test',
        'fulfillment' => 'fulfillment@example.test',
    ]);
    config()->set('mail.from.name', 'The World Tarot');

    expect(Sender::Support->address()->address)->toBe('support@example.test')
        ->and(Sender::Orders->address()->address)->toBe('orders@example.test')
        ->and(Sender::Fulfillment->address()->address)->toBe('fulfillment@example.test')
        ->and(Sender::Support->address()->name)->toBe('The World Tarot');
});

it('has an address configured for every sender, so none falls back to nothing', function () {
    foreach (Sender::cases() as $sender) {
        expect(config('mail.senders.'.$sender->value))
            ->toBeString("{$sender->value} has no address")
            ->not->toBeEmpty();
    }
});

it('sends the transport check from the support address', function () {
    Mail::fake();

    $this->artisan('mail:check', ['email' => 'someone@example.com'])->assertSuccessful();

    Mail::assertQueued(
        TransportCheck::class,
        fn (TransportCheck $mail): bool => $mail->hasFrom(config('mail.senders.support')),
    );
});

it('queues every message this application can send', function () {
    $files = glob(app_path('Mail/*.php'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Mail\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        // The convention is that nothing sends inline. A convention nothing
        // checks holds until the first time somebody is in a hurry, so this
        // catches a mailable that does not extend QueuedMail rather than
        // trusting that the next one will.
        expect($reflection->implementsInterface(ShouldQueue::class))
            ->toBeTrue("{$class} is not queued");
    }
});
