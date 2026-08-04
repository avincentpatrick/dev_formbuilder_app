<?php

declare(strict_types=1);

use App\Enums\FormVersionStatus;
use App\Enums\TenantUserStatus;
use App\Models\FormVersion;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment E — /api/v1 token surface end-to-end (real subdomain + RLS pipeline).
|--------------------------------------------------------------------------
| Proves the Sanctum-token auth path (Architecture A: tenant context set from the subdomain BEFORE the
| token lookup, so cross-tenant tokens are invisible), the ability + FormPolicy gates, the error envelope,
| cursor pagination, publish-via-API, and tenant-scoped token minting. Ability tests use REAL tokens with
| withToken(), never actingAs() — the Sanctum guard's first-party web-guard loop would otherwise resolve
| an actingAs() user to a TransientToken that passes every ability check.
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

/** A tenant reachable at {slug}.meridian.test. */
function apiTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

// apiMember() moved to tests/Pest.php in H22a. It lived here, and a single-file run of any OTHER API test
// therefore died with "Call to undefined function apiMember()" — Pest loads every test file into one
// process, so it only ever resolved when this file happened to be loaded too. That is the exact failure
// tests/Pest.php's own header exists to prevent.

// ── Auth path ─────────────────────────────────────────────────────────────────────────

it('lists forms for a valid read token and records last_used_at', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    app(FormService::class)->create($tenant, $admin, 'Survey');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'title', 'status']], 'meta' => ['next_cursor', 'has_more']])
        ->assertJsonPath('data.0.title', 'Survey');

    enterTenant($tenant->id, $admin->id);
    expect(PersonalAccessToken::query()->value('last_used_at'))->not->toBeNull();
});

it('rejects a request with no bearer token', function (): void {
    apiTenant();

    $this->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects a malformed bearer token', function (): void {
    apiTenant();

    $this->withToken('not-a-real-token')
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects an expired token without recording last_used_at', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS], now()->subMinute())->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertUnauthorized();

    enterTenant($tenant->id, $admin->id);
    expect(PersonalAccessToken::query()->value('last_used_at'))->toBeNull();
});

it('hides a token minted for another tenant (RLS cross-tenant invisibility)', function (): void {
    apiTenant('acme');
    $beta = apiTenant('beta');
    enterTenant($beta->id);
    $betaAdmin = apiMember('admin');
    $betaToken = $betaAdmin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    // Present beta's token on the acme subdomain → strict RLS hides the token row → 401.
    $this->withToken($betaToken)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('invalidates a removed member’s token', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $member = apiMember('admin');
    $token = $member->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    enterTenant($tenant->id, $member->id);
    TenantUser::query()->where('user_id', $member->id)->update(['status' => TenantUserStatus::Removed]);

    // The tokenable user is now hidden by the users join-shape policy (no active membership) → 401.
    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertUnauthorized();
});

// ── Ability + policy gates ──────────────────────────────────────────────────────────────

it('forbids a read-only token from the publish route', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $readToken = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($readToken)
        ->postJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$form->draft_version_id}/publish")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability')
        ->assertJsonPath('error.details.missing', [ApiAbilities::WRITE_FORMS]);
});

// ── Error envelope + pagination ───────────────────────────────────────────────────────────

it('returns the error envelope for an unknown form', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms/'.Str::uuid()->toString())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message']]);
});

it('cursor-paginates the forms list', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
        app(FormService::class)->create($tenant, $admin, $title);
    }
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $page1 = $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/forms?limit=2')
        ->assertOk()
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonCount(2, 'data');

    $cursor = $page1->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/forms?limit=2&cursor={$cursor}")
        ->assertOk()
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonCount(1, 'data');
});

// ── Publish via API ───────────────────────────────────────────────────────────────────────

it('publishes a draft version through the API', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $writeToken = $admin->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($writeToken)
        ->postJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$form->draft_version_id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonStructure(['data' => ['id', 'version_number', 'status', 'change_summary', 'schema_snapshot']]);

    enterTenant($tenant->id, $admin->id);
    expect(FormVersion::query()->where('form_id', $form->id)->where('status', FormVersionStatus::Published)->count())->toBe(1);
});

it('maps a lifecycle-rule violation to the 422 envelope', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $writeToken = $admin->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;
    $publishedVersionId = $form->draft_version_id; // becomes 'published' after the first publish

    $this->withToken($writeToken)
        ->postJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$publishedVersionId}/publish")
        ->assertOk();

    // Re-publishing an already-published version is not allowed (only a draft can be published).
    $this->withToken($writeToken)
        ->postJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$publishedVersionId}/publish")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'form_rule_violated');
});

// ── Token minting (session-authenticated) ─────────────────────────────────────────────────

it('mints a tenant-scoped token with the issuer’s abilities', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');

    $response = $this->actingAs($admin)
        ->postJson('http://acme.meridian.test/api/v1/auth/tokens', [
            'name' => 'CI key',
            'abilities' => [ApiAbilities::READ_FORMS, ApiAbilities::WRITE_FORMS],
        ])
        ->assertCreated()
        ->assertJsonPath('abilities', [ApiAbilities::READ_FORMS, ApiAbilities::WRITE_FORMS]);

    expect($response->json('token'))->toBeString();

    enterTenant($tenant->id, $admin->id);
    $token = PersonalAccessToken::query()->firstOrFail();
    expect($token->tenant_id)->toBe($tenant->id)               // R1: tenant_id auto-filled at mint
        ->and($token->abilities)->toBe([ApiAbilities::READ_FORMS, ApiAbilities::WRITE_FORMS]);
});

it('trims requested abilities to the issuer’s RBAC', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $viewer = apiMember('viewer');

    $this->actingAs($viewer)
        ->postJson('http://acme.meridian.test/api/v1/auth/tokens', [
            'name' => 'Viewer key',
            'abilities' => [ApiAbilities::WRITE_FORMS, ApiAbilities::READ_FORMS],
        ])
        ->assertCreated()
        ->assertJsonPath('abilities', []); // a viewer holds no forms.* permissions → both trimmed away
});

it('validates the requested abilities against the catalog', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');

    $this->actingAs($admin)
        ->postJson('http://acme.meridian.test/api/v1/auth/tokens', [
            'name' => 'Bad key',
            'abilities' => ['bogus:ability'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['abilities.0']]]]);
});

it('requires authentication to mint a token', function (): void {
    apiTenant();

    $this->postJson('http://acme.meridian.test/api/v1/auth/tokens', [
        'name' => 'x',
        'abilities' => [ApiAbilities::READ_FORMS],
    ])->assertUnauthorized();
});

it('enforces an empty-ability token on a write route', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    // A viewer's intersected ability set is empty; the resulting token can authenticate but does nothing.
    $viewer = apiMember('viewer');
    $emptyToken = $viewer->createToken('ci', ApiAbilities::intersect($viewer, [ApiAbilities::WRITE_FORMS]))->plainTextToken;

    $this->withToken($emptyToken)
        ->postJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$form->draft_version_id}/publish")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('reads the current tenant profile', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.name', 'Acme');
});

// ── Token management (list / revoke, session-authenticated) ───────────────────────────────

it('lists and revokes only the caller’s own API keys', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $other = apiMember('admin');

    enterTenant($tenant->id, $admin->id);
    $admin->createToken('key-a', [ApiAbilities::READ_FORMS]);
    $keyB = $admin->createToken('key-b', [ApiAbilities::READ_FORMS])->accessToken;

    enterTenant($tenant->id, $other->id);
    $other->createToken('other-key', [ApiAbilities::READ_FORMS]);

    // List (session-authenticated) → only the caller's two keys, and never the plaintext secret.
    $list = $this->actingAs($admin)
        ->getJson('http://acme.meridian.test/api/v1/auth/tokens')
        ->assertOk();
    expect($list->json('data'))->toHaveCount(2)
        ->and(collect($list->json('data'))->pluck('name')->all())->toContain('key-a', 'key-b')
        ->and($list->json('data.0'))->not->toHaveKey('token');

    $this->actingAs($admin)
        ->deleteJson("http://acme.meridian.test/api/v1/auth/tokens/{$keyB->getKey()}")
        ->assertNoContent();

    enterTenant($tenant->id, $admin->id);
    expect(PersonalAccessToken::query()->where('name', 'key-b')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->where('name', 'other-key')->exists())->toBeTrue();
});

it('404s a revoke with a non-numeric token id', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');

    $this->actingAs($admin)
        ->deleteJson('http://acme.meridian.test/api/v1/auth/tokens/not-a-number')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

// ── Form versions ─────────────────────────────────────────────────────────────────────────

it('lists versions without schema and shows a version with its schema', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(PublishService::class)->publish($form, $admin); // v1 published + v2 draft cloned forward
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.schema_snapshot');

    enterTenant($tenant->id, $admin->id);
    $publishedId = FormVersion::query()->where('form_id', $form->id)->where('status', FormVersionStatus::Published)->value('id');

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/forms/{$form->id}/versions/{$publishedId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonStructure(['data' => ['id', 'version_number', 'schema_snapshot']]);
});

it('404s a version that belongs to another form (scoped binding)', function (): void {
    $tenant = apiTenant();
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    $formA = app(FormService::class)->create($tenant, $admin, 'A');
    $formB = app(FormService::class)->create($tenant, $admin, 'B');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson("http://acme.meridian.test/api/v1/forms/{$formA->id}/versions/{$formB->draft_version_id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

// ── Host / envelope robustness ─────────────────────────────────────────────────────────────

it('returns a tenant-not-identified envelope on the central host', function (): void {
    apiTenant();

    $this->getJson('http://meridian.test/api/v1/forms')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'tenant_not_identified');
});
