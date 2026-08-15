<?php

namespace App\Services\Newsletter;

use App\Contracts\NewsletterList;
use App\Support\NewsletterSignup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Everything this application knows about Mailchimp.
 *
 * One thing, in fact: put an address on the audience. Nothing else here talks
 * to them, so when their API changes there is one file to fix.
 *
 * Named in config/newsletter.php. The audience id is config, never an argument,
 * because which list a signup joins is a deployment decision and not something
 * a caller should be able to choose. See .claude/external-services.md.
 */
final class MailchimpAudience implements NewsletterList
{
    /**
     * Mailchimp's word for "this address is already on the list".
     *
     * Matched on `title`, which is their stable machine-ish field, rather than
     * on `detail`, which is a sentence written for a person and carries the
     * address inside it.
     */
    private const ALREADY_ON_THE_LIST = 'Member Exists';

    public function subscribe(NewsletterSignup $signup): void
    {
        try {
            $response = Http::withBasicAuth('key', $this->configured('key'))
                ->timeout((int) config('services.mailchimp.timeout'))
                ->acceptJson()
                ->post($this->endpoint(), $this->body($signup));
        } catch (ConnectionException $e) {
            // Never reached them at all. Always worth trying again, so this is
            // deliberately not permanent.
            throw MailchimpRequestFailed::transient(0, $e->getMessage());
        }

        if ($response->successful() || $this->alreadyOnTheList($response)) {
            return;
        }

        throw $this->refusal($response);
    }

    /**
     * **Already subscribed is success, and that is what makes this safe to run
     * twice.**
     *
     * Managed queues deliver at least once, so this method will be called again
     * for somebody who is already on the list, and treating Mailchimp's 400 as
     * a failure would turn every redelivery into a retry storm ending in a
     * failed job for a person who is perfectly well subscribed.
     *
     * It also covers a case worth being explicit about: somebody who once
     * unsubscribed gets this same answer, and returning quietly is exactly
     * right. Signing them up again because they filled in a form is how a
     * mailing list earns a spam complaint.
     */
    private function alreadyOnTheList(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('title') === self::ALREADY_ON_THE_LIST;
    }

    /**
     * Which refusals are worth retrying.
     *
     * 429 is Mailchimp rate limiting us, which is the definition of temporary
     * and is exactly what a launch spike looks like. Treating it as permanent
     * would drop addresses at the one moment the list is filling up.
     *
     * Everything else in the 4xx range is our fault and stays our fault: a
     * malformed address, a rejected key, an audience id that does not exist.
     * 5xx is theirs and may pass.
     */
    private function refusal(Response $response): MailchimpRequestFailed
    {
        $permanent = $response->status() !== 429 && $response->clientError();

        return $permanent
            ? MailchimpRequestFailed::permanent($response->status(), $response->body())
            : MailchimpRequestFailed::transient($response->status(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(NewsletterSignup $signup): array
    {
        $body = [
            'email_address' => $signup->email,

            // Single opt-in, decided 13 August 2026. `pending` here would make
            // Mailchimp send a confirmation email and hold the contact until
            // they clicked it.
            'status' => 'subscribed',
        ];

        // Omitted rather than sent as null. Mailchimp validates these when they
        // are present, and an explicit null is a value that fails validation
        // rather than a field that was not offered.
        if (filled($signup->ip)) {
            $body['ip_signup'] = $signup->ip;
        }

        if (filled($signup->name)) {
            $body['merge_fields'] = ['FNAME' => $signup->name];
        }

        // Their format, in UTC. Not ISO 8601, which they accept elsewhere and
        // not here.
        $body['timestamp_signup'] = $signup->consentedAt->utc()->format('Y-m-d H:i:s');

        return $body;
    }

    private function endpoint(): string
    {
        $prefix = $this->configured('server_prefix');

        return "https://{$prefix}.api.mailchimp.com/3.0/lists/{$this->configured('audience_id')}/members";
    }

    /**
     * A missing credential is a configuration failure, not a request failure.
     *
     * Said in words, because the alternative is a 401 that reads like a revoked
     * key and sends somebody looking in the wrong dashboard.
     */
    private function configured(string $key): string
    {
        $value = config("services.mailchimp.{$key}");

        if (blank($value)) {
            throw MailchimpRequestFailed::misconfigured('MAILCHIMP_'.strtoupper($key === 'key' ? 'api_key' : $key));
        }

        return (string) $value;
    }
}
