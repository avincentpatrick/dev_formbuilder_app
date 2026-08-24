<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Increment M11 — `client_submission_uuid` RESOLVES WITHIN ONE FORM AND ONE AUTHOR, asserted rather than
 * described.
 *
 * I9b wrote that invariant into a docblock and implemented it in one of the three places that needed it.
 * Two copies kept filtering on the uuid alone for four increments, and the row that reported them is the
 * only reason anyone looked. A prohibition that lives in a docblock is not a prohibition — the register
 * {@see SubmissionReferenceDisclosureTest} established, and this is the second file in it.
 *
 * ⚠️ THE ALLOWLIST IS ONE FILE ON PURPOSE. An allowlist of three would document the copies rather than stop
 * a fourth; folding the two already-correct call sites onto the shared resolver is what makes a single-entry
 * allowlist possible, and a single-entry allowlist is what makes this gate mean anything.
 *
 * ⚠️ HELPER NAMES ARE DELIBERATELY DISTINCT FROM {@see SubmissionReferenceDisclosureTest}'s, which declares
 * `appSourceFiles()` and `referenceSourceWithoutComments()` at top level. Pest loads every spec into ONE
 * process, so a shared name here is a fatal redeclare on any run that loads both — the inverse of the
 * "undefined function" failure `tests/Pest.php`'s header records twice. GuestDraftRuntimeTest already takes
 * this posture for the same reason, and says so.
 *
 * ⚠️ COMMENTS ARE STRIPPED BEFORE MATCHING — several files carry prose about exactly this predicate, and a
 * raw match would find the warning rather than the code.
 */
function uuidScopeSourceWithoutComments(string $path): string
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

/** Every `.php` under `app/`, as absolute paths. */
function uuidScopeSourceFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('queries client_submission_uuid in exactly one file in the whole application', function (): void {
    $offenders = [];
    $occurrences = 0;
    $scanned = 0;

    foreach (uuidScopeSourceFiles() as $path) {
        $scanned++;
        $code = uuidScopeSourceWithoutComments($path);

        // Case-insensitive and `orWhere`-aware, the correction SubmissionReferenceDisclosureTest paid for:
        // a lint that cannot match the spelling its own subject uses reads like coverage and is worse than
        // nothing. `firstWhere` is included because it is the one-liner somebody reaches for next.
        $matches = preg_match_all("/(?:or)?(?:first)?where\(\s*'client_submission_uuid'/i", $code);

        if ($matches > 0) {
            $occurrences += $matches;
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    // ⚠️ NON-VACUITY FIRST, because a scan that silently reads nothing passes this file cheerfully. The
    // resolver holds exactly two queries — the scoped resolve and the tenant-wide existence probe — so a
    // count that is not 2 means either a copy was added inside the allowlisted file or the regex stopped
    // matching the code it is written about.
    expect($scanned)->toBeGreaterThan(200)
        ->and($occurrences)->toBe(2);

    expect($offenders)->toBe([
        'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Submissions'.DIRECTORY_SEPARATOR.'ClientUuidResolver.php',
    ]);
});

it('exposes no route that resolves a submission by a client-chosen uuid', function (): void {
    // Read off the real route table rather than a hand-kept list. A `{client_submission_uuid}` segment would
    // turn a caller-chosen, caller-guessable idempotency key into a lookup key on a route — the shape the
    // whole increment exists to close, arriving through the door nobody is watching.
    $offenders = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), '{client_submission_uuid}')
            || str_contains($route->uri(), '{clientSubmissionUuid}'))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('keeps the uniqueness domain blind to soft deletes, which is what withTrashed() rests on', function (): void {
    // ⚠️ THE PREMISE, PINNED AGAINST THE LIVE SCHEMA RATHER THAN AGAINST A MIGRATION FILE. `isClaimed()`
    // probes `withTrashed()` because the partial unique index filters on `client_submission_uuid IS NOT
    // NULL` and NOT on `deleted_at IS NULL` — so a tombstone still owns the uuid. If a later migration ever
    // adds `deleted_at IS NULL` to this index, that reasoning inverts: reuse becomes legal at the database
    // and `isClaimed()` starts refusing identifiers the index would accept. This case is where that change
    // should stop and read the docblock.
    TenantContext::flush();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    enterTenant($tenant->id, User::factory()->create()->id);

    $definition = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE indexname = 'submissions_tenant_client_uuid_unique'"
    );

    expect($definition)->not->toBeNull()
        ->and($definition->indexdef)->toContain('client_submission_uuid IS NOT NULL')
        ->and($definition->indexdef)->not->toContain('deleted_at');
});
