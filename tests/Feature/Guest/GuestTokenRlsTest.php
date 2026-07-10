<?php

declare(strict_types=1);

use App\Enums\FormVersionStatus;
use App\Models\Form;
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
| Increment F5 — the DATABASE-level backstop for the guest (null-user) context.
|--------------------------------------------------------------------------
| EstablishGuestTenantContext sets app.current_tenant_id from the token but leaves app.current_user_id NULL
| (guests have no account). This proves — as the non-superuser meridian_app role, with raw DB:: probes so
| the enforcement is the DATABASE's, not the ORM's — that such a context reads and writes ONLY its own
| tenant's forms/versions/submissions, so even if the app-layer guards were bypassed a guest link for
| tenant A is powerless against tenant B.
*/

beforeEach(function (): void {
    TenantContext::flush();
});

/**
 * Seed a tenant with a published v1. Leaves transaction-local context set to this tenant + a user.
 *
 * @return array{tenant: Tenant, user: User, form: Form, version: FormVersion}
 */
function seedGuestTenant(string $name): array
{
    $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($name)]);
    $user = User::factory()->create();

    TenantContext::applyLocal($tenant->id, $user->id);

    $form = Form::create([
        'title' => "{$name} Survey",
        'default_locale' => 'en',
        'owner_user_id' => $user->id,
        'created_by' => $user->id,
    ]);
    $version = FormVersion::create([
        'form_id' => $form->id,
        'version_number' => 1,
        'status' => FormVersionStatus::Published,
        'title' => "{$name} Survey",
        'schema_snapshot' => [],
        'published_at' => now(),
    ]);
    $form->update(['current_published_version_id' => $version->id]);

    return compact('tenant', 'user', 'form', 'version');
}

/**
 * A raw guest submissions row — source=guest, no respondent, no client uuid.
 *
 * @return array<string, mixed>
 */
function guestSubmissionRow(string $tenantId, string $formId, string $versionId): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'form_id' => $formId,
        'form_version_id' => $versionId,
        'status' => 'submitted',
        'source' => 'guest',
    ];
}

it('lets a guest (null-user) context read its own published version but not another tenant’s', function (): void {
    $a = seedGuestTenant('Alpha');
    $b = seedGuestTenant('Bravo');

    // Exactly what the guest middleware establishes: tenant A, NO user.
    TenantContext::applyLocal($a['tenant']->id, null);

    expect(DB::table('form_versions')->where('id', $a['version']->id)->exists())->toBeTrue()
        ->and(DB::table('form_versions')->where('id', $b['version']->id)->exists())->toBeFalse()
        ->and(DB::table('form_versions')->count())->toBe(1); // only A's is visible
});

it('lets a guest context write a submission for its own tenant', function (): void {
    $a = seedGuestTenant('Alpha');
    TenantContext::applyLocal($a['tenant']->id, null);

    expect(DB::table('submissions')->insert(guestSubmissionRow($a['tenant']->id, $a['form']->id, $a['version']->id)))
        ->toBeTrue()
        ->and(DB::table('submissions')->count())->toBe(1);
});

it('fails closed with no tenant context', function (): void {
    $a = seedGuestTenant('Alpha');
    DB::table('submissions')->insert(guestSubmissionRow($a['tenant']->id, $a['form']->id, $a['version']->id));

    TenantContext::applyLocal(null);
    expect(DB::table('form_versions')->count())->toBe(0)
        ->and(DB::table('submissions')->count())->toBe(0);
});

it('rejects a guest context writing a submission into another tenant', function (): void {
    $a = seedGuestTenant('Alpha');
    $b = seedGuestTenant('Bravo');

    TenantContext::applyLocal($a['tenant']->id, null);

    // The WITH CHECK on submissions refuses a row stamped for tenant B (throws — the transaction aborts, so
    // this is the final DB interaction in the test).
    expect(fn () => DB::table('submissions')->insert(
        guestSubmissionRow($b['tenant']->id, $b['form']->id, $b['version']->id)
    ))->toThrow(QueryException::class);
});
