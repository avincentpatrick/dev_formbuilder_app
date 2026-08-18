<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoCertificateInspector;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SsoConnectionFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Certificate expiry on the SSO settings screen (P1a, ADR-0016 §D11). SsoMetadataParser deliberately does
| NOT check validity dates at import — an IdP legitimately publishes a not-yet-active successor during a
| rollover, and refusing the document would make a correct rotation fail at the SP. Expiry is surfaced here
| instead, and these cases are what say it is surfaced HONESTLY.
|
| ⚠️ HOW THE TIME-DEPENDENT ARMS ARE REACHED, BECAUSE IT IS NOT OBVIOUS. PHP's OpenSSL API cannot set
| `notBefore`, so every generated certificate starts NOW. OpenSSL stamps validity from the REAL system clock
| while Carbon moves only Laravel's — and that asymmetry is the whole mechanism: travelling the app's clock
| FORWARD reaches `expiring_soon`/`expired`, travelling it BACKWARD reaches `not_yet_valid`. A `time()` call
| anywhere in the inspector would make three of the five states untestable, which is why it takes a Carbon.
|--------------------------------------------------------------------------
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
    assignPlanTier(PlanTier::Enterprise);

    $this->inspector = app(SsoCertificateInspector::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('reports a live certificate as valid, with the days remaining', function (): void {
    $rows = $this->inspector->inspect([SsoConnectionFactory::certificate(365)]);

    expect($rows[0]['state'])->toBe('valid')
        ->and($rows[0]['subject'])->toBe('idp.example.com')
        ->and($rows[0]['thumbprint_short'])->toHaveLength(12)
        ->and($rows[0]['expires_in_days'])->toBeGreaterThan(300)
        ->and($this->inspector->rollup($rows))->toMatchArray(['state' => 'ok', 'warning' => null]);
});

it('warns before expiry rather than after', function (): void {
    $this->travel(340)->days();

    $rows = $this->inspector->inspect([SsoConnectionFactory::certificate(365)]);
    $rollup = $this->inspector->rollup($rows);

    expect($rows[0]['state'])->toBe('expiring_soon')
        ->and($rollup['state'])->toBe('expiring_soon')
        ->and($rollup['warning'])->toContain('expires in');
});

it('reports an expired certificate and names the date', function (): void {
    $this->travel(400)->days();

    $rows = $this->inspector->inspect([SsoConnectionFactory::certificate(365)]);
    $rollup = $this->inspector->rollup($rows);

    expect($rows[0]['state'])->toBe('expired')
        // Signed, so "expired N days ago" needs no second field.
        ->and($rows[0]['expires_in_days'])->toBeLessThan(0)
        ->and($rollup['state'])->toBe('expired')
        ->and($rollup['warning'])->toContain('expired on');
});

it('reports a certificate that is not yet valid', function (): void {
    // The app's clock moves back; OpenSSL already stamped notBefore from the real one. See the header.
    $certificate = SsoConnectionFactory::certificate(365);
    $this->travelTo(now()->subDays(2));

    expect($this->inspector->inspect([$certificate])[0]['state'])->toBe('not_yet_valid');
});

it('treats a rollover pair as healthy even though one certificate is not yet valid', function (): void {
    // THE RULE THAT STOPS THE INDICATOR BECOMING NOISE (§D11). A live key beside a successor published ahead
    // of a rotation is an IdP doing exactly the right thing; a naive worst-state-wins rollup would paint it
    // red and teach an admin to ignore the one alert that matters. Any currently-usable key ⇒ ok.
    $rows = [
        ['state' => 'valid', 'expires_in_days' => 200, 'not_after' => now()->addDays(200)->toIso8601String()],
        ['state' => 'not_yet_valid', 'expires_in_days' => 900, 'not_after' => now()->addDays(900)->toIso8601String()],
    ];

    expect($this->inspector->rollup($rows))->toMatchArray(['state' => 'ok', 'warning' => null]);
});

it('reports an unreadable certificate rather than failing the page', function (): void {
    // Reachable in production even though the parser refuses unparsable certificates at import: an OpenSSL
    // major-version upgrade can start rejecting a key it once accepted. A settings page that 500s on a
    // stored value would strand the tenant with no route to fix the thing that broke.
    $this->withoutVite();
    SsoConnection::factory()->unreadableCertificate()->create();

    expect($this->inspector->inspect([base64_encode('nonsense')])[0]['state'])->toBe('unreadable');

    $this->actingAs($this->admin)
        ->get('http://acme.meridian.test/settings/sso')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('data.certificates.0.state', 'unreadable')
            ->where('data.certificates_state', 'unreadable'));
});

it('surfaces a non-email NameID format on the page, because it will not resolve to a user', function (): void {
    // config/saml.php states the requirement outright: an IdP sending a persistent opaque identifier will
    // not match a user here, "which the settings screen must say plainly".
    $this->withoutVite();
    SsoConnection::factory()->create(['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent']);

    $this->actingAs($this->admin)
        ->get('http://acme.meridian.test/settings/sso')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('data.name_id_format_is_email', false)
            ->where('data.name_id_format_known', true));
});

it('renders a stored NameID format the picker cannot represent as read-only', function (): void {
    $this->withoutVite();
    SsoConnection::factory()->create(['name_id_format' => 'urn:example:custom-format']);

    $this->actingAs($this->admin)
        ->get('http://acme.meridian.test/settings/sso')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.name_id_format_known', false));
});
