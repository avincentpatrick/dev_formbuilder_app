<?php

declare(strict_types=1);

use App\Models\Audit;
use App\Models\Form;
use App\Models\FormFolder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filing a form into a folder (M131, `R-9e634897`, `D79`).
|--------------------------------------------------------------------------
| Filing is an edit of the form, so it rides `can:update,form` — the editor of a form files it, and nobody
| else does. `folder_id` is deliberately NOT mass-assignable: `FormService::assignFolder()` is its only
| writer, which is what puts the audit row beside every change.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->editor = User::factory()->create();
    makeActiveMember($this->editor, 'form_editor');

    $this->folder = new FormFolder;
    $this->folder->name = 'Clinics';
    $this->folder->save();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function folderAssignUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/folder";
}

it('lets the editor of a form file it, and audits the move', function (): void {
    // Through FormService::create, so the creator holds the Editor grant `can:update,form` needs.
    $form = app(FormService::class)->create($this->tenant, $this->editor, 'Theirs');

    $this->actingAs($this->editor)->withoutVite()
        ->patch(folderAssignUrl($form), ['folder_id' => $this->folder->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->value('folder_id'))->toBe($this->folder->id);

    $audit = Audit::query()->where('auditable_type', 'form')->where('auditable_id', $form->id)
        ->where('event', 'updated')->latest('created_at')->firstOrFail();
    expect($audit->old_values)->toMatchArray(['folder_id' => null, 'folder_name' => null])
        ->and($audit->new_values)->toMatchArray(['folder_id' => $this->folder->id, 'folder_name' => 'Clinics']);
});

it('refuses an editor who holds no grant on the form', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Not theirs');

    $this->actingAs($this->editor)->withoutVite()
        ->patch(folderAssignUrl($form), ['folder_id' => $this->folder->id])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->value('folder_id'))->toBeNull();
});

it('unfiles a form when the folder is null', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Filed');
    app(FormService::class)->assignFolder($form, $this->folder->id, $this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(folderAssignUrl($form), ['folder_id' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->value('folder_id'))->toBeNull();
});

it('refuses another workspace\'s folder', function (): void {
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    $otherAdmin = User::factory()->create();
    enterTenant($other->id, $otherAdmin->id);
    makeActiveMember($otherAdmin, 'admin');
    $theirs = new FormFolder;
    $theirs->name = 'Theirs';
    $theirs->save();

    enterTenant($this->tenant->id, $this->admin->id);
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Ours');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(folderAssignUrl($form), ['folder_id' => $theirs->id])
        ->assertSessionHasErrors('folder_id');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->value('folder_id'))->toBeNull();
});

it('keeps folder_id out of mass assignment', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Guarded');

    $form->fill(['folder_id' => $this->folder->id]);

    expect($form->folder_id)->toBeNull()
        ->and((new Form)->getFillable())->not->toContain('folder_id');
});
