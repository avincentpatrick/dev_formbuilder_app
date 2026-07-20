<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
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
| form_versions published-immutability fuzz pack (Increment D1, DoD).
|--------------------------------------------------------------------------
| Runs as the NON-superuser meridian_app role, which OWNS these tables — so every "cannot mutate a
| published version's content" assertion is ALSO the proof that FORCE ROW LEVEL SECURITY is active.
| The two load-bearing claims this pack pins RED-then-GREEN:
|   (1) A draft version's content is freely writable; a published/superseded version's content is not.
|   (2) A published version cannot be DELETEd — closing the FK-CASCADE bypass (referential actions run
|       bypassing RLS, so a deletable published version would wipe its "immutable" children).
| Assertions use raw DB:: queries (no Eloquent scope) so what is proven is the DATABASE's enforcement.
*/

beforeEach(function (): void {
    TenantContext::flush();
});

/**
 * Seed one tenant with a form carrying a PUBLISHED version (v1, one field) and a DRAFT version (v2, one
 * field). The published version's field is written while v1 is still a draft, then v1 is flipped to
 * published — the only path by which immutable content can come to exist (the seeded-draft-then-flip
 * gotcha).
 *
 * @return array{tenant: Tenant, user: User, form: Form, publishedVersion: FormVersion, publishedField: FormField, draftVersion: FormVersion, draftField: FormField}
 */
function seedForm(): array
{
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();

    TenantContext::applyLocal($tenant->id, $user->id);

    $form = Form::create([
        'title' => 'Household Survey',
        'default_locale' => 'en',
        'owner_user_id' => $user->id,
        'created_by' => $user->id,
    ]);

    $publishedVersion = FormVersion::create([
        'form_id' => $form->id,
        'version_number' => 1,
        'status' => FormVersionStatus::Draft, // written as a draft, published below
        'title' => 'Household Survey',
        'schema_snapshot' => [],
    ]);
    $publishedField = FormField::create([
        'form_version_id' => $publishedVersion->id,
        'key' => 'age',
        'field_type' => FieldType::Integer,
        'label' => 'Age',
        'is_required' => RequiredMode::Required,
        'created_by' => $user->id,
    ]);
    $publishedVersion->update(['status' => FormVersionStatus::Published, 'published_at' => now()]);

    $draftVersion = FormVersion::create([
        'form_id' => $form->id,
        'version_number' => 2,
        'status' => FormVersionStatus::Draft,
        'title' => 'Household Survey',
        'schema_snapshot' => [],
    ]);
    $draftField = FormField::create([
        'form_version_id' => $draftVersion->id,
        'key' => 'age',
        'field_type' => FieldType::Integer,
        'label' => 'Age',
        'is_required' => RequiredMode::Required,
        'created_by' => $user->id,
    ]);

    $form->update([
        'current_published_version_id' => $publishedVersion->id,
        'draft_version_id' => $draftVersion->id,
        'status' => FormStatus::Published,
    ]);

    return compact('tenant', 'user', 'form', 'publishedVersion', 'publishedField', 'draftVersion', 'draftField');
}

/**
 * A raw form_fields insert row (bypasses Eloquent), used to probe the INSERT write policy directly.
 *
 * @return array<string, mixed>
 */
function fieldRow(string $tenantId, string $versionId, string $userId, string $key): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'form_version_id' => $versionId,
        'key' => $key,
        'field_type' => 'short_text',
        'label' => 'probe',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('runs as a non-superuser role with RLS enabled AND forced on every versioning table', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as bypass_rls '
        .'from pg_roles where rolname = current_user'
    );
    expect((int) $role->is_super)->toBe(0);
    expect((int) $role->bypass_rls)->toBe(0);

    $tables = [
        'forms', 'form_versions', 'form_sections', 'form_fields', 'form_field_validations',
        // G10a: `form_collaborators` retired in favour of these two. Listing them here means a future
        // migration that drops FORCE on either fails this test rather than silently widening access.
        'resource_grants', 'scope_nodes',
    ];

    foreach ($tables as $table) {
        $meta = DB::selectOne(
            'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced from pg_class where relname = ?',
            [$table]
        );
        expect((int) $meta->enabled)->toBe(1)->and((int) $meta->forced)->toBe(1);
    }
});

it('freely writes content against a DRAFT version', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // INSERT a new field on the draft version — the EXISTS-draft guard permits it.
    DB::table('form_fields')->insert(fieldRow($s['tenant']->id, $s['draftVersion']->id, $s['user']->id, 'income'));
    expect(DB::table('form_fields')->where('form_version_id', $s['draftVersion']->id)->count())->toBe(2);

    // UPDATE and DELETE the draft field both take effect.
    expect(DB::table('form_fields')->where('id', $s['draftField']->id)->update(['label' => 'Age (years)']))->toBe(1);
    expect(DB::table('form_fields')->where('id', $s['draftField']->id)->delete())->toBe(1);
});

it('rejects INSERTing content against a PUBLISHED version (WITH CHECK)', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    expect(fn () => DB::table('form_fields')->insert(
        fieldRow($s['tenant']->id, $s['publishedVersion']->id, $s['user']->id, 'smuggled')
    ))->toThrow(QueryException::class);
});

it('silently no-ops UPDATE and DELETE of a PUBLISHED version\'s content (USING)', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // The published field is visible (SELECT is not draft-gated) but frozen against writes: the write
    // policies' USING clause excludes it, so both statements match zero rows and change nothing.
    expect(DB::table('form_fields')->where('id', $s['publishedField']->id)->update(['label' => 'hacked']))->toBe(0);
    expect(DB::table('form_fields')->where('id', $s['publishedField']->id)->delete())->toBe(0);

    expect(DB::table('form_fields')->where('id', $s['publishedField']->id)->value('label'))->toBe('Age');
});

it('always allows READING a published version\'s content (SELECT is never draft-gated)', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    expect(DB::table('form_fields')->where('form_version_id', $s['publishedVersion']->id)->count())->toBe(1);
});

it('lets the publish transition published→superseded succeed (the anti-trap)', function (): void {
    // A same-table `status <> 'published'` guard would make this UPDATE a silent zero-row no-op. The
    // form_version shape keeps UPDATE strict precisely so supersede works.
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    expect(DB::table('form_versions')->where('id', $s['publishedVersion']->id)
        ->update(['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);

    expect(DB::table('form_versions')->where('id', $s['publishedVersion']->id)->value('status'))->toBe('superseded');
});

it('refuses to DELETE a published version, keeping its children safe from the FK cascade', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // DELETE is draft-only: a published version matches zero rows, so it survives — and because it
    // survives, its ON DELETE CASCADE never fires and the immutable child is never wiped.
    expect(DB::table('form_versions')->where('id', $s['publishedVersion']->id)->delete())->toBe(0);
    expect(DB::table('form_versions')->where('id', $s['publishedVersion']->id)->exists())->toBeTrue();
    expect(DB::table('form_fields')->where('id', $s['publishedField']->id)->exists())->toBeTrue();
});

it('permits discarding a DRAFT version, cascading its content away', function (): void {
    $s = seedForm();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    expect(DB::table('form_versions')->where('id', $s['draftVersion']->id)->delete())->toBe(1);
    // The draft's field cascaded away with it.
    expect(DB::table('form_fields')->where('id', $s['draftField']->id)->exists())->toBeFalse();
});

it('fails closed on the versioning tables with no tenant context', function (): void {
    $s = seedForm();

    // No context at all ⇒ zero rows, never all rows.
    TenantContext::applyLocal(null);
    expect(DB::table('form_versions')->count())->toBe(0);
    expect(DB::table('form_fields')->count())->toBe(0);
});

it('isolates the versioning tables across tenants', function (): void {
    $s = seedForm();

    // Another tenant sees none of it …
    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    TenantContext::applyLocal($other->id, $s['user']->id);

    expect(DB::table('form_versions')->count())->toBe(0);
    expect(DB::table('form_fields')->count())->toBe(0);

    // … and cannot smuggle a write into the other tenant's draft. (This throw aborts the test's
    // transaction, so it must be the final DB interaction in the test.)
    expect(fn () => DB::table('form_fields')->insert(
        fieldRow($s['tenant']->id, $s['draftVersion']->id, $s['user']->id, 'cross_tenant')
    ))->toThrow(QueryException::class);
});

it('carries exactly one write policy per command on each draft-guarded child table', function (): void {
    // The whole reason the EXISTS guard is restrictive (not merely additive) is that it is the SOLE
    // write policy per command — Postgres OR-combines same-command permissive policies. A stray second
    // strict policy would silently re-open published-version writes; assert it never lands.
    foreach (['form_sections', 'form_fields', 'form_field_validations'] as $table) {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $cmd) {
            $count = DB::table('pg_policies')
                ->where('tablename', $table)
                ->where('cmd', $cmd)
                ->count();
            expect($count)->toBe(1, "{$table} must have exactly one {$cmd} policy, found {$count}");
        }
    }
});
