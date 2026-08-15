<?php

namespace App\Contracts;

/**
 * A failure that retrying cannot fix.
 *
 * A marker, carried by an exception, so a queued job can tell "the service was
 * having a bad minute" from "this will refuse identically forever". The first
 * deserves the full retry window. The second deserves to stop immediately, and
 * spending twenty attempts over six hours on a malformed address is a worker
 * doing nothing slowly.
 *
 * **Deliberately vendor neutral, and it lives here rather than beside any one
 * service.** The distinction is real for every third party this system talks
 * to, and a job that had to catch `MailchimpRequestFailed` by name to make it
 * would have learned which service it was talking to, which is the whole thing
 * the interfaces in this directory exist to prevent.
 *
 * Which failures are permanent is knowledge the implementation holds, since
 * only it knows what its service's status codes mean.
 */
interface PermanentFailure {}
