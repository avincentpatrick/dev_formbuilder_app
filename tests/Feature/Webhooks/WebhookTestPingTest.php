<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /api/v1/webhooks/{endpoint}/test (H13b) — the synthetic test.ping. Synchronous + inline: it runs the
| real SSRF guard + signer + no-redirect POST and returns the result, but persists NO delivery row, never
| meters, and works on an endpoint in any status. `test.ping` lives only in the body, never the event_type
| column.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function testPingToken(): string
{
    /** @var User $admin */
    $admin = test()->admin;

    return $admin->createToken('ci', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;
}

it('sends a signed test.ping, returns the result inline, and persists no delivery row', function (): void {
    Http::fake(['*' => Http::response('pong', 200)]);
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://8.8.8.8/hook']);

    $this->withToken(testPingToken())
        ->postJson("http://acme.meridian.test/api/v1/webhooks/{$endpoint->id}/test")
        ->assertOk()
        ->assertJsonPath('data.delivered', true)
        ->assertJsonPath('data.response_status', 200);

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->header(WebhookSigner::SIGNATURE_HEADER)[0], 'sha256=')
        && $r->hasHeader(WebhookSigner::EVENT_ID_HEADER)
        && json_decode($r->body(), true)['event_type'] === 'test.ping');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('reports a blocked result for an SSRF target and sends nothing', function (): void {
    Http::fake();
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'http://127.0.0.1/hook']);

    $response = $this->withToken(testPingToken())
        ->postJson("http://acme.meridian.test/api/v1/webhooks/{$endpoint->id}/test")
        ->assertOk()
        ->assertJsonPath('data.delivered', false);

    expect($response->json('data.error'))->toBeString()->not->toBeEmpty();
    Http::assertNothingSent();
});
