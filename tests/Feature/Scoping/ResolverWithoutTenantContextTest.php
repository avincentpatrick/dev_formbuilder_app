<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fail-closed behaviour with no tenant context (Increment G10a).
|
| Policies are reachable off-tenant — HandleInertiaRequests::share() runs on every response, including on
| the central admin console where no tenant GUC and no Spatie team are set. Today `share()` only calls
| `can('viewAny', Form::class)`, which is permission-only and never reaches the resolver, so this file is
| deliberately FORWARD-LOOKING: it costs nothing now and catches the day a policy that DOES hit the
| resolver gets added to that path. The requirement is that everything returns false and nothing THROWS —
| an exception here would 500 every page render rather than fail closed.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run(); // idempotent, committed on the privileged connection
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'form_editor');
    $this->form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
});

it('resolves nothing, and throws nothing, once tenant context is gone', function (): void {
    $resolver = app(ResourceGrantResolver::class);
    expect($resolver->holds($this->user, $this->form, ResourceCapacity::Editor))->toBeTrue();

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $resolver->forget();

    expect($resolver->holds($this->user, $this->form, ResourceCapacity::Editor))->toBeFalse()
        ->and($resolver->holdsAny($this->user, $this->form))->toBeFalse()
        ->and($resolver->holdsOnFormId($this->user, $this->form->id, ResourceCapacity::Editor))->toBeFalse()
        ->and($resolver->grantedFormIdsQuery($this->user)->count())->toBe(0);
});

it('denies the form and submission policies off-tenant without throwing', function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(ResourceGrantResolver::class)->forget();

    // `can()` rather than `hasPermissionTo()` is what keeps this a `false` instead of a
    // PermissionDoesNotExist exception; the policies' docblocks call that out and this pins it.
    expect($this->user->can('view', $this->form))->toBeFalse()
        ->and($this->user->can('update', $this->form))->toBeFalse()
        ->and($this->user->can('create', [Submission::class, $this->form]))->toBeFalse();
});
