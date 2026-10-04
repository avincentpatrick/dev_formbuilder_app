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
| Form folders (M131, `R-9e634897`, `D78`, `D79`) — the folder itself.
|--------------------------------------------------------------------------
| A folder is a FILING axis, shared by the whole workspace, one per form. `D79` splits who may do what:
| anyone who may create forms creates a folder, and only Owners and Admins (`forms.edit.any`) rename or
| delete one, because a folder holds other people's forms. Deleting a folder UNFILES its forms — the
| database does it, through `forms_folder_fk`'s `ON DELETE SET NULL (folder_id)` — and never deletes one.
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

    $this->reviewer = User::factory()->create();
    makeActiveMember($this->reviewer, 'reviewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function folderUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/form-folders'.$suffix;
}

function makeFolder(string $name): FormFolder
{
    $folder = new FormFolder;
    $folder->name = $name;
    $folder->save();

    return $folder;
}

it('lets anyone who may create forms create a folder, and audits it', function (): void {
    $this->actingAs($this->editor)->withoutVite()
        ->post(folderUrl(), ['name' => 'Clinics'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    $folder = FormFolder::query()->where('name', 'Clinics')->firstOrFail();

    expect($folder->created_by)->toBe($this->editor->id);

    $audit = Audit::query()->where('auditable_type', 'form_folder')->where('auditable_id', $folder->id)->firstOrFail();
    expect($audit->event->value)->toBe('created')
        ->and($audit->new_values)->toMatchArray(['name' => 'Clinics']);
});

it('refuses a member who may not create forms', function (): void {
    $this->actingAs($this->reviewer)->withoutVite()
        ->post(folderUrl(), ['name' => 'Clinics'])
        ->assertForbidden();
});

it('refuses a second folder with the same name in any case', function (): void {
    makeFolder('Clinics');

    $this->actingAs($this->editor)->withoutVite()
        ->post(folderUrl(), ['name' => 'CLINICS'])
        ->assertSessionHasErrors('name');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormFolder::query()->count())->toBe(1);
});

it('refuses the names the list itself uses', function (string $name): void {
    $this->actingAs($this->editor)->withoutVite()
        ->post(folderUrl(), ['name' => $name])
        ->assertSessionHasErrors('name');
})->with(['Unfiled', 'all folders', '  ', str_repeat('x', 81)]);

it('lets an admin rename a folder, and audits both names', function (): void {
    $folder = makeFolder('Clinics');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(folderUrl("/{$folder->id}"), ['name' => 'Rural clinics'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    expect($folder->refresh()->name)->toBe('Rural clinics');

    $audit = Audit::query()->where('auditable_type', 'form_folder')->where('event', 'updated')->firstOrFail();
    expect($audit->old_values)->toMatchArray(['name' => 'Clinics'])
        ->and($audit->new_values)->toMatchArray(['name' => 'Rural clinics']);
});

it('refuses a form editor who tries to rename or delete a folder', function (): void {
    $folder = makeFolder('Clinics');

    $this->actingAs($this->editor)->withoutVite()
        ->patch(folderUrl("/{$folder->id}"), ['name' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($this->editor)->withoutVite()
        ->delete(folderUrl("/{$folder->id}"))
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormFolder::query()->whereKey($folder->id)->value('name'))->toBe('Clinics');
});

it('unfiles every form in a deleted folder and deletes none of them', function (): void {
    $folder = makeFolder('Clinics');
    $forms = app(FormService::class);

    $live = $forms->create($this->tenant, $this->admin, 'Live one');
    $archived = $forms->create($this->tenant, $this->admin, 'Archived one');
    $trashed = $forms->create($this->tenant, $this->admin, 'Trashed one');

    foreach ([$live, $archived, $trashed] as $form) {
        $forms->assignFolder($form, $folder->id, $this->admin);
    }
    $forms->archive($archived, $this->admin);
    $trashed->delete();

    $this->actingAs($this->admin)->withoutVite()
        ->delete(folderUrl("/{$folder->id}"))
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormFolder::query()->count())->toBe(0);

    $after = Form::withTrashed()->whereIn('id', [$live->id, $archived->id, $trashed->id])->get();
    expect($after)->toHaveCount(3)
        ->and($after->pluck('folder_id')->unique()->all())->toBe([null]);

    $audit = Audit::query()->where('auditable_type', 'form_folder')->where('event', 'deleted')->firstOrFail();
    expect($audit->old_values)->toMatchArray(['name' => 'Clinics', 'unfiled_forms' => 3]);
});

it('cannot reach another workspace\'s folder', function (): void {
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    $otherAdmin = User::factory()->create();
    enterTenant($other->id, $otherAdmin->id);
    makeActiveMember($otherAdmin, 'admin');
    $theirs = makeFolder('Theirs');

    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(folderUrl("/{$theirs->id}"), ['name' => 'Taken'])
        ->assertNotFound();

    $this->actingAs($this->admin)->withoutVite()
        ->delete(folderUrl("/{$theirs->id}"))
        ->assertNotFound();
});
