<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\PublicFormPresenter;
use App\Support\Forms\RedirectTarget;
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
    $draftOnly = app(FormService::class)->create($this->tenant, $this->owner, 'Unpublished');

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
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Sketchpad');
    addFormField($form->draftVersion, $this->owner, 'sig', FieldType::Signature, 1);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

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

// ── M130 (`R-db169c29`, `D76`) — where a respondent goes after the thank-you screen ─────────────────────────
//
// The same PATCH, the same request and the same audit row as the message. Every refusal follows an accepted save
// that differs from it in the one value under test.

function confirmationRedirectPatch(object $test, User $user, Form $form, array $data): mixed
{
    return $test->actingAs($user)->patch("http://acme.meridian.test/forms/{$form->id}/confirmation", $data);
}

function confirmationRedirectLatestAudit(string $formId): Audit
{
    return Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $formId)
        ->where('event', AuditEvent::Updated->value)
        ->orderByDesc('id')
        ->firstOrFail();
}

it('saves each kind of destination with the message, in one audit row', function (): void {
    $target = publishedInboxForm($this->tenant, $this->owner, 'Follow-up');

    confirmationRedirectPatch($this, $this->owner, $this->form, [
        'confirmation_message' => 'Thanks!',
        'redirect_kind' => 'form',
        'redirect_form_id' => $target->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $form = Form::findOrFail($this->form->id);
    expect($form->redirect_form_id)->toBe($target->id)
        ->and($form->redirect_url)->toBeNull()
        ->and(confirmationRedirectLatestAudit($form->id)->new_values)->toMatchArray([
            'confirmation_message' => 'Thanks!',
            'redirect_form_id' => $target->id,
            'redirect_url' => null,
        ]);

    confirmationRedirectPatch($this, $this->owner, $this->form, [
        'confirmation_message' => 'Thanks!',
        'redirect_kind' => 'url',
        'redirect_url' => 'https://health.example.org/next',
    ])->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $form = Form::findOrFail($this->form->id);
    expect($form->redirect_url)->toBe('https://health.example.org/next')
        ->and($form->redirect_form_id)->toBeNull();

    confirmationRedirectPatch($this, $this->owner, $this->form, [
        'confirmation_message' => 'Thanks!',
        'redirect_kind' => 'none',
    ])->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $form = Form::findOrFail($this->form->id);
    expect($form->redirect_url)->toBeNull()
        ->and($form->redirect_form_id)->toBeNull()
        ->and(confirmationRedirectLatestAudit($form->id)->old_values)->toMatchArray(['redirect_url' => 'https://health.example.org/next']);
});

it('leaves the destination as it is when a save does not mention it, as Reset to default does', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, 'Thanks!', null, RedirectTarget::url('https://health.example.org/next'), $this->owner);

    confirmationRedirectPatch($this, $this->owner, $this->form, [
        'confirmation_message' => null,
        'confirmation_message_translations' => null,
    ])->assertSessionHasNoErrors()->assertSessionHas('toast.message', 'Confirmation message reset to the default.');

    enterTenant($this->tenant->id, $this->owner->id);
    $form = Form::findOrFail($this->form->id);
    expect($form->confirmation_message)->toBeNull()
        ->and($form->redirect_url)->toBe('https://health.example.org/next');
});

it('refuses a web address a respondent should not be sent to, after a safe one is accepted', function (string $url): void {
    confirmationRedirectPatch($this, $this->owner, $this->form, ['redirect_kind' => 'url', 'redirect_url' => 'https://health.example.org/next'])
        ->assertSessionHasNoErrors();

    confirmationRedirectPatch($this, $this->owner, $this->form, ['redirect_kind' => 'url', 'redirect_url' => $url])
        ->assertSessionHasErrors('redirect_url');
})->with([
    'a script' => 'javascript:alert(1)',
    'plain http' => 'http://health.example.org/next',
    'data' => 'data:text/html,x',
    'a control character' => "https://health.example.org/\x01",
    'userinfo' => 'https://trusted.example@evil.example/',
    'too long' => 'https://health.example.org/'.str_repeat('a', 2000),
    'nothing' => '',
]);

it('refuses a destination form the author may not send respondents to, after another form is accepted', function (string $case): void {
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $mine = app(FormService::class)->create($this->tenant, $editor, 'My intake');
    $alsoMine = app(FormService::class)->create($this->tenant, $editor, 'My follow-up');

    confirmationRedirectPatch($this, $editor, $mine, ['redirect_kind' => 'form', 'redirect_form_id' => $alsoMine->id])
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $refused = match ($case) {
        'this form itself' => $mine->id,
        'something that is not an id' => 'my-follow-up',
        'a form that does not exist' => '0192f1a2-b3c4-7d5e-8f90-00000000dead',
        'a form the editor cannot open' => $this->form->id,
    };

    confirmationRedirectPatch($this, $editor, $mine, ['redirect_kind' => 'form', 'redirect_form_id' => $refused])
        ->assertSessionHasErrors('redirect_form_id');
})->with([
    'this form itself',
    'something that is not an id',
    'a form that does not exist',
    'a form the editor cannot open',
]);

it('refuses another workspace\'s form, which reads as missing', function (): void {
    $beta = inboxTenant('beta');
    $betaOwner = User::factory()->create();
    enterTenant($beta->id, $betaOwner->id);
    $foreign = app(FormService::class)->create($beta, $betaOwner, 'Elsewhere');

    enterTenant($this->tenant->id, $this->owner->id);
    $local = publishedInboxForm($this->tenant, $this->owner, 'Follow-up');
    confirmationRedirectPatch($this, $this->owner, $this->form, ['redirect_kind' => 'form', 'redirect_form_id' => $local->id])
        ->assertSessionHasNoErrors();

    confirmationRedirectPatch($this, $this->owner, $this->form, ['redirect_kind' => 'form', 'redirect_form_id' => $foreign->id])
        ->assertSessionHasErrors('redirect_form_id');
});
