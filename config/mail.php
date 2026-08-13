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
        'orders' => env('MAIL_ORDERS_ADDRESS') ?: 'orders@theworldtarot.com',
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
    | `fulfillment` is where "somebody is waiting for a reading" lands, so it
    | has to reach Jennifer. It defaults to the address it is sent from, which
    | works only if that mailbox exists and she reads it. **Sending is not
    | receiving**, and a notification nobody sees is worse than none, because
    | the system reports success while a customer waits.
    |
    | **This is the one that was actually broken**, on 13 August 2026. It read
    | `env('MAIL_FULFILLMENT_RECIPIENT', env(...))`, `.env.example` ships that
    | key blank, and `composer setup` copies `.env.example` into place. So the
    | documented default never applied anywhere the template had been used, the
    | address resolved to an empty string, and the only message telling Jennifer
    | there is work waiting was addressed to nobody. It reported success.
    |
    */

    'notify' => [
        'fulfillment' => env('MAIL_FULFILLMENT_RECIPIENT')
            ?: env('MAIL_FULFILLMENT_ADDRESS')
            ?: 'fulfillment@theworldtarot.com',
    ],

];
