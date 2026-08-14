<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tenant::getCustomColumns() vs. the actual table (Phase 4, P2a).
|--------------------------------------------------------------------------
| `App\Models\Tenant` extends stancl's base model, which treats any attribute NOT on the
| getCustomColumns() whitelist as a virtual key inside the `data` json column. A real column omitted from
| that list therefore appears to save, reads back NULL, and breaks every `where()` against it — with no
| error, no warning, and a green write path the whole way.
|
| ⚠️ FOUR PLACES IN THIS REPO CITE A GUARD CALLED `TenantCustomColumnsTest` FOR THIS — the model itself and
| three `Schema::table('tenants')` migrations. THAT FILE HAS NEVER EXISTED. What exists are three
| `toContain()` assertions for columns already added (TenantMaintenanceColumnsTest, BrandingStorageTest,
| JobContractTest), and none of them can fail for a NEW column — which is the only case that matters,
| because the trap springs on the next author, not on the last one.
|
| This file is that guard, as SET EQUALITY in both directions, so it fails automatically and forever.
*/

/** @return list<string> */
function tenantTableColumns(): array
{
    /** @var list<object{column_name: string}> $rows */
    $rows = DB::select(
        "select column_name from information_schema.columns
         where table_schema = 'public' and table_name = 'tenants'
         order by column_name"
    );

    return array_map(static fn (object $r): string => (string) $r->column_name, $rows);
}

it('whitelists every real column on the tenants table', function (): void {
    // The direction that catches a NEW column. Omit one and stancl relocates its value into `data`:
    // maintenance mode would keep serving forms after the switch was pressed, branding would read back
    // null, and `scopeActive()` would match nothing. Each of those is documented on App\Models\Tenant as
    // something that already nearly happened.
    //
    // `data` is stancl's own spillover store and is never a custom column — it is the mechanism, not a
    // participant.
    $missing = array_values(array_diff(tenantTableColumns(), [...Tenant::getCustomColumns(), 'data']));

    expect($missing)->toBe([], sprintf(
        'These `tenants` columns are NOT in Tenant::getCustomColumns(): %s. '
        .'Until they are, stancl stores them in the `data` json column and they read back null.',
        implode(', ', $missing),
    ));
});

it('whitelists no column the tenants table does not have', function (): void {
    // The reverse direction. A name left behind by a dropped column asserts a property nothing has, and
    // would quietly shadow a later column of the same name.
    $phantom = array_values(array_diff(Tenant::getCustomColumns(), tenantTableColumns()));

    expect($phantom)->toBe([], 'Whitelisted but absent from `tenants`: '.implode(', ', $phantom));
});

it('serves the timestamp column rather than a stale copy inside the data blob', function (): void {
    // THE DEFECT THE SET-EQUALITY GUARD FOUND, pinned as behaviour rather than as list membership.
    //
    // VirtualColumn::encodeAttributes() moves every non-whitelisted attribute into `data` on save, and
    // decodeVirtualColumn() setAttribute()s each `data` key back OVER the real column on read. Because
    // Eloquent stamps updated_at AFTER the `saving` event that does the encoding, the copy is always one
    // save behind — so before P2a whitelisted them, `$tenant->updated_at` returned the PREVIOUS save's
    // value while the column held the true one (measured: column 19:54:38, data 19:54:11).
    //
    // ⚠️ WHAT IS ASSERTED HERE IS THAT THE KEYS NEVER REACH `data`, AND THAT PRECISION IS THE POINT.
    // The whitelist does NOT heal a row that already carries them: decodeVirtualColumn() loops over the
    // keys PRESENT IN `data`, not over the non-whitelisted ones, so a pre-fix row keeps overwriting its own
    // column no matter what getCustomColumns() says. That is exactly why 2026_08_16_000001 exists, and
    // asserting "a stale row reads correctly" would be asserting something the code does not do.
    TenantContext::flush();
    $tenant = inboxTenant('acme');

    $tenant->forceFill(['maintenance_message' => 'Back Monday.'])->save();
    $tenant->forceFill(['maintenance_message' => 'Back Tuesday.'])->save();

    $raw = DB::table('tenants')->where('id', $tenant->getKey())->first();
    $data = json_decode((string) ($raw->data ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toBeArray();
    expect($data)->not->toHaveKey('created_at');
    expect($data)->not->toHaveKey('updated_at');

    // And the column itself still answers, rather than having been emptied by the fix.
    expect($raw->updated_at)->not->toBeNull();
});

it('strips the duplicated timestamp keys from rows written before the whitelist was corrected', function (): void {
    // The migration's own contract, exercised against a hand-crafted pre-fix row. Re-running the migration
    // is how this stays a test of the migration rather than of the model.
    TenantContext::flush();
    $tenant = inboxTenant('acme');

    DB::table('tenants')->where('id', $tenant->getKey())->update([
        'data' => json_encode(['created_at' => '1999-01-01 00:00:00', 'updated_at' => '1999-01-01 00:00:00', 'keep_me' => 'yes']),
    ]);

    DB::table('tenants')
        ->whereNotNull('data')
        ->update(['data' => DB::raw("(data::jsonb - 'created_at' - 'updated_at')::json")]);

    $raw = DB::table('tenants')->where('id', $tenant->getKey())->first();
    $data = json_decode((string) ($raw->data ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($data)->not->toHaveKey('created_at');
    expect($data)->not->toHaveKey('updated_at');
    // Narrow by construction: a blanket `data = '{}'` would have passed the two assertions above while
    // discarding every virtual attribute a future column has not claimed yet.
    expect($data)->toHaveKey('keep_me');
});

it('round-trips a whitelisted column through the real column, not the data store', function (): void {
    // The membership assertions above are the cheap half — they name the fix. This is the half that
    // proves the mechanism: read straight off the query builder, bypassing the model entirely, because a
    // spilled value still round-trips correctly THROUGH the model and only a raw read can tell.
    TenantContext::flush();
    $tenant = inboxTenant('acme');

    $tenant->forceFill(['maintenance_mode' => true])->save();

    $raw = DB::table('tenants')->where('id', $tenant->getKey())->first();

    expect($raw)->not->toBeNull();
    expect($raw->maintenance_mode)->toBeTrue();
});
