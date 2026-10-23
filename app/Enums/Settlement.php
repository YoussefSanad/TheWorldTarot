<?php

namespace App\Enums;

enum Settlement
{
    case Settled;
    case AlreadyPaid;
    case PaidTwice;
    case Gone;
}
