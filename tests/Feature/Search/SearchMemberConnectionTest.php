<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Search\Arms\MemberSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| No user-supplied predicate may run on `pgsql_auth` (Increment J1c).
|
| `users` has no `tenant_id` column, so on the pre-auth connection there is NO tenant boundary of any kind:
| `users_auth_select ... TO meridian_auth USING (true)` OR's the join-shape policy away, deliberately, so
| the login path can resolve an identity before any tenant context exists. `TenantMembershipService` uses
| that connection safely because it hands it an id set already bounded by an RLS-scoped `tenant_users` read.
| A SEARCH hands it a predicate straight from the user, and that is the whole difference.
|
| Measured on the seeded corpus before this arm was written — a demo-scoped `email ILIKE '%o%'` returns 8
| rows on `pgsql_auth` INCLUDING `owner@northwind.test`, and 6 on the app connection.
|
| ── WHY THREE CASES AND NOT ONE ──────────────────────────────────────────────────────────────────────────
| The runtime case is the real proof, but it only covers the paths this fixture exercises: a future
| `->when($flag)` branch that hops connections is invisible to it. The two static cases are a LINT, not a
| proof — they fail at authorship instead of at runtime, and the directory sweep is the one that survives a
| seventh arm written by someone who never read this file. Neither kind is sufficient alone; both are cheap.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@acme.test']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

it('runs every member query on the default connection, never on the pre-auth one', function (): void {
    // ⭐ THE RUNTIME PROOF. A real listener, never `Event::fake()` — faking swallows QueryExecuted and this
    // case would pass having observed nothing at all.
    $seen = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$seen): void {
        $seen[] = $event->connectionName;
    });

    $arm = app(MemberSearchArm::class);
    $terms = SearchTerms::parse('maria');

    $arm->search($this->owner, $terms, 50);
    $arm->count($this->owner, $terms);

    // Anti-vacuity: if the listener captured nothing, the assertion below would pass trivially.
    expect($seen)->not->toBeEmpty('no queries were observed, so this case proves nothing');

    expect(array_values(array_unique($seen)))->toBe(
        [config('database.default')],
        'the members arm touched a connection other than the default: '.implode(', ', array_unique($seen))
    );
});

/**
 * A file's source with every comment and docblock removed.
 *
 * ⚠️ LOAD-BEARING, AND THE FIRST DRAFT WITHOUT IT FAILED ON ITS OWN DOCUMENTATION. `MemberSearchArm`'s
 * docblock explains at length why the arm must NOT touch `pgsql_auth` and why it carries no
 * `withTrashed()` — so a naive `str_contains` over the raw file matches the prose that exists precisely to
 * prevent the thing being linted for. Stripping comments makes these cases assert what they claim to
 * assert: that the CODE contains no hop, while leaving authors free to write down why.
 */
function searchSourceWithoutComments(string $path): string
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

it('contains no connection hop in its own source, which a runtime case cannot promise', function (): void {
    // Resolved by reflection rather than a hard-coded path, so a rename keeps the assertion pointed at the
    // real class instead of silently reading nothing.
    $path = (new ReflectionClass(MemberSearchArm::class))->getFileName();
    $source = $path === false ? '' : searchSourceWithoutComments($path);

    // Anti-vacuity: prove we actually read the class before asserting things are absent from it.
    expect($source)->toContain('final readonly class MemberSearchArm');

    expect($source)->not->toContain('pgsql_auth')
        ->and($source)->not->toContain('setConnection')
        ->and($source)->not->toContain('withTrashed');
});

it('keeps the whole search namespace off the pre-auth connection, including arms not yet written', function (): void {
    // The case that survives a seventh arm added by someone who never read this file.
    $files = array_merge(
        glob(app_path('Services/Search/*.php')) ?: [],
        glob(app_path('Services/Search/Arms/*.php')) ?: [],
        glob(app_path('Support/Search/*.php')) ?: [],
    );

    expect(count($files))->toBeGreaterThan(6, 'the glob found almost nothing, so this sweep proves nothing');

    $offenders = [];
    foreach ($files as $file) {
        if (str_contains(searchSourceWithoutComments($file), 'pgsql_auth')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'these search files reference pgsql_auth: '.implode(', ', $offenders));
});
