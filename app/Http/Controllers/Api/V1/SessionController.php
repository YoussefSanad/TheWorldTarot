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
     * An account can exist with no password at all, created by somebody buying
     * something before they have chosen one.
     *
     * **The null check is belt and braces, and measured as such.** Removing it
     * on 9 August 2026 changed no test, because `Hash::check` against null
     * already returns false. It stays because that is Laravel's behaviour
     * rather than our guarantee, and because the next person to authenticate a
     * customer may not route through here. It is not what makes an unclaimed
     * account safe today, and this comment used to say that it was.
     *
     * **Every path pays for exactly one bcrypt, and that is the whole point.**
     * Returning early on a missing account skipped the only expensive thing on
     * this route, which put the answer on a stopwatch. Measured 20 August 2026
     * at ten rounds: 0.9ms for an address we have never seen, 43.6ms for one we
     * have. Production runs twelve, so the gap there is wider again.
     *
     * The two channels this endpoint already guards, status and body, were
     * identical while a third was wide open, and it separated three states
     * rather than two, because an unclaimed account short circuited the same
     * way. No account, unclaimed account and claimed account were all
     * distinguishable from anywhere in the world, without a single failed sign
     * in being needed to learn it.
     *
     * So a missing account or an unusable password hashes the supplied password
     * and throws the result away. Hashing costs what checking costs, both being
     * the same key derivation at the same rounds, and there is nothing to
     * compare it against. A dummy hash held in a static would do the same job
     * and would have to be built at the configured rounds and kept correct as
     * they change, which is a second thing to get wrong for no gain.
     */
    private function credentialsMatch(?Customer $customer, string $password): bool
    {
        if ($customer === null || $customer->password === null) {
            Hash::make($password);

            return false;
        }

        return Hash::check($password, $customer->password);
    }
}
