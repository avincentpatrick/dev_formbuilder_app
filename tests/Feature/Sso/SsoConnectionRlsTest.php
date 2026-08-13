<?php

declare(strict_types=1);

use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Row-level security on the two SSO tables (P1a, ADR-0002 / ADR-0016).
|
| Raw `DB::` throughout, deliberately: these cases must prove the DATABASE rather than the Eloquent global
| scope. `BelongsToTenant` adds `where tenant_id = …` to every model query, so an ORM-only test would pass
| identically with RLS switched off — and RLS is the entire isolation here, because the protocol endpoints
| run WITHOUT `auth` and reach these rows with nothing but a hostname to go on.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();

    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);

    enterTenant($this->acme->id);
    $this->acmeConnection = SsoConnection::factory()->active()->create();

    enterTenant($this->globex->id);
    $this->globexConnection = SsoConnection::factory()->create();
});

it('has row-level security enabled AND forced on both SSO tables', function (string $table): void {
    // FORCE is the half that matters: without it the table's OWNER — which is the application role —
    // bypasses every policy below, and the whole suite would pass while isolating nothing.
    $flags = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
        [$table],
    );

    expect($flags->relrowsecurity)->toBeTrue()
        ->and($flags->relforcerowsecurity)->toBeTrue();
})->with(['sso_connections', 'sso_auth_requests']);

it('shows one tenant’s connection to that tenant only', function (): void {
    enterTenant($this->acme->id);
    $acmeRows = DB::select('SELECT id FROM sso_connections');

    enterTenant($this->globex->id);
    $globexRows = DB::select('SELECT id FROM sso_connections');

    expect($acmeRows)->toHaveCount(1)
        ->and($acmeRows[0]->id)->toBe((string) $this->acmeConnection->id)
        ->and($globexRows)->toHaveCount(1)
        ->and($globexRows[0]->id)->toBe((string) $this->globexConnection->id);
});

it('refuses a cross-tenant update, silently and completely', function (): void {
    // Zero rows affected, no error — which is what makes the ORM's global scope insufficient on its own and
    // this test necessary. A bug that dropped the scope would still write nothing.
    enterTenant($this->acme->id);

    $affected = DB::update(
        'UPDATE sso_connections SET idp_entity_id = ? WHERE id = ?',
        ['https://attacker.example.com/saml2', (string) $this->globexConnection->id],
    );

    expect($affected)->toBe(0);

    enterTenant($this->globex->id);
    expect(DB::selectOne('SELECT idp_entity_id FROM sso_connections')->idp_entity_id)
        ->toBe($this->globexConnection->idp_entity_id);
});

it('refuses a cross-tenant delete', function (): void {
    enterTenant($this->acme->id);

    expect(DB::delete('DELETE FROM sso_connections WHERE id = ?', [(string) $this->globexConnection->id]))->toBe(0);

    enterTenant($this->globex->id);
    expect(DB::selectOne('SELECT count(*) AS n FROM sso_connections')->n)->toBe(1);
});

it('refuses an insert that names another tenant', function (): void {
    // The INSERT policy's WITH CHECK compares to the ambient GUC, not to whatever the row claims.
    enterTenant($this->acme->id);

    expect(fn () => DB::insert(
        'INSERT INTO sso_connections (id, tenant_id, idp_entity_id, idp_sso_url, idp_certificates, idp_certificates_fingerprint, name_id_format, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, now(), now())',
        [
            (string) $this->globex->id,
            'https://attacker.example.com/saml2',
            'https://attacker.example.com/sso',
            'ciphertext',
            str_repeat('a', 64),
            'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        ],
    ))->toThrow(QueryException::class);
});

it('sees nothing at all with no tenant context, rather than everything', function (): void {
    // Fail-closed: the GUC is absent, `NULLIF(...)::uuid` is NULL, and `tenant_id = NULL` matches no row.
    TenantContext::flush();
    DB::statement("SELECT set_config('app.current_tenant_id', '', true)");

    expect(DB::select('SELECT id FROM sso_connections'))->toBeEmpty();
});

it('confines the auth-request cascade to the deleted connection’s own tenant', function (): void {
    // The composite FK (tenant_id, sso_connection_id) exists for this: PostgreSQL runs referential actions
    // BYPASSING RLS, so a single-column FK would let one tenant's delete reach across if ids ever collided.
    enterTenant($this->acme->id);
    DB::insert(
        'INSERT INTO sso_auth_requests (id, tenant_id, sso_connection_id, request_id, intent, force_authn, issued_at, expires_at, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, ?, ?, ?, false, now(), now() + interval \'10 minutes\', now(), now())',
        [(string) $this->acme->id, (string) $this->acmeConnection->id, '_'.bin2hex(random_bytes(16)), 'login'],
    );

    enterTenant($this->globex->id);
    DB::insert(
        'INSERT INTO sso_auth_requests (id, tenant_id, sso_connection_id, request_id, intent, force_authn, issued_at, expires_at, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, ?, ?, ?, false, now(), now() + interval \'10 minutes\', now(), now())',
        [(string) $this->globex->id, (string) $this->globexConnection->id, '_'.bin2hex(random_bytes(16)), 'login'],
    );

    enterTenant($this->acme->id);
    DB::delete('DELETE FROM sso_connections WHERE id = ?', [(string) $this->acmeConnection->id]);
    expect(DB::selectOne('SELECT count(*) AS n FROM sso_auth_requests')->n)->toBe(0);

    enterTenant($this->globex->id);
    expect(DB::selectOne('SELECT count(*) AS n FROM sso_auth_requests')->n)->toBe(1);
});
