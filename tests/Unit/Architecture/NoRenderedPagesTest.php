<?php

/**
 * Controllers render nothing. CLAUDE.md section 1 ("never build customer-facing
 * UI in this repo. Filament is the only interface this project renders") and
 * hard rule 1.
 *
 * docs/plans/arch-tests.md, step 4.
 *
 * **Why a source scan and not `arch()`.** Steps 1 and 3 showed `not->toUse()`
 * matches by prefix on resolved names, and the plan itself doubts that arch sees
 * the `view()` helper (a function) or a facade alias. A scan of the text is at
 * least as strong and does not depend on what the parser can see. It is
 * case-insensitive and, like step 3, deliberately strict: a docblock that
 * contains `view(` also fails.
 *
 * **Mail views are allowed.** Mailables are not controllers: `app/Mail` renders
 * `resources/views/mail`. Only `app/Http/Controllers` is scanned.
 *
 * **The welcome page is exempt, by decision 4 (10 October 2026).**
 * `routes/web.php` returns `view('welcome')` at `/`. It is the stock Laravel
 * skeleton page, not something the brand's customers are sent to. It is a route
 * closure rather than a controller, so this rule does not see it. It is left in
 * place deliberately. Moving it into a controller would make this rule fail,
 * which is the intended prompt to revisit. No test asserts anything about `/`
 * either way, and the scan below never reads `routes/`.
 */

/*
 * Known limits. The scan reads text, so it cannot see:
 * - `app('view')->make(...)` or `resolve('view')`, a container lookup by string;
 * - returning a Mailable or Notification whose body is a view (rendering done
 *   elsewhere, in `app/Mail`, which is allowed);
 * - `response($html)` or `response($string)` where the HTML was built by
 *   something this scan does not name.
 * These are rarer than the helper and the facades and are left to review.
 */

/**
 * Patterns for every way a controller can render a page.
 *
 * @return list<string>
 */
function renderingPatterns(): array
{
    return [
        '/(?<![\w$>:])view\s*\(/i',                         // view('welcome'), \view('welcome')
        '/(->|::)\s*view\s*\(/i',                           // response()->view(...), Response::view(...)
        '/\bView\s*::/i',                                   // View::make(...)
        '/\bBlade\s*::/i',                                  // Blade::render(...)
        '/Illuminate\\\\{1,2}Support\\\\{1,2}Facades\\\\{1,2}(View|Blade)\b/i',
        '/Illuminate\\\\{1,2}(Contracts\\\\{1,2})?View\b/i', // factory, contract, Illuminate\View\*
        '/\bInertia\b/i',
    ];
}

/**
 * @return list<string> the patterns the line matches
 */
function renderingMatches(string $line): array
{
    return array_values(array_filter(renderingPatterns(), fn (string $p): bool => preg_match($p, $line) === 1));
}

/**
 * @return list<string> absolute paths of every .php file under app/Http/Controllers
 */
function controllerFiles(): array
{
    $base = dirname(__DIR__, 3).'/app/Http/Controllers';
    $paths = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

/**
 * @return list<string> `path:line` for each offending line under app/Http/Controllers
 */
function controllersThatRender(): array
{
    $root = dirname(__DIR__, 3);
    $hits = [];

    foreach (controllerFiles() as $path) {
        foreach (file($path) as $number => $line) {
            if (renderingMatches($line) !== []) {
                $hits[] = substr($path, strlen($root) + 1).':'.($number + 1);
            }
        }
    }

    return $hits;
}

test('no controller renders a view', function (): void {
    // Guard against passing because the walk found nothing to scan.
    expect(count(controllerFiles()))->toBeGreaterThanOrEqual(1);

    expect(controllersThatRender())->toBe([]);
});

test('the scan recognises every way of rendering a page', function (string $line): void {
    expect(renderingMatches($line))->not->toBe([]);
})->with([
    "return view('welcome');",
    "return VIEW ('welcome');",
    "return \\view('welcome');",
    "return response()->view('x');",
    "return Response::view('x');",
    "return View::make('x');",
    "return \\Illuminate\\Support\\Facades\\View::make('x');",
    'use Illuminate\Support\Facades\View;',
    'use Illuminate\Support\Facades\Blade;',
    'use Illuminate\View\View;',
    'use Illuminate\Contracts\View\Factory;',
    "return Blade::render('x');",
    "return Inertia::render('Home');",
]);

test('the scan leaves ordinary controller text alone', function (string $line): void {
    expect(renderingMatches($line))->toBe([]);
})->with([
    "Gate::authorize('enter-viewing-room');",
    '* The Viewing Room, and the paywall.',
    'return response()->json($data);',
    '$overview = $this->overview();',
    'return new ProductResource($product);',
]);
