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
use Illuminate\Database\QueryException;
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

it('refuses at the database to record a user impersonating themselves', function (): void {
    // "Never yourself" is a decision of record (rbac §9), and `audits_acting_as_not_self_check` is where it
    // is enforced rather than merely intended. The reachable route to violating it is not an I11b coding
    // error so much as an `impersonator_id` left in the session after exit — nothing clears it today — at
    // which point the operator's own later actions would file rows saying they impersonated themselves.
    // That is not noise in a compliance ledger; it reads as an accusation.
    ImpersonationContext::set((string) $this->owner->id);

    expect(fn () => recordImpersonationProbe($this->owner))
        ->toThrow(QueryException::class, 'audits_acting_as_not_self_check');
});

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

it('reads the operator from the SESSION, which is the only path production uses', function (): void {
    // ⚠️ EVERY OTHER CASE HERE DRIVES `ImpersonationContext::set()` — the override branch. Nothing in the
    // app writes `impersonator_id` yet (I11b does), so without this case the real production path would be
    // entirely unexercised and the commit's "written ambiently at the single write path" claim would be
    // proven only for the test seam. Break `fromSession()` alone — rename the key, invert the
    // `hasSession()` guard, drop the sanitiser's happy path — and every other case here stays green.
    //
    // This is also the ONE assertion that would notice I11b's writer drifting from this reader.
    session([ImpersonationContext::SESSION_KEY => (string) $this->operator->id]);

    $audit = recordImpersonationProbe($this->owner);

    expect($audit->fresh()->acting_as_user_id)->toBe((string) $this->operator->id);
});

it('ignores a session value that is not a uuid, rather than failing the caller write', function (): void {
    // The column is `uuid`, and `AuditLogger` writes INSIDE the caller's transaction. A junk value reaches
    // PostgreSQL as SQLSTATE 22P02, which nothing catches — so the audit INSERT throws, the enclosing
    // transaction rolls back, and the form save that triggered it is silently undone. Failing CLOSED
    // (attributing nothing) is the right trade against failing the user's actual write.
    session([ImpersonationContext::SESSION_KEY => 'not-a-uuid']);

    $audit = recordImpersonationProbe($this->owner);

    expect($audit->fresh()->acting_as_user_id)->toBeNull();
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
            ->where('data.0.acting_as', 'Platform operator')
            ->where('data.0.actor', $this->owner->name));
});

it('still refuses to name the operator when that operator IS a member of the tenant', function (): void {
    // ⚠️ THE CASE ABOVE PASSES FOR A REASON THAT DOES NOT GENERALISE, WHICH IS WHY THIS ONE EXISTS. The
    // first draft justified the fixed label by claiming the name "is not reachable anyway" — platform staff
    // hold no membership, so the join-shape `users` policy hides them. But
    // `TenantIsolation::usersVisibilitySql()` is `id = app.current_user_id OR EXISTS(active membership in
    // app.current_tenant_id)` and says NOTHING about `is_super_admin`; nothing in the schema or the app
    // stops an operator also being an active member of the tenant they impersonate into. Adversarial review
    // demonstrated the row IS visible in that case, and the presenter duly rendered the real name to every
    // Owner of that workspace.
    //
    // So the label is now a policy rather than an accident, and this is the case that holds it to that.
    // Delete the guard in `actingAsLabel()` and the case above stays GREEN while this one reddens.
    makeActiveMember($this->operator, 'owner');

    ImpersonationContext::set((string) $this->operator->id);
    recordImpersonationProbe($this->owner);
    ImpersonationContext::forget();

    $this->withoutVite();
    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audit/Index', false)
            ->where('data.0.acting_as', 'Platform operator'));
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
