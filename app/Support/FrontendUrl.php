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
     * @param  array<string, string>  $query
     */
    private static function to(string $path, array $query = []): string
    {
        $url = rtrim((string) config('frontend.url'), '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
