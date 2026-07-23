<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The `audits` append-only RLS shape (H4). The ledger is immutable and never deleted (data-dictionary §13);
| this proves the DATABASE enforces that, not the application — SELECT + INSERT work, UPDATE + DELETE are
| denied even to the owning tenant. Every assertion uses raw DB:: so it proves the guarantee an ORM caller
| could otherwise bypass.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

it('has RLS enabled AND forced on audits', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced from pg_class where relname = ?',
        ['audits']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('permits INSERT and SELECT but DENIES UPDATE and DELETE, even to the owning tenant', function (): void {
    $audit = app(AuditLogger::class)->record(
        AuditEvent::Created,
        'form',
        Uuid::uuid7()->toString(),
        new: ['status' => 'draft'],
        actorId: $this->user->id,
    );
    $id = $audit->id;

    // INSERT + SELECT are permitted (the row exists and is visible to its tenant).
    expect(Audit::query()->whereKey($id)->exists())->toBeTrue();

    // UPDATE is DENIED: with no UPDATE policy under FORCE RLS the row is not modifiable — 0 rows affected,
    // and the stored value is byte-unchanged. (Add a `tenant_update` policy and this becomes 1 → the test
    // fails, which is exactly the append-only regression it guards.)
    $updated = DB::update('UPDATE audits SET event = ? WHERE id = ?', ['deleted', $id]);
    expect($updated)->toBe(0);
    expect(DB::table('audits')->where('id', $id)->value('event'))->toBe('created');

    // DELETE is DENIED the same way — 0 rows, the row remains.
    $deleted = DB::delete('DELETE FROM audits WHERE id = ?', [$id]);
    expect($deleted)->toBe(0);
    expect(DB::table('audits')->where('id', $id)->exists())->toBeTrue();
});

it('never shows one tenant the audit rows of another', function (): void {
    $mine = app(AuditLogger::class)->record(AuditEvent::Created, 'form', Uuid::uuid7()->toString(), actorId: $this->user->id);

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id, $this->user->id);

    // Tenant B sees none of tenant A's rows (strict SELECT scoping).
    expect(Audit::query()->whereKey($mine->id)->exists())->toBeFalse();
    expect(Audit::query()->count())->toBe(0);
});
