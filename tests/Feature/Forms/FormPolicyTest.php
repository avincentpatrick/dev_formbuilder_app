<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run(); // idempotent, committed on the privileged connection
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('lets an Admin edit, publish, and delete any form via the .any permissions', function (): void {
    $creator = User::factory()->create();
    enterTenant($this->tenant->id, $creator->id);
    $form = app(FormService::class)->create($this->tenant, $creator, 'Survey');

    $admin = User::factory()->create();
    enterTenant($this->tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    expect($admin->can('update', $form))->toBeTrue()
        ->and($admin->can('publish', $form))->toBeTrue()
        ->and($admin->can('delete', $form))->toBeTrue();
});

it('confines a Form Editor to the forms they collaborate on (.own)', function (): void {
    $editor = User::factory()->create();
    $other = User::factory()->create();

    // The editor creates a form → gets an editor collaborator row for it.
    enterTenant($this->tenant->id, $editor->id);
    $mine = app(FormService::class)->create($this->tenant, $editor, 'Mine');
    makeActiveMember($editor, 'form_editor');

    // Someone else's form — the editor is not a collaborator on it.
    enterTenant($this->tenant->id, $other->id);
    $theirs = app(FormService::class)->create($this->tenant, $other, 'Theirs');

    enterTenant($this->tenant->id, $editor->id);

    expect($editor->can('update', $mine))->toBeTrue()       // editor collaborator on this one
        ->and($editor->can('publish', $mine))->toBeTrue()
        ->and($editor->can('update', $theirs))->toBeFalse() // no collaborator row → .own fails
        ->and($editor->can('delete', $mine))->toBeFalse();  // editors have no forms.delete at all
});

it('denies a Viewer any edit, publish, or create', function (): void {
    $creator = User::factory()->create();
    enterTenant($this->tenant->id, $creator->id);
    $form = app(FormService::class)->create($this->tenant, $creator, 'Survey');

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    expect($viewer->can('update', $form))->toBeFalse()
        ->and($viewer->can('publish', $form))->toBeFalse()
        ->and($viewer->can('create', Form::class))->toBeFalse();
});
