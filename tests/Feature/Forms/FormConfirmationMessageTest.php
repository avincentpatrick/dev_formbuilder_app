<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Submissions\PublicFormPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The confirmation-message write path (Increment H6a, Doc #26 §6.2) — the storage the PRD's
// confirmation-screen piping claim needed and that no migration had. Grammar is checked here; references
// resolve at publish (TemplateValidationGateTest covers those).
//
// EVERY `${` literal is SINGLE-quoted (PHP 8.3 removed `${var}` interpolation).

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('saves a confirmation message and its locale variants', function (): void {
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${full_name}!',
            'confirmation_message_translations' => ['fil' => 'Salamat, ${full_name}!'],
        ])
        ->assertRedirect();

    // Re-enter the tenant after the HTTP request: the middleware forgets the context in terminate(), so a
    // read here would be blocked by RLS otherwise (the FormScheduleSettingsTest convention).
    enterTenant($this->tenant->id, $this->owner->id);
    $form = Form::findOrFail($this->form->id);

    expect($form->confirmation_message)->toBe('Thanks, ${full_name}!')
        ->and($form->confirmation_message_translations)->toBe(['fil' => 'Salamat, ${full_name}!']);
});

it('rejects a malformed hole at request time', function (): void {
    // ValidTemplate checks GRAMMAR, which is context-free and always decidable — unlike reference
    // resolution, which is version-relative and has no version to resolve against here.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${1abc}!',
        ])
        ->assertSessionHasErrors('confirmation_message');
});

it('rejects a malformed hole inside a locale variant', function (): void {
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks!',
            'confirmation_message_translations' => ['fil' => 'Salamat, ${a-b}!'],
        ])
        ->assertSessionHasErrors('confirmation_message_translations.fil');
});

it('accepts a hole naming a field that does not exist, deferring that to publish', function (): void {
    // The deliberate split (§6.2 as amended): request time cannot resolve references because the column is
    // form-level and editable on a form with no published version at all. The next publish refuses it.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${ghost}!',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('clears the message back to the runtime default', function (): void {
    $this->form->forceFill(['confirmation_message' => 'Thanks!'])->save();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => '',
        ])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(Form::findOrFail($this->form->id)->confirmation_message)->toBeNull();
});

it('forbids a user without update rights on the form', function (): void {
    $stranger = User::factory()->create();
    enterTenant($this->tenant->id, $stranger->id);

    $this->actingAs($stranger)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks!',
        ])
        ->assertForbidden();
});

it('is emitted raw to the guest runtime, never rendered server-side', function (): void {
    // Doc #26 §4's normative order (resolve the locale, THEN render) can only be honoured on the client:
    // the runtime picks its locale reactively, and `version.schema` travels with a checksum the client pins
    // against. So the presenter emits the TEMPLATE. H6b builds the renderer.
    $this->form->forceFill([
        'confirmation_message' => 'Thanks, ${full_name}!',
        'confirmation_message_translations' => ['fil' => 'Salamat!'],
    ])->save();

    $presented = app(PublicFormPresenter::class)->present(
        Form::findOrFail($this->form->id),
        FormVersion::findOrFail($this->form->current_published_version_id),
    );

    expect($presented['form']['confirmation_message'])->toBe('Thanks, ${full_name}!')
        ->and($presented['form']['confirmation_message_translations'])->toBe(['fil' => 'Salamat!']);
});
