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
 */
class SetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $customer = Customer::firstWhere('email', $request->string('email')->value());

        if ($customer === null || $customer->password !== null) {
            // Deliberately the same message for both, so this does not become a
            // way to ask whether an address has an account or whether that
            // account has been claimed.
            throw ValidationException::withMessages([
                'email' => ['This link is no longer valid. Ask for a new one from the sign in page.'],
            ]);
        }

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password): void {
                $customer->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($customer));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Your account is ready. You can sign in now.',
        ])->header('Cache-Control', 'no-store');
    }
}
