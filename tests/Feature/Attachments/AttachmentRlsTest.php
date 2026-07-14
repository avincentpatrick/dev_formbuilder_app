<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G6 — the DB-level RLS proof for the new `attachments` table (the
| shared polymorphic file store). Uses raw DB:: queries (bypassing the ORM
| tenant scope) so it proves PostgreSQL FORCE RLS itself, not the convenience
| scope. Mirrors SubmissionRlsTest for the submissions family.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

it('runs as a non-superuser role with RLS enabled AND forced on attachments', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $role = DB::selectOne('select rolsuper::int as is_super, rolbypassrls::int as bypass_rls from pg_roles where rolname = current_user');
    expect((int) $role->is_super)->toBe(0)->and((int) $role->bypass_rls)->toBe(0);

    $meta = DB::selectOne('select relrowsecurity::int as enabled, relforcerowsecurity::int as forced from pg_class where relname = ?', ['attachments']);
    expect((int) $meta->enabled)->toBe(1)->and((int) $meta->forced)->toBe(1);
});

it('carries exactly one policy per command on attachments', function (): void {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $cmd) {
        $count = DB::table('pg_policies')->where('tablename', 'attachments')->where('cmd', $cmd)->count();
        expect($count)->toBe(1, "attachments must have exactly one {$cmd} policy, found {$count}");
    }
});

it('fails closed on attachments with no tenant context', function (): void {
    Attachment::factory()->create();

    TenantContext::applyLocal(null);
    expect(DB::table('attachments')->count())->toBe(0);
});

it('isolates attachments across tenants and rejects a cross-tenant write', function (): void {
    Attachment::factory()->create();
    expect((int) DB::selectOne('select count(*) as c from attachments')->c)->toBe(1);

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id, $this->user->id);

    // The other tenant's session sees none of Alpha's files.
    expect((int) DB::selectOne('select count(*) as c from attachments')->c)->toBe(0);

    // … and cannot smuggle a row into Alpha (the WITH CHECK aborts the transaction, so this is the final op).
    expect(fn () => DB::table('attachments')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $this->tenant->id, // Alpha, while running as Bravo → WITH CHECK fails
        'attachable_type' => 'form_field',
        'attachable_id' => Uuid::uuid7()->toString(),
        'kind' => 'submission_file',
        'disk' => 'local',
        'path' => 'tenants/x/submission_file/202607/x.bin',
        'virus_scan_status' => 'pending',
        'is_encrypted_at_rest' => false,
        'is_pii' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
