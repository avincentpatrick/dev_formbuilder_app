<?php

declare(strict_types=1);

use App\Services\Admin\SuperAdminService;
use App\Services\Audit\PlatformAuditPresenter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The PLATFORM ledger read, at the service and presenter (Increment I7b).
|
| PlatformAuditRlsTest proves the database half. This file proves the half that fails SILENTLY: the ORM's
| own `BelongsToTenant` scope, which is added independently of RLS and which no Postgres policy can undo.
| The first two cases are the mutation guards for it — remove `withoutGlobalScope()` from
| SuperAdminService::listPlatformAudits() and they are the only things in the suite that go red.
*/

beforeEach(function (): void {
    TenantContext::flush();
    clearPlatformRows();
    $this->beforeApplicationDestroyed(purgeCommittedPlatformAuditFixtures(...));
});

afterEach(function (): void {
    clearPlatformRows();
});

it('returns platform rows even when a tenant context happens to be open', function (): void {
    // ⭐ THE SHARP MUTATION GUARD. With the tenant scope left on, the query becomes
    // `tenant_id = <thisTenant> AND tenant_id IS NULL` — unsatisfiable — so this returns zero rows with no
    // error whatsoever. It fails for exactly one reason, which is what makes it useful.
    $operator = committedSuperAdmin('ctx@platformaudittest.local');
    committedAudit(null, $operator->id);

    $tenant = committedPlatformTenant('platform-audit-ctxopen');
    enterTenant($tenant->id, $operator->id);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);

    expect($result['rows'])->toHaveCount(1);
});

it('returns platform rows with no tenant context at all', function (): void {
    // The real console condition. Without the scope drop the predicate becomes the NO_TENANT_SENTINEL, so
    // the query asks for rows belonging to a tenant that cannot exist — again, zero rows and no error.
    $operator = committedSuperAdmin('noctx-read@platformaudittest.local');
    committedAudit(null, $operator->id);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['meta']['total'])->toBe(1);
});

it('never returns a tenant-scoped row', function (): void {
    $tenant = committedPlatformTenant('platform-audit-svcforeign');
    $operator = committedSuperAdmin('svcforeign@platformaudittest.local');
    $platformRow = committedAudit(null, $operator->id);
    committedAudit($tenant->id, $operator->id, 'form');

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['id'])->toBe($platformRow);
});

it('resolves the acting operator by name', function (): void {
    // Proves the `User::on(CONNECTION)` lookup runs INSIDE the still-open elevated transaction. Move it
    // outside and the GUC is gone, the read returns nothing, and every row silently reads "Unknown user".
    $operator = committedSuperAdmin('named@platformaudittest.local', 'Dana Operator');
    committedAudit(null, $operator->id);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);

    expect($result['rows'][0]['actor'])->toBe('Dana Operator')
        ->and($result['rows'][0]['is_system'])->toBeFalse();
});

it('labels a row with no actor as System', function (): void {
    committedAudit(null, null);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);

    expect($result['rows'][0]['actor'])->toBe('System')
        ->and($result['rows'][0]['is_system'])->toBeTrue();
});

it('offers only super-admins in the actor catalog', function (): void {
    // Pins the roster choice against a later "just reuse listAllUsers()", which returns every user in the
    // deployment — hundreds of options, none of which but a super-admin can ever match a platform row.
    $operator = committedSuperAdmin('roster@platformaudittest.local', 'Roster Operator');
    committedPlainUser('ordinary@platformaudittest.local', 'Ordinary Person');
    committedAudit(null, $operator->id);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 1);
    $labels = array_column($result['actors'], 'label');

    expect($labels)->toContain('Roster Operator')
        ->and($labels)->not->toContain('Ordinary Person');
});

it('clamps an out-of-range page instead of reporting an empty ledger', function (): void {
    // The defect this guards is loud on a compliance surface: an unclamped `paginate()` on `?page=99` over
    // a one-row ledger returns nothing, `empty_reason` computes `no_rows`, and the page states that nobody
    // has ever saved a platform change — over a ledger that is not empty.
    $operator = committedSuperAdmin('clamp@platformaudittest.local');
    committedAudit(null, $operator->id);

    $result = app(SuperAdminService::class)->listPlatformAudits([], 25, 99);

    expect($result['meta']['current_page'])->toBe(1)
        ->and($result['meta']['last_page'])->toBe(1)
        ->and($result['rows'])->toHaveCount(1);

    $props = app(PlatformAuditPresenter::class)->index([], 99);
    expect($props['empty_reason'])->toBeNull()
        ->and($props['data'])->toHaveCount(1);
});

it('filters by operator', function (): void {
    $one = committedSuperAdmin('filter-one@platformaudittest.local', 'Operator One');
    $two = committedSuperAdmin('filter-two@platformaudittest.local', 'Operator Two');
    committedAudit(null, $one->id);
    committedAudit(null, $two->id);

    $result = app(SuperAdminService::class)->listPlatformAudits(['user_id' => $one->id], 25, 1);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['actor'])->toBe('Operator One');
});

it('includes the whole of the `to` day in a date filter', function (): void {
    $operator = committedSuperAdmin('dates@platformaudittest.local');
    committedAudit(null, $operator->id);

    $today = CarbonImmutable::now();

    // A same-day filter must match. `to` resolved to midnight would exclude every row on the day it names —
    // the exact defect the API twin carries and the web surfaces deliberately do not.
    $result = app(SuperAdminService::class)->listPlatformAudits([
        'from' => $today->startOfDay(),
        'to' => $today->endOfDay(),
    ], 25, 1);

    expect($result['rows'])->toHaveCount(1);

    $past = app(SuperAdminService::class)->listPlatformAudits([
        'to' => $today->subDays(2)->endOfDay(),
    ], 25, 1);

    expect($past['rows'])->toBe([]);
});

it('distinguishes an empty ledger from a filter that matched nothing', function (): void {
    $presenter = app(PlatformAuditPresenter::class);

    expect($presenter->index([], 1)['empty_reason'])->toBe('no_rows');

    $operator = committedSuperAdmin('empty@platformaudittest.local');
    committedAudit(null, $operator->id);

    $props = $presenter->index(['user_id' => '00000000-0000-7000-8000-000000000000'], 1);

    expect($props['empty_reason'])->toBe('no_matches')
        ->and($props['data'])->toBe([]);
});

it('shapes a row the way the shared AuditRow contract expects', function (): void {
    $operator = committedSuperAdmin('shape@platformaudittest.local', 'Shape Operator');
    committedAudit(null, $operator->id, 'settings', 'updated', ['registration.open_signup' => false]);

    $row = app(PlatformAuditPresenter::class)->index([], 1)['data'][0];

    expect($row['event'])->toBe('updated')
        ->and($row['event_label'])->toBe('Updated')
        ->and($row['target']['type'])->toBe('settings')
        ->and($row['target']['type_label'])->toBe('Settings')
        // Always null, deliberately: there is no addressable page for a platform settings change, and
        // `auditable_id` is the acting operator's own user uuid.
        ->and($row['target']['label'])->toBeNull()
        ->and($row['target']['url'])->toBeNull()
        ->and($row['actor'])->toBe('Shape Operator')
        // I11a — present and null on an ordinary row. Asserting the KEY EXISTS matters as much as its
        // value: the shared `AuditRow` contract is what `AuditChangeModal` and both pages are typed
        // against, so a platform row missing the field is a structural divergence, not a cosmetic one.
        ->and($row)->toHaveKey('acting_as')
        ->and($row['acting_as'])->toBeNull()
        ->and($row['changes'])->not->toBeEmpty()
        ->and(array_column($row['changes'], 'key'))->toContain('registration.open_signup');
});

it('names the operator behind an impersonated row, where the tenant viewer cannot', function (): void {
    $operator = committedSuperAdmin('driver@platformaudittest.local', 'Dana Operator');
    $member = committedPlainUser('member@platformaudittest.local', 'Mira Member');
    committedAudit(null, $member->id, 'settings', 'updated', ['registration.open_signup' => false], null, $operator->id);

    $row = app(PlatformAuditPresenter::class)->index([], 1)['data'][0];

    // The asymmetry this increment deliberately builds: this read runs on the elevated connection, so the
    // operator's row is visible even though they belong to no tenant. `AuditLogPresenter` can only manage
    // "Platform operator" for exactly the same fact — see ImpersonationAttributionTest.
    //
    // `actor` is the MEMBER, not the operator. If a future change ever swaps them, the ledger stops
    // recording whose authority the action ran under, which is the one thing rbac §9:433 asks it to keep.
    expect($row['actor'])->toBe('Mira Member')
        ->and($row['acting_as'])->toBe('Dana Operator');
});
