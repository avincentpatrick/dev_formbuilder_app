<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Pins the OUTCOME of the G10a migration chain in-suite (Increment G10a).
|
| The backfill itself cannot be tested here — the privileged connection commits outside
| RefreshDatabase's transaction, so recreating `form_collaborators` mid-run is uncommitted DDL that
| session cannot see, and a failure would strand the table for every sibling test. The copy logic lives
| in CollaboratorBackfill (unit-tested with no database) and an end-to-end CI job migrates a seeded
| database across the boundary. What THIS file guarantees is that the chain ran to completion at all.
*/

it('has retired form_collaborators', function (): void {
    expect(Schema::hasTable('form_collaborators'))->toBeFalse();
});

it('has both new scoping tables', function (): void {
    expect(Schema::hasTable('scope_nodes'))->toBeTrue()
        ->and(Schema::hasTable('resource_grants'))->toBeTrue();
});

it('carries every column the resolver reads', function (): void {
    expect(Schema::hasColumns('resource_grants', [
        'id', 'tenant_id', 'scopeable_type', 'scopeable_id',
        'user_id', 'capacity', 'includes_descendants', 'granted_by',
    ]))->toBeTrue();

    expect(Schema::hasColumns('scope_nodes', [
        'id', 'tenant_id', 'parent_id', 'name', 'code', 'node_type',
        'path', 'depth', 'position', 'is_active',
    ]))->toBeTrue();
});

it('points forms at a scope node', function (): void {
    expect(Schema::hasColumn('forms', 'scope_node_id'))->toBeTrue();
});

it('ships NO deleted_at on either scoping table', function (): void {
    // Deliberate (G10a D4): a soft-delete column the authorization resolver must remember to filter is a
    // landmine. Revocation is a hard delete; deactivation is `is_active`, which the resolver DOES filter.
    // If a future migration adds one of these, this test is the tripwire.
    expect(Schema::hasColumn('resource_grants', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('scope_nodes', 'deleted_at'))->toBeFalse();
});
