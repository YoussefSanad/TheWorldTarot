<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Making an account, from the website or from a future native app.
 *
 * Registering signs you in. Asking somebody to type a password twice, once to
 * choose it and once to prove it, is a step that exists only because the system
 * was easier to build that way.
 *
 * Email verification is deliberately absent, decided 8 August 2026. Nothing in
 * this milestone gates on a verified address, password reset already proves
 * somebody holds the mailbox, and machinery that nothing reads is worse than no
 * machinery. See docs/plans/milestone-5-plan.md, open question 2.
 */
class RegisterController extends Controller
{
    use IssuesCredentials;

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $customer = Customer::create($request->safe()->only(['name', 'email', 'password']));

        return $this->issueCredentials($request, $customer, 201);
    }
}
