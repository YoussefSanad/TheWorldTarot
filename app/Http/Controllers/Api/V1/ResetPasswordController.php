<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesCredentials;
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
 *
 * **A successful reset signs the customer in**, since 12 November 2026, issue
 * #124. ~~It answered a message and no session, and the website showed a link
 * to the sign in page.~~ It ends the way signing in does, through
 * `IssuesCredentials::signInFromPasswordLink()`, which says why a valid link is
 * enough. A pass receipt has sent a claimed buyer here since #114, under the
 * same "Set your password" button a first purchase gets, so this is the same
 * walk as a claim to the person taking it.
 */
class ResetPasswordController extends Controller
{
    use IssuesCredentials;

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $reset = null;

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password) use (&$reset): void {
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
                // ~~Browser sessions on other devices are not killed by this and
                // survive until they expire. Saying so plainly because it is a
                // real gap rather than an oversight, and closing it means
                // reaching into the session store.~~
                //
                // Corrected 12 November 2026, issue #124: they mostly are, and
                // not by this line. Sanctum's `AuthenticateSession`, in the
                // stateful pipeline by default, keeps a hash of the password
                // in each session and signs one out on the first request
                // whose hash no longer matches. A session that signed in and
                // has made no request since holds no hash yet and survives.
                // `SignInAfterPasswordLinkTest` pins the part that works.
                $customer->tokens()->delete();

                event(new PasswordReset($customer));

                $reset = $customer;
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->signInFromPasswordLink(
            $request,
            $reset,
            'Your password has been changed. You can sign in with it now.',
        );
    }
}
