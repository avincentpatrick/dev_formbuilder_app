<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The H6a publish gate (docs/piping-output-encoding-design.md §3/§6) — the increment's core.
//
// EVERY field here gets a DISTINCT `sequence`. addFormField() defaults it to 0, and §3.3 rule 1 as amended
// treats a positional TIE as a rejection (two fields at the same position have no defined render order, so
// a reference between them is not provably backward). A fixture that leaves the default would fail these
// tests for the wrong reason.
//
// EVERY `${` literal is SINGLE-quoted: PHP 8.3 removed `${var}` interpolation, so a double-quoted literal
// loses its holes before the gate sees them and the assertion tests nothing.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
    $this->publisher = app(PublishService::class);
});

/** A repeatable (or flat) section on a draft, at an explicit sequence. */
function templateGateSection(string $versionId, string $key, int $sequence, bool $repeatable = false): FormSection
{
    return FormSection::create([
        'form_version_id' => $versionId,
        'key' => $key,
        'label' => ucfirst($key),
        'sequence' => $sequence,
        'is_repeatable' => $repeatable,
        'min_instances' => $repeatable ? 1 : null,
        'max_instances' => $repeatable ? 10 : null,
    ]);
}

it('publishes a label that pipes a preceding field', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'child_name', FieldType::ShortText, 1);
    addFormField($draft, $this->user, 'child_age', FieldType::Integer, 2, ['label' => 'Age of ${child_name}']);

    $published = $this->publisher->publish($form->refresh(), $this->user);

    expect($published->status)->toBe(FormVersionStatus::Published);
});

it('refuses a forward reference', function (): void {
    // A hole naming a field that comes AFTER its host is permanently empty — an authoring bug the gate can
    // see (§3.3 rule 1).
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'child_age', FieldType::Integer, 1, ['label' => 'Age of ${child_name}']);
    addFormField($draft, $this->user, 'child_name', FieldType::ShortText, 2);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_forward_reference');
});

it('refuses a hole naming a field that does not exist', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1, ['label' => 'Age of ${ghost}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'unknown_template_reference');
});

it('refuses a section key used as a template hole', function (): void {
    // §3.3 rule 4 — `${roster}` is a legal count() operand in an EXPRESSION and an illegal hole. This is
    // precisely why the gate cannot reuse ExpressionParser::assertReferencesResolve() unchanged: its
    // $knownKeys is a flat union of field AND section keys, so it would accept this.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $roster = templateGateSection($draft->id, 'roster', 1, repeatable: true);
    addFormField($draft, $this->user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $roster->id]);
    addFormField($draft, $this->user, 'total', FieldType::Integer, 5, ['label' => 'Members in ${roster}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_references_section');
});

it('refuses an object-valued source type', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'where', FieldType::Geopoint, 1);
    addFormField($draft, $this->user, 'note_field', FieldType::ShortText, 2, ['label' => 'Near ${where}?']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_source_not_pipeable');
});

it('refuses an answer-free source type with a distinct verdict', function (): void {
    // `note` holds no answer at all, which is a different mistake from "holds an unrenderable answer" —
    // the three-valued classification exists so the builder can say which.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'preamble', FieldType::Note, 1);
    addFormField($draft, $this->user, 'age', FieldType::Integer, 2, ['label' => 'After ${preamble}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_source_has_no_answer');
});

it('pipes a calculated and a hidden field, which OCR would refuse', function (): void {
    // The two deliberate divergences from OcrFieldEligibility (§3.1), exercised end-to-end through publish.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'ref', FieldType::Hidden, 1);
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 2, [
        'config' => ['calculated_formula' => '1 + 1'],
    ]);
    addFormField($draft, $this->user, 'confirm', FieldType::ShortText, 3, [
        'label' => 'Confirm ${ref} totalling ${total}',
    ]);

    expect($this->publisher->publish($form->refresh(), $this->user)->status)
        ->toBe(FormVersionStatus::Published);
});

it('refuses a hole crossing from one repeat into another', function (): void {
    // §3.3 rule 3 — there is no instance to pick.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $a = templateGateSection($draft->id, 'household', 1, repeatable: true);
    $b = templateGateSection($draft->id, 'visits', 2, repeatable: true);
    addFormField($draft, $this->user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $a->id]);
    addFormField($draft, $this->user, 'visit_note', FieldType::ShortText, 2, [
        'form_section_id' => $b->id,
        'label' => 'Visit for ${member_name}',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_cross_repeat_reference');
});

it('refuses a flat-scope hole naming a repeat-scoped field', function (): void {
    // §3.3 rule 3's other half — the key names N values, not one.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $roster = templateGateSection($draft->id, 'roster', 1, repeatable: true);
    addFormField($draft, $this->user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $roster->id]);
    addFormField($draft, $this->user, 'summary', FieldType::ShortText, 9, ['label' => 'About ${member_name}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'template_repeat_to_flat_reference');
});

it('allows a hole inside a repeat naming a field in the same repeat', function (): void {
    // §3.3 rule 2 — resolved against the CURRENT instance.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $roster = templateGateSection($draft->id, 'roster', 1, repeatable: true);
    addFormField($draft, $this->user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $roster->id]);
    addFormField($draft, $this->user, 'member_age', FieldType::Integer, 2, [
        'form_section_id' => $roster->id,
        'label' => 'Age of ${member_name}',
    ]);

    expect($this->publisher->publish($form->refresh(), $this->user)->status)
        ->toBe(FormVersionStatus::Published);
});

it('refuses a dangling hole in one locale variant while the base is clean', function (): void {
    // §4 — every variant is independently a template and is independently validated. This is the
    // "multi-locale assertReferencesResolve()" H-map row 225 names, and the first locale-varying assertion
    // anywhere in the suite.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'child_name', FieldType::ShortText, 1);
    addFormField($draft, $this->user, 'child_age', FieldType::Integer, 2, [
        'label' => 'Age of ${child_name}',
        'label_translations' => ['fil' => 'Edad ni ${ghost}'],
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'unknown_template_reference');
});

it('does not require reference-set parity across locales', function (): void {
    // §4 — different grammars legitimately need different references. A locale may repeat a name, or omit
    // it where the sentence reads better without. The enforceable rule is only that every hole resolves.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'child_name', FieldType::ShortText, 1);
    addFormField($draft, $this->user, 'child_age', FieldType::Integer, 2, [
        'label' => 'Age of ${child_name}',
        'label_translations' => ['fil' => 'Ilang taon na?'],
    ]);

    expect($this->publisher->publish($form->refresh(), $this->user)->status)
        ->toBe(FormVersionStatus::Published);
});

it('validates a hint as a template too', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1, ['hint' => 'e.g. ${1abc}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'malformed_reference');
});

it('validates a section description as a template too', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer, 1);
    $section = templateGateSection($draft->id, 'details', 5);
    $section->forceFill(['description' => 'About ${ghost}'])->save();
    addFormField($draft, $this->user, 'more', FieldType::ShortText, 6, ['form_section_id' => $section->id]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'unknown_template_reference');
});

it('refuses a dangling hole in the form confirmation message', function (): void {
    // §6.2 — `forms.confirmation_message` is a FORM-level column, so it is not frozen per version. Its
    // holes are validated against the version being PUBLISHED (not the outgoing one, which is about to be
    // superseded).
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1);
    $form->forceFill(['confirmation_message' => 'Thanks, ${ghost}!'])->save();

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'confirmation_message');
});

it('publishes a confirmation message piping any field, since it renders after the whole form', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'name', FieldType::ShortText, 1);
    $form->forceFill(['confirmation_message' => 'Thanks, ${name}!'])->save();

    expect($this->publisher->publish($form->refresh(), $this->user)->status)
        ->toBe(FormVersionStatus::Published);
});

it('does not treat default_value as template-bearing', function (): void {
    // §6's closed list excludes `default_value` deliberately: it already has an expression mode
    // (`default_value_is_expression`), and one column with two sub-grammars is how ambiguity gets built in.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1, [
        'default_value' => '${ghost}',
        'default_value_is_expression' => false,
    ]);

    expect($this->publisher->publish($form->refresh(), $this->user)->status)
        ->toBe(FormVersionStatus::Published);
});

it('leaves the version a draft with no snapshot when a template is refused', function (): void {
    // A refused publish must leave NOTHING behind — no snapshot, no status flip, no form pointer.
    //
    // Note precisely what this does and does not prove — verified by mutation, not assumed. It does NOT
    // pin §6's step-1-before-step-3 ORDERING: the whole publish runs inside DB::transaction, so a throw
    // anywhere inside it rolls the snapshot write back, and this test still passes with the gate moved
    // below the freeze. The transaction is what guarantees the OUTCOME; the ordering is a code-structure
    // convention that avoids serializing a doomed publish and keeps step 1 the single validation phase.
    //
    // No honest test can pin the ordering from outside: it has no observable effect inside the
    // transaction, and SchemaSnapshotSerializer is `final` with PublishService type-hinting it, so it
    // cannot be spied on either. Doc #26 §6 is amended to say the transaction carries the guarantee
    // (amendment A5) rather than leaving the reader to infer a test that cannot exist.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer, 1, ['label' => 'Age of ${ghost}']);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class);

    $draft->refresh();

    expect($draft->status)->toBe(FormVersionStatus::Draft)
        ->and($draft->schema_snapshot)->toBe([])
        ->and($form->refresh()->current_published_version_id)->toBeNull();
});
