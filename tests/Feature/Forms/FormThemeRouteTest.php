<?php

declare(strict_types=1);

use App\Enums\FormThemePreset;
use App\Enums\PlanTier;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Choosing a form's preset theme (M131, `R-6017d6d8`, `D81`).
|--------------------------------------------------------------------------
| `PATCH /forms/{form}/theme`, gated `can:update,form` and nothing else: `D81` puts presets on every plan, so
| there is no `feature:` gate (the M120 page-mode precedent — minting one would be a pricing decision). The
| column is written only by `FormService::setTheme()`, and it is no longer mass-assignable.
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
    assignPlanTier(PlanTier::Free);

    $this->editor = User::factory()->create();
    makeActiveMember($this->editor, 'form_editor');

    $this->form = app(FormService::class)->create($this->tenant, $this->editor, 'Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function themeUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/theme";
}

it('lets the editor of a form choose a preset on a Free plan, and audits it', function (): void {
    $this->actingAs($this->editor)->withoutVite()
        ->patch(themeUrl($this->form), ['preset' => 'graphite'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    $form = Form::query()->whereKey($this->form->id)->firstOrFail();
    expect($form->theme)->toBe(['preset' => 'graphite']);

    $audit = Audit::query()->where('auditable_type', 'form')->where('auditable_id', $form->id)
        ->where('event', 'updated')->latest('created_at')->firstOrFail();
    expect($audit->old_values)->toMatchArray(['theme_preset' => null])
        ->and($audit->new_values)->toMatchArray(['theme_preset' => 'graphite']);
});

it('goes back to the workspace brand when the preset is null', function (): void {
    app(FormService::class)->setTheme($this->form, FormThemePreset::Forest, $this->editor);

    $this->actingAs($this->editor)->withoutVite()
        ->patch(themeUrl($this->form), ['preset' => null])
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($this->form->id)->firstOrFail()->theme)->toBeNull();
});

it('refuses a preset that does not exist', function (): void {
    $this->actingAs($this->editor)->withoutVite()
        ->patch(themeUrl($this->form), ['preset' => 'neon'])
        ->assertSessionHasErrors('preset');
});

it('refuses a member who may not edit the form', function (): void {
    $other = User::factory()->create();
    makeActiveMember($other, 'form_editor');

    $this->actingAs($other)->withoutVite()
        ->patch(themeUrl($this->form), ['preset' => 'graphite'])
        ->assertForbidden();
});

it('keeps theme out of mass assignment', function (): void {
    $this->form->fill(['theme' => ['preset' => 'graphite']]);

    expect($this->form->theme)->toBeNull()
        ->and((new Form)->getFillable())->not->toContain('theme');
});

it('hands the settings the current preset and the transmitted catalogue', function (): void {
    app(FormService::class)->setTheme($this->form, FormThemePreset::Plum, $this->editor);

    $this->actingAs($this->editor)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$this->form->id}/settings")
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Settings', false)
            ->where('form.theme_preset', 'plum')
            ->has('form.theme_presets', count(FormThemePreset::cases()))
            ->where('form.theme_presets.0.value', FormThemePreset::cases()[0]->value)
            ->where('form.theme_presets.4.lines', FormThemePreset::Graphite->lines()));
});
