<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M120 / `R-f1332829` — the presentation-mode write surface (PATCH /forms/{form}/page-mode).
|--------------------------------------------------------------------------
| A guarded FormService::setSinglePageMode write behind can:update,form ALONE — ungated, because the
| entitlement catalog holds no key for presentation mode and minting one here would be a pricing decision
| rather than the enforcement of one. Same shape as the schedule route, not the save-resume one.
|
| ⛔ THE COLUMN HAD NO WRITER OF ANY KIND BEFORE THIS. `forms.single_page_mode` shipped with the first forms
| migration and is read by PublicFormPresenter and EncodeFormPresenter, but every assignment in the tree was a
| seeder or a test — so the single-page branch of the guest runtime AND of manual encoding were unreachable
| for a real tenant despite being built and covered. This endpoint is what makes them reachable, which is why
| the cases below assert BOTH directions rather than only the interesting one.
|
| ⚠️ The sibling save-resume route has no HTTP-level test at all. That gap is deliberately not inherited.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function pageModeUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/page-mode";
}

it('starts every new form stepped, in the model AND in the row', function (): void {
    // ⛔ BOTH HALVES, BECAUSE THE FIRST ONE FAILED WHEN THIS CASE WAS FIRST RUN. `D35` fixed the default at
    // step by step and the migration already said `false` — but `Form::create()` omitted the key, so the
    // returned MODEL carried `null` while the stored ROW carried `false`. `BuilderPresenter` publishes that
    // attribute into a prop the client declares as `boolean`, so the omission put a `null` into a boolean
    // contract on any path that presents a form it had just created. `FormService::create()` now states the
    // decided default explicitly; asserting the model alone, or the row alone, would miss that again.
    //
    // ⚠️ AND IT GOES THROUGH `FormService::create()` RATHER THAN `makeForm()`, WHICH IS THE POINT. The Pest
    // helper is a bare `Form::create()` that omits the key exactly as the service used to, so it reproduces
    // the `null` rather than testing the fix. The sole real creation path is the one that has to be right.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    expect($form->single_page_mode)->toBeFalse();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->single_page_mode)->toBeFalse();
});

it('lets an editor switch a form to one page', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(pageModeUrl($form), ['single_page_mode' => true])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->single_page_mode)->toBeTrue();
});

it('switches it back to step by step, so the submitted value is READ rather than assumed', function (): void {
    // ⛔ NOT A MIRROR OF THE CASE ABOVE. A controller that hard-codes `true`, or a service that ignores its
    // argument, passes that one and fails this one. This is the pair that makes the endpoint a setting rather
    // than a switch that only goes one way.
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill(['single_page_mode' => true])->save();

    $this->actingAs($this->admin)->withoutVite()
        ->patch(pageModeUrl($form), ['single_page_mode' => false])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->single_page_mode)->toBeFalse();
});

it('refuses a member without can:update,form (403)', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->withoutVite()
        ->patch(pageModeUrl($form), ['single_page_mode' => true])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->single_page_mode)->toBeFalse();
});

it('requires the flag and refuses anything that is not a boolean', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(pageModeUrl($form), [])
        ->assertSessionHasErrors('single_page_mode');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(pageModeUrl($form), ['single_page_mode' => 'maybe'])
        ->assertSessionHasErrors('single_page_mode');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->single_page_mode)->toBeFalse();
});

it('surfaces the mode to the builder, which is what lets the live preview render it', function (): void {
    // ⛔ THE PRESENTER KEY IS LOAD-BEARING FOR THE PREVIEW, NOT JUST THE PANEL. Until this row
    // BuilderPresenter emitted no `single_page_mode`, so `PreviewPane`'s projection fell back to `?? false`
    // and the preview was always stepped no matter what the author chose. Deleting the presenter key reddens
    // this case and four Vitest cases at once.
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill(['single_page_mode' => true])->save();

    $props = app(BuilderPresenter::class)->present($form->refresh());

    expect($props['form']['single_page_mode'])->toBeTrue();
});
