<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /api/v1/webhooks/{endpoint}/rotate-secret (H13b) — dual-secret rotation grace. The current secret
| moves to secret_previous (kept for the grace window), a new secret is minted + returned once, both secrets
| are redacted in the audit, and during the window a delivery is signed with BOTH.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function rotateToken(): string
{
    /** @var User $admin */
    $admin = test()->admin;

    return $admin->createToken('ci', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;
}

it('rotates the secret: new plaintext once, old kept for the grace window', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    $oldSecret = $endpoint->secret;

    $response = $this->withToken(rotateToken())
        ->postJson("http://acme.meridian.test/api/v1/webhooks/{$endpoint->id}/rotate-secret")
        ->assertOk();

    $newSecret = $response->json('data.secret');
    expect($newSecret)->toStartWith('whsec_')->not->toBe($oldSecret)
        ->and($response->json('data.secret_previous_expires_at'))->not->toBeNull();

    enterTenant($this->tenant->id, $this->admin->id);
    $endpoint->refresh();
    expect($endpoint->secret)->toBe($newSecret)
        ->and($endpoint->secret_previous)->toBe($oldSecret)
        ->and($endpoint->secret_previous_expires_at->isFuture())->toBeTrue();
});

it('writes an updated audit with both signing secrets redacted', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    $this->withToken(rotateToken())
        ->postJson("http://acme.meridian.test/api/v1/webhooks/{$endpoint->id}/rotate-secret")
        ->assertOk();

    enterTenant($this->tenant->id, $this->admin->id);
    $audit = DB::table('audits')
        ->where('auditable_type', 'webhook_endpoint')
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull();
    expect(json_decode((string) $audit->redacted_fields, true))
        ->toContain('secret')
        ->toContain('secret_previous');
    expect((string) $audit->new_values)->not->toContain('whsec_');
});

it('signs with both secrets during the grace window and only the new one after it lapses', function (): void {
    $endpoint = WebhookEndpoint::factory()->make([
        'secret' => 'whsec_new',
        'secret_previous' => 'whsec_old',
        'secret_previous_expires_at' => Carbon::now()->addHour(),
    ]);
    $signer = app(WebhookSigner::class);
    $ts = '2026-07-26T10:00:00+00:00';
    $body = '{"a":1}';

    $dual = $signer->signatureHeaderFor($endpoint, $ts, $body);
    expect(substr_count($dual, 'sha256='))->toBe(2)
        ->and($dual)->toContain('sha256='.hash_hmac('sha256', "{$ts}.{$body}", 'whsec_new'))
        ->and($dual)->toContain('sha256='.hash_hmac('sha256', "{$ts}.{$body}", 'whsec_old'));

    // Once the window lapses, only the current secret signs.
    $endpoint->secret_previous_expires_at = Carbon::now()->subHour();
    $single = $signer->signatureHeaderFor($endpoint, $ts, $body);
    expect(substr_count($single, 'sha256='))->toBe(1)
        ->and($single)->toBe('sha256='.hash_hmac('sha256', "{$ts}.{$body}", 'whsec_new'));
});
