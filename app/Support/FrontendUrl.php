<?php

namespace App\Support;

/**
 * Links into the website.
 *
 * Every address a customer is sent to belongs to the frontend, never to this
 * API, because this API renders nothing a customer sees. Built here rather than
 * inside each mailable so the whole set moves together when the partner changes
 * a route, and so a staging email can never send somebody to production.
 */
class FrontendUrl
{
    /**
     * Where somebody goes to choose a new password.
     *
     * The email travels alongside the token because the reset is verified
     * against the pair. A token alone identifies nobody.
     */
    public static function resetPassword(string $email, string $token): string
    {
        return self::to(config('frontend.paths.reset_password'), [
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * Where somebody who bought before they had an account sets their first
     * password.
     *
     * A different page from the reset, and deliberately so. "Reset your
     * password" is confusing wording for a person who has never had one.
     */
    public static function setPassword(string $email, string $token): string
    {
        return self::to(config('frontend.paths.set_password'), [
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private static function to(string $path, array $query = []): string
    {
        $url = rtrim((string) config('frontend.url'), '/').'/'.self::page($path);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * The page's path, always ending in a slash.
     *
     * The website is a static export built with `trailingSlash`, confirmed by
     * the partner on 28 August 2026, so every address there ends in one and the
     * slashless form is a redirect. The configuration is still the authority on
     * **which** page we link to; this only settles the slash, because a value
     * typed without one into an environment dashboard is not a decision anybody
     * took and costs every mail we send a hop.
     *
     * A hop is not merely untidy on these two links. They carry a single-use
     * token, and a scanner that follows the redirect on the way to the inbox
     * spends it before the person reading ever clicks.
     */
    private static function page(string $path): string
    {
        return rtrim(ltrim($path, '/'), '/').'/';
    }
}
