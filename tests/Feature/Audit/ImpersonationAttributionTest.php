<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Http\Resources\Api\V1\AuditResource;
use App\Models\Audit;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `audits.acting_as_user_id` — Increment I11a.
|--------------------------------------------------------------------------
| The single requirement `docs/multi-tenancy-rbac-design.md` §9:433 states about impersonation: keep "a
| super-admin's own actions distinguishable from actions taken while impersonating". The column ships
| BEFORE the feature (I11b) so the ledger can never be lied to without being able to record the lie.
|
| The interesting cases here are the NEGATIVE ones. A column that is null on ~100% of rows is trivially
| easy to test into a state where it is null for the wrong reason — because nothing ever set it, because
| the write path silently dropped it, because the presenter never read it — and every one of those looks
| identical to "no impersonation happened". So each case below pins a value that had to travel.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    $this->operator = User::factory()->create(['is_super_admin' => true]);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** One audit row through the real write path, with whatever ambient context the case has established. */
function recordImpersonationProbe(User $actor): Audit
{
    return app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'form',
        (string) Str::uuid(),
        ['title' => 'before'],
        ['title' => 'after'],
        (string) $actor->id,
    );
}

it('leaves acting_as_user_id null when nobody is impersonating', function (): void {
    $audit = recordImpersonationProbe($this->owner);

    expect($audit->fresh()->acting_as_user_id)->toBeNull();
});

it('records the operator on every row written while impersonating', function (): void {
    ImpersonationContext::set((string) $this->operator->id);

    $audit = recordImpersonationProbe($this->owner);

    // BOTH columns, and the pair is the point. `user_id` stays the EFFECTIVE actor — whose authority the
    // action ran under — while `acting_as_user_id` names the human driving. Asserting only the new column
    // would pass just as happily if the write had clobbered `user_id` with the operator, which is the one
    // outcome that would silently re-point `audits_tenant_user_idx`, the actor filter and the CSV's Actor
    // column at platform staff.
    expect($audit->fresh()->acting_as_user_id)->toBe((string) $this->operator->id)
        ->and($audit->fresh()->user_id)->toBe((string) $this->owner->id);
});

it('stops recording the operator once the context is forgotten', function (): void {
    ImpersonationContext::set((string) $this->operator->id);
    recordImpersonationProbe($this->owner);

    ImpersonationContext::forget();
    $after = recordImpersonationProbe($this->owner);

    // The static outliving its session is the failure mode this guards: on a long-lived queue worker it
    // would attribute an unrelated later action to an operator who had gone home. ImpersonationContext's
    // docblock requires the `finally`; this is what makes the requirement observable.
    expect($after->fresh()->acting_as_user_id)->toBeNull();
});

it('shows the tenant that an operator acted, without naming them', function (): void {
    ImpersonationContext::set((string) $this->operator->id);
    recordImpersonationProbe($this->owner);
    ImpersonationContext::forget();

    $this->withoutVite();
    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audit/Index', false)
            // "Platform operator", NOT the operator's name — and this is not a redaction the presenter
            // chooses, it is the only reachable answer. The operator holds no membership of this tenant, so
            // the join-shape `users` RLS hides their row and the eager load yields null. See
            // AuditLogPresenter::actingAsLabel().
            ->where('data.0.acting_as', 'Platform operator')
            ->where('data.0.actor', $this->owner->name));
});

it('leaves acting_as null on the tenant page for an ordinary row', function (): void {
    recordImpersonationProbe($this->owner);

    $this->withoutVite();
    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit/Index', false)
            ->where('data.0.acting_as', null));
});

it('carries the operator into the CSV export, in the column beside the actor', function (): void {
    ImpersonationContext::set((string) $this->operator->id);
    recordImpersonationProbe($this->owner);
    ImpersonationContext::forget();

    $response = $this->actingAs($this->owner)->get('http://acme.meridian.test/audit-log/export')->assertOk();

    // Parsed inline rather than through `AuditExportTest`'s `auditExportRows()`. Pest loads every file into
    // one process, so that helper IS reachable on a full-suite run — and would then vanish the moment
    // anyone ran this file on its own, which is the failure mode worth avoiding in a diagnostic-adjacent
    // suite. Two lines is cheaper than that coupling.
    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
        array_values(array_filter(preg_split('/\R/', $response->streamedContent()) ?: [], 'strlen')),
    );

    // Index 5, immediately after Actor at 4. Asserting the POSITION rather than just the presence of the
    // string is deliberate: a CSV consumed by a compliance reader is a positional contract, and a column
    // that arrives in the wrong place is a different defect from one that is missing.
    expect($rows[0][5])->toBe('Acting as')
        ->and($rows[1][4])->toBe($this->owner->name)
        ->and($rows[1][5])->toBe('Platform operator');
});

it('exposes the operator id on the API resource', function (): void {
    ImpersonationContext::set((string) $this->operator->id);
    $audit = recordImpersonationProbe($this->owner);
    ImpersonationContext::forget();

    // The ID, not a name — `AuditResource` hands out identifiers so an integration can join on them, and
    // this is the one field a name would make unjoinable. Read straight off the row rather than over HTTP:
    // the /api/v1 surface's own auth is covered by AuditApiTest, and what is under test here is the shape.
    $payload = (new AuditResource($audit->fresh()))->toArray(request());

    expect($payload)->toHaveKey('acting_as_user_id')
        ->and($payload['acting_as_user_id'])->toBe((string) $this->operator->id)
        // `user_id` unchanged beside it — the same pairing the DB-level case asserts, re-pinned at the API
        // boundary because that is a separately versioned contract (`openapi.json` is diffed byte-for-byte).
        ->and($payload['user_id'])->toBe((string) $this->owner->id);
});
