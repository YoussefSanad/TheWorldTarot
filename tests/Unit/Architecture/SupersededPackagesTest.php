<?php

/**
 * Cashier cannot come back. CLAUDE.md section 2: "Superseded, treat as bugs if
 * seen anywhere: ... Cashier (now the plain Stripe SDK)".
 *
 * docs/plans/arch-tests.md, step 3.
 *
 * **Why a source scan and not `arch()`.** Cashier is not installed, so its
 * classes never resolve, and `not->toUse()` / `ignoring()` match by prefix on
 * resolved names (seen in step 1). An `arch()` rule on a package that is absent
 * cannot be trusted to see anything, and could not be proven red without
 * installing the package, so it would be decoration. The scan reads text, is
 * case-insensitive (PHP class names are), and also catches a name in a string
 * or comment, which is deliberately strict.
 */

/**
 * Every PHP file under `app/`, `config/`, `routes/` and `bootstrap/` that names
 * `Laravel\Cashier`, as `path:line`. CLAUDE.md section 2 says "seen anywhere",
 * so a config entry or a provider listed in bootstrap counts, not only classes.
 * Matches the single-backslash form and the escaped `Laravel\\Cashier` form.
 * `bootstrap/cache` is skipped: it is generated and gitignored.
 *
 * @return list<string>
 */
function cashierReferencesInCode(): array
{
    $root = dirname(__DIR__, 3);
    $hits = [];

    foreach (['app', 'config', 'routes', 'bootstrap'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/laravel\\\\{1,2}cashier/i', $line) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1).':'.($number + 1);
                }
            }
        }
    }

    sort($hits);

    return $hits;
}

test('Cashier is named by nothing in app, config, routes or bootstrap', function (): void {
    expect(cashierReferencesInCode())->toBe([]);
});

test('Cashier is absent from composer.json require and require-dev', function (): void {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // Guard against the test passing because the sections were not read.
    expect($composer['require'])->toHaveKey('stripe/stripe-php');

    $declared = array_map('strtolower', array_merge(
        array_keys($composer['require'] ?? []),
        array_keys($composer['require-dev'] ?? []),
    ));

    // Deliberately broader than `laravel/cashier`: any package whose name contains
    // "cashier" (cashier-paddle, a fork, a vendor namespace) is the same mistake.
    expect(array_values(array_filter($declared, fn (string $name): bool => str_contains($name, 'cashier'))))->toBe([]);
});
