<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The config files call database_path(), which needs a booted application, so
// this unit file boots one. The files are still required directly below.
uses(TestCase::class);

/**
 * What the database settings fall back to when `DB_CONNECTION` says nothing.
 *
 * **This file evaluates the config files themselves with the variable removed**,
 * because the value the running suite sees is whatever `.env` or phpunit.xml
 * set, which would make an assertion about the default pass or fail for reasons
 * unrelated to the default. Section 6 of CLAUDE.md: Postgres locally, never
 * SQLite, to match production. A silent SQLite fallback is how a laptop with no
 * `.env` ends up testing against a database production does not run.
 *
 * The variable is cleared from the Env repository, `$_ENV`, `$_SERVER` and
 * `putenv`, then restored, so no other test sees a difference.
 */
function configWithoutDbConnection(string $file): array
{
    $repository = Env::getRepository();

    $hadEnv = array_key_exists('DB_CONNECTION', $_ENV);
    $hadServer = array_key_exists('DB_CONNECTION', $_SERVER);
    $envValue = $_ENV['DB_CONNECTION'] ?? null;
    $serverValue = $_SERVER['DB_CONNECTION'] ?? null;
    $processValue = getenv('DB_CONNECTION');

    $repository->clear('DB_CONNECTION');
    unset($_ENV['DB_CONNECTION'], $_SERVER['DB_CONNECTION']);
    putenv('DB_CONNECTION');

    try {
        // Guard against asserting nothing: if the variable is still visible the
        // default was never exercised. Inside the try so a failure here still
        // restores the environment.
        expect(env('DB_CONNECTION'))->toBeNull();

        return require dirname(__DIR__, 2).'/config/'.$file.'.php';
    } finally {
        if ($hadEnv) {
            $_ENV['DB_CONNECTION'] = $envValue;
        }
        if ($hadServer) {
            $_SERVER['DB_CONNECTION'] = $serverValue;
        }
        if ($processValue !== false) {
            putenv('DB_CONNECTION='.$processValue);
        }
        if ($processValue !== false || $hadEnv || $hadServer) {
            $repository->set('DB_CONNECTION', (string) ($processValue !== false ? $processValue : ($envValue ?? $serverValue)));
        }
    }
}

test('the default database connection is pgsql when DB_CONNECTION is unset', function () {
    expect(configWithoutDbConnection('database')['default'])->toBe('pgsql');
});

test('job batching uses pgsql when DB_CONNECTION is unset', function () {
    expect(configWithoutDbConnection('queue')['batching']['database'])->toBe('pgsql');
});

test('failed jobs are stored in pgsql when DB_CONNECTION is unset', function () {
    expect(configWithoutDbConnection('queue')['failed']['database'])->toBe('pgsql');
});

test('the connection the suite actually uses is pgsql', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('an explicit DB_CONNECTION still wins over the default', function () {
    $repository = Env::getRepository();
    $previous = $repository->get('DB_CONNECTION');

    // The repository is immutable: clear() and set() do nothing for a variable
    // that came from outside it, such as a phpunit.xml <env> entry. The direct
    // writes to $_ENV, $_SERVER and putenv below are what actually change the
    // value env() returns. The clear() and set() calls are kept for the case
    // where the repository did load it.
    $repository->clear('DB_CONNECTION');
    $repository->set('DB_CONNECTION', 'mysql');
    $_ENV['DB_CONNECTION'] = 'mysql';
    $_SERVER['DB_CONNECTION'] = 'mysql';
    putenv('DB_CONNECTION=mysql');

    try {
        expect(env('DB_CONNECTION'))->toBe('mysql');

        $config = require dirname(__DIR__, 2).'/config/database.php';
    } finally {
        $repository->clear('DB_CONNECTION');
        unset($_ENV['DB_CONNECTION'], $_SERVER['DB_CONNECTION']);
        putenv('DB_CONNECTION');

        if ($previous !== null) {
            $repository->set('DB_CONNECTION', $previous);
            $_ENV['DB_CONNECTION'] = $previous;
            $_SERVER['DB_CONNECTION'] = $previous;
            putenv('DB_CONNECTION='.$previous);
        }
    }

    expect($config['default'])->toBe('mysql');
});
