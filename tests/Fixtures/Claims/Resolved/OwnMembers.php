<?php

namespace Tests\Fixtures\Claims\Resolved;

/**
 * A docblock enumerating names the class declares itself.
 *
 * "Its enum" is not the only way a class owns a name. These are its own, so
 * they resolve without any enum being involved.
 *
 * - `Withdrawn` is a constant on this class
 * - `settle` is a method on this class
 * - `reason` is a property on this class
 */
class OwnMembers
{
    public const Withdrawn = 'withdrawn';

    public function __construct(private readonly string $reason) {}

    public function settle(): string
    {
        return $this->reason;
    }
}
