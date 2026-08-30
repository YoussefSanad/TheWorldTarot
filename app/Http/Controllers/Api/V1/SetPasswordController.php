<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ClaimAccount;
use App\Enums\ClaimOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Claiming an account that was made for somebody when they bought something.
 *
 * Shares the token machinery with password reset, because the question is the
 * same one: does this person hold something that only reached their mailbox. It
 * is a separate endpoint because it answers a different question afterwards.
 *
 * **It works once, on an account that has never had a password.** That single
 * condition is what stops a claim link becoming a spare key: once the password
 * exists, this link is worth nothing, even inside the hour it is valid for, and
 * even to the person it was sent to. Changing a password after that is a reset,
 * which requires a fresh link to a mailbox somebody is currently reading.
 *
 * **The condition and the write both live in `ClaimAccount`**, which is the
 * whole reason that action exists. This controller is the mapping from what
 * happened to what a person reads, and it must not learn to ask the question
 * itself again: a check here and a write there is exactly the shape that let
 * two claims arriving together both win.
 */
class SetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, ClaimAccount $claim): JsonResponse
    {
        $outcome = $claim(
            $request->string('email')->value(),
            $request->string('token')->value(),
            (string) $request->input('password'),
        );

        if ($outcome !== ClaimOutcome::Claimed) {
            throw ValidationException::withMessages([
                'email' => [$this->wording($outcome)],
            ]);
        }

        return response()->json([
            'message' => 'Your account is ready. You can sign in now.',
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * What a refusal says, which is deliberately less than what it knows.
     *
     * An unknown address and a claimed account already arrive as one outcome,
     * so this cannot separate them even by accident. A token that is stale or
     * invented goes on saying so, on `ResetPasswordController`'s reasoning:
     * somebody holding a token learns nothing new from being told it is dead,
     * and not telling them leaves a person clicking a link with no idea why.
     */
    private function wording(ClaimOutcome $outcome): string
    {
        return $outcome === ClaimOutcome::AlreadyClaimed
            ? 'This link is no longer valid. Ask for a new one from the sign in page.'
            : __(Password::INVALID_TOKEN);
    }
}
