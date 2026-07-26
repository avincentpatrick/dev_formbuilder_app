<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\PlanTier;
use App\Enums\WebhookEndpointStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1/webhooks (H13a) — CRUD, secret masking, the webhooks feature gate, the endpoint cap, the
| ability+policy two-layer authz, and RLS tenant isolation. Real tokens via withToken() (the AuditApiTest
| pattern): actingAs() would resolve a TransientToken that passes every ability, making the two-layer check
| vacuous.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function webhooksUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/api/v1/webhooks'.$suffix;
}

function adminWebhookToken(): string
{
    /** @var User $admin */
    $admin = test()->admin;

    return $admin->createToken('ci', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;
}

it('creates an endpoint and returns the plaintext secret exactly once', function (): void {
    $response = $this->withToken(adminWebhookToken())->postJson(webhooksUrl(), [
        'name' => 'Ops relay',
        'url' => 'https://8.8.8.8/hooks/inbound',
        'event_types' => [DomainEventType::SubmissionCreated->value, DomainEventType::FormPublished->value],
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'Ops relay')
        ->assertJsonPath('data.status', WebhookEndpointStatus::Active->value);

    $secret = $response->json('data.secret');
    expect($secret)->toStartWith('whsec_')
        ->and($response->json('data.secret_masked'))->toStartWith('…');

    // Persisted encrypted; the API list never returns the plaintext again.
    enterTenant($this->tenant->id, $this->admin->id);
    $endpoint = WebhookEndpoint::query()->firstOrFail();
    expect($endpoint->secret)->toBe($secret);

    $this->withToken(adminWebhookToken())->getJson(webhooksUrl())
        ->assertOk()
        ->assertJsonMissingPath('data.0.secret')
        ->assertJsonPath('data.0.secret_masked', $endpoint->maskedSecret());
});

it('writes a created audit row with the secret redacted', function (): void {
    $this->withToken(adminWebhookToken())->postJson(webhooksUrl(), [
        'name' => 'Audited',
        'url' => 'https://8.8.8.8/hooks/x',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    $audit = DB::table('audits')->where('auditable_type', 'webhook_endpoint')->where('event', 'created')->first();

    expect($audit)->not->toBeNull();
    expect(json_decode((string) $audit->redacted_fields, true))->toContain('secret');
    expect((string) $audit->new_values)->not->toContain('whsec_');
});

it('updates fields and resets the breaker when re-activated', function (): void {
    $endpoint = WebhookEndpoint::factory()->paused()->create(['consecutive_failure_count' => 20]);

    $this->withToken(adminWebhookToken())->patchJson(webhooksUrl('/'.$endpoint->id), [
        'name' => 'Renamed',
        'status' => WebhookEndpointStatus::Active->value,
    ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'active');

    enterTenant($this->tenant->id, $this->admin->id);
    $endpoint->refresh();
    expect($endpoint->consecutive_failure_count)->toBe(0)
        ->and($endpoint->disabled_reason)->toBeNull();
});

it('soft-deletes an endpoint', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    $this->withToken(adminWebhookToken())->deleteJson(webhooksUrl('/'.$endpoint->id))->assertNoContent();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(WebhookEndpoint::withTrashed()->find($endpoint->id)->trashed())->toBeTrue();
});

it('refuses a Viewer even when their token carries manage:webhooks (the policy re-checks the user)', function (): void {
    $token = $this->viewer->createToken('ci', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;

    $this->withToken($token)->getJson(webhooksUrl())->assertForbidden();
});

it('blocks creation when the plan lacks the webhooks feature (Free)', function (): void {
    assignPlanTier(PlanTier::Free);

    $this->withToken(adminWebhookToken())->postJson(webhooksUrl(), [
        'name' => 'Nope',
        'url' => 'https://8.8.8.8/x',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertStatus(402);
});

it('hard-blocks creation past the per-tier endpoint cap', function (): void {
    assignPlanTier(PlanTier::Starter); // webhook_endpoints_count = 3
    WebhookEndpoint::factory()->count(3)->create();

    $this->withToken(adminWebhookToken())->postJson(webhooksUrl(), [
        'name' => 'Fourth',
        'url' => 'https://8.8.8.8/x',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertStatus(402);
});

it('rejects a private/SSRF URL and an unknown event type at validation', function (): void {
    // One token reused across both requests: a request tears the tenant GUC down, so minting a second token
    // afterwards would hit the personal_access_tokens strict-RLS WITH CHECK with no context.
    $token = adminWebhookToken();

    $this->withToken($token)->postJson(webhooksUrl(), [
        'name' => 'Bad url',
        'url' => 'http://10.0.0.1/hook',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['url']]]]);

    $this->withToken($token)->postJson(webhooksUrl(), [
        'name' => 'Bad event',
        'url' => 'https://8.8.8.8/x',
        'event_types' => ['not.a.real.event'],
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['event_types.0']]]]);
});

it('never lists another tenant\'s endpoints', function (): void {
    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id);
    $foreign = WebhookEndpoint::factory()->create();

    enterTenant($this->tenant->id, $this->admin->id);
    WebhookEndpoint::factory()->create();

    $ids = collect($this->withToken(adminWebhookToken())->getJson(webhooksUrl())->assertOk()->json('data'))->pluck('id');
    expect($ids)->toHaveCount(1)->not->toContain($foreign->id);
});
