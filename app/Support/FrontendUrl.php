<?php

namespace App\Support;

use App\Enums\ProductKey;

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
     * Where Stripe returns a customer who paid.
     *
     * **`{CHECKOUT_SESSION_ID}` is Stripe's own placeholder**, substituted into
     * the address when it sends the browser here, so it is appended after the
     * query is built and never encoded. The page hands the id straight back to
     * `POST /orders/status`, because a publishable key cannot resolve it.
     */
    public static function checkoutComplete(): string
    {
        return self::to(config('frontend.paths.checkout_complete'))
            .'?session_id={CHECKOUT_SESSION_ID}';
    }

    /**
     * A reading's page, or the index of them when no reading is named.
     *
     * Where a cancelled checkout lands. **The page is named by a `ProductKey`
     * and never by a string**, which is the whole reason `return_to` is
     * validated as an enum: the enum's values are already the frontend's slugs,
     * so nothing a caller typed is ever concatenated into an address.
     */
    public static function readings(?ProductKey $reading = null): string
    {
        $index = self::to(config('frontend.paths.readings'));

        return $reading instanceof ProductKey ? $index.$reading->value.'/' : $index;
    }

    /**
     * Where a cancelled checkout for this product lands.
     *
     * The pass returns to the viewing room and a reading to its own page.
     * Still named by a `ProductKey`, so nothing a caller typed reaches an
     * address.
     */
    public static function returnPage(ProductKey $product): string
    {
        return $product === ProductKey::ViewingRoomPass
            ? self::to(config('frontend.paths.viewing_room'))
            : self::readings($product);
    }

    /**
     * Where a recipient spends the code they were given.
     *
     * **The printed form goes in the link**, `XXXX-XXXX-XXXX`, because the same
     * characters are printed underneath it in the mail for somebody typing them
     * in by hand, and two spellings of one credential in one message is a
     * person wondering which of them is the real one. `/gifts/lookup` strips
     * the separators before it looks anything up, so the hyphens cost nothing
     * on the way back.
     *
     * **A query parameter and not a path segment.** The website is a static
     * export, so a path per code would have to be pre-rendered at build time,
     * which is not a thing codes can be. The frontend's third ADR, "redemption
     * is a page of its own", carries the reasoning and what it accepts about
     * browser history; `API_CONTRACT.md` carries our half of it, under Gifting.
     */
    public static function redeem(string $printedCode): string
    {
        return self::to(config('frontend.paths.redeem'), ['code' => $printedCode]);
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
