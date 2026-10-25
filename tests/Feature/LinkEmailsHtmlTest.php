<?php

use App\Mail\PasswordResetLink;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
| The designed link emails, issue #106. Covers both the password reset
| and the set-password invitation.
|
| Same split as `OrderPaidHtmlTest`: the message is multipart, the HTML part
| escapes, the text part still echoes raw. The one new thing is that a URL
| carries `&`, which is correct raw in text and has to be `&amp;` inside an
| `href` attribute. `Mail::fake()` is never set, because a fake leaves no
| rendered message to read.
*/

function linkHtmlSent(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages()->all();

    expect($messages)->toHaveCount(1, 'Expected exactly one sent message');

    return (string) $messages[0]->getOriginalMessage()->getHtmlBody();
}

function linkTextSent(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages()->all();

    return (string) $messages[0]->getOriginalMessage()->getTextBody();
}

function sendReset(?string $name = 'Jodie O\'Reilly', string $token = 'tok-abc123'): string
{
    $customer = Customer::factory()->create(['name' => $name, 'email' => 'jodie@example.com']);

    Mail::to($customer->email)->send(new PasswordResetLink($customer, $token));

    return FrontendUrl::resetPassword($customer->email, $token);
}

it('sends the reset as both an HTML part and a text part', function () {
    sendReset();

    expect(linkHtmlSent())->toContain('<html')
        ->and(linkTextSent())->toContain('Someone asked to reset the password');
});

it('puts the reset link in the HTML as an escaped href', function () {
    $url = sendReset();

    // The link has two query parameters, so it contains a literal `&`.
    expect($url)->toContain('&')
        ->and(linkHtmlSent())->toContain('href="'.e($url).'"')
        ->and(linkHtmlSent())->toContain('&amp;email=')
        ->and(linkHtmlSent())->not->toContain('href="'.$url.'"');
});

it('states how long the link works in the HTML part', function () {
    config()->set('auth.passwords.customers.expire', 47);

    sendReset();

    expect(linkHtmlSent())->toContain('47 minutes');
});

it('keeps the raw unescaped URL in the text part', function () {
    $url = sendReset();

    expect(linkTextSent())->toContain($url)
        ->and(linkTextSent())->not->toContain('&amp;');
});

it('escapes a name with an apostrophe in HTML and leaves it raw in text', function () {
    sendReset('Jodie O\'Reilly');

    expect(linkHtmlSent())->toContain('Hello Jodie O&#039;Reilly,')
        ->and(linkHtmlSent())->not->toContain("Jodie O'Reilly")
        ->and(linkTextSent())->toContain("Hello Jodie O'Reilly,")
        ->and(linkTextSent())->not->toContain('&#039;');
});

it('never lets a name carrying markup become markup', function () {
    sendReset('<script>alert(1)</script>');

    expect(linkHtmlSent())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and(linkHtmlSent())->not->toContain('<script>')
        ->and(linkTextSent())->toContain('<script>alert(1)</script>');
});

it('opens without a name rather than inventing one', function () {
    sendReset(null);

    expect(linkHtmlSent())->toContain('Hello,')
        ->and(linkHtmlSent())->not->toContain('Hello ,')
        ->and(linkHtmlSent())->not->toContain('jodie,')
        ->and(linkTextSent())->toContain('Hello,');
});

it('leaks no Blade source into the HTML', function () {
    sendReset();

    // Anchored on the link so an empty body cannot pass.
    expect(linkHtmlSent())->toContain('tok-abc123')
        ->and(linkHtmlSent())->not->toContain('@endsection')
        ->and(linkHtmlSent())->not->toContain('@extends')
        ->and(linkHtmlSent())->not->toContain('@section')
        ->and(linkHtmlSent())->not->toContain('{{')
        ->and(linkHtmlSent())->not->toContain('{!!');
});

it('loads images from the configured assets host', function () {
    config()->set('mail.assets.url', 'https://cdn.example.com/email');

    sendReset();

    preg_match_all('/(?:src|srcset)="([^"]+)"/', linkHtmlSent(), $found);

    expect($found[1])->not->toBeEmpty();

    foreach ($found[1] as $reference) {
        expect($reference)->toStartWith('https://cdn.example.com/email/');
    }
});

// Runs on the `array` mailer, which delivers nowhere and logs nothing itself. So
// this only covers logging done inside this application's code (listened for
// via Log::listen). It does not cover the full message that a log transport
// such as `MAIL_MAILER=log` would write.
it('does not write the link or token to the logs', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    sendReset(token: 'secret-token-xyz');

    // Anchored on the message having been sent.
    expect(linkHtmlSent())->toContain('secret-token-xyz')
        ->and(implode("\n", $logged))->not->toContain('secret-token-xyz');
});

/*
| Step 2: the Viewing Room set-password invitation.
*/

function sendSetPassword(?string $name = 'Jodie O\'Reilly', string $token = 'tok-abc123'): string
{
    $customer = Customer::factory()->create(['name' => $name, 'email' => 'jodie@example.com']);

    Mail::to($customer->email)->send(new SetPasswordLink($customer, $token));

    return FrontendUrl::setPassword($customer->email, $token);
}

it('sends the set-password link as both an HTML part and a text part', function () {
    sendSetPassword();

    expect(linkHtmlSent())->toContain('<html')
        ->and(linkTextSent())->toContain('Your Viewing Room pass is ready');
});

it('puts the set-password link in the HTML as an escaped href', function () {
    $url = sendSetPassword();

    expect($url)->toContain('&')
        ->and($url)->toContain('/set-password/')
        ->and(linkHtmlSent())->toContain('href="'.e($url).'"')
        ->and(linkHtmlSent())->toContain('/set-password/')
        ->and(linkHtmlSent())->toContain('&amp;email=')
        ->and(linkHtmlSent())->not->toContain('href="'.$url.'"');
});

it('states how long the set-password link works in the HTML part', function () {
    config()->set('auth.passwords.customers.expire', 47);

    sendSetPassword();

    expect(linkHtmlSent())->toContain('47 minutes');
});

it('keeps the raw unescaped set-password URL in the text part', function () {
    $url = sendSetPassword();

    expect(linkTextSent())->toContain($url)
        ->and(linkTextSent())->not->toContain('&amp;');
});

it('escapes a set-password name with an apostrophe in HTML and leaves it raw in text', function () {
    sendSetPassword('Jodie O\'Reilly');

    expect(linkHtmlSent())->toContain('Hello Jodie O&#039;Reilly,')
        ->and(linkHtmlSent())->not->toContain("Jodie O'Reilly")
        ->and(linkTextSent())->toContain("Hello Jodie O'Reilly,")
        ->and(linkTextSent())->not->toContain('&#039;');
});

it('never lets a set-password name carrying markup become markup', function () {
    sendSetPassword('<script>alert(1)</script>');

    expect(linkHtmlSent())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and(linkHtmlSent())->not->toContain('<script>')
        ->and(linkTextSent())->toContain('<script>alert(1)</script>');
});

it('opens the set-password email without a name rather than inventing one', function () {
    sendSetPassword(null);

    expect(linkHtmlSent())->toContain('Hello,')
        ->and(linkHtmlSent())->not->toContain('Hello ,')
        ->and(linkHtmlSent())->not->toContain('jodie,')
        ->and(linkTextSent())->toContain('Hello,');
});

it('leaks no Blade source into the set-password HTML', function () {
    sendSetPassword();

    expect(linkHtmlSent())->toContain('tok-abc123')
        ->and(linkHtmlSent())->not->toContain('@endsection')
        ->and(linkHtmlSent())->not->toContain('@extends')
        ->and(linkHtmlSent())->not->toContain('@section')
        ->and(linkHtmlSent())->not->toContain('{{')
        ->and(linkHtmlSent())->not->toContain('{!!');
});

it('loads set-password images from the configured assets host', function () {
    config()->set('mail.assets.url', 'https://cdn.example.com/email');

    sendSetPassword();

    preg_match_all('/(?:src|srcset)="([^"]+)"/', linkHtmlSent(), $found);

    expect($found[1])->not->toBeEmpty();

    foreach ($found[1] as $reference) {
        expect($reference)->toStartWith('https://cdn.example.com/email/');
    }
});

it('does not write the set-password link or token to the logs', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    sendSetPassword(token: 'secret-token-xyz');

    expect(linkHtmlSent())->toContain('secret-token-xyz')
        ->and(implode("\n", $logged))->not->toContain('secret-token-xyz');
});

it('words the set-password HTML as a Viewing Room pass, not a reset', function () {
    sendSetPassword();

    expect(linkHtmlSent())->toContain('Viewing Room pass')
        ->and(linkHtmlSent())->toContain('is ready')
        ->and(strtolower(linkHtmlSent()))->not->toContain('reset');
});

it('keeps Viewing Room wording out of the reset HTML', function () {
    sendReset();

    expect(linkHtmlSent())->toContain('tok-abc123')
        ->and(linkHtmlSent())->not->toContain('Viewing Room');
});
