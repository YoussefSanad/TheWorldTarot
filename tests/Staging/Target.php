<?php

namespace Tests\Staging;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * The deployed environment these tests talk to.
 *
 * **This suite is the opposite of the one in tests/Feature, and the difference
 * is the whole point of it.**
 *
 * The feature suite boots this application, fakes every third party, and proves
 * that our code does what we meant. It cannot prove that a deployed environment
 * works, and in M4 it stayed green for five days while playback was refused for
 * every viewer alive. `.claude/testing.md` exists because of that.
 *
 * Nothing here loads the application. There is no Laravel test case, no
 * database, no factory, no fake and no access to a single class of ours. These
 * tests know only a URL and what comes back from it, which is exactly what a
 * customer knows. **An assertion here fails when the deployment is wrong, not
 * when the code is wrong**, and that is the only kind of assertion worth making
 * about an environment.
 *
 * ## Running it
 *
 * ```bash
 * STAGING_URL=https://staging-api.theworldtarot.com composer test:staging
 * ```
 *
 * Without `STAGING_URL` every test skips itself loudly, which is why this can
 * sit in the repository without ever running by accident.
 *
 * ## What it writes
 *
 * Real customers and real orders, in whatever environment you point it at.
 * **Never point it at production.** It refuses to run against a host it does not
 * recognise as staging or local unless `STAGING_I_KNOW_WHAT_THIS_IS=1` is set.
 */
final class Target
{
    /**
     * Why this suite cannot run, or null if it can.
     *
     * Returned rather than thrown so each file can skip with the reason
     * attached, instead of a run of red tests that all mean "not configured".
     */
    public static function unavailable(): ?string
    {
        $url = getenv('STAGING_URL');

        if (! is_string($url) || $url === '') {
            return 'STAGING_URL is not set, so there is no deployed environment to test. See tests/Staging/README.md';
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return 'STAGING_URL is not a URL: '.$url;
        }

        // The one guard rail worth having. This suite registers accounts and
        // places orders, and doing that to production is not a test, it is a
        // mess somebody has to clean up out of a table that records money.
        $looksSafe = str_contains($host, 'staging')
            || str_contains($host, 'localhost')
            || str_contains($host, '127.0.0.1');

        if (! $looksSafe && getenv('STAGING_I_KNOW_WHAT_THIS_IS') !== '1') {
            return $host.' does not look like a staging host. This suite creates real customers and real orders. '
                .'If you genuinely mean it, set STAGING_I_KNOW_WHAT_THIS_IS=1';
        }

        return null;
    }

    public static function url(string $path = ''): string
    {
        return rtrim((string) getenv('STAGING_URL'), '/').$path;
    }

    /**
     * The origin the website will really be served from.
     *
     * It matters more than it looks. CORS and Sanctum's stateful domain list
     * are both keyed on it, and getting either wrong fails as something that
     * looks like a network problem rather than like a configuration one.
     */
    public static function origin(): string
    {
        $origin = getenv('STAGING_ORIGIN');

        return is_string($origin) && $origin !== ''
            ? rtrim($origin, '/')
            : 'https://staging.theworldtarot.com';
    }

    /**
     * A client that reports what happened rather than throwing about it.
     *
     * Redirects are not followed, because a redirect is frequently the answer
     * being tested: the admin panel sending an unauthenticated caller to its
     * login page is the proof that the guards are separate.
     */
    public static function client(?CookieJar $jar = null): Client
    {
        return new Client([
            'base_uri' => self::url('/'),
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 30,
            'cookies' => $jar ?? false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'the-world-tarot-staging-walk',
            ],
        ]);
    }

    /**
     * Whether this is a laptop rather than a deployed environment.
     *
     * A handful of checks are about how an environment is configured rather
     * than about what the application does, and locally they are meant to
     * fail: debug is on and the API reference is open, deliberately, because
     * that is what local development is for.
     */
    public static function isLocal(): bool
    {
        $host = (string) parse_url(self::url(), PHP_URL_HOST);

        return str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The session cookie, whatever it happens to be called.
     *
     * Found by shape rather than by name. It is called `theworldtarot-session`
     * today, it is named after `APP_NAME` so it changes if that does, and a
     * test that quietly finds nothing because it was looking for the wrong
     * string passes while proving nothing. It is the HttpOnly one that is not
     * the CSRF cookie.
     *
     * @return array<string, mixed>|null
     */
    public static function sessionCookie(CookieJar $jar): ?array
    {
        foreach ($jar->toArray() as $cookie) {
            if (($cookie['Name'] ?? '') !== 'XSRF-TOKEN' && ($cookie['HttpOnly'] ?? false) === true) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * One account, made once, shared by everything that just needs somebody.
     *
     * **Registration allows ten a minute per address**, and a suite that makes
     * an account per test spends its whole budget proving nothing and then
     * fails the rest with 429s that look like bugs. Anything needing an account
     * of its own, because it locks one out or buys something with it, still
     * makes one. Everything else shares this.
     *
     * @return array{email: string, token: string}
     */
    public static function sharedAccount(): array
    {
        return self::$shared ??= self::register('shared');
    }

    /** @var array{email: string, token: string}|null */
    private static ?array $shared = null;

    /**
     * @return array{email: string, token: string}
     */
    public static function register(string $tag): array
    {
        $email = self::freshEmail($tag);

        $response = self::client()->post('api/v1/register', [
            'json' => [
                'name' => 'Staging Walk',
                'email' => $email,
                'password' => self::password(),
                'password_confirmation' => self::password(),
                'device_name' => 'staging walk',
            ],
        ]);

        // Said plainly rather than left as a mystery 429 three files later.
        if ($response->getStatusCode() === 429) {
            Assert::markTestSkipped(
                'Registration is rate limited, ten a minute per address. Wait a minute and run this again.'
            );
        }

        if ($response->getStatusCode() !== 201) {
            Assert::fail(
                'Could not register an account to test with. '.$response->getStatusCode().': '.$response->getBody()
            );
        }

        $body = self::json($response);

        return ['email' => $email, 'token' => (string) $body['token']];
    }

    /**
     * @param  array{email: string, token: string}  $account
     * @return array<string, string>
     */
    public static function as(array $account): array
    {
        return ['Authorization' => 'Bearer '.$account['token']];
    }

    /**
     * A 429 where a 429 makes no sense, said in words rather than as a number.
     *
     * Every route carrying an inline `throttle:n,1` shares **one counter per
     * address**, because Laravel keys a guest's limiter on the domain and the
     * IP and never on the route. So the strictest limit in the application
     * applies to the sum of every throttled route a visitor touches, and the
     * first symptom is a 429 from an endpoint they have not called at all.
     *
     * Proved against a deployed environment on 13 August 2026: six requests to
     * `/api/v1/orders`, then one to `/api/v1/forgot-password`, which answered
     * 429 on its first call of the minute.
     */
    public static function assertNotThrottled(ResponseInterface $response, string $route): void
    {
        if ($response->getStatusCode() !== 429) {
            return;
        }

        Assert::fail(
            $route.' answered 429 without being called enough times to deserve it. '
            .'Every inline throttle in routes/api.php shares one bucket per address, so a handful of requests '
            .'to any of them locks a visitor out of all of them. Give each throttle its own prefix, '
            .'as in throttle:6,1,forgot-password. See tests/Staging/README.md.'
        );
    }

    /**
     * An address nothing has used before.
     *
     * **Set `STAGING_EMAIL` to your own address and every message this suite
     * triggers lands in your inbox**, through plus addressing, while each walk
     * still registers as a separate customer. That is the one part of this walk
     * no assertion can cover: whether a message really arrived, who it came
     * from, whether it escaped the spam folder, and whether its link points at
     * the website, are all knowable only from inside a mailbox.
     *
     * `STAGING_EMAIL_DOMAIN` is the older, blunter version and still works.
     *
     * The default is `example.com`, which is reserved and has no MX record, so
     * anything sent there hard bounces. That is deliberate for a suite nobody
     * is watching, and it is a reason to set a real address when you are.
     */
    public static function freshEmail(string $tag): string
    {
        $unique = sprintf('walk-%s-%s', $tag, bin2hex(random_bytes(4)));

        $inbox = getenv('STAGING_EMAIL');

        if (is_string($inbox) && str_contains($inbox, '@')) {
            [$local, $domain] = explode('@', $inbox, 2);

            return sprintf('%s+%s@%s', $local, $unique, $domain);
        }

        $domain = getenv('STAGING_EMAIL_DOMAIN');

        if (! is_string($domain) || $domain === '') {
            $domain = 'example.com';
        }

        return sprintf('%s@%s', $unique, $domain);
    }

    /**
     * Long enough to clear any password rule, and fixed so a failed run leaves
     * an account somebody can still sign into while working out why.
     */
    public static function password(): string
    {
        return 'staging walk correct horse battery staple';
    }

    /**
     * Something the tester can see while a test waits on them.
     *
     * Written to stderr rather than returned, because it is an instruction to a
     * person rather than part of any result.
     */
    public static function say(string $message): void
    {
        fwrite(STDERR, PHP_EOL.$message.PHP_EOL);
    }
}
