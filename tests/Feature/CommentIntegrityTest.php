<?php

use Tests\Support\CommentClaims;

/*
| Comments that cannot quietly go wrong.
|
| `CLAUDE.md` section 0.6 prefers a comment to a document because a comment
| cannot drift from the code it sits above. It cannot. It can drift from
| everything else it mentions, and `app/` is two fifths comment, so the surface
| making claims about other files is large and every claim is read by an agent
| as fact.
|
| Two had already gone wrong, both silently. `config/scramble.php` sent the
| frontend partner to a document deleted after M1, and that text is served at
| `/docs/api`, so the partner is the one who hits the dead end. The
| `PaymentInstruction` docblock listed three names as the answers the pay
| endpoint gives, and the enum declares three different ones.
|
| This is the same failure this project has paid for twice and written down
| twice. `BunnyStreamTest` was green about a signature Bunny had never accepted.
| `MailTransportTest` records a fallback address that resolved to an empty
| string while the queue reported success. Its comment says the lesson outright,
| and it is the reason this file exists: **a convention nothing checks holds
| until the first time somebody is in a hurry.**
|
| **What is asserted is that a reference resolves, never that a sentence is
| present.** `.claude/testing.md` warns against a test that breaks when a method
| is renamed while nothing observable changed, and that warning is worth meeting
| head on here, because a test reading source text looks like exactly what it
| forbids. The observable behaviour is that a reader is not misled: a dead path
| in the generated API docs is a 404 the partner reaches, and a docblock naming
| cases that do not exist is the first thing an agent reads before touching the
| payment path. Renaming a method breaks this only where a comment was making a
| claim about that method and the claim is now false, which is the defect and
| not a false alarm.
|
| The rule about external services does not apply. Every claim here is about
| files this repository owns, where asserting our output against our intent is
| explicitly the right thing.
|
| **One defect this cannot catch, stated rather than skipped.** The
| `PaymentInstruction` docblock also carried two contradictory accounts of what
| `StripeCheckout` returns, one a client secret confirmed in the page and one a
| hosted Checkout Session. Two internally consistent sentences that disagree
| with each other are invisible to any resolution check. That was fixed by hand
| in the same step.
*/

/**
 * The trees the repository actually ships, and the claims left unresolved in
 * them.
 *
 * `tests/` is not among them. A check that scanned itself would be asserting
 * about its own fixtures, which are broken on purpose.
 *
 * @return array<int, string>
 */
function unresolvedClaims(string ...$trees): array
{
    return CommentClaims::under(base_path(), $trees)->unresolved();
}

/**
 * The case that fails the build.
 *
 * No database, no network, no fixtures, so it costs nothing on a day when no
 * comment references anything and it cannot flake. Every unresolved claim is
 * listed, because a run that reports all of them is one fix cycle.
 */
it('resolves every claim the comments in this repository make', function () {
    $unresolved = unresolvedClaims('app', 'config', 'database', 'routes');

    expect($unresolved)->toBe([], PHP_EOL.PHP_EOL.implode(PHP_EOL, $unresolved).PHP_EOL);
});

it('resolves every claim in the fixtures written to resolve', function () {
    // The clean run above cannot tell a checker that saw every claim and
    // resolved it from one that matched nothing at all. These carry one of each
    // shape, all of them true, and the cases below prove each shape is seen.
    expect(unresolvedClaims('tests/Fixtures/Claims/Resolved'))->toBe([]);
});

it('reports a comment naming a document that does not exist, with its file and line', function () {
    expect(unresolvedClaims('tests/Fixtures/Claims/Unresolved/DeletedDocument.php'))->toBe([
        'tests/Fixtures/Claims/Unresolved/DeletedDocument.php:11  names the document docs/api/never-written-contract.md, which does not exist',
        'tests/Fixtures/Claims/Unresolved/DeletedDocument.php:16  names the document docs/plans/milestone-99-plan.md, which does not exist',
    ]);
});

it('reports every unresolved claim in a run rather than only the first', function () {
    // Two dead documents in one file, two citations on one line, and a list
    // with two bad names in it. Stopping at the first would be as many fix
    // cycles as there are defects.
    $unresolved = unresolvedClaims('tests/Fixtures/Claims/Unresolved');

    expect($unresolved)->toHaveCount(9)
        ->and(array_filter($unresolved, fn (string $note): bool => str_contains($note, 'DeletedDocument')))
        ->toHaveCount(2);
});

it('reports a comment naming a config file that does not exist, written either way', function () {
    // Once as `config/gifting.php` and once as a `config('gifting.codes...')`
    // call, because comments here write both.
    expect(unresolvedClaims('tests/Fixtures/Claims/Unresolved/MissingConfig.php'))->toBe([
        'tests/Fixtures/Claims/Unresolved/MissingConfig.php:8  names the config file config/gifting.php, which does not exist',
        'tests/Fixtures/Claims/Unresolved/MissingConfig.php:9  names the config file config/gifting.php, which does not exist',
    ]);
});

it('says nothing about a key path on a config file that exists', function () {
    // `config('payments.gateways.stripe')` in the resolved fixture. A key can
    // be nested, conditional, or written in at runtime by a package, so a key
    // this cannot find is not evidence of a false claim.
    expect(unresolvedClaims('tests/Fixtures/Claims/Resolved/EveryClaimShape.php'))->toBe([]);
});

it('reports a citation of a CLAUDE.md section or hard rule that does not exist', function () {
    // The warning at the top of CLAUDE.md, enforced rather than remembered.
    // Section 0.9 is cited across a line break and is still reported on the
    // line the citation starts on.
    expect(unresolvedClaims('tests/Fixtures/Claims/Unresolved/RenumberedCitation.php'))->toBe([
        'tests/Fixtures/Claims/Unresolved/RenumberedCitation.php:8  cites CLAUDE.md section 0.9, which does not exist',
        'tests/Fixtures/Claims/Unresolved/RenumberedCitation.php:8  cites CLAUDE.md section 42, which does not exist',
        'tests/Fixtures/Claims/Unresolved/RenumberedCitation.php:9  cites hard rule 99, and CLAUDE.md has 15',
    ]);
});

it('reports a docblock listing a name neither the class nor its enum declares', function () {
    // `PayLater` is nothing anywhere. `ManualSettlement` is a real gateway
    // class, and listing it as an answer this can give is still false, which is
    // why resolving a name somewhere else is not the question.
    expect(unresolvedClaims('tests/Fixtures/Claims/Unresolved/WrongCases.php'))->toBe([
        'tests/Fixtures/Claims/Unresolved/WrongCases.php:14  docblock lists PayLater among what WrongCases offers, and neither it nor its enum declares one',
        'tests/Fixtures/Claims/Unresolved/WrongCases.php:15  docblock lists ManualSettlement among what WrongCases offers, and neither it nor its enum declares one',
    ]);
});

it('says nothing about a name the prose marks as former or future', function () {
    // `CLAUDE.md` section 0.6 requires a reversed decision to be struck through
    // and dated rather than deleted, and the repository does it: `FilmSource`
    // explains it was once `RevealVideoSource`, and `PaymentInstruction`
    // explains gift code redemption will answer the same as hand settlement
    // once gifting exists. Both survive.
    //
    // `PayOnCollection` sits in the same list as the two names reported above,
    // marked as withdrawn, so the exemption is proved where it has to work:
    // inside a list that is otherwise failing.
    $unresolved = unresolvedClaims('tests/Fixtures/Claims/Unresolved/WrongCases.php');

    expect(implode(' ', $unresolved))->not->toContain('PayOnCollection')
        ->and(unresolvedClaims('tests/Fixtures/Claims/Resolved/TaggedAnswer.php'))->toBe([]);
});

it('says nothing about a framework or vendor name a comment mentions', function () {
    // `firstOrCreate` and `Cache::add` are Laravel's,
    // `ZoneSecurityIncludeHashRemoteIP` is Bunny's, and `ProductCard` is a
    // component in the frontend partner's repository, which this one cannot
    // see. A naive scan for backticked names reports all four. An allowlist of
    // them would need extending every time a comment named a new framework
    // method and would rot, so the question is narrowed instead.
    expect(unresolvedClaims('tests/Fixtures/Claims/Resolved/EveryClaimShape.php'))->toBe([]);
});

it('treats a markdown path inside a string literal as data rather than a claim', function () {
    // `EveryClaimShape::notAClaim()` returns the dead M1 path as a string and
    // is not reported. This is what the tokenizer buys over a regex: a regex
    // over the file text cannot tell a comment from a string that contains one.
    expect(unresolvedClaims('tests/Fixtures/Claims/Resolved/EveryClaimShape.php'))->toBe([]);
});

it('reads a config value as prose, since a config value is what the partner reads', function () {
    // `config/scramble.php` renders its description on `/docs/api`. A dead path
    // there is the only one in this repository a stranger reaches first, and it
    // is the defect that prompted all of this. A value has to carry a directory
    // before it counts, so `emails.gifting.md` in the same fixture, a view name
    // that is data and not a path, is left alone.
    expect(unresolvedClaims('tests/Fixtures/Claims/config'))->toBe([
        'tests/Fixtures/Claims/config/gifting-docs.php:12  names the document docs/api/gift-contract.md, which does not exist',
    ]);
});
