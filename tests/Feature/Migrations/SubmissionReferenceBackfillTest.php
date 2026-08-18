<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Migrations\SubmissionReferenceBackfill;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ScriptedSubmissionReferenceIssuer;

/**
 * Increment J2e — the merge-day backfill that lets `submissions.reference` become `NOT NULL` on a database
 * that already holds rows.
 *
 * Its EFFECT is proven here rather than by running the migration, for the reason all three backfill
 * precedents give: a `migrate:fresh` test database is EMPTY at migration time, so a test that only ran the
 * migration would assert nothing at all. The class is connection-agnostic precisely so this file can drive it
 * on the app connection under a live tenant GUC and watch real rows change.
 *
 * ⚠️ EVERY CASE HERE DROPS THE `NOT NULL` CONSTRAINT FIRST, AND THAT IS NOT A HACK — it is the only way to
 * reconstruct the pre-migration state the backfill exists to repair. PostgreSQL DDL is transactional, so the
 * `ALTER` rolls back with `RefreshDatabase`'s transaction and cannot leak into another file.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);

    $this->form = publishedInboxForm($this->tenant, $this->owner);
});

/** Reconstruct the pre-migration shape: nullable column, and `$count` rows carrying no reference. */
function unbackfilledSubmissions(int $count): void
{
    DB::statement('ALTER TABLE submissions ALTER COLUMN reference DROP NOT NULL');

    for ($i = 0; $i < $count; $i++) {
        seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, ['full_name' => "Person {$i}"]);
    }

    DB::table('submissions')->update(['reference' => null]);
}

it('fills every reference-less row with a valid, distinct code', function (): void {
    unbackfilledSubmissions(12);

    (new SubmissionReferenceBackfill)(DB::connection());

    $references = DB::table('submissions')->pluck('reference')->all();

    expect($references)->toHaveCount(12)
        ->and(array_filter($references, fn (?string $r): bool => $r === null))->toBe([])
        ->and(count(array_unique($references)))->toBe(12);

    foreach ($references as $reference) {
        expect(SubmissionReference::isValid((string) $reference))->toBeTrue();
    }
});

it('does not touch updated_at', function (): void {
    unbackfilledSubmissions(3);

    // `updated_at` is a meaningful timestamp on this table, and filling in a NEW column is not editing the
    // submission. Bumping it would misreport every historical row as modified on deploy day.
    $before = DB::table('submissions')->orderBy('id')->pluck('updated_at')->all();

    (new SubmissionReferenceBackfill)(DB::connection());

    expect(DB::table('submissions')->orderBy('id')->pluck('updated_at')->all())->toBe($before);
});

it('is idempotent — a second run changes nothing', function (): void {
    unbackfilledSubmissions(5);

    (new SubmissionReferenceBackfill)(DB::connection());
    $first = DB::table('submissions')->orderBy('id')->pluck('reference')->all();

    (new SubmissionReferenceBackfill)(DB::connection());

    // The candidate filter is `reference IS NULL`, so the second pass examines strictly less and can only
    // ever fill a hole — never overwrite a code a respondent has already been shown.
    expect(DB::table('submissions')->orderBy('id')->pluck('reference')->all())->toBe($first);
});

it('re-mints a chunk whose code is already taken', function (): void {
    // The chunk-level retry, made deterministic. Without the injectable issuer this path could only be
    // reached by chance at odds of ~1 in 10^12, i.e. never — so it would ship unexercised.
    // The holder goes in FIRST and KEEPS its reference, so it sits outside the candidate set
    // (`reference IS NULL`) but inside the unique index — which is what makes the first mint collide.
    $holder = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Holder']);
    $holder->forceFill(['reference' => 'TAKEN123'])->save();

    DB::statement('ALTER TABLE submissions ALTER COLUMN reference DROP NOT NULL');
    $candidate = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Candidate']);
    DB::table('submissions')->where('id', $candidate->id)->update(['reference' => null]);

    $issuer = new ScriptedSubmissionReferenceIssuer(['TAKEN123', 'FRESH123']);

    (new SubmissionReferenceBackfill($issuer))(DB::connection());

    expect(DB::table('submissions')->whereNull('reference')->count())->toBe(0)
        ->and(DB::table('submissions')->where('reference', 'FRESH123')->count())->toBe(1)
        ->and($issuer->calls())->toBe(2);
});

it('refuses to continue when a row is left unfilled', function (): void {
    // The postcondition is what stops a partial run handing an impossible job to the SET NOT NULL that
    // follows it in the migration. Asserted on the decision function so it needs no database.
    expect(fn () => SubmissionReferenceBackfill::assertNoneRemaining(3))
        ->toThrow(RuntimeException::class, 'left 3 submission(s) with no reference');

    // `0 > 0` is false, which is what makes migrate:fresh on an empty database pass cleanly.
    expect(fn () => SubmissionReferenceBackfill::assertNoneRemaining(0))->not->toThrow(RuntimeException::class);
});

it('refuses a connection that does not bypass RLS', function (): void {
    // ⚠️ THE VACUOUS-GREEN GUARD. A NOBYPASSRLS role with valid credentials reads ZERO submissions under
    // FORCE ROW LEVEL SECURITY with no tenant GUC, fills nothing, and then passes the postcondition against
    // the same zero rows — leaving SET NOT NULL to fail one statement later with no explanation.
    expect(fn () => SubmissionReferenceBackfill::assertPrivilegedRole(0))
        ->toThrow(RuntimeException::class, 'neither SUPERUSER nor BYPASSRLS');

    // A missing pg_roles row is not a reason to proceed either.
    expect(fn () => SubmissionReferenceBackfill::assertPrivilegedRole(null))
        ->toThrow(RuntimeException::class);

    expect(fn () => SubmissionReferenceBackfill::assertPrivilegedRole(1))->not->toThrow(RuntimeException::class);
});

it('runs the real privileged connection the migration chooses', function (): void {
    // The migration's own line, exercised: whatever DB_PRIVILEGED_USERNAME points at must actually bypass
    // RLS. This is the half `assertPrivilegedRole` cannot prove on its own, because it takes a scalar.
    SubmissionReferenceBackfill::assertPrivileged(DB::connection('pgsql_privileged'));
})->throwsNoExceptions();
