<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubscribeToNewsletterRequest;
use App\Jobs\SubscribeToNewsletter;
use App\Support\NewsletterSignup;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NewsletterController extends Controller
{
    /**
     * Somebody asking to hear from us.
     *
     * Queued and answered immediately, per .claude/api.md, which forbids a
     * request that waits on a third party. What the visitor is told is that we
     * have their address, which is true whatever Mailchimp does next.
     *
     * **202, not 201.** Nothing has been created yet. It has been accepted.
     *
     * **The answer is identical for an address already on the list, a new one,
     * and one the list will refuse later.** Anything else turns a public
     * endpoint into a way to ask whether a given person subscribed, which is
     * somebody's private business and is exactly the kind of thing an
     * unauthenticated endpoint must not answer.
     */
    public function __invoke(SubscribeToNewsletterRequest $request): JsonResponse
    {
        if ($request->filled('website')) {
            // The honeypot caught something. Answered exactly like a success,
            // and thrown away. Refusing would tell whoever sent it which field
            // to leave alone next time, and a 422 is a free oracle.
            Log::info('A newsletter signup filled in the honeypot and was dropped.');

            return $this->accepted();
        }

        SubscribeToNewsletter::dispatch(new NewsletterSignup(
            email: $request->string('email')->toString(),

            // When they ticked the box, which is now. Not when the queue gets
            // to it, which could be six hours later after an outage, and which
            // would make the consent record describe the wrong moment.
            consentedAt: CarbonImmutable::now(),

            // The real visitor rather than a proxy, which is only true because
            // trusted proxies are configured. See App\Support\TrustedProxies.
            ip: $request->ip(),

            name: $request->string('name')->toString() ?: null,
        ));

        return $this->accepted();
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['subscribed' => true], 202);
    }
}
