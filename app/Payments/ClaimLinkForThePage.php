<?php

namespace App\Payments;

use App\Enums\ClaimLinkState;

/**
 * What the confirmation page is told about a claim link, issue #122.
 *
 * **The URL is whole or absent, never in parts and never an empty string.** It
 * is built by `FrontendUrl::setPassword()`, which already owns the address and
 * its `next`, so the page cannot assemble a half-built link from pieces. Only
 * `ready()` can carry one, and it refuses an empty string, so `null` is the one
 * spelling of "no link" this can produce.
 */
final class ClaimLinkForThePage
{
    private function __construct(
        public readonly ClaimLinkState $state,
        public readonly ?string $url,
    ) {}

    public static function ready(string $url): self
    {
        if ($url === '') {
            throw new \InvalidArgumentException('A claim link handed to the page cannot be empty.');
        }

        return new self(ClaimLinkState::Ready, $url);
    }

    public static function notYet(): self
    {
        return new self(ClaimLinkState::NotYet, null);
    }

    public static function none(): self
    {
        return new self(ClaimLinkState::None, null);
    }

    /**
     * @return array{set_password_link: string, set_password_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'set_password_link' => $this->state->value,
            'set_password_url' => $this->url,
        ];
    }
}
