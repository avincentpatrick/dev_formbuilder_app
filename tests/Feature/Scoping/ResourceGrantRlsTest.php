<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| resource_grants RLS (Increment G10a). The standard four properties, PLUS the ones unique to a
| polymorphic authorization table: `scopeable_id` carries no foreign key, so nothing but the RLS guard
| stops a grant pointing at another tenant's form. Every assertion uses raw DB:: queries — the point is
| to prove the DATABASE enforces this, since the ORM is exactly what an attacker bypasses.
|
| Two house constraints shape how these are written:
|   - Fixtures are inserted on the DEFAULT connection under the owning tenant's context, never on
|     pgsql_privileged: that session commits outside RefreshDatabase's transaction and therefore cannot
|     see the `tenants` rows these tests create.
|   - PostgreSQL aborts the surrounding transaction on ANY error, so a test that asserts a throw must not
|     issue further statements. Each rejection therefore gets its own test.
*/

beforeEach(function (): void {
    TenantContext::flush();
});

/** Two tenants and a user, with tenant A left as the active context. */
function twoTenants(): array
{
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    $user = User::factory()->create();

    return [$a, $b, $user];
}

/** A form owned by $tenant, written under that tenant's own context so RLS permits it. */
function formFor(Tenant $tenant, User $owner, string $title = 'Survey'): string
{
    $id = Uuid::uuid7()->toString();

    TenantContext::applyLocal($tenant->id, $owner->id);
    DB::table('forms')->insert([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'title' => $title,
        'status' => 'draft',
        'default_locale' => 'en',
        'supported_locales' => json_encode([]),
        'capability_flags' => json_encode([]),
        'owner_user_id' => $owner->id,
        'created_by' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** A scope node owned by $tenant, likewise written under that tenant's context. */
function nodeFor(Tenant $tenant, string $name = 'Region'): string
{
    $id = Uuid::uuid7()->toString();

    TenantContext::applyLocal($tenant->id);
    DB::table('scope_nodes')->insert([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'name' => $name,
        'path' => '/'.$id.'/',
        'depth' => 0,
        'position' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** @return array<string, mixed> */
function grantRow(string $tenantId, string $type, string $targetId, string $userId): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'scopeable_type' => $type,
        'scopeable_id' => $targetId,
        'user_id' => $userId,
        'capacity' => 'editor',
        'includes_descendants' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has RLS enabled AND forced on resource_grants', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['resource_grants']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('never shows one tenant the grants of another', function (): void {
    [$a, $b, $user] = twoTenants();

    $formA = formFor($a, $user, 'Alpha form');
    $formB = formFor($b, $user, 'Bravo form');

    TenantContext::applyLocal($a->id, $user->id);
    DB::table('resource_grants')->insert(grantRow($a->id, 'form', $formA, $user->id));

    TenantContext::applyLocal($b->id, $user->id);
    DB::table('resource_grants')->insert(grantRow($b->id, 'form', $formB, $user->id));
    expect(DB::table('resource_grants')->pluck('scopeable_id')->all())->toBe([$formB]);

    TenantContext::applyLocal($a->id, $user->id);
    expect(DB::table('resource_grants')->pluck('scopeable_id')->all())->toBe([$formA]);
});

it('refuses a grant whose tenant_id is not the current context', function (): void {
    [$a, $b, $user] = twoTenants();
    $formB = formFor($b, $user);

    TenantContext::applyLocal($a->id, $user->id);

    expect(fn () => DB::table('resource_grants')->insert(grantRow($b->id, 'form', $formB, $user->id)))
        ->toThrow(QueryException::class);
});

it('REFUSES a grant pointing at another tenant\'s form, even with my own tenant_id', function (): void {
    // The core novel hazard. Under the plain strict shape this INSERT SUCCEEDS: tenant_id matches the
    // context, and a morph has no FK to contradict `scopeable_id`. Only the guard's same-tenant EXISTS
    // rejects it. If this ever stops throwing, cross-tenant privilege escalation is live.
    [$a, $b, $attacker] = twoTenants();
    $victimForm = formFor($b, $attacker, 'Bravo secret');

    TenantContext::applyLocal($a->id, $attacker->id);

    expect(fn () => DB::table('resource_grants')->insert(grantRow($a->id, 'form', $victimForm, $attacker->id)))
        ->toThrow(QueryException::class);
});

it('REFUSES a grant pointing at another tenant\'s scope node', function (): void {
    [$a, $b, $attacker] = twoTenants();
    $victimNode = nodeFor($b);

    TenantContext::applyLocal($a->id, $attacker->id);

    expect(fn () => DB::table('resource_grants')->insert(grantRow($a->id, 'scope_node', $victimNode, $attacker->id)))
        ->toThrow(QueryException::class);
});

it('refuses a grant pointing at a well-formed but nonexistent target', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $user = User::factory()->create();

    TenantContext::applyLocal($a->id, $user->id);

    expect(fn () => DB::table('resource_grants')->insert(
        grantRow($a->id, 'form', Uuid::uuid7()->toString(), $user->id)
    ))->toThrow(QueryException::class);
});

it('refuses to REPOINT an existing grant at another tenant\'s form', function (): void {
    // UPDATE's WITH CHECK validates the RESULT row, so re-pointing is guarded even though its USING
    // clause is tenant-only. Without that branch, an attacker inserts a legal grant then mutates the
    // target — the same escalation in two steps.
    [$a, $b, $attacker] = twoTenants();
    $mine = formFor($a, $attacker, 'Alpha own');
    $theirs = formFor($b, $attacker, 'Bravo secret');

    TenantContext::applyLocal($a->id, $attacker->id);
    $row = grantRow($a->id, 'form', $mine, $attacker->id);
    DB::table('resource_grants')->insert($row);

    expect(fn () => DB::table('resource_grants')->where('id', $row['id'])->update(['scopeable_id' => $theirs]))
        ->toThrow(QueryException::class);
});

it('keeps an orphaned grant readable and deletable so it can still be revoked', function (): void {
    // SELECT and DELETE are deliberately NOT target-guarded. A grant whose target was hard-deleted must
    // not become un-revokable — otherwise the guard manufactures permanent garbage it also forbids
    // cleaning up.
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $user = User::factory()->create();
    $form = formFor($a, $user);

    TenantContext::applyLocal($a->id, $user->id);
    $row = grantRow($a->id, 'form', $form, $user->id);
    DB::table('resource_grants')->insert($row);

    // Orphan it exactly as a hard delete of the form would (no FK on scopeable_id to cascade).
    DB::table('forms')->where('id', $form)->delete();

    expect(DB::table('resource_grants')->where('id', $row['id'])->exists())->toBeTrue();
    expect(DB::table('resource_grants')->where('id', $row['id'])->delete())->toBe(1);
});
