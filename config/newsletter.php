<?php

use App\Services\Newsletter\LoggedNewsletterList;
use App\Services\Newsletter\MailchimpAudience;

return [

    /*
    |--------------------------------------------------------------------------
    | Which list people join
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4.
    | Which service holds the mailing list is a decision, not a value Jennifer
    | owns, so it lives here rather than in the database.
    |
    | Replacing the service means writing a class against
    | App\Contracts\NewsletterList and naming it here. No migration, no change to
    | the endpoint, and nothing for the frontend to do.
    |
    | **The switch exists because both deployed environments share one audience.**
    | Decided 14 August 2026. Without a way to opt out, every developer running
    | the form locally would be adding rows to the list Jennifer mails on launch
    | day. Set NEWSLETTER_DRIVER=log locally and signups go to the log instead.
    |
    | Deployed environments leave it unset and get Mailchimp. A driver name that
    | is not recognised also gets Mailchimp, deliberately: a typo in an env file
    | must not be a quiet way to stop collecting subscribers.
    |
    */

    'list' => match (env('NEWSLETTER_DRIVER', 'mailchimp')) {
        'log' => LoggedNewsletterList::class,
        default => MailchimpAudience::class,
    },

];
