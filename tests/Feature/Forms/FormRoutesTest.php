<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
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
| Increment D3 — forms HTTP wiring through the real subdomain pipeline.
|--------------------------------------------------------------------------
| Proves the FormPolicy `.any`/`.own` gate on the forms routes end-to-end (the service-level
| FormPolicyTest can't exercise the `can:<ability>,<model>` middleware + route-model binding). Tenants
| resolve on a subdomain of the central domain (config/tenancy.php → meridian.test).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The demo tenant reachable at acme.meridian.test. */
function formsTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

it('forbids a Viewer from the forms list', function (): void {
    $tenant = formsTenant();
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no forms.* permissions

    $this->actingAs($viewer)
        ->get('http://acme.meridian.test/forms')
        ->assertForbidden();
});

it('lets an Admin list and create forms through the gated routes', function (): void {
    $this->withoutVite(); // the GET renders the Inertia root view; CI tests job builds no assets
    $tenant = formsTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/forms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Index', false)->has('forms'));

    $this->actingAs($admin)
        ->post('http://acme.meridian.test/forms', ['title' => 'Household Survey'])
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id);
    expect(Form::where('title', 'Household Survey')->count())->toBe(1);
});

it('opens the builder on the form it just created, and the creator can actually load it', function (): void {
    // ⭐ J5c CHANGED THIS REDIRECT, AND THE SECOND HALF IS THE ASSERTION THAT MATTERS. `store()` used to
    // return `back()` — you named a form and landed where you started — while its sibling
    // `FormTemplateController::instantiate()` has always opened the builder. Onboarding §2 offers the two
    // as equally-weighted choices, and one of them stopping short of the product is not equal.
    //
    // The risk this pins is not the URL: `forms.builder` carries `can:update,form`, so a redirect into a
    // page the creator is refused would be the "links that bounce" defect shipped by the very commit that
    // exists to remove a dead end. It is safe because `FormService::create()` writes the creator an
    // explicit Editor `ResourceGrant` — so the FOLLOWED request is the real check, not the Location header.
    $this->withoutVite();
    $tenant = formsTenant();
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    $this->actingAs($editor)
        ->post('http://acme.meridian.test/forms', ['title' => 'Clinic Intake'])
        ->assertRedirect();

    enterTenant($tenant->id, $editor->id);
    $form = Form::where('title', 'Clinic Intake')->firstOrFail();

    $this->actingAs($editor)
        ->get("http://acme.meridian.test/forms/{$form->id}/builder")
        ->assertOk();
});

it('publishes and archives a form through the gated routes', function (): void {
    $tenant = formsTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/publish")
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id);
    expect(FormVersion::where('form_id', $form->id)->where('status', FormVersionStatus::Published)->count())->toBe(1);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/archive")
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id);
    expect(Form::whereKey($form->id)->value('status'))->toBe(FormStatus::Archived);
});

it('confines publishing to a form the editor collaborates on (.own)', function (): void {
    $tenant = formsTenant();
    $editor = User::factory()->create();
    $other = User::factory()->create();

    enterTenant($tenant->id, $editor->id);
    $mine = app(FormService::class)->create($tenant, $editor, 'Mine'); // editor is a collaborator here
    makeActiveMember($editor, 'form_editor');

    enterTenant($tenant->id, $other->id);
    $theirs = app(FormService::class)->create($tenant, $other, 'Theirs');

    // The editor can publish their own form …
    $this->actingAs($editor)
        ->post("http://acme.meridian.test/forms/{$mine->id}/publish")
        ->assertRedirect();

    // … but not a form they don't collaborate on (FormPolicy publish.own → no collaborator row → 403).
    $this->actingAs($editor)
        ->post("http://acme.meridian.test/forms/{$theirs->id}/publish")
        ->assertForbidden();
});
