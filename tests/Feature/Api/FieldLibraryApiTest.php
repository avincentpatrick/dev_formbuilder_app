<?php

declare(strict_types=1);

use App\Models\FieldLibrary;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1/field-library (Increment G9b) — the token surface. Real Sanctum tokens (withToken), the ability
| gate, the light list shape (no default_config/default_validations), and authoring via the API.
| Uniquely-named helper (fieldLibraryAdmin) — Pest loads every test file into one process, so a top-level
| function name must not collide with another file's helper.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A tenant reachable at {slug}.meridian.test with an active admin member (context left on that tenant). */
function fieldLibraryAdmin(string $slug = 'acme'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$tenant, $admin];
}

it('lists library items for a read token without the heavy jsonb', function (): void {
    [$tenant, $admin] = fieldLibraryAdmin();
    FieldLibrary::create([
        'tenant_id' => $tenant->id, 'name' => 'Age', 'field_type' => 'integer', 'default_label' => 'Age',
        'default_config' => ['min' => 0], 'default_validations' => [], 'is_active' => true, 'usage_count' => 0,
    ]);
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/field-library')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'field_type', 'default_label', 'is_platform', 'usage_count']], 'meta' => ['next_cursor', 'has_more']])
        ->assertJsonPath('data.0.name', 'Age');

    expect($response->json('data.0'))->not->toHaveKey('default_config')
        ->and($response->json('data.0'))->not->toHaveKey('default_validations');
});

it('authors a library item for a write token', function (): void {
    [$tenant, $admin] = fieldLibraryAdmin();
    $token = $admin->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/field-library', [
            'name' => 'Consent', 'field_type' => 'yes_no', 'default_label' => 'Do you consent?',
            'category' => 'Consent',
        ])
        ->assertCreated() // 201 — the wrapped model wasRecentlyCreated (ResourceResponse::calculateStatus)
        ->assertJsonPath('data.name', 'Consent')
        ->assertJsonPath('data.field_type', 'yes_no')
        ->assertJsonPath('data.is_platform', false);

    enterTenant($tenant->id, $admin->id);
    expect(FieldLibrary::query()->where('name', 'Consent')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('forbids authoring a library item with a read-only token', function (): void {
    [$tenant, $admin] = fieldLibraryAdmin();
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/field-library', [
            'name' => 'Nope', 'field_type' => 'short_text', 'default_label' => 'Nope',
        ])
        ->assertForbidden();
});

it('rejects an unknown field_type on author', function (): void {
    [, $admin] = fieldLibraryAdmin();
    $token = $admin->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/field-library', [
            'name' => 'Bad', 'field_type' => 'not_a_type', 'default_label' => 'Bad',
        ])
        ->assertStatus(422);
});
