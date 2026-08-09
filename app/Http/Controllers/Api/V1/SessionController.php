<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    use IssuesCredentials;

    /**
     * Signing in.
     *
     * **The failure never says which half was wrong.** An unknown email and a
     * wrong password give the same message on the same field, because telling
     * them apart turns this endpoint into a way to ask whether somebody has an
     * account here, which is a question about a tarot customer that is nobody
     * else's business.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $customer = Customer::where('email', $request->string('email'))->first();

        if (! $this->credentialsMatch($customer, (string) $request->input('password'))) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        return $this->issueCredentials($request, $customer);
    }

    /**
     * Signing out, from whichever way they came in.
     *
     * A token is deleted, and only the one that made this request. Signing out
     * of a phone must not sign the same person out of their laptop, which is
     * what deleting every token would do.
     */
    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Cookie auth answers this with a TransientToken, which represents the
        // session rather than a row, and has nothing to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * Whether these are really them.
     *
     * The null password check is not defensive clutter. From step 5 an account
     * can exist without one, created by somebody buying something before they
     * have set a password, and `Hash::check` against nothing must never be the
     * only thing standing in the way of that account.
     */
    private function credentialsMatch(?Customer $customer, string $password): bool
    {
        if ($customer === null || $customer->password === null) {
            return false;
        }

        return Hash::check($password, $customer->password);
    }
}
