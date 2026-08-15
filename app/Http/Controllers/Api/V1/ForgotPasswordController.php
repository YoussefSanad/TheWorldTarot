<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Asking for a reset link.
 *
 * **The answer is the same whatever happened**, and that is the whole design of
 * this endpoint rather than a detail of it. A known address, an unknown one, and
 * one asking again too soon all produce an identical body and an identical
 * status, because any difference makes this a way to ask whether somebody has an
 * account here. That question is worth something to a stranger and is nobody
 * else's business.
 *
 * The consequence, accepted knowingly: somebody who mistypes their own address
 * is told the same reassuring thing as somebody who did not, and waits for mail
 * that is never coming. That is the cost of not answering the other question,
 * and the reason the wording says "if we have an account" rather than "sent".
 */
class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('customers')
            ->sendResetLink($request->only('email'));

        // Kept for support, who do get asked "I never received it". Nothing
        // about it reaches the response.
        if ($status !== Password::ResetLinkSent) {
            Log::info('Password reset link not sent.', [
                'reason' => $status,
                'email' => $request->string('email')->value(),
            ]);
        }

        return response()->json([
            'message' => 'If we have an account for that address, a reset link is on its way.',
        ])->header('Cache-Control', 'no-store');
    }
}
