<?php

declare(strict_types=1);

use App\Http\Controllers\Public\GuestDraftResumeController;
use App\Models\Submission;
use App\Support\Submissions\SubmissionReference;
use Tests\Support\SourceTree;

/**
 * Increment J2e — `submissions.reference` is a DISPLAY HANDLE AND NEVER A CREDENTIAL, asserted rather than
 * described.
 *
 * Forty bits (32^8 ≈ 1.1e12) is ample to keep one tenant's references distinct and nowhere near enough to
 * resist enumeration: at a modest hundred requests a second an attacker walks a meaningful slice of a
 * tenant's namespace. That is fine for something printed on a confirmation screen and pasted into an
 * authenticated search box; it is not fine for anything that RESOLVES a submission on its own.
 *
 * Nothing does today, and the hazard is entirely the next author. The guest POST response now carries a
 * reference, which makes it the natural anchor for "let a respondent look their submission up", and
 * {@see GuestDraftResumeController} is the shape that would receive it — where
 * `where('reference', $r)` becomes an unauthenticated lookup. So this file pins the rule structurally, in
 * the register `SearchMemberConnectionTest` established: a prohibition that lives only in a docblock is not
 * a prohibition.
 *
 * ⚠️ COMMENTS ARE STRIPPED BEFORE MATCHING, and that is not cosmetic — several files carry prose explaining
 * exactly why they must not do this, and a raw `str_contains` would match the warning rather than the code.
 */
function referenceSourceWithoutComments(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * Every `.php` under `app/`, as absolute paths.
 *
 * ⛔ THIS WAS A `RecursiveDirectoryIterator` UNTIL M76, AND THIS FILE HAD NO FLOOR AT ALL, WHICH IS THE
 * WORSE OF THE TWO POSITIONS. Over this project's Windows bind mount that iterator returned 719 of the 814
 * files under `app/`, dropping whole directories — including all 52 under `app/Http/Controllers/Tenant`.
 * The sole assertion here is an exact-equality on the offender list, so it stayed green precisely BECAUSE
 * it found nothing new: the one legitimate site lives in `app/Models/`, which survived the truncation,
 * while a `where('reference', …)` added in a tenant controller — the exact shape the docblock above says
 * this file exists to prevent — could never have been seen locally. {@see SourceTree}
 * carries the measurement and the disagreement guard.
 */
function appSourceFiles(): array
{
    return SourceTree::filesUnder(base_path('app'));
}

it('queries submissions.reference in exactly one place in the whole application', function (): void {
    // The one legitimate site is `Submission::scopeMatchingKeyword`, which is only ever composed onto a
    // builder that a caller has already bounded — `scopeVisibleTo` in the inbox, the arm's own gate in
    // search. A SECOND site is the thing this case exists to make impossible to add quietly, because the
    // second one is where somebody reaches for a reference as a lookup key on a public route.
    $offenders = [];
    $scanned = 0;

    foreach (appSourceFiles() as $path) {
        $scanned++;
        $code = referenceSourceWithoutComments($path);

        // ⚠️ CASE-INSENSITIVE, AND THAT IS NOT A DETAIL. The first draft of this lint matched `where(` only,
        // so it silently skipped `orWhere(` — which is the exact spelling the one legitimate call site uses.
        // It therefore found nothing and would have passed just as happily against a public controller that
        // resolved a submission by reference. The failing assertion is what exposed it; a lint that cannot
        // match the code it is written about is worse than no lint, because it reads like coverage.
        if (preg_match("/where\(\s*'(submissions\.)?reference'/i", $code) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    // ⚠️ NON-VACUITY FIRST, ADDED BY M76 BECAUSE THIS FILE HAD NONE. An exact-equality on the offender
    // list looks self-protecting — a blind sweep that lost `Submission.php` would redden — but that is
    // only true for the ONE file already on the list. Losing any of the other 813 is invisible to it,
    // which is exactly the direction a new offender arrives from. 800 is chosen against a measured 814.
    expect($scanned)->toBeGreaterThan(800);

    expect($offenders)->toBe(['app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Submission.php']);
});

it('exposes no route that resolves a submission by its reference', function (): void {
    // Read off the real route table rather than a hand-kept list, so a route added tomorrow is covered
    // without anyone remembering this file exists. `{reference}` as a binding segment is the shape that
    // would turn the handle into a bearer token.
    $offenders = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), '{reference}'))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('does not make the reference resolvable through route-model binding', function (): void {
    // `getRouteKeyName()` is the other way a handle silently becomes a lookup key: change it to 'reference'
    // and every existing `{submission}` route starts resolving codes, with no route file edited.
    expect((new Submission)->getRouteKeyName())->toBe('id');
});

it('keeps the handle short enough to be quotable and therefore too short to be secret', function (): void {
    // The arithmetic the rule above rests on, stated as a test so it cannot drift into folklore. Eight
    // Crockford characters is 32^8 ≈ 1.1e12 — roughly 40 bits. If someone widens the alphabet or the length
    // to "make it secure", this case is where they should stop and read the docblock instead.
    expect(SubmissionReference::LENGTH)->toBe(8)
        ->and(strlen(SubmissionReference::ALPHABET) ** SubmissionReference::LENGTH)
        ->toBeLessThan(2 ** 41);
});
