<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\Customer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Choosing the new password.
 *
 * Unlike asking for the link, this one does say when it failed. By the time
 * somebody is here they hold a token, so telling them it has expired reveals
 * nothing they could not already work out, and not telling them leaves a person
 * clicking a dead link with no idea why.
 */
class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password): void {
                $customer->forceFill([
                    // The `hashed` cast does the hashing on assignment.
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // **Every token this customer holds dies here.** Somebody
                // resetting a password is often somebody who thinks another
                // person has it, and leaving a phone signed in would make the
                // reset a gesture rather than a remedy.
                //
                // Browser sessions on other devices are not killed by this and
                // survive until they expire. Saying so plainly because it is a
                // real gap rather than an oversight, and closing it means
                // reaching into the session store.
                $customer->tokens()->delete();

                event(new PasswordReset($customer));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been changed. You can sign in with it now.',
        ])->header('Cache-Control', 'no-store');
    }
}
