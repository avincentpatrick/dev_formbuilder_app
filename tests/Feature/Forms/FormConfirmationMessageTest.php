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
    // Increment H6b WARNS about it here (below) but still accepts — see the A3 block.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${ghost}!',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(Form::findOrFail($this->form->id)->confirmation_message)->toBe('Thanks, ${ghost}!');
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

/*
|--------------------------------------------------------------------------
| Amendment A3, closed by H6b as a WARNING (amendment A10)
|
| A message edited after the last publish can dangle a reference that nothing notices until the next
| publish refuses it, naming a message edited weeks earlier. The edit path now resolves against the
| CURRENTLY PUBLISHED version and says so — without refusing the write, because the publish gate resolves
| against a DIFFERENT version (the one being published), so an author whose draft adds the field is right
| and a 422 would block them for being early.
|--------------------------------------------------------------------------
*/

it('warns when a saved message references a field the published form does not have', function (): void {
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${ghost}!',
        ])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
            && str_contains($toast['message'], 'Confirmation message saved.')
            && str_contains($toast['message'], 'ghost')
            && str_contains($toast['message'], 'not a field on the published form'));
});

it('stays silent when every hole resolves against the published version', function (): void {
    // `full_name` is a real short_text field on publishedInboxForm's published version.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${full_name}!',
        ])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success'
            && $toast['message'] === 'Confirmation message saved.');
});

it('stays silent on a form with no published version at all', function (): void {
    // A3's own reasoning: there is nothing to resolve against, and inventing an answer would be dishonest.
    $draftOnly = app(App\Services\Forms\FormService::class)->create($this->tenant, $this->owner, 'Unpublished');

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$draftOnly->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${ghost}!',
        ])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');
});

it('warns about a dangling hole inside a locale variant, naming that variant', function (): void {
    // §4: each variant is independently a template, and parity across locales is NOT required — so the
    // base resolving is no excuse for the variant that does not.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", [
            'confirmation_message' => 'Thanks, ${full_name}!',
            'confirmation_message_translations' => ['fil' => 'Salamat, ${ghost}!'],
        ])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
            && str_contains($toast['message'], 'ghost'));
});

it('warns with the right reason when the referenced field exists but is not pipeable', function (): void {
    $form = app(App\Services\Forms\FormService::class)->create($this->tenant, $this->owner, 'Sketchpad');
    addFormField($form->draftVersion, $this->owner, 'sig', App\Enums\FieldType::Signature, 1);
    app(App\Services\Forms\PublishService::class)->publish($form->refresh(), $this->owner);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$form->id}/confirmation", [
            'confirmation_message' => 'Signed: ${sig}',
        ])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
            && str_contains($toast['message'], 'cannot be piped'));
});

it('says nothing at all when the message is cleared', function (): void {
    $this->form->forceFill(['confirmation_message' => 'Thanks, ${ghost}!'])->save();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/forms/{$this->form->id}/confirmation", ['confirmation_message' => ''])
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success'
            && $toast['message'] === 'Confirmation message reset to the default.');
});
