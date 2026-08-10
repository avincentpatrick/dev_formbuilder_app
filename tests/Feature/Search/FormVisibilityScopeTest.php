<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `Form::scopeVisibleTo()` == `FormPolicy::view()`, in BOTH directions (Increment J1b).
|
| The scope is the LIST twin of the policy, and `Form.php`'s docblock has named this file as its pin since
| the scope was extracted. A list that shows a row the detail view refuses is a bug in one direction; a list
| that hides a row the user may open is a bug in the other. Only a set-equality assertion catches both.
|
| ⚠️ BOTH SIDES ARE MEASURED WITH `withTrashed()`, AND THAT IS THE FAITHFUL COMPARISON, NOT A LOOPHOLE. The
| scope's own docblock says archive/soft-delete filtering is the CALLER's job ("may I see this row" and
| "does this list show archived rows" are different questions), and `FormPolicy::view()` likewise says
| nothing about either. Comparing the scope-with-SoftDeletes against the policy-without would measure the
| global scope, not the rule the two are supposed to share.
|
| ── WHAT THIS FILE FOUND ─────────────────────────────────────────────────────────────────────────────────
| The two rules DID disagree when this file was written, and the last case is the one that showed it:
| `FormPolicy::view()` requires `forms.edit.any || (forms.edit.own && editorGrant)`, while the scope checked
| only `forms.edit.any` before falling straight to the Editor-grant subquery — with no `forms.edit.own`
| conjunct at all. So a user holding an Editor grant but NOT `forms.edit.own` was inside the scope's set and
| outside the policy's, and `/forms` would list rows whose builder refused to open.
|
| Under the five seeded roles that is unreachable — `owner`/`admin` hold `forms.edit.any` and `form_editor`
| holds `forms.edit.own` — which is why every existing test passed over it. But `FormPolicy::viewAny()`
| admits `forms.create` ALONE, so a custom role reaches it, and "unreachable under today's seed data" is a
| property of the seeder rather than of the rule. The scope now mirrors the policy and fails closed; the
| synthetic-role case below is what holds it there. (RBAC §8 records its own unreachable row the same way —
| the precedent is to pin it, not to argue it away.)
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $forms = app(FormService::class);

    // A deliberately varied corpus: a form the subject created (so they hold the grant), one they did not,
    // an archived one, and a soft-deleted one. Each is a row on which the scope and the policy could
    // plausibly disagree, which is the only kind of row worth putting in this fixture.
    $this->ownGrant = $forms->create($this->tenant, $this->owner, 'Clinic Intake');
    $this->foreign = $forms->create($this->tenant, $this->owner, 'Payroll Register');

    $this->archived = $forms->create($this->tenant, $this->owner, 'Clinic Archive');
    $this->archived->forceFill(['status' => FormStatus::Archived->value])->save();

    $this->trashed = $forms->create($this->tenant, $this->owner, 'Clinic Deleted');
    $this->trashed->delete();
});

/**
 * The scope's answer: the ids `Form::scopeVisibleTo()` admits, with soft-deleted rows included so the
 * comparison is against the rule rather than against the SoftDeletes global scope.
 *
 * @return list<string>
 */
function scopeAdmittedFormIds(User $user): array
{
    $ids = Form::query()->withTrashed()->visibleTo($user)->pluck('forms.id')->all();
    sort($ids);

    return array_map(strval(...), $ids);
}

/**
 * The policy's answer over the same corpus, asked one row at a time through the real Gate.
 *
 * @return list<string>
 */
function policyAdmittedFormIds(User $user): array
{
    $ids = Form::query()->withTrashed()->get()
        ->filter(fn (Form $form): bool => Gate::forUser($user)->allows('view', $form))
        ->pluck('id')
        ->all();
    sort($ids);

    return array_map(strval(...), $ids);
}

/**
 * A member holding exactly the capability combination the seeded five-role matrix never produces: passes
 * `FormPolicy::viewAny()` on `forms.create`, holds NEITHER `forms.edit.any` NOR `forms.edit.own`.
 *
 * ⚠️ A DIRECT PERMISSION, NOT A SYNTHETIC ROLE, AND THE REASON IS A BUG THIS FILE ALREADY CAUSED. The first
 * draft minted a global role on `pgsql_privileged` — which is OUTSIDE `RefreshDatabase`'s transaction, so
 * the row COMMITTED and leaked into every subsequent test in the suite. `RbacRlsTest` counts the global
 * catalog and went red with "6 is not 5", a hundred files later and with nothing pointing back here. Every
 * single-file run passed; only the full sweep could see it.
 *
 * Cleaning up afterwards would not have fixed it either: `afterEach` runs BEFORE RefreshDatabase rolls
 * back, so deleting the parent role on the privileged connection while the test's own uncommitted
 * `model_has_roles` rows still reference it would block on the FK lock — a hang rather than a failure.
 *
 * `viewer` is the carrier role because it holds no `forms.*` key at all, so the direct grant below is the
 * only forms capability in play. Both writes go through the default connection and roll back cleanly.
 */
function makeFormsCreatorOnly(User $user): void
{
    makeActiveMember($user, 'viewer');
    $user->givePermissionTo('forms.create');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('admits exactly what the policy admits, for every role the product actually ships', function (string $role): void {
    $user = User::factory()->create();
    makeActiveMember($user, $role);

    // A grant on one form, so the per-row branch of both rules is genuinely exercised rather than
    // short-circuited by an org-wide permission.
    makeCollaborator($this->ownGrant, $user, ResourceCapacity::Editor);

    expect(scopeAdmittedFormIds($user))->toBe(
        policyAdmittedFormIds($user),
        "the list scope and FormPolicy::view() disagree for the [{$role}] role"
    );
})->with(['owner', 'admin', 'form_editor', 'reviewer', 'viewer']);

it('admits nothing for a role that can create forms but may edit none of them', function (): void {
    // ⚠️ THE CASE THAT FOUND THE DEFECT, and the reason it is written against a synthetic role rather than a
    // seeded one: `FormPolicy::viewAny()` passes on `forms.create` alone, so this capability combination is
    // reachable the moment anyone adds a custom role — but no seeded role produces it, so nothing else in
    // the suite can observe the divergence.
    $user = User::factory()->create();
    makeFormsCreatorOnly($user);
    makeCollaborator($this->ownGrant, $user, ResourceCapacity::Editor);

    // Anti-vacuity, part one: the capability combination really is the one under test. If a future seeder
    // change gave `viewer` a forms key, this case would silently start measuring something else.
    expect($user->can('forms.create'))->toBeTrue()
        ->and($user->can('forms.edit.any'))->toBeFalse()
        ->and($user->can('forms.edit.own'))->toBeFalse();

    // Anti-vacuity, part two: the grant really is held, so an empty result below means the RULE refused it
    // and not that the fixture forgot to create it.
    expect(app(ResourceGrantResolver::class)
        ->holds($user, $this->ownGrant, ResourceCapacity::Editor))->toBeTrue();

    expect(policyAdmittedFormIds($user))->toBe([])
        ->and(scopeAdmittedFormIds($user))->toBe([]);
});

it('keeps a soft-deleted form on whichever side of the rule the caller put it', function (): void {
    // The scope is asked with `withTrashed()` here and without it in production, and that difference must
    // stay the CALLER's — this case is what makes the docblock's claim ("archive/soft-delete filtering is
    // the caller's") an executable statement rather than a comment.
    expect(scopeAdmittedFormIds($this->owner))->toContain((string) $this->trashed->id)
        ->and(Form::query()->visibleTo($this->owner)->pluck('forms.id')->all())
        ->not->toContain((string) $this->trashed->id);
});
