<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| TenantContext::runFor() (M3) — the composition every caller that needs "act for THIS tenant, whatever
| the ambient request or worker left behind" used to hand-roll. Asserted at the DATABASE and not only in
| the PHP mirror: the two diverge by design (applyLocal() scopes only the GUC to the transaction), and a
| mirror-only assertion would pass on a version of this method that never touched Postgres at all.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $this->userId = (string) User::factory()->create()->getKey();
});

/** The GUC as Postgres actually holds it — `current_setting(name, true)` yields NULL when unset. */
function pgSetting(string $name): ?string
{
    return DB::selectOne('select current_setting(?, true) as value', [$name])?->value ?: null;
}

it('establishes the tenant for the closure when there is no ambient context, and leaves none behind', function (): void {
    TenantContext::applyLocal(null);

    $seen = TenantContext::runFor($this->acme->id, fn (): array => [
        TenantContext::currentTenantId(),
        pgSetting(TenantContext::TENANT_SETTING),
    ]);

    expect($seen)->toBe([$this->acme->id, $this->acme->id])
        ->and(TenantContext::currentTenantId())->toBeNull()
        ->and(pgSetting(TenantContext::TENANT_SETTING))->toBeNull();
});

it('restores a DIFFERENT ambient tenant afterwards, at the database as well as in the mirror', function (): void {
    TenantContext::applyLocal($this->globex->id, $this->userId);

    TenantContext::runFor($this->acme->id, function (): void {
        expect(pgSetting(TenantContext::TENANT_SETTING))->toBe($this->acme->id)
            // A user id is NOT carried across a tenant switch: it would assert a membership that may not
            // exist. Null is the fail-closed value — a user-keyed policy then matches zero rows.
            ->and(pgSetting(TenantContext::USER_SETTING))->toBeNull();
    });

    expect(TenantContext::currentTenantId())->toBe($this->globex->id)
        ->and(TenantContext::currentUserId())->toBe($this->userId)
        ->and(pgSetting(TenantContext::TENANT_SETTING))->toBe($this->globex->id)
        ->and(pgSetting(TenantContext::USER_SETTING))->toBe($this->userId);
});

it('carries the user id through when the tenant is unchanged', function (): void {
    TenantContext::applyLocal($this->acme->id, $this->userId);

    TenantContext::runFor($this->acme->id, function (): void {
        expect(pgSetting(TenantContext::USER_SETTING))->toBe($this->userId);
    });
});

it('restores the ambient context when the work throws, without masking the exception', function (): void {
    TenantContext::applyLocal($this->globex->id, $this->userId);

    expect(fn (): mixed => TenantContext::runFor(
        $this->acme->id,
        fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class, 'boom');

    expect(TenantContext::currentTenantId())->toBe($this->globex->id)
        ->and(pgSetting(TenantContext::TENANT_SETTING))->toBe($this->globex->id);
});

it('does not mask a QUERY exception, which is the case a finally-inside-the-transaction would break', function (): void {
    // The reason the restore sits after the transaction rather than in a `finally` inside it. A failed
    // statement leaves Postgres refusing every further command on that transaction, so a restore issued on
    // the way out would throw its own error and replace this one.
    TenantContext::applyLocal($this->globex->id, $this->userId);

    expect(fn (): mixed => TenantContext::runFor(
        $this->acme->id,
        fn () => DB::statement('select * from a_table_that_does_not_exist'),
    ))->toThrow(QueryException::class);

    expect(TenantContext::currentTenantId())->toBe($this->globex->id);
});

it('does not leak the tenant into an ENCLOSING transaction — the H12a sweep shape', function (): void {
    // The branch runFor exists for. DB::transaction() nested inside another opens a SAVEPOINT, and a
    // `SET LOCAL` issued inside one survives its release, so without the explicit re-apply the rest of the
    // enclosing transaction would run under the wrong tenant with nothing to signal it.
    DB::transaction(function (): void {
        TenantContext::applyLocal($this->globex->id, $this->userId);

        TenantContext::runFor($this->acme->id, fn (): null => null);

        expect(pgSetting(TenantContext::TENANT_SETTING))->toBe($this->globex->id)
            ->and(pgSetting(TenantContext::USER_SETTING))->toBe($this->userId)
            ->and(TenantContext::currentTenantId())->toBe($this->globex->id);
    });
});
