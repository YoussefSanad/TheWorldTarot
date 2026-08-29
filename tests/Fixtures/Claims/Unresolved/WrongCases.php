<?php

namespace Tests\Fixtures\Claims\Unresolved;

use App\Enums\PaymentInstructionType;

/**
 * A docblock listing names its enum does not declare.
 *
 * This is the `PaymentInstruction` defect in miniature: the list reads as the
 * answers this can give, and two of them are names nothing declares.
 *
 * - `NothingToPay` is a case the enum has, and resolves
 * - `PayLater` is a name nothing here declares
 * - `ManualSettlement` is a real class elsewhere and still not a case here,
 *   which is why resolving the name somewhere else is not the question
 * - `PayOnCollection` was withdrawn before M5, so it is not offered today and
 *   is not reported
 */
class WrongCases
{
    public function __construct(public readonly PaymentInstructionType $type) {}
}
