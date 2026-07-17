<?php

declare(strict_types=1);

use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1/form-templates (Increment G9a) — the token surface. Real Sanctum tokens (withToken), the
| ability gate, the light list shape (no schema_blueprint), and save-as via the API.
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
function formTemplateAdmin(string $slug = 'acme'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$tenant, $admin];
}

it('lists templates for a read token without the heavy blueprint', function (): void {
    [$tenant, $admin] = formTemplateAdmin();
    FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'My template', 'usage_count' => 0, 'is_public' => false,
        'schema_blueprint' => ['sections' => [], 'fields' => [
            ['key' => 'q', 'section_key' => null, 'field_type' => 'short_text', 'label' => 'Q', 'is_required' => 'optional', 'sequence' => 1, 'config' => []],
        ]],
    ]);
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/form-templates')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'field_count', 'usage_count', 'is_platform']], 'meta' => ['next_cursor', 'has_more']])
        ->assertJsonPath('data.0.name', 'My template')
        ->assertJsonPath('data.0.field_count', 1);

    expect($response->json('data.0'))->not->toHaveKey('schema_blueprint');
});

it('saves a version as a template for a write token', function (): void {
    [$tenant, $admin] = formTemplateAdmin();
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $token = $admin->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/form-templates', [
            'form_version_id' => $form->draft_version_id,
            'name' => 'From API',
            'category' => 'M&E / Research',
        ])
        ->assertCreated() // 201 — the wrapped model wasRecentlyCreated (ResourceResponse::calculateStatus)
        ->assertJsonPath('data.name', 'From API')
        ->assertJsonPath('data.is_platform', false);

    enterTenant($tenant->id, $admin->id);
    expect(FormTemplate::query()->where('name', 'From API')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('forbids saving a template with a read-only token', function (): void {
    [$tenant, $admin] = formTemplateAdmin();
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/form-templates', [
            'form_version_id' => $form->draft_version_id,
            'name' => 'Nope',
        ])
        ->assertForbidden();
});
