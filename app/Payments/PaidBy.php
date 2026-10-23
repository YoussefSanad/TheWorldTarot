<?php

namespace App\Payments;

final class PaidBy
{
    public function __construct(public readonly string $intentId, public readonly int $amount) {}
}
