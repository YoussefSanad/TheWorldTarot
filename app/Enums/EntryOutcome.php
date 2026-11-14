<?php

namespace App\Enums;

/**
 * What `POST /orders/enter` may do for the browser holding a pay token, issue
 * #128. Each case is one status code, and the page reads nothing else.
 */
enum EntryOutcome
{
    /** Every bound held and the single use was taken here. Sign them in. 200 */
    case Enter;

    /** Not settled yet, and nothing was spent. Ask again. 409 */
    case NotYet;

    /** This token will never sign anybody in. "Sign in to enter". 403 */
    case Never;
}
