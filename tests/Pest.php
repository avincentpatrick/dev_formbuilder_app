<?php

declare(strict_types=1);
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full framework against real PostgreSQL (never sqlite
| — RLS can't be validated on sqlite; see docs/testing-strategy.md §2).
| RefreshDatabase is applied per-file where a test actually touches the DB.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared RBAC / membership test helpers (Increment B2a/B2b)
|--------------------------------------------------------------------------
| Defined here (not in a single test file) so any test — including single-file
| runs — can use them, and so there is exactly one definition (Pest loads every
| test file into one process; a duplicate top-level function would fatal).
*/

/** Set BOTH the RLS session context and Spatie's permissions team to the tenant (as the middleware does). */
function enterTenant(string $tenantId, ?string $userId = null): void
{
    TenantContext::applyLocal($tenantId, $userId);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
}

/** The id of a global (platform-seeded) role by name, read on the privileged connection. */
function catalogRole(string $name): string
{
    return (string) DB::connection('pgsql_privileged')->table('roles')->where('name', $name)->value('id');
}

/** Create an active membership + assign its tenant-scoped role (requires enterTenant already called). */
function makeActiveMember(User $user, string $roleName): void
{
    TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Active,
        'joined_at' => now(),
        'invited_role_id' => catalogRole($roleName),
    ]);
    $user->syncRoles([$roleName]);
}

/*
|--------------------------------------------------------------------------
| Shared form-versioning test helpers (Increment D). Require enterTenant already called (so
| BelongsToTenant auto-fills tenant_id and the RLS write policies pass).
|--------------------------------------------------------------------------
*/

/** A bare durable form record (no version/collaborator — for structural tests that build versions by hand). */
function makeForm(User $user, string $title = 'Survey'): Form
{
    return Form::create([
        'title' => $title,
        'default_locale' => 'en',
        'owner_user_id' => $user->id,
        'created_by' => $user->id,
    ]);
}

/** A draft form_versions row for a form. */
function makeDraftVersion(Form $form, int $number = 1): FormVersion
{
    return FormVersion::create([
        'form_id' => $form->id,
        'version_number' => $number,
        'status' => FormVersionStatus::Draft,
        'title' => $form->title,
        'schema_snapshot' => [],
    ]);
}

/**
 * Add a field to a (draft) version.
 *
 * @param  array<string, mixed>  $extra
 */
function addFormField(FormVersion $version, User $user, string $key, FieldType $type = FieldType::ShortText, int $sequence = 0, array $extra = []): FormField
{
    return FormField::create(array_merge([
        'form_version_id' => $version->id,
        'key' => $key,
        'field_type' => $type,
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'is_required' => RequiredMode::Optional,
        'sequence' => $sequence,
        'created_by' => $user->id,
    ], $extra));
}
