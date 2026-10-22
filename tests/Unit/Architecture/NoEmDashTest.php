<?php

/**
 * No em dash survives in app/, config/ or routes/. CLAUDE.md section 7 ("No em
 * dashes, anywhere, ever") and hard rule 8.
 *
 * docs/plans/arch-tests.md, step 6.
 *
 * **Why a plain scan and not `arch()`.** Arch cannot read text.
 *
 * **Scope is `app/`, `config/` and `routes/` and nothing else** (decision 2,
 * 10 October 2026). Known hits in `resources/` (Blade comments), `database/`
 * (migration and seeder comments), `tests/` and `docs/` are deliberately left
 * alone. Do not widen this in passing.
 *
 * Every file is read, not only `.php`, and three spellings count: the character
 * U+2014, `&mdash;` and `&#8212;`.
 */

/**
 * @return list<string> the spellings of an em dash a line may contain
 */
function emDashSpellings(): array
{
    return ["\u{2014}", '&mdash;', '&#8212;'];
}

function lineHasEmDash(string $line): bool
{
    foreach (emDashSpellings() as $spelling) {
        if (stripos($line, $spelling) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string> absolute paths of every file under app/, config/, routes/
 */
function emDashScopedFiles(): array
{
    $root = dirname(__DIR__, 3);
    $paths = [];

    foreach (['app', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);

    return $paths;
}

/**
 * @return list<string> `path:line` for each line containing an em dash
 */
function emDashHits(): array
{
    $root = dirname(__DIR__, 3);
    $hits = [];

    foreach (emDashScopedFiles() as $path) {
        foreach (file($path) as $number => $line) {
            if (lineHasEmDash($line)) {
                $hits[] = substr($path, strlen($root) + 1).':'.($number + 1);
            }
        }
    }

    return $hits;
}

test('no em dash appears in app, config or routes', function (): void {
    // Guard against passing because the walk found nothing to scan.
    $files = emDashScopedFiles();
    expect(count($files))->toBeGreaterThanOrEqual(50);
    expect(implode("\n", $files))
        ->toContain('/app/Enums/ProductKey.php')
        ->toContain('/config/payments.php')
        ->toContain('/routes/');

    $hits = emDashHits();

    expect($hits)->toBe([], "Em dash found (CLAUDE.md section 7, hard rule 8). Use a comma or a period:\n".implode("\n", $hits));
});

test('the detector recognises every spelling of an em dash', function (string $line): void {
    expect(lineHasEmDash($line))->toBeTrue();
})->with([
    "// a \u{2014} b",
    'a &mdash; b',
    'a &MDASH; b',
    'a &#8212; b',
    "\u{2014}",
]);

test('the detector leaves other dashes and ordinary text alone', function (string $line): void {
    expect(lineHasEmDash($line))->toBeFalse();
})->with([
    'a - b',
    "a \u{2013} b",      // en dash
    '--no-interaction',
    'a &ndash; b',
    'a &#8211; b',
    'The Month Ahead, a forecast.',
]);
