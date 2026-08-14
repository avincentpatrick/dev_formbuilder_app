<?php

declare(strict_types=1);

use App\Enums\ExtractFilter;
use App\Enums\TenantUserStatus;
use App\Exceptions\Tenancy\TenantExtractException;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\Extraction\ExtractWriter;
use App\Services\Tenancy\Extraction\TenantExtractService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The per-tenant extract (Phase 4, P2b — ADR-0018).
|--------------------------------------------------------------------------
| Every assertion here is about a way the extractor can produce a clean, plausible artefact that is
| WRONG. That is the whole risk surface of this feature: nothing throws, the manifest is well-formed,
| the row counts look reasonable, and the contents are somebody else's data, or this tenant's
| credentials, or half of a torn read.
|
| ⚠️ TWO PROPERTIES OF THIS HARNESS SHAPE WHAT CAN BE PROVEN HERE, AND BOTH ARE STATED RATHER THAN
| WORKED AROUND.
|
|  1. `RefreshDatabase` wraps every test in a transaction, so the extractor's own DB::transaction() is
|     a SAVEPOINT and `set transaction isolation level` is deliberately skipped (PostgreSQL refuses it
|     once the enclosing transaction has run a statement). The manifest therefore reports `read
|     committed` here and `repeatable read` in production — which is exactly why the manifest reports
|     what it READ BACK. Asserting the production value in this suite would mean asserting something
|     false, and "fixing" it by hard-coding the requested level into the manifest would turn the field
|     into a decoration.
|  2. The extract reads `users` under a tenant-only GUC, so the fixtures deliberately use ordinary
|     factory users on the default connection rather than committedTenantIdentity(): what is being
|     proven is which rows the JOIN-SHAPE POLICY admits, and a committed identity would be visible
|     for reasons that have nothing to do with membership.
*/

beforeEach(function (): void {
    // The 5 roles and 29 permissions are PLATFORM rows (`tenant_id IS NULL`), so seeding them is also what
    // makes the widened-table assertions meaningful — without the catalog there is nothing for the
    // explicit predicate to keep out, and "kept the platform rows out" would pass over an empty table.
    (new RolePermissionSeeder)->run();

    $this->extractDir = sys_get_temp_dir().'/p2b-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->extractDir);
    TenantContext::applyLocal(null);
    TenantContext::flush();
});

/**
 * Two tenants, each with a form and a member, plus one INVITED (not active) member on the first — which
 * is the shape ADR-0017's first entry criterion is about.
 *
 * @return array{0: Tenant, 1: Tenant, 2: User, 3: User}
 */
function twoPopulatedTenants(): array
{
    $acme = inboxTenant('acme');
    $other = inboxTenant('other');

    enterTenant($acme->id);
    $acmeOwner = apiMember('owner');
    makeForm($acmeOwner, 'Acme Survey');

    // The invited member: a `tenant_users` row whose user the `users` SELECT policy will NOT return,
    // because that policy admits only ACTIVE co-tenants.
    $invited = User::factory()->create();
    TenantUser::create([
        'user_id' => $invited->id,
        'status' => TenantUserStatus::Invited,
        'invited_at' => now(),
        'invited_role_id' => catalogRole('viewer'),
    ]);

    enterTenant($other->id);
    $otherOwner = apiMember('owner');
    makeForm($otherOwner, 'Other Survey');

    enterTenant($acme->id);

    return [$acme, $other, $acmeOwner, $invited];
}

/** @return list<array<string, mixed>> */
function readExtractTable(string $directory, string $table): array
{
    $path = $directory."/tables/{$table}.ndjson";
    $lines = array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $l): bool => $l !== '');

    return array_map(static fn (string $l): array => json_decode($l, true, flags: JSON_THROW_ON_ERROR), $lines);
}

function runExtract(Tenant $tenant, string $directory): array
{
    $manifest = app(TenantExtractService::class)->extract($tenant, new ExtractWriter($directory));

    return $manifest->toArray();
}

it('extracts the tenant\'s own rows and none of the neighbouring tenant\'s', function (): void {
    // T1 — the premise. Everything below is about narrower failures; this is the one that would make the
    // whole feature a liability rather than a bug.
    [$acme] = twoPopulatedTenants();

    runExtract($acme, $this->extractDir);

    $forms = readExtractTable($this->extractDir, 'forms');

    expect($forms)->toHaveCount(1);
    expect($forms[0]['title'])->toBe('Acme Survey');
    expect(array_column($forms, 'tenant_id'))->each->toBe($acme->id);
});

it('keeps the platform catalog out of the widened tables and counts what it kept out', function (): void {
    // T2 — ADR-0017's SECOND entry criterion, answered and then measured. RLS is not the filter on these
    // six: the SELECT policy is widened with `OR tenant_id IS NULL`, so a no-predicate read returns the
    // tenant's rows AND the product's. `permissions` is the sharpest case — 29 platform rows, zero
    // tenant rows, so without the predicate the file would contain 29 rows that belong to nobody.
    [$acme] = twoPopulatedTenants();

    $manifest = runExtract($acme, $this->extractDir);
    $permissions = collect($manifest['tables'])->firstWhere('table', 'permissions');

    expect(readExtractTable($this->extractDir, 'permissions'))->toBe([]);
    expect($permissions['filter'])->toBe(ExtractFilter::RlsAndPredicate->value);
    expect($permissions['platform_rows_excluded'])->toBeGreaterThan(0);
});

it('extracts users as a roster and no credential column with it', function (): void {
    // T3 — ADR-0017's FIRST entry criterion. Named individually rather than asserted as a column count,
    // because a count is satisfied by swapping one secret for another.
    [$acme] = twoPopulatedTenants();

    runExtract($acme, $this->extractDir);
    $users = readExtractTable($this->extractDir, 'users');

    expect($users)->not->toBeEmpty();

    foreach ($users as $user) {
        expect($user)->toHaveKeys(['id', 'name', 'email'])
            ->and($user)->not->toHaveKey('password')
            ->and($user)->not->toHaveKey('remember_token')
            ->and($user)->not->toHaveKey('two_factor_secret')
            ->and($user)->not->toHaveKey('two_factor_recovery_codes')
            ->and($user)->not->toHaveKey('is_super_admin')
            ->and($user)->not->toHaveKey('last_active_tenant_id');
    }
});

it('reports a referenced member it could not extract instead of dropping it', function (): void {
    // T4 — THE FINDING ADR-0017 PREDICTED, REPRODUCED. An invitation creates a `tenant_users` row whose
    // `user_id` the `users` policy will not return, because that policy admits only ACTIVE co-tenants. The
    // artefact therefore carries a foreign key to a row it does not contain, and the only two honest
    // options are to report it or to widen the GUC — which J3c1 refused for the same reason it is refused
    // here: `app.current_user_id` names an authenticated person, and an invitee is not one.
    [$acme, , , $invited] = twoPopulatedTenants();

    $manifest = runExtract($acme, $this->extractDir);
    $unresolved = $manifest['unresolved_user_references'];

    expect($unresolved['by_column'])->toHaveKey('tenant_users.user_id');
    expect($unresolved['by_column']['tenant_users.user_id'])->toContain($invited->id);
    expect($unresolved['distinct_users'])->toBeGreaterThan(0);

    // And the row that names them IS extracted — the reference is dangling, not absent. An extract that
    // silently dropped the membership row would lose the fact that an invitation was outstanding at all.
    expect(array_column(readExtractTable($this->extractDir, 'tenant_users'), 'user_id'))
        ->toContain($invited->id);
});

it('filters domains by the predicate alone, because it has no row-level security at all', function (): void {
    // T5 — THE MUTATION TARGET. `domains` is the one extracted table with zero policies (it resolves the
    // tenant from the host, so scoping it by tenant would be circular), so the `where` clause in
    // TenantExtractService::query() is its ONLY isolation. Delete that clause and every other assertion
    // in this file still passes while this one fails with the neighbour's hostname in the artefact.
    [$acme, $other] = twoPopulatedTenants();

    $manifest = runExtract($acme, $this->extractDir);
    $domains = array_column(readExtractTable($this->extractDir, 'domains'), 'domain');

    expect($domains)->toContain('acme');
    expect($domains)->not->toContain('other');
    expect(collect($manifest['tables'])->firstWhere('table', 'domains'))
        ->toMatchArray([
            'filter' => ExtractFilter::PredicateOnly->value,
            'filter_is_database_enforced' => false,
        ]);

    // `tenants` is the same shape one level up, and the same single point of failure.
    expect(array_column(readExtractTable($this->extractDir, 'tenants'), 'id'))->toBe([$acme->id]);
});

it('withholds the domain verification token, which is a credential for the routing layer', function (): void {
    [$acme] = twoPopulatedTenants();
    customDomain($acme, 'acme.example.test');

    runExtract($acme, $this->extractDir);

    foreach (readExtractTable($this->extractDir, 'domains') as $domain) {
        expect($domain)->not->toHaveKey('verification_token');
    }
});

it('decodes jsonb and types booleans rather than writing PostgreSQL\'s text rendering', function (): void {
    // ⚠️ HONEST ABOUT WHAT THIS PINS. On this stack the BOOLEAN half passes because the driver already
    // returns a PHP bool (see DriverTypeMappingTest) — deleting ExtractRowEncoder's boolean branch leaves
    // this assertion green, which mutation testing established rather than inspection. The branch is
    // pinned by the unit cases, which feed it the emulated-prepares string form; what this case proves is
    // the end-to-end property a reader of the artefact cares about: the JSON types are right. The `jsonb`
    // half IS pinned here — the driver returns encoded text, so without the decode this reads a string.
    [$acme] = twoPopulatedTenants();

    runExtract($acme, $this->extractDir);
    $form = readExtractTable($this->extractDir, 'forms')[0];

    expect($form['allow_guest_submissions'])->toBeBool();
    expect($form['supported_locales'])->toBeArray();
});

it('reports row counts that match what it actually wrote', function (): void {
    // The manifest is the part of an extract that can be wrong with nothing failing. Counting lines back
    // off disk is the only assertion that cannot be satisfied by the code that produced the number.
    [$acme] = twoPopulatedTenants();

    $manifest = runExtract($acme, $this->extractDir);

    foreach ($manifest['tables'] as $table) {
        expect(readExtractTable($this->extractDir, $table['table']))
            ->toHaveCount($table['rows'], "{$table['table']} row count disagrees with its file");
    }

    expect($manifest['row_total'])->toBe(array_sum(array_column($manifest['tables'], 'rows')));
});

it('reports the isolation level and role it actually ran under', function (): void {
    // READ BACK, not asserted from configuration. Under RefreshDatabase this is `read committed` because
    // the isolation statement is skipped on a nested transaction — see this file's header. A manifest that
    // reported the REQUESTED level would say `repeatable read` here and be wrong.
    [$acme] = twoPopulatedTenants();

    $manifest = runExtract($acme, $this->extractDir);

    expect($manifest['snapshot']['role'])->toBe(config('database.connections.pgsql.username'));
    expect($manifest['snapshot']['isolation_level'])->toBeIn(['read committed', 'repeatable read']);
    expect($manifest['snapshot']['database'])->toBe(config('database.connections.pgsql.database'));
});

it('refuses a destination that already holds an extract', function (): void {
    // Merging one point-in-time artefact into another produces a directory whose manifest describes
    // neither, and the second run's manifest would overwrite the first's — so the result LOOKS coherent.
    [$acme] = twoPopulatedTenants();

    runExtract($acme, $this->extractDir);

    expect(fn () => runExtract($acme, $this->extractDir))
        ->toThrow(TenantExtractException::class, 'already contains files');
});

it('restores the tenant context it was called with', function (): void {
    // The extractor sets a tenant GUC to do its work. Leaving it set would silently rescope whatever runs
    // next — which under RefreshDatabase is the next test, and in production is the rest of the command.
    // ⚠️ Restored with applyLocal(), never forget(): forget() is session-scoped, survives the rollback and
    // bleeds a CLEARED context into the following test on this connection.
    [$acme, $other] = twoPopulatedTenants();

    enterTenant($other->id);
    runExtract($acme, $this->extractDir);

    expect(TenantContext::currentTenantId())->toBe($other->id);
});

it('validates every table\'s column policy before it creates the destination', function (): void {
    // ⚠️ AN ORDERING ASSERTION, AND THE ORDERING IS THE WHOLE POINT. `assertWithheldColumnsExist()` exists
    // to stop a credential reaching a file. Run per table inside the write loop — which is where it was
    // first written — a renamed secret on the 43rd table fires only after the other 42 have been written,
    // so the "refusal" leaves a directory of real tenant data on disk that somebody now has to remember to
    // delete. Hoisting it changes nothing about WHETHER it refuses and everything about what refusing costs.
    //
    // Driven by dropping a table rather than by renaming a column, because a rename needs DDL this suite
    // would then have to unwind; both take the same pre-flight path and the same exception class.
    [$acme] = twoPopulatedTenants();

    DB::statement('drop table if exists global_probes cascade');

    expect(fn () => runExtract($acme, $this->extractDir))->toThrow(TenantExtractException::class);
    expect(File::isDirectory($this->extractDir))->toBeFalse();
});

it('writes no manifest when it refuses, so a failed run cannot be mistaken for a small one', function (): void {
    // The manifest is written LAST and its presence is what makes a directory an extract.
    [$acme] = twoPopulatedTenants();

    File::ensureDirectoryExists($this->extractDir);
    File::put($this->extractDir.'/stray.txt', 'x');

    expect(fn () => runExtract($acme, $this->extractDir))->toThrow(TenantExtractException::class);
    expect(File::exists($this->extractDir.'/manifest.json'))->toBeFalse();
});
