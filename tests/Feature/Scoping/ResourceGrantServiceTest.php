<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Exceptions\Authorization\GrantException;
use App\Models\ResourceGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Authorization\ResourceGrantService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The grant write surface + its escalation rules (Increment G10b) — the increment's headline: this is
| what makes the Reviewer role assignable at all. Before it, the only production writer of a grant was
| FormService::create (always Editor, always the creator).
|
| Two layers are asserted separately on purpose:
|   - ResourceGrantPolicy  — WHO may hand out WHAT (base gate, no self-grant, anti-amplification).
|   - ResourceGrantService — whether the resulting row is COHERENT (same tenant, active recipient,
|                            replace-don't-accumulate).
| Collapsing them would hide which layer is actually holding the line.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->member = User::factory()->create();
    makeActiveMember($this->member, 'form_editor');

    // `formIn()` reads tenant/user off the test, matching the other scoping suites.
    $this->user = $this->admin;

    $this->service = app(ResourceGrantService::class);
    $this->resolver = app(ResourceGrantResolver::class);
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
});

// ── The write ────────────────────────────────────────────────────────────────────────────────────────

it('grants a reviewer capacity on a form — the thing that was impossible before G10b', function (): void {
    $grant = $this->service->grant($this->admin, $this->member, $this->form, ResourceCapacity::Reviewer);

    expect($grant->capacity)->toBe(ResourceCapacity::Reviewer)
        ->and($grant->user_id)->toBe($this->member->id)
        ->and($grant->granted_by)->toBe($this->admin->id)
        ->and($grant->scopeable_type)->toBe('form')
        ->and($this->resolver->holdsAny($this->member, $this->form))->toBeTrue();
});

it('REPLACES a capacity rather than accumulating one', function (): void {
    $this->service->grant($this->admin, $this->member, $this->form, ResourceCapacity::Editor);
    $this->service->grant($this->admin, $this->member, $this->form, ResourceCapacity::Reviewer);

    // The unique key excludes `capacity` precisely so a demotion cannot leave edit rights standing beside
    // the new review rights. One row, and the old capacity is gone.
    expect(ResourceGrant::query()->where('user_id', $this->member->id)->count())->toBe(1)
        ->and($this->resolver->holds($this->member, $this->form, ResourceCapacity::Editor))->toBeFalse()
        ->and($this->resolver->holds($this->member, $this->form, ResourceCapacity::Reviewer))->toBeTrue();
});

it('grants on a scope node with descendant inheritance', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $form = formIn(makeScopeNode($root, 'Province A'));

    $this->service->grant($this->admin, $this->member, $root, ResourceCapacity::Editor, includesDescendants: true);

    expect($this->resolver->holds($this->member, $form, ResourceCapacity::Editor))->toBeTrue();
});

it('refuses descendant inheritance on a form target', function (): void {
    // A form has no descendants. The DB CHECK is the backstop; this is the clean refusal in front of it.
    expect(fn () => $this->service->grant(
        $this->admin, $this->member, $this->form, ResourceCapacity::Editor, includesDescendants: true,
    ))->toThrow(GrantException::class, 'only to a grant on a scope node');
});

it('refuses to grant to an outsider, without leaking that the account exists', function (): void {
    $stranger = User::factory()->create();   // no tenant_users row

    // `users` carries a visibility RLS policy — `id = current_user OR EXISTS(active membership)` — so an
    // outsider is invisible and the tenant-scoped re-resolve 404s. That is the RIGHT surface: a distinct
    // "not a member" error would confirm the email is registered on the platform.
    expect(fn () => $this->service->grant($this->admin, $stranger, $this->form, ResourceCapacity::Reviewer))
        ->toThrow(ModelNotFoundException::class);

    expect(ResourceGrant::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

it('refuses to grant to a visible user who is not an ACTIVE member', function (): void {
    // Reaching the guard needs a recipient the `users` policy lets through while their membership is not
    // active — today that is only "yourself" (`id = current_user`). So this drives the service as an
    // unaffiliated user acting on themselves.
    //
    // The guard is therefore defence in depth: RLS gets there first on every route-reachable path. It is
    // kept because the resolver counts only ACTIVE members' grants, so if that visibility policy ever
    // widens (a members screen listing suspended people would want exactly that), the failure without it
    // is a grant that is written, listed, and confers nothing.
    $outsider = User::factory()->create();
    enterTenant($this->tenant->id, $outsider->id);

    expect(fn () => $this->service->grant($outsider, $outsider, $this->form, ResourceCapacity::Reviewer))
        ->toThrow(GrantException::class, 'not an active member');
});

it('revokes, and the resolver stops honouring it in the same request', function (): void {
    $grant = $this->service->grant($this->admin, $this->member, $this->form, ResourceCapacity::Editor);
    expect($this->resolver->holdsAny($this->member, $this->form))->toBeTrue();

    $this->service->revoke($grant);

    // forget($userId) is the right call here — only this user's grant set changed, no paths moved.
    expect($this->resolver->holdsAny($this->member, $this->form))->toBeFalse();
});

// ── The escalation rules ─────────────────────────────────────────────────────────────────────────────

/** Build an actor holding exactly $permissions and nothing else. */
function actorWith(array $permissions): User
{
    $user = User::factory()->create();
    makeActiveMember($user, 'viewer');
    $user->syncRoles([]);
    $user->syncPermissions($permissions);

    return $user;
}

it('lets an admin grant either capacity', function (): void {
    foreach ([ResourceCapacity::Editor, ResourceCapacity::Reviewer] as $capacity) {
        expect(Gate::forUser($this->admin)->allows('grantCapacity', [ResourceGrant::class, $capacity, $this->member]))
            ->toBeTrue();
    }
});

it('refuses a self-grant', function (): void {
    // Otherwise "may manage collaborators" silently means "may give myself anything, anywhere".
    expect(Gate::forUser($this->admin)->allows(
        'grantCapacity', [ResourceGrant::class, ResourceCapacity::Editor, $this->admin],
    ))->toBeFalse();
});

it('refuses anyone without the base collaborator permission', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    // RBAC §5: deliberately NOT delegable to a form's own editors, so a Form Editor cannot add themselves
    // or others to forms an administrator never granted them.
    expect(Gate::forUser($editor)->allows(
        'grantCapacity', [ResourceGrant::class, ResourceCapacity::Editor, $this->member],
    ))->toBeFalse();
});

it('blocks amplification: granting editor needs forms.edit.any', function (): void {
    // Built from a raw permission subset, not a seeded role — under the shipped 5-role matrix this
    // combination is unreachable, because Owner/Admin are the only holders of forms.collaborators.manage
    // and they hold both `.any` permissions. The rule is forward-looking; see the policy docblock.
    $actor = actorWith(['forms.collaborators.manage', 'submissions.review.any']);

    expect(Gate::forUser($actor)->allows('grantCapacity', [ResourceGrant::class, ResourceCapacity::Editor, $this->member]))
        ->toBeFalse()
        ->and(Gate::forUser($actor)->allows('grantCapacity', [ResourceGrant::class, ResourceCapacity::Reviewer, $this->member]))
        ->toBeTrue();
});

it('blocks amplification: granting reviewer needs submissions.review.any', function (): void {
    $actor = actorWith(['forms.collaborators.manage', 'forms.edit.any']);

    expect(Gate::forUser($actor)->allows('grantCapacity', [ResourceGrant::class, ResourceCapacity::Reviewer, $this->member]))
        ->toBeFalse()
        ->and(Gate::forUser($actor)->allows('grantCapacity', [ResourceGrant::class, ResourceCapacity::Editor, $this->member]))
        ->toBeTrue();
});

it('allows an admin to revoke, including their own grant', function (): void {
    $own = $this->resolver;   // keep the resolver referenced; the grant below is the creator's own
    $grant = ResourceGrant::query()->where('user_id', $this->admin->id)->firstOrFail();

    // Self-REVOKE is de-escalation and stays allowed, unlike self-grant.
    expect(Gate::forUser($this->admin)->allows('delete', $grant))->toBeTrue()
        ->and($own)->not->toBeNull();
});
