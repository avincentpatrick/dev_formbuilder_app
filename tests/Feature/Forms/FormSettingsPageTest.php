<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\PublishService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — the form hub's Settings tab (`R-1132a6f3`, the half of `D63` that `M117` did not build).
|--------------------------------------------------------------------------
| `D63` answered both entry points with ONE set of sections against ONE set of routes, so the case that matters
| most here is that this page and the builder's modal are handed the same settings block — that is the
| property "no second implementation to drift" names, asserted rather than trusted.
|
| ⚠️ Helpers are prefixed `settingsPage*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    // These cases render whole pages, and CI builds no Vite manifest: without the suite opt-out every render is a
    // 500 there while it passes here, where the dev server or a local build supplies one (measured, M129).
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = settingsPageForm($this->tenant, $this->owner, 'Clinic Visit');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function settingsPageForm(Tenant $tenant, User $user, string $title, ?FieldType $extra = null, bool $publish = true): Form
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    addFormField($form->draftVersion, $user, 'patient_name', FieldType::ShortText, 1);
    if ($extra !== null) {
        addFormField($form->draftVersion, $user, 'extra', $extra, 2);
    }
    if ($publish) {
        app(PublishService::class)->publish($form->refresh(), $user);
    }

    return $form->refresh();
}

function settingsPageUrl(Form $form, string $slug = 'acme'): string
{
    return "http://{$slug}.meridian.test/forms/{$form->id}/settings";
}

it('renders the Settings tab for an Owner, current in the strip, with the trail back to the form', function (): void {
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('forms/Settings', false)
            ->where('form.id', $this->form->id)
            ->where('form.title', 'Clinic Visit')
            ->where('tabs.4.key', 'settings')
            ->where('tabs.4.href', "/forms/{$this->form->id}/settings")
            ->where('crumbs.0.label', 'Forms')
            ->where('crumbs.1.label', 'Clinic Visit')
            ->where('crumbs.1.href', "/forms/{$this->form->id}")
            ->where('crumbs.2.label', 'Settings')
            ->has('share')
            ->has('timezones'));
});

it('hands the page exactly the settings block the builder\'s modal receives', function (): void {
    $page = $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))->assertOk()
        ->viewData('page')['props']['form'];

    enterTenant($this->tenant->id, $this->owner->id);
    // The viewer too (M130): the destination picker lists the forms this author may open, on both surfaces.
    $builder = app(BuilderPresenter::class)->present($this->form->refresh(), $this->owner)['form'];

    // The builder's own two keys are `id` and `status`; every other key is a settings section's.
    $builderSettings = array_diff_key($builder, ['id' => true, 'status' => true]);
    $pageSettings = array_diff_key($page, ['id' => true]);

    expect(array_keys($pageSettings))->toBe(array_keys($builderSettings))
        ->and($pageSettings)->toEqual($builderSettings);
});

it('keeps the builder\'s form block in the order and with the keys it always had', function (): void {
    $builder = app(BuilderPresenter::class)->present($this->form)['form'];

    expect(array_keys($builder))->toBe([
        'id', 'title', 'description', 'status', 'save_and_resume', 'single_page_mode', 'opens_at', 'closes_at',
        'timezone', 'max_responses', 'confirmation_message', 'confirmation_message_translations',
        // M130 (`D76`) — the after-submit destination, with the Thank-you section it is saved from.
        'redirect_kind', 'redirect_form_id', 'redirect_url', 'redirect_targets',
        // M138 (`R-df7f4b62`, `D91`) — how long the thank-you screen waits before the move.
        'redirect_delay_seconds',
        // M131 (`R-6017d6d8`) — the preset theme and the transmitted catalogue, for the Theme section and the preview.
        'theme_preset', 'theme_presets',
        'default_locale', 'supported_locales',
    ]);
});

it('refuses a Viewer, after an Owner is admitted, and answers 404 from another workspace', function (): void {
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))->assertOk();

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    $this->withoutVite()->actingAs($viewer)->get(settingsPageUrl($this->form))->assertForbidden();

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $beta->domains()->create(['domain' => 'beta']);
    $betaOwner = User::factory()->create();
    enterTenant($beta->id, $betaOwner->id);
    makeActiveMember($betaOwner, 'owner');

    $this->withoutVite()->actingAs($betaOwner)->get(settingsPageUrl($this->form, 'beta'))->assertNotFound();
});

it('offers Scanning for a form paper can carry, and says why for one it cannot', function (): void {
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ocr_scanning.enabled', false)
            ->where('ocr_scanning.eligible', true)
            ->where('ocr_scanning.reason', null));

    enterTenant($this->tenant->id, $this->owner->id);
    $grid = settingsPageForm($this->tenant, $this->owner, 'With a location', FieldType::Geopoint);
    $draftOnly = settingsPageForm($this->tenant, $this->owner, 'Never published', null, false);

    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($grid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ocr_scanning.eligible', false)
            ->where('ocr_scanning.reason', OcrException::formNotEligible()->getMessage()));

    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($draftOnly))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ocr_scanning.reason', 'Scans can be read once this form is published.'));
});

it('offers no Scanning section where the workspace cannot scan — module off, or a plan without it', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->owner);
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ocr_scanning', null));

    enterTenant($this->tenant->id, $this->owner->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => true], $this->owner);
    assignPlanTier(PlanTier::Starter);
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ocr_scanning', null));
});

it('offers Scope only to a holder of scopes.manage', function (): void {
    $this->withoutVite()->actingAs($this->owner)->get(settingsPageUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scope.current_node_id', null)
            ->has('scope.options'));

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $own = settingsPageForm($this->tenant, $editor, 'Editor\'s Own');

    $this->withoutVite()->actingAs($editor)->get(settingsPageUrl($own))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scope', null));
});

it('hands the builder the same Scanning facts, so both entry points show one section', function (): void {
    $builder = app(BuilderPresenter::class)->present($this->form);

    expect($builder['ocr_scanning'])->toBe(['enabled' => false, 'eligible' => true, 'reason' => null]);
});

it('offers as destinations the other forms the author may open, each marked live or not (M130)', function (): void {
    $draft = app(FormService::class)->create($this->tenant, $this->owner, 'Another form');
    $live = settingsPageForm($this->tenant, $this->owner, 'Live form');
    $live->update(['public_slug' => 'live-form', 'allow_guest_submissions' => true]);
    $archived = app(FormService::class)->create($this->tenant, $this->owner, 'Archived form');
    app(FormService::class)->archive($archived, $this->owner);

    // By title, never this form, never an archived one; `live` is the predicate the submit response uses.
    expect(app(FormSettingsPresenter::class)->form($this->form->refresh(), $this->owner)['redirect_targets'])->toBe([
        ['id' => $draft->id, 'title' => 'Another form', 'live' => false],
        ['id' => $live->id, 'title' => 'Live form', 'live' => true],
    ]);
});

it('lists a Form Editor only their own forms as destinations, and nobody without a viewer (M130)', function (): void {
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $mine = app(FormService::class)->create($this->tenant, $editor, 'My intake');
    $alsoMine = app(FormService::class)->create($this->tenant, $editor, 'My follow-up');

    expect(array_column(app(FormSettingsPresenter::class)->form($mine, $editor)['redirect_targets'], 'id'))->toBe([$alsoMine->id])
        ->and(app(FormSettingsPresenter::class)->form($mine)['redirect_targets'])->toBe([]);
});
