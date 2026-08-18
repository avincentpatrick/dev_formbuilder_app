<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\PlanTier;
use App\Enums\SsoConnectionStatus;
use App\Models\Audit;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoConnectionService;
use App\Support\Audit\AuditableTypes;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SsoConnectionFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The `sso_connection` audit sites (P1a, ADR-0016 §D12/§D13; audit-compliance-logging-spec §1/§2).
|
| Its own file rather than a line in AuditCoverageTest, on the AuditDomainSitesTest precedent: an audit
| surface with its own addressing rule and its own redaction hazard earns one.
|
| ⚠️ THE HAZARD THIS FILE EXISTS FOR. `Model::getOriginal()` maps attributes through their casts, so on
| `idp_certificates` (`encrypted:array`) it returns the DECRYPTED key list — and `$hidden` does not apply to
| it. The repo's ordinary snapshot idiom would therefore write plaintext IdP signing keys into `audits`,
| which is append-only by RLS policy and never pruned. Two tests below cover the two independent defences:
| the service's hand-built payload (the mechanism) and the AuditRedactor registration (the backstop). Both
| are needed — the backstop stays green if someone "simplifies" the payload to getOriginal(), so only the
| first would catch that, and only the second would catch a future writer that snapshots the model.
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

    $this->service = app(SsoConnectionService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return Collection<int, Audit> */
function ssoAudits(): Collection
{
    return Audit::query()
        ->where('auditable_type', SsoConnectionService::AUDIT_TYPE)
        ->orderBy('created_at')
        ->get();
}

it('keys every SSO row on the tenant, never on the connection uuid', function (): void {
    // The connection is a singleton a tenant may delete and re-create, so keying on the row would split one
    // workspace's SSO history across two auditable_ids and make it unqueryable by the index the viewer's
    // per-resource filter rides on. NOT the `domain` row's reason — that id is an integer; this one is a
    // uuid and would insert cleanly, which is exactly why nothing would catch the mistake.
    $connection = SsoConnection::factory()->create();
    $firstId = (string) $connection->id;

    $this->service->updatePolicy(['status' => SsoConnectionStatus::Active], $this->admin);
    $this->service->delete($this->admin);
    $this->service->importMetadata(idpMetadataXml(), $this->admin);

    $rows = ssoAudits();
    $secondId = (string) SsoConnection::query()->first()->id;

    expect($secondId)->not->toBe($firstId)
        ->and($rows)->toHaveCount(3)
        ->and($rows->pluck('auditable_id')->unique()->all())->toBe([(string) $this->tenant->id]);
});

it('records the fingerprint and never the certificate body', function (): void {
    $certificate = SsoConnectionFactory::certificate();
    $connection = SsoConnection::factory()->withCertificates([$certificate])->create();

    $this->service->updatePolicy(['default_role_name' => 'reviewer'], $this->admin);

    $row = ssoAudits()->first();
    $payload = json_encode([$row->old_values, $row->new_values]);

    expect($row->new_values['idp_certificates_fingerprint'])->toBe($connection->idp_certificates_fingerprint)
        ->and($row->new_values['idp_certificate_count'])->toBe(1)
        ->and($payload)->not->toContain($certificate)
        // 'MII' is the DER prelude every X.509 certificate's base64 starts with — a second, shape-based
        // check that survives a change to how the fixture certificate is generated.
        ->and($payload)->not->toContain('MII')
        ->and($row->new_values)->not->toHaveKey('idp_certificates');
});

it('records a policy change as an update carrying both sides', function (): void {
    SsoConnection::factory()->create();

    $this->service->updatePolicy(['status' => SsoConnectionStatus::Active], $this->admin);

    $row = ssoAudits()->first();

    expect($row->event)->toBe(AuditEvent::Updated)
        ->and($row->old_values['status'])->toBe('draft')
        ->and($row->new_values['status'])->toBe('active')
        ->and((string) $row->user_id)->toBe((string) $this->admin->id);
});

it('records a first import as a creation with the whole trust anchor on the new side', function (): void {
    $this->service->importMetadata(idpMetadataXml(), $this->admin);

    $row = ssoAudits()->first();

    expect($row->event)->toBe(AuditEvent::Created)
        ->and($row->old_values)->toBeNull()
        ->and($row->new_values['idp_entity_id'])->toBe('https://idp.example.com/saml2')
        // refresh() before the snapshot is what puts the DB-default columns here. Without it the next
        // policy edit would read as `(absent) → active`.
        ->and($row->new_values['status'])->toBe('draft')
        ->and($row->new_values['default_role_name'])->toBe('viewer');
});

it('records a deletion as the only surviving record that the connection existed', function (): void {
    $connection = SsoConnection::factory()->active()->create();
    $fingerprint = $connection->idp_certificates_fingerprint;

    $this->service->delete($this->admin);

    $row = ssoAudits()->first();

    expect($row->event)->toBe(AuditEvent::Deleted)
        ->and($row->new_values)->toBeNull()
        ->and($row->old_values['idp_entity_id'])->toBe($connection->idp_entity_id)
        ->and($row->old_values['idp_certificates_fingerprint'])->toBe($fingerprint);
});

it('redacts idp_certificates if a future writer ever snapshots the model', function (): void {
    // THE BACKSTOP'S OWN TEST. It stays green when someone "simplifies" the service's hand-built payload
    // into getOriginal() — which is precisely why the raw-body assertion above must exist alongside it.
    $redacted = app(AuditRedactor::class)->redact(
        SsoConnectionService::AUDIT_TYPE,
        ['idp_certificates' => ['MIIC-pretend-a-key']],
        null,
    );

    expect($redacted['old']['idp_certificates'])->toBe(AuditRedactor::PLACEHOLDER)
        ->and($redacted['redacted_fields'])->toContain('idp_certificates');
});

it('registers the alias so the audit-log filter can offer it', function (): void {
    // label() fails open and options() fails closed, so an unregistered alias renders un-prettified in the
    // ledger while silently missing from the dropdown — visible only to someone looking for it.
    expect(AuditableTypes::label(SsoConnectionService::AUDIT_TYPE))->toBe('Single sign-on')
        ->and(array_column(AuditableTypes::options(), 'value'))->toContain(SsoConnectionService::AUDIT_TYPE);
});
