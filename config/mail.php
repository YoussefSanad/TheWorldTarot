<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    | The same `?:` as the senders below, and for the same reason: a blank
    | variable is not an absent one, and a message with no From address is
    | refused by the transport rather than quietly delivered.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS') ?: 'support@theworldtarot.com',
        'name' => env('MAIL_FROM_NAME') ?: (env('APP_NAME') ?: 'The World Tarot'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Senders
    |--------------------------------------------------------------------------
    |
    | Which address each kind of message comes from. Decided 8 August 2026,
    | because a customer reads the sender before they read anything else.
    |
    | Named through App\Enums\Sender rather than typed at a call site, so an
    | address changes in one place. Every one of these has to sit on a domain
    | verified in Resend, `theworldtarot.com` today. Sending as anything else is
    | accepted and then filed as spam, which is a failure that reports success.
    |
    | Sending is not receiving. Verification authorises sending and creates no
    | mailbox. A reply to any of these goes nowhere until an MX record routes it
    | somewhere real, and nobody is told. See CLAUDE.md section 8.
    |
    | **`orders` left this list on 30 August 2026 and `noreply` took its place.**
    | The customer's confirmation used to come from orders@; Jennifer asked that
    | it come from noreply@ and that orders@ receive the new order notification
    | instead, so orders@ moved down to `notify` below. noreply@ sits on
    | `theworldtarot.com`, which is already verified, so this needed no new
    | domain verification and no wait on DNS before it could send.
    |
    | **Every address below falls back with `?:` and not with `env()`'s second
    | argument.** That argument applies only when a key is absent. A key that is
    | present and blank, which is what `.env.example` ships for the recipient
    | below and what a cleared field in a hosting panel leaves behind, gives an
    | empty string instead, and an empty address is carried all the way to the
    | transport and delivers nothing. Found on 13 August 2026, see below.
    |
    */

    'senders' => [
        'support' => env('MAIL_SUPPORT_ADDRESS') ?: 'support@theworldtarot.com',
        'noreply' => env('MAIL_NOREPLY_ADDRESS') ?: 'noreply@theworldtarot.com',
        'fulfillment' => env('MAIL_FULFILLMENT_ADDRESS') ?: 'fulfillment@theworldtarot.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where we write to
    |--------------------------------------------------------------------------
    |
    | Not senders, recipients. Where a message goes when it is for us rather
    | than for a customer.
    |
    | `orders` is where "somebody is waiting for a reading" lands, asked for on
    | 30 August 2026. `fulfillment` is where a chargeback lands, and it is where
    | both of them used to go.
    |
    | **They are two keys rather than one on purpose.** Pointing the single old
    | key at orders@ was the smaller change and the wrong one: it would have
    | left the key named for a mailbox it no longer reached, and it would have
    | quietly moved `PaymentDisputed` with it. A chargeback is not a new order
    | and it is the message with a bank deadline attached, so it stayed where it
    | already was, confirmed 30 August 2026.
    |
    | Both have to reach Jennifer, and neither can check that it did. **Sending
    | is not receiving**, and a notification nobody sees is worse than none,
    | because the system reports success while a customer waits.
    |
    | **orders@ is the half of this that is not code.** Sending from noreply@
    | needs nothing new, since the domain is verified. Receiving at orders@
    | needs a mailbox behind an MX record and somebody reading it. Point this at
    | orders@ before that exists and every new order notification is accepted by
    | Resend, delivered nowhere, and reported as a success. That is the 13 August
    | shape again, with a different address in it.
    |
    | **`fulfillment` is the one that was actually broken**, on 13 August 2026.
    | It read `env('MAIL_FULFILLMENT_RECIPIENT', env(...))`, `.env.example` ships
    | that key blank, and `composer setup` copies it into place. So the
    | documented default never applied anywhere the template had been used, the
    | address resolved to an empty string, and the only message telling Jennifer
    | there is work waiting was addressed to nobody. It reported success.
    |
    */

    'notify' => [
        'orders' => env('MAIL_ORDERS_RECIPIENT') ?: 'orders@theworldtarot.com',

        'fulfillment' => env('MAIL_FULFILLMENT_RECIPIENT')
            ?: env('MAIL_FULFILLMENT_ADDRESS')
            ?: 'fulfillment@theworldtarot.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where a designed message loads its images from
    |--------------------------------------------------------------------------
    |
    | Added 1 September 2026 with issue #53, the first message in this
    | application that is HTML.
    |
    | **An email client cannot resolve a relative path.** There is no document
    | base to resolve one against, so `src="/email/band-top.png"` is not a
    | broken image in some clients and fine in others, it is a broken image in
    | all of them. Every reference has to be absolute, which makes the host a
    | decision this file has to make rather than one a view can duck.
    |
    | **The files ship in `public/email/` and are served by this application.**
    | 100 KB of ornament for the gold frame, so no CDN is bought and no vendor
    | is added. Chosen over a Bunny zone, because Bunny is wrapped here for
    | Stream and for signing playback and serving a static PNG is a different
    | job, and over the frontend's `public/`, because a backend template's
    | dependency living in the frontend partner's repository breaks the first
    | time somebody tidies away a folder that looks unused.
    |
    | **The supplied files pointed at `theworldtarot-emails.netlify.app`, a
    | preview host**, and the designer's README names replacing it as the one
    | thing to do before integrating. This key is that replacement. A key rather
    | than a find-and-replace, so the next move, to a CDN in front of this app
    | or to the platform bucket, is an environment variable instead of an edit
    | to a 200 line view. `OrderPaidHtmlTest` fails on any reference that does
    | not start with it, so a half-finished move cannot reach an inbox.
    |
    | Falls back through `APP_URL` with `?:` rather than `env()`'s second
    | argument, for the reason the senders above do: a key that is present and
    | blank gives an empty string, and an empty string here is a receipt whose
    | every image is requested from whatever host the client decides
    | `/email/band-top.png` means.
    |
    */

    'assets' => [
        'url' => rtrim(env('MAIL_ASSETS_URL')
            ?: rtrim((string) (env('APP_URL') ?: 'http://localhost'), '/').'/email', '/'),
    ],

];
