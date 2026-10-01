<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ConversionCensus;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M122 — ConversionCensus (R-495abf48, B5a second half).
|--------------------------------------------------------------------------
| A conversion keeps the field's id and key, so every reference to it survives SYNTACTICALLY — and some of
| them change MEANING. The engine cannot see them because it reads one field; this census reads the rest of
| the draft and says which references the conversion changes. It is advisory: nothing here is a refusal.
|
| Every case asserts the EXACT set of `code@site:key` it expects, so an extra impact is a failure rather than
| noise. The negatives are as load-bearing as the positives — a census that reports everything is as useless
| to the dialog as one that reports nothing.
|
| ⚠️ Helpers are prefixed `conversionCensus*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->form = app(FormService::class)->create($this->tenant, $this->user, 'Survey')->refresh();
    $this->draft = FormVersion::query()->whereKey($this->form->draft_version_id)->firstOrFail();
    $this->census = app(ConversionCensus::class);
});

/**
 * The census for converting $field to $to, as sorted `code@site:key` strings.
 *
 * @return list<string>
 */
function conversionCensusOf(ConversionCensus $census, Form $form, FormField $field, FieldType $to): array
{
    $field->refresh();
    $flat = array_map(
        static fn (array $impact): string => "{$impact['code']}@{$impact['site']}:{$impact['key']}",
        $census->judge($census->referencesTo($form->refresh(), $field), $field->field_type, $to),
    );
    sort($flat);

    return $flat;
}

/** A structured rule on $owner that names $related — the row shape the conditional editor writes. */
function conversionCensusRule(FormField $owner, FormField $related, ValidationRuleType $type, ?ComparisonOperator $operator = null, ?string $value = null): FormFieldValidation
{
    return FormFieldValidation::create([
        'form_version_id' => $owner->form_version_id,
        'form_field_id' => $owner->id,
        'related_form_field_id' => $related->id,
        'rule_type' => $type,
        'operator' => $operator,
        'rule_value' => $value,
        'sequence' => 0,
    ]);
}

/** An expression constraint row on $owner (`.` is the owner, `${k}` any other field). */
function conversionCensusConstraint(FormField $owner, string $expression): FormFieldValidation
{
    return FormFieldValidation::create([
        'form_version_id' => $owner->form_version_id,
        'form_field_id' => $owner->id,
        'expression' => $expression,
        'sequence' => 0,
    ]);
}

// ── (a) the answer stops or starts being a list ─────────────────────────────────────────────────────────

it('reports a contains rule when a multi-select becomes a single choice, because membership becomes a substring test', function (): void {
    $colours = addFormField($this->draft, $this->user, 'colours', FieldType::MultiSelect, 1);
    $why = addFormField($this->draft, $this->user, 'why_red', FieldType::ShortText, 2);
    conversionCensusRule($why, $colours, ValidationRuleType::RequiredIf, ComparisonOperator::Contains, 'red');

    expect(conversionCensusOf($this->census, $this->form, $colours, FieldType::SingleSelect))->toBe(['list_meaning_changes@rule:why_red'])
        // The same row reads the same way when the list becomes a hidden field's plain string.
        ->and(conversionCensusOf($this->census, $this->form, $colours, FieldType::Hidden))->toBe(['list_meaning_changes@rule:why_red']);
});

it('reports an equality against a choice when a single choice becomes a multi-select, because a list never equals one value', function (): void {
    $colour = addFormField($this->draft, $this->user, 'colour', FieldType::SingleSelect, 1);
    addFormField($this->draft, $this->user, 'why', FieldType::ShortText, 2, ['relevant_expression' => "\${colour} = 'red'"]);

    expect(conversionCensusOf($this->census, $this->form, $colour, FieldType::MultiSelect))->toBe(['list_meaning_changes@relevance:why']);
});

// ── (b) the answer stops being a number ─────────────────────────────────────────────────────────────────

it('reports every numeric use of a scale that becomes a choice — rule rows, both comparison sides, and a section', function (): void {
    $rating = addFormField($this->draft, $this->user, 'rating', FieldType::LikertScale, 1);
    $low = addFormField($this->draft, $this->user, 'low_reason', FieldType::ShortText, 2, ['relevant_expression' => '3 < ${rating}']);
    $target = addFormField($this->draft, $this->user, 'target', FieldType::Integer, 3);
    conversionCensusRule($low, $rating, ValidationRuleType::RequiredIf, ComparisonOperator::Gt, '3');
    // greater_than_field names the scale as its RELATED field and carries no operator at all.
    FormFieldValidation::create([
        'form_version_id' => $target->form_version_id, 'form_field_id' => $target->id,
        'related_form_field_id' => $rating->id, 'rule_type' => ValidationRuleType::GreaterThanField, 'sequence' => 0,
    ]);
    FormSection::create([
        'form_version_id' => $this->draft->id, 'key' => 'follow_up', 'label' => 'Follow up', 'sequence' => 1,
        'relevant_expression' => '${rating} > 3',
    ]);

    expect(conversionCensusOf($this->census, $this->form, $rating, FieldType::SingleSelect))->toBe([
        'numeric_use_on_non_number@relevance:low_reason',
        'numeric_use_on_non_number@rule:low_reason',
        'numeric_use_on_non_number@rule:target',
        'numeric_use_on_non_number@section_relevance:follow_up',
    ]);
});

it('reports arithmetic over a scale that becomes a multi-select, and the field own constraint', function (): void {
    $rating = addFormField($this->draft, $this->user, 'rating', FieldType::LikertScale, 1);
    addFormField($this->draft, $this->user, 'total', FieldType::Calculated, 2, ['config' => ['calculated_formula' => '${rating} + 1']]);
    // The engine KEEPS the field's own expression rows and defers to the expression gate, which never
    // checks ordering — so `. >= 2` over a list would refuse every answer and publish clean.
    conversionCensusConstraint($rating, '. >= 2');

    expect(conversionCensusOf($this->census, $this->form, $rating, FieldType::MultiSelect))->toBe([
        'numeric_use_on_non_number@constraint:rating',
        'numeric_use_on_non_number@formula:total',
    ]);
});

// ── (c) the question stops having an answer ─────────────────────────────────────────────────────────────

it('reports every condition, rule and relevance that names a question which becomes a note', function (): void {
    $age = addFormField($this->draft, $this->user, 'age', FieldType::Integer, 1);
    $a = addFormField($this->draft, $this->user, 'guardian', FieldType::ShortText, 2, ['relevant_expression' => '${age} < 18']);
    $b = addFormField($this->draft, $this->user, 'consent', FieldType::ShortText, 3);
    $c = addFormField($this->draft, $this->user, 'school', FieldType::ShortText, 4);
    $d = addFormField($this->draft, $this->user, 'retire_age', FieldType::Integer, 5);
    conversionCensusRule($b, $age, ValidationRuleType::RequiredIf, ComparisonOperator::Eq, '17');
    conversionCensusRule($c, $age, ValidationRuleType::RequiredWith); // null operator: "when age is answered"
    conversionCensusRule($d, $age, ValidationRuleType::GreaterThanField);

    expect(conversionCensusOf($this->census, $this->form, $age, FieldType::Note))->toBe([
        'reference_has_no_answer@relevance:guardian',
        'reference_has_no_answer@rule:consent',
        'reference_has_no_answer@rule:retire_age',
        'reference_has_no_answer@rule:school',
    ]);
});

it('reports a single-choice contains that becomes a note — a weaker problem on the source must not hide the break', function (): void {
    // `contains` on a single choice is already a substring test. A census that judged the source as a
    // whole would call this reference "already broken" and stay silent while the field lost its answer.
    $colour = addFormField($this->draft, $this->user, 'colour', FieldType::SingleSelect, 1);
    $why = addFormField($this->draft, $this->user, 'why', FieldType::ShortText, 2);
    conversionCensusRule($why, $colour, ValidationRuleType::RequiredIf, ComparisonOperator::Contains, 're');

    expect(conversionCensusOf($this->census, $this->form, $colour, FieldType::Note))->toBe(['reference_has_no_answer@rule:why']);
});

// ── (d) a `${key}` in text can no longer show the answer ────────────────────────────────────────────────

it('reports a template hole in a label, a translation and the confirmation message when the question becomes a note', function (): void {
    $name = addFormField($this->draft, $this->user, 'name', FieldType::ShortText, 1);
    addFormField($this->draft, $this->user, 'greeting', FieldType::Note, 2, ['label' => 'Hello ${name}']);
    addFormField($this->draft, $this->user, 'email', FieldType::Email, 3, ['hint_translations' => ['fil' => 'Para kay ${name}']]);
    $this->form->forceFill(['confirmation_message' => 'Thank you, ${name}.'])->save();

    expect(conversionCensusOf($this->census, $this->form, $name, FieldType::Note))->toBe([
        'template_hole_unanswerable@template:confirmation_message',
        'template_hole_unanswerable@template:email',
        'template_hole_unanswerable@template:greeting',
    ]);
});

// ── What it must NOT report ─────────────────────────────────────────────────────────────────────────────

it('stays silent on uses that mean the same thing across single and multiple choice', function (): void {
    $colour = addFormField($this->draft, $this->user, 'colour', FieldType::SingleSelect, 1);
    addFormField($this->draft, $this->user, 'picked', FieldType::ShortText, 2, ['relevant_expression' => "selected(\${colour}, 'red')"]);
    addFormField($this->draft, $this->user, 'answered', FieldType::ShortText, 3, ['relevant_expression' => "\${colour} != ''"]);

    expect(conversionCensusOf($this->census, $this->form, $colour, FieldType::MultiSelect))->toBe([])
        ->and(conversionCensusOf($this->census, $this->form, $colour, FieldType::Dropdown))->toBe([]);
});

it('does not blame the conversion for a use that was already wrong for the same reason', function (): void {
    // An ordered comparison against a single choice is already outside what that shape allows; turning the
    // choice into a dropdown changes nothing about it.
    $colour = addFormField($this->draft, $this->user, 'colour', FieldType::SingleSelect, 1);
    $why = addFormField($this->draft, $this->user, 'why', FieldType::ShortText, 2);
    conversionCensusRule($why, $colour, ValidationRuleType::RequiredIf, ComparisonOperator::Gt, '3');

    expect(conversionCensusOf($this->census, $this->form, $colour, FieldType::Dropdown))->toBe([]);
});

it('leaves the field own structured rows to the engine plan', function (): void {
    $age = addFormField($this->draft, $this->user, 'age', FieldType::Integer, 1);
    $floor = addFormField($this->draft, $this->user, 'floor', FieldType::Integer, 2);
    // `${age} > ${floor}` names `age`, but the row is age's OWN — the plan keeps or drops it.
    FormFieldValidation::create([
        'form_version_id' => $age->form_version_id, 'form_field_id' => $age->id,
        'related_form_field_id' => $floor->id, 'rule_type' => ValidationRuleType::GreaterThanField, 'sequence' => 0,
    ]);

    expect(conversionCensusOf($this->census, $this->form, $age, FieldType::Note))->toBe([]);
});

it('reads past an unparsable expression and a malformed template without throwing', function (): void {
    $name = addFormField($this->draft, $this->user, 'name', FieldType::ShortText, 1);
    addFormField($this->draft, $this->user, 'broken', FieldType::ShortText, 2, ['relevant_expression' => '${name} >']);
    addFormField($this->draft, $this->user, 'garbled', FieldType::ShortText, 3, ['label' => 'Hi ${name']);
    addFormField($this->draft, $this->user, 'fine', FieldType::ShortText, 4, ['relevant_expression' => "\${name} = 'x'"]);

    expect(conversionCensusOf($this->census, $this->form, $name, FieldType::Note))->toBe(['reference_has_no_answer@relevance:fine']);
});

it('reports nothing for a lossless conversion inside the text family', function (): void {
    $name = addFormField($this->draft, $this->user, 'name', FieldType::ShortText, 1, ['label' => 'Name']);
    addFormField($this->draft, $this->user, 'nick', FieldType::ShortText, 2, ['relevant_expression' => "\${name} = 'x'", 'label' => 'Hi ${name}']);

    expect(conversionCensusOf($this->census, $this->form, $name, FieldType::LongText))->toBe([]);
});
