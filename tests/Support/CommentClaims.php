<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;

/**
 * The claims comments make about other files, and whether they still hold.
 *
 * `CLAUDE.md` section 0.6 prefers a comment to a document because a comment
 * cannot drift from the code it sits above. That is true about the code it sits
 * above and about nothing else. A comment naming a document, a config file, a
 * section of `CLAUDE.md` or an enum case is making a claim about another file,
 * and until this existed nothing checked that the claim still held. Two had
 * already gone wrong silently: `config/scramble.php` sent the frontend partner
 * to a document deleted after M1, and the `PaymentInstruction` docblock listed
 * three names its enum does not declare.
 *
 * The shape is `tests/Feature/MailTransportTest.php`, the case named "queues
 * every message this application can send": enumerate the source, assert a
 * repository-wide convention, and say why. That test's comment is the argument
 * for this one. A convention nothing checks holds until the first time somebody
 * is in a hurry.
 *
 * **Claims are collected, never thrown at the first one**, for the same reason
 * `refusals()` in `App\Purchasability\Purchasability` returns every refusal: a
 * run that reports everything wrong is one fix cycle, and a run that stops at
 * the first is as many cycles as there are defects.
 *
 * **The trees to scan are given rather than assumed**, so the test can point
 * this at fixtures for the cases that must fail and at the repository for the
 * case that must not. A checker that could only ever run against the whole
 * repository could not be shown to catch anything.
 *
 * A fourth claim type is an addition here, not a rewrite: a pattern, a
 * resolver, and one more call in `read`.
 */
final class CommentClaims
{
    /**
     * A markdown path, as a comment writes one.
     *
     * The lookbehind is what keeps a URL out: nothing may sit immediately
     * before the first segment, so `https://example.com/thing.md` offers no
     * position where a match can start.
     */
    private const DOCUMENT = '/(?<![\w\/.-])((?:[\w.-]+\/)*[\w.-]+\.md)/';

    /**
     * `config/name.php` written out, and `config('name.key')` called.
     *
     * The extension is optional because comments write it both ways, and a key
     * path after the file name stops the match at the file, which is the only
     * part this asks about.
     */
    private const CONFIG_FILE = '/(?<![\w\/.-])config\/([\w-]+)(?:\.php)?\b/';

    private const CONFIG_CALL = '/\bconfig\(\s*[\'"]([\w-]+)(?:\.[^\'"]*)?[\'"]/';

    /**
     * A section of `CLAUDE.md`, cited either way round, and a hard rule.
     *
     * Only citations that name `CLAUDE.md` are read. `API_CONTRACT.md` and
     * `.claude/translation.md` number their own sections, and "section 9"
     * alone does not say whose. Hard rules need no attribution: `CLAUDE.md` is
     * the only document here that has any.
     */
    private const SECTION_OF = '/\bsections?\s+(\d+(?:\.\d+)?)\s+of\s+[`\'"]?CLAUDE\.md/i';

    private const SECTION_IN = '/CLAUDE\.md[`\'"]?[,:]?\s+sections?\s+(\d+(?:\.\d+)?)/i';

    private const HARD_RULE = '/\bhard\s+rules?\s+(\d+)/i';

    /**
     * A bullet opening with a backticked name, which is how this repository
     * writes an enumeration of what a class offers.
     */
    private const ENUMERATED = '/^\s*(?:[-*+]|\d+\.)\s+(?:\*\*|_)?`([A-Za-z_][A-Za-z0-9_]*)`/';

    /**
     * Prose marking a name as one the class used to offer, or will.
     *
     * `CLAUDE.md` section 0.6 requires a reversed decision to be struck through
     * and dated rather than deleted, and the repository does it: `FilmSource`
     * explains it was once `RevealVideoSource`, and `PaymentInstruction`
     * explains gift code redemption will answer the same as hand settlement
     * once the gifting milestone exists. Both have to survive. This is the one
     * place the check reads intent, and it reads it narrowly: only a name the
     * prose presents as currently offered is a claim.
     */
    private const TENSED = '/\b(?:used to|formerly|previously|once called|once named|renamed|no longer|until|withdrawn|removed|replaced|superseded|will|would|going to|not yet|planned|future|milestone)\b/i';

    /** @var array<int, array{0: string, 1: int, 2: string}> */
    private array $unresolved = [];

    /** @var array<int, string>|null */
    private ?array $sections = null;

    private ?int $rules = null;

    /**
     * @param  array<int, string>  $trees  paths relative to the root, each a directory or a file
     */
    private function __construct(
        private readonly string $root,
        private readonly array $trees,
    ) {}

    /**
     * @param  array<int, string>  $trees
     */
    public static function under(string $root, array $trees): self
    {
        return new self(rtrim($root, '/'), $trees);
    }

    /**
     * Every claim in the given trees that no longer resolves.
     *
     * Each reads "path:line  what was claimed", because a failure that does not
     * name the line is a search rather than a fix.
     *
     * @return array<int, string>
     */
    public function unresolved(): array
    {
        $this->unresolved = [];

        foreach ($this->files() as $file) {
            $this->read($file);
        }

        usort(
            $this->unresolved,
            fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]],
        );

        return array_map(
            fn (array $note): string => "{$note[0]}:{$note[1]}  {$note[2]}",
            $this->unresolved,
        );
    }

    /**
     * @return array<int, string>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->trees as $tree) {
            $path = $this->root.'/'.trim($tree, '/');

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($entries as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * **The tokenizer rather than a regular expression over the file text.**
     *
     * `token_get_all` tells a comment from a string that happens to contain
     * comment syntax, and hands back the line the comment starts on. A regex
     * over raw text would report a path inside a string literal as a comment
     * claim, and would have no line to name.
     */
    private function read(string $file): void
    {
        $tokens = token_get_all((string) file_get_contents($file));

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                [$prose, $lines] = self::prose($text, $line);

                $this->documents($file, $prose, $lines);
                $this->configs($file, $prose, $lines);
                $this->citations($file, $prose, $lines);

                continue;
            }

            /*
             * **A config file's string values are prose too**, and the one that
             * started this is: `config/scramble.php` renders its description on
             * `/docs/api`, where the frontend partner reads it. A dead path
             * there is the only one in this repository a stranger reaches
             * first, which is exactly the reader least able to do anything
             * about it.
             *
             * Values are read for documents only, and only in `config/`.
             * Everywhere else a string is data, and a path inside one is not a
             * claim anybody is making.
             */
            if ($id === T_CONSTANT_ENCAPSED_STRING && basename(dirname($file)) === 'config') {
                [$prose, $lines] = self::prose($text, $line);

                $this->documents($file, $prose, $lines, written: true);
            }
        }

        $this->symbols($file, $tokens);
    }

    /**
     * A comment as sentences, with every character's line kept.
     *
     * Decoration is stripped and wrapping is undone, because
     * `.claude/translation.md` and the section number it carries are routinely
     * on separate lines and a citation split across a line break is the same
     * citation. The line each character came from is carried alongside, so a
     * claim found in the joined-up text is still reported where it was written.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private static function prose(string $text, int $line): array
    {
        $prose = '';
        $lines = [];
        $space = false;

        foreach (preg_split('/\R/', $text) ?: [] as $offset => $source) {
            $source = (string) preg_replace('/^\s*(\/\*+|\*+\/|\*|\/\/|#)/', ' ', $source);

            foreach (str_split($source === '' ? ' ' : $source) as $character) {
                if (ctype_space($character)) {
                    $space = true;

                    continue;
                }

                if ($space && $prose !== '') {
                    $prose .= ' ';
                    $lines[] = $line + $offset;
                }

                $space = false;
                $prose .= $character;
                $lines[] = $line + $offset;
            }
        }

        return [$prose, $lines];
    }

    /**
     * **`$written` asks a config value to look like a file rather than a key**,
     * and only a config value is asked. In prose a bare `CLAUDE.md` is
     * unmistakably a document. In data a dotted chain ending in `.md` is a view
     * or a key, `emails.gifting.md` being the shape, so a value counts only
     * when it carries a directory or is a single name. Both documents a config
     * value here names, `docs/api/frontend-contract.md` and `API_CONTRACT.md`,
     * are read.
     *
     * @param  array<int, int>  $lines
     */
    private function documents(string $file, string $prose, array $lines, bool $written = false): void
    {
        preg_match_all(self::DOCUMENT, $prose, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$path, $offset]) {
            if ($written && ! str_contains($path, '/') && substr_count($path, '.') > 1) {
                continue;
            }

            if (is_file($this->root.'/'.$path)) {
                continue;
            }

            /*
             * Reported as written, never guessed at. A document that exists
             * somewhere else is a decision about which one is current, and
             * searching for a similar basename would make that decision by
             * inference and be wrong the one time it mattered.
             */
            $this->note($file, $lines[$offset] ?? 0, "names the document {$path}, which does not exist");
        }
    }

    /**
     * The config file only, never the key path.
     *
     * A key can be nested, conditional, or written in at runtime by a package,
     * so a key this cannot find is not evidence of a false claim, and reporting
     * one would be reporting a claim that is true.
     *
     * @param  array<int, int>  $lines
     */
    private function configs(string $file, string $prose, array $lines): void
    {
        foreach ([self::CONFIG_FILE, self::CONFIG_CALL] as $pattern) {
            preg_match_all($pattern, $prose, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$name, $offset]) {
                if (is_file($this->root.'/config/'.$name.'.php')) {
                    continue;
                }

                $this->note(
                    $file,
                    $lines[$offset] ?? 0,
                    "names the config file config/{$name}.php, which does not exist",
                );
            }
        }
    }

    /**
     * Section and hard rule numbers cited against `CLAUDE.md`.
     *
     * `CLAUDE.md` opens by saying its section numbers are referenced by
     * comments in committed code and that renumbering breaks those references.
     * Until this existed, that warning was held up by whoever renumbered
     * remembering it.
     *
     * @param  array<int, int>  $lines
     */
    private function citations(string $file, string $prose, array $lines): void
    {
        $sections = $this->claudeSections();
        $rules = $this->claudeHardRules();

        if ($sections === [] || $rules === 0) {
            return;
        }

        foreach ([self::SECTION_OF, self::SECTION_IN] as $pattern) {
            preg_match_all($pattern, $prose, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$number, $offset]) {
                if (! in_array($number, $sections, true)) {
                    $this->note($file, $lines[$offset] ?? 0, "cites CLAUDE.md section {$number}, which does not exist");
                }
            }
        }

        preg_match_all(self::HARD_RULE, $prose, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$number, $offset]) {
            if ((int) $number < 1 || (int) $number > $rules) {
                $this->note($file, $lines[$offset] ?? 0, "cites hard rule {$number}, and CLAUDE.md has {$rules}");
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function claudeSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $claude = $this->root.'/CLAUDE.md';

        if (! is_file($claude)) {
            return $this->sections = [];
        }

        preg_match_all('/^#{2,4}\s+(\d+(?:\.\d+)?)[.\s]/m', (string) file_get_contents($claude), $matches);

        return $this->sections = array_values(array_unique($matches[1]));
    }

    /**
     * How many hard rules `CLAUDE.md` section 9 declares, counted rather than
     * pinned at fourteen, so adding one does not break every citation of it.
     */
    private function claudeHardRules(): int
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $claude = $this->root.'/CLAUDE.md';

        if (! is_file($claude)) {
            return $this->rules = 0;
        }

        $text = (string) file_get_contents($claude);

        if (! preg_match('/^##\s+\d+\.\s+Hard rules\s*$(.*?)(?=^##\s|\z)/ms', $text, $section)) {
            return $this->rules = 0;
        }

        preg_match_all('/^(\d+)\.\s+/m', $section[1], $matches);

        return $this->rules = $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));
    }

    /**
     * Names a class docblock enumerates as what the class offers.
     *
     * **Deliberately the narrowest of the three.** A scan for every backticked
     * name in every comment is mostly false positives: `firstOrCreate` and
     * `Cache::add` are Laravel's, `ZoneSecurityIncludeHashRemoteIP` is Bunny's,
     * and `ProductCard` is a component in the frontend partner's repository,
     * which this one cannot see. An allowlist of those would need extending
     * every time a comment named a new framework method, and it would rot.
     *
     * So the question is narrowed until it has one unambiguous answer. A class
     * docblock that opens two or more bullets with a backticked name is
     * enumerating what the class it sits above offers, and every name in that
     * list has to be one the class or its enum declares. Prose mentioning a
     * name is not a claim of ownership. A list is.
     *
     * **Whether the name resolves to a class elsewhere is not the question**,
     * which is the whole reason the rule is drawn here. `ManualSettlement` and
     * `StripeCheckout` are both real gateway classes, and listing them as the
     * answers `PaymentInstruction` can give was still wrong: they are what
     * produces an instruction, not the kinds of instruction there are. A check
     * that skipped a name for existing somewhere would have been blind to the
     * defect that prompted it.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function symbols(string $file, array $tokens): void
    {
        $cases = self::casesOf(self::imports($tokens));

        foreach (self::declarations($tokens) as [$name, $docblock, $line, $members]) {
            if ($docblock === null) {
                continue;
            }

            $owned = $members + $cases + [$name => true];
            $claims = [];

            foreach (self::chunks($docblock) as [$chunk, $offset]) {
                if (preg_match(self::ENUMERATED, $chunk, $match) === 1) {
                    $claims[] = [$match[1], $chunk, $line + $offset];
                }
            }

            // One bullet that happens to open with a name is not a list.
            if (count($claims) < 2) {
                continue;
            }

            foreach ($claims as [$symbol, $chunk, $at]) {
                if (isset($owned[$symbol]) || self::tensed($chunk, $symbol)) {
                    continue;
                }

                $this->note($file, $at, "docblock lists {$symbol} among what {$name} offers, and neither it nor its enum declares one");
            }
        }
    }

    /**
     * The case names of every enum the file imports.
     *
     * "Its enum" is how a tagged value like `PaymentInstruction` gets its
     * vocabulary: the names live on `PaymentInstructionType` and the class
     * holds one of them. Reading them off the import is what lets a docblock be
     * checked against an enum one file away, which is where the defect was.
     *
     * @param  array<string, string>  $imports
     * @return array<string, true>
     */
    private static function casesOf(array $imports): array
    {
        $cases = [];

        foreach ($imports as $fqcn) {
            if (! enum_exists($fqcn)) {
                continue;
            }

            foreach ((new ReflectionEnum($fqcn))->getCases() as $case) {
                $cases[$case->getName()] = true;
            }
        }

        return $cases;
    }

    /**
     * A docblock split into the passages a reader reads as one statement: a
     * bullet, or a paragraph. Each carries how far into the docblock it starts,
     * so a failure names the line the claim is on rather than the class.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function chunks(string $docblock): array
    {
        $body = (string) preg_replace('/^[ \t]*(\/\*+|\*+\/|\*)/m', '', $docblock);

        $pieces = preg_split(
            '/\n\s*\n|\n(?=\s*(?:[-*+]|\d+\.)\s)/',
            $body,
            -1,
            PREG_SPLIT_OFFSET_CAPTURE,
        ) ?: [];

        $chunks = [];

        foreach ($pieces as [$piece, $offset]) {
            $text = trim((string) preg_replace('/\s+/', ' ', $piece));

            if ($text !== '') {
                $chunks[] = [$piece, substr_count(substr($body, 0, $offset), "\n")];
            }
        }

        return $chunks;
    }

    /**
     * Whether the bullet presents this name as one the class used to offer, or
     * one it will.
     *
     * `was` counts only where it follows the name, because "`Redirect` was
     * withdrawn" is a former case and "the reason it was written this way" is
     * not a tense claim about anything.
     */
    private static function tensed(string $chunk, string $symbol): bool
    {
        $chunk = (string) preg_replace('/\s+/', ' ', $chunk);

        if (preg_match(self::TENSED, $chunk) === 1) {
            return true;
        }

        return preg_match('/`'.preg_quote($symbol, '/').'`.{0,40}?\b(?:was|were)\b/i', $chunk) === 1;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<int, array{0: string, 1: ?string, 2: int, 3: array<string, true>}>
     */
    private static function declarations(array $tokens): array
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $previous = self::previousMeaningful($tokens, $index);

            // `Foo::class` and `new class`, neither of which declares a name.
            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $name = self::nextMeaningful($tokens, $index);

            if (! is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            [$docblock, $line] = self::docblockBefore($tokens, $index);

            $found[] = [$name[1], $docblock, $line, self::membersAfter($tokens, $index)];
        }

        return $found;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: ?string, 1: int}
     */
    private static function docblockBefore(array $tokens, int $index): array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_string($token)) {
                // An attribute's closing bracket sits between a docblock and
                // the class it belongs to.
                if ($token === ']') {
                    continue;
                }

                return [null, 0];
            }

            if ($token[0] === T_DOC_COMMENT) {
                return [$token[1], $token[2]];
            }

            if (! in_array($token[0], [T_WHITESPACE, T_ABSTRACT, T_FINAL, T_READONLY, T_ATTRIBUTE, T_STRING, T_COMMENT], true)) {
                return [null, 0];
            }
        }

        return [null, 0];
    }

    /**
     * The names a declaration's own body declares: cases, constants, methods
     * and properties, promoted ones included.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, true>
     */
    private static function membersAfter(array $tokens, int $index): array
    {
        $members = [];
        $depth = 0;
        $started = false;

        for ($i = $index, $last = count($tokens); $i < $last; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;
                $started = true;

                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($started && $depth === 0) {
                    break;
                }

                continue;
            }

            if (! $started || $depth !== 1 || ! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CASE, T_CONST, T_FUNCTION], true)) {
                $name = self::nextMeaningful($tokens, $i);

                if (is_array($name) && $name[0] === T_STRING) {
                    $members[$name[1]] = true;
                }

                continue;
            }

            if ($token[0] === T_VARIABLE) {
                $members[ltrim($token[1], '$')] = true;
            }
        }

        return $members;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $collecting = false;
        $current = '';

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_USE) {
                // A closure's `use` is not an import.
                if (self::nextMeaningful($tokens, $index) === '(') {
                    continue;
                }

                $collecting = true;
                $current = '';

                continue;
            }

            if (! $collecting) {
                continue;
            }

            if ($token === ';' || $token === ',' || $token === '{' || $token === '}') {
                $current = trim($current);

                if ($current !== '') {
                    $parts = explode('\\', $current);
                    $imports[end($parts)] = $current;
                }

                $current = '';

                if ($token === ';') {
                    $collecting = false;
                }

                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_AS) {
                    $collecting = false;

                    continue;
                }

                if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $current .= $token[1];
                }
            }
        }

        return $imports;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function nextMeaningful(array $tokens, int $index): array|string|null
    {
        for ($i = $index + 1, $last = count($tokens); $i < $last; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningful(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    private function note(string $file, int $line, string $message): void
    {
        $relative = str_starts_with($file, $this->root.'/')
            ? substr($file, strlen($this->root) + 1)
            : $file;

        $note = [$relative, $line, $message];

        if (! in_array($note, $this->unresolved, true)) {
            $this->unresolved[] = $note;
        }
    }
}
