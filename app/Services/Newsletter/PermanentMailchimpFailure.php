<?php

namespace App\Services\Newsletter;

use App\Contracts\PermanentFailure;

/**
 * A Mailchimp refusal that will never succeed.
 *
 * A subclass rather than a flag on the parent, so the marker interface can be
 * asked about with `instanceof` and no caller has to remember to read a
 * property. See App\Contracts\PermanentFailure.
 */
class PermanentMailchimpFailure extends MailchimpRequestFailed implements PermanentFailure {}
