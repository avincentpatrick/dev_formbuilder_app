<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The I5 storage layer for tenant maintenance mode — two `tenants` columns and the accessors over them.
 *
 * **This file exists because the migration's own CI green is VACUOUS**, exactly as `BrandingStorageTest`
 * records for the H23a2 columns: `scripts/migration-lint.php` keys on `Schema::create` and skips alter-only
 * migrations entirely, so nothing in the build would notice if these were wrong.
 *
 * And the failure it guards is quieter than a broken column. `tenants` is a stancl/tenancy model with a
 * `data` json virtual-column store, and a real column NOT listed in {@see Tenant::getCustomColumns()} is
 * silently relocated into `data` and read back as **null** — no error, no warning, a green write path, and
 * a tenant whose forms keep serving after they pressed the switch that was supposed to stop them. The
 * fail-OPEN direction is what makes it worth pinning twice (the list, and a real round trip).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = inboxTenant();
});

it('lists both maintenance columns as stancl custom columns', function (string $column): void {
    // The cheap half of the guard: assert the list. It names the fix when the round-trip test below fails.
    expect(Tenant::getCustomColumns())->toContain($column);
})->with(['maintenance_mode', 'maintenance_message']);

it('round-trips maintenance state through REAL columns, not the data json store', function (): void {
    $this->tenant->forceFill([
        'maintenance_mode' => true,
        'maintenance_message' => 'Back on Monday.',
    ])->save();

    // Read straight off the query builder, bypassing the model entirely: if the values had spilled into
    // `data`, these columns would still be false/null here while the model appeared to hold them.
    $row = DB::table('tenants')->where('id', $this->tenant->id)->first();

    expect($row->maintenance_mode)->toBeTrue();
    expect($row->maintenance_message)->toBe('Back on Monday.');

    $fresh = Tenant::findOrFail($this->tenant->id);
    expect($fresh->isUnderMaintenance())->toBeTrue();
    expect($fresh->maintenanceNotice())->toBe('Back on Monday.');
});

it('defaults to serving, and falls back to the product notice with no stored message', function (): void {
    $fresh = Tenant::findOrFail($this->tenant->id);

    expect($fresh->isUnderMaintenance())->toBeFalse();
    expect($fresh->maintenanceNotice())->toContain('temporarily unavailable');
});

it('reads an unset flag as "serving" — the fail-OPEN direction', function (): void {
    // The two documented ways the attribute reads null (a partial ->select() upstream; a just-created,
    // unrefreshed model whose DB-default has not been back-filled). Both must mean "keep serving": an
    // unreadable flag taking a tenant's forms offline would be the worse failure by far.
    $partial = Tenant::query()->select('id')->findOrFail($this->tenant->id);

    expect($partial->isUnderMaintenance())->toBeFalse();
});
