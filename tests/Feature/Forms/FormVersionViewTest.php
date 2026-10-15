<?php

declare(strict_types=1);

use App\Models\FormVersion;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M172 (`R-52f5ad93`, `D133`) — an old version of a form, opened read-only from the hub's Versions panel.
|--------------------------------------------------------------------------
| The testers asked for "a way to check the older version for history". The route takes Print blank's shape and gate:
| a version of THIS form only (`scopeBindings`), `can:view,form`, and a draft refused — its snapshot is empty.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'History Form'); // v1 published, v2 the draft
    $this->v1 = FormVersion::query()->where('form_id', $this->form->id)->where('version_number', 1)->firstOrFail();
    $this->v2 = FormVersion::query()->where('form_id', $this->form->id)->where('version_number', 2)->firstOrFail();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function versionViewUrl(string $formId, string $versionId): string
{
    return "http://acme.meridian.test/forms/{$formId}/versions/{$versionId}/view";
}

it('opens a published version read-only, with the runtime input its preview is drawn from', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(versionViewUrl($this->form->id, $this->v1->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/VersionView', false)
            ->where('version.version_number', 1)
            ->where('version.status', 'published')
            ->where('schema.form.id', $this->form->id)
            ->where('schema.version.version_number', 1)
            ->has('schema.version.schema.fields', 4)
            ->where('hub_href', "/forms/{$this->form->id}")
            ->etc());
});

it('refuses a draft, whose snapshot is empty', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(versionViewUrl($this->form->id, $this->v2->id))
        ->assertNotFound();
});

it('refuses a reader without view on the form, as Print blank does', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(versionViewUrl($this->form->id, $this->v1->id))
        ->assertForbidden();
});
