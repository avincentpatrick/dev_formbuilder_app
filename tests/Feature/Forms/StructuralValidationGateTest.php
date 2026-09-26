<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\StructuralValidationGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->gate = new StructuralValidationGate;
});

it('passes a structurally valid draft', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'name');
    addFormField($version, $this->user, 'age', FieldType::Integer, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Number,
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});

it('rejects a queryable field with no indexed data type, naming the field', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'age', FieldType::Integer, 0, ['is_queryable' => true]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'age');
});

it('rejects a field whose section belongs to a different version', function (): void {
    // A section that lives on ANOTHER version …
    $otherVersion = makeDraftVersion(makeForm($this->user));
    $foreignSection = FormSection::create([
        'form_version_id' => $otherVersion->id,
        'key' => 'demographics',
        'label' => 'Demographics',
    ]);

    // … referenced by a field on THIS version.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'name', FieldType::ShortText, 0, [
        'form_section_id' => $foreignSection->id,
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'name');
});

// ── Hidden fields (Increment H7) ────────────────────────────────────────────────────────────────────
// The governing rule: a hidden field must be incapable of producing an error a respondent can never repair.
// Both engines already decline to evaluate one (golden/validation/hidden.json), so shipping such a form
// would fail SILENTLY rather than loudly — which is exactly why the author is told at publish instead.
// Every case passes a DISTINCT sequence: addFormField() defaults it to 0 and a positional tie is its own
// rejection elsewhere in the publish path, so a shared 0 would fail these for the wrong reason.

it('publishes a hidden field sourced from a fixed value', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'campaign', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'fixed'],
        'default_value' => 'newsletter',
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue();
});

it('publishes a hidden field sourced from the link, with and without an explicit parameter name', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url', 'url_param' => 'promo-code'],
    ]);
    addFormField($version, $this->user, 'referrer', FieldType::Hidden, 1, [
        'config' => ['prefill_source' => 'url'], // falls back to the field key
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue();
});

it('refuses a required hidden field, naming the field and the slug', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url'],
        'is_required' => RequiredMode::Required,
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'hidden_field_required');
});

it('refuses a conditionally-required hidden field too', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url'],
        'is_required' => RequiredMode::Conditional,
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'hidden_field_required');
});

it('refuses a hidden field carrying a validation rule', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $field = addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url'],
    ]);
    FormFieldValidation::create([
        // `form_version_id` is not optional here: `form_field_validations` carries the draft-child RLS
        // shape, so an insert without it violates the row-level policy rather than merely being untidy.
        'form_version_id' => $version->id,
        'form_field_id' => $field->id,
        'rule_type' => ValidationRuleType::Pattern,
        'rule_value' => '^[0-9]+$',
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'hidden_field_has_validations');
});

it('refuses a hidden field inside a repeatable section', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $section = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'household',
        'label' => 'Household',
        'is_repeatable' => true,
    ]);
    addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url'],
        'form_section_id' => $section->id,
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'promo');
});

it('refuses a link-sourced hidden field whose parameter name is unusable', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'promo', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'url', 'url_param' => 'not a param'],
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'prefill_param_invalid');
});

it('does not apply the parameter-name rule to a fixed-source hidden field', function (): void {
    // A stale `url_param` left behind after the author switched the source to `fixed` is inert, not a
    // publish blocker — the config panel writes both keys and only one of them is ever read.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'campaign', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'fixed', 'url_param' => 'not a param'],
        'default_value' => 'newsletter',
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue();
});

it('leaves a required NON-hidden field alone', function (): void {
    // The rule is scoped to `hidden`; a required short_text beside one still publishes.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'campaign', FieldType::Hidden, 0, [
        'config' => ['prefill_source' => 'fixed'],
        'default_value' => 'newsletter',
    ]);
    addFormField($version, $this->user, 'name', FieldType::ShortText, 1, [
        'is_required' => RequiredMode::Required,
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue();
});

// ── M112: the gate covers every option-bearing type, and reports ALL violations ─────────────────

it('refuses each of the three select types that used to publish with no options', function (string $type): void {
    // ⛔ THE REPORTED DEFECT. The options check ran for `likert_scale` ONLY, so these three published a
    // form whose control renders empty to every respondent, while `cascading_select` and the two grids
    // refused — which is exactly the "some input types are not working" partition the row was filed from.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'colour', FieldType::from($type));

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'colour');
})->with(['single_select', 'multi_select', 'dropdown']);

it('still refuses a likert_scale with no options, which is the arm that already worked', function (): void {
    // The negative control for the widening: the pre-existing coverage must not have been traded away.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'satisfaction', FieldType::LikertScale);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'satisfaction');
});

it('still publishes a yes_no field, whose two options are fixed and stored nowhere', function (): void {
    // ⛔ THE CATASTROPHIC-REGRESSION SENTINEL. `yes_no` is choice-SHAPED but carries no author option
    // list, so classifying it as `ValueShape::Choice` would make EVERY yes/no field in the product
    // unpublishable. The totality assertions in ValueShapeTest pass either way, which is why this one is
    // written here, against the gate that would actually do the refusing.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'consented', FieldType::YesNo);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});

it('reports EVERY violation in one refusal, naming each field', function (): void {
    // Three independent defects across three fields. Before M112 the author learned about one of them
    // per publish attempt; the whole point of the row is that they now learn about all three at once.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'colour', FieldType::Dropdown);
    addFormField($version, $this->user, 'age', FieldType::Integer, 1, ['is_queryable' => true]);
    addFormField($version, $this->user, 'satisfaction', FieldType::LikertScale, 2);

    try {
        $this->gate->assertPublishable($version->refresh());
        $this->fail('expected the gate to refuse this draft');
    } catch (PublishValidationException $e) {
        // ⚠️ str_contains(...)->toBeTrue(), never toContain($needle, $message): Pest reads toContain's
        // second argument as a SECOND NEEDLE, so a case written that way fails on its own explanatory
        // prose and reports a reason that is not the reason (measured in M109).
        $message = $e->getMessage();

        expect(str_contains($message, 'colour'))->toBeTrue("the message should name «colour»: {$message}")
            ->and(str_contains($message, 'age'))->toBeTrue("the message should name «age»: {$message}")
            ->and(str_contains($message, 'satisfaction'))->toBeTrue("the message should name «satisfaction»: {$message}");

        expect($e->violations())->toHaveCount(3);
        expect(array_column($e->violations(), 'field'))
            ->toEqualCanonicalizing(['colour', 'age', 'satisfaction']);
        expect(array_column($e->violations(), 'code'))
            ->toEqualCanonicalizing(['choice_options_invalid', 'queryable_field_missing_type', 'choice_options_invalid']);
    }
});

it('leaves a single violation byte-identical to its own sentence', function (): void {
    // ⛔ THIS IS THE PROPERTY THAT KEEPS ~27 PRE-EXISTING ASSERTIONS GREEN, and it is asserted rather
    // than hoped for. `several()` joins the parts' sentences, so one part joins to itself — which is why
    // every `toThrow(..., '<key or slug>')` written before M112 still matches. A `summarize()`-style
    // message ("N fields failed structural validation.") would drop every key and redden all of them.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'age', FieldType::Integer, 0, ['is_queryable' => true]);

    try {
        $this->gate->assertPublishable($version->refresh());
        $this->fail('expected the gate to refuse this draft');
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(1)
            ->and($e->getMessage())->toBe($e->violations()[0]['message'])
            ->and($e->violations()[0]['code'])->toBe('queryable_field_missing_type')
            ->and($e->violations()[0]['field'])->toBe('age');
    }
});

it('carries a stable code that is not the prose, so a consumer never matches on wording', function (): void {
    // The four factories that used to carry free prose as their only detail gained a slug in M112; the
    // prose stays in `message` untouched. Message and structure are therefore gated independently, which
    // is what lets one mutation prove one of them without the other.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'colour', FieldType::SingleSelect);

    try {
        $this->gate->assertPublishable($version->refresh());
        $this->fail('expected the gate to refuse this draft');
    } catch (PublishValidationException $e) {
        expect($e->violations()[0]['code'])->toBe('choice_options_invalid')
            ->and($e->violations()[0]['message'])->toContain('no options defined');
    }
});

/*
|--------------------------------------------------------------------------
| M113 — a validation rule the field's value shape cannot satisfy is refused at publish.
|
| ⛔ THESE RULES DO NOT MERELY FAIL TO CONSTRAIN — THEY FAIL CLOSED, IN BOTH ENGINES.
| `Coercion::NUMERIC_RE` is `/^-?[0-9]+(\.[0-9]+)?$/`, so `2026-01-15` is not numeric-like.
| `StructuredRuleEvaluator`'s `MinValue` arm reads `isEmpty($answer) || (isNumericLike($answer) && …)`
| and `ExpressionEvaluator`'s ordered comparison returns `false` on a NaN operand — so every non-empty
| answer becomes INVALID and the field is unanswerable, with nothing telling the respondent why.
| `coercion.ts` and `evaluator.ts` are byte-identical on both points, so this is a correctness defect
| rather than a parity one.
|
| ⚠️ `min_value` WITH A DATE-SHAPED THRESHOLD WAS ALREADY REFUSED, by `ExpressionValidationGate`'s
| `non_numeric_threshold` arm. What had NO check anywhere is `greater_than_field` — `end_date >
| start_date`, the most natural temporal rule an author would ever write — which published clean.
| That asymmetry is why the numeric-threshold case below is pinned separately from the field one.
|
| ⛔ THIS IS `ValueShape::allows()`'s FIRST PRODUCTION CALLER. `M112` shipped the table and wired it
| to nothing, so the row's "the authoring half is closed" was an overstatement measured here.
*/

it('rejects greater_than_field between two date fields, naming the field and a stable slug', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $start = addFormField($version, $this->user, 'start_date', FieldType::Date, 0);
    $end = addFormField($version, $this->user, 'end_date', FieldType::Date, 1);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $end->id,
        'related_form_field_id' => $start->id,
        'rule_type' => ValidationRuleType::GreaterThanField,
    ]);

    // ⚠️ The MESSAGE is author-facing prose and deliberately carries no snake_case slug: unlike the
    // older factories in this file, `M113` puts `violations()` on the Inertia wire too, so the stable
    // `code` travels structurally and does not have to be smuggled into the sentence. The slug is
    // asserted below, on the field it belongs to.
    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'greater_than_field');

    try {
        $this->gate->assertPublishable($version->refresh());
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(1)
            ->and($e->violations()[0]['field'])->toBe('end_date')
            ->and($e->violations()[0]['code'])->toBe('rule_not_allowed_for_shape')
            ->and($e->violations()[0]['message'])->toContain('greater_than_field');
    }
});

it('rejects min_value on a date field even when the threshold is numeric', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $visit = addFormField($version, $this->user, 'visit_date', FieldType::Date, 0);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $visit->id,
        'rule_type' => ValidationRuleType::MinValue,
        'rule_value' => '0',
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'visit_date');
});

it('still allows the same ordered rule between two number fields', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $low = addFormField($version, $this->user, 'low', FieldType::Integer, 0);
    $high = addFormField($version, $this->user, 'high', FieldType::Integer, 1);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $high->id,
        'related_form_field_id' => $low->id,
        'rule_type' => ValidationRuleType::GreaterThanField,
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});

it('still allows a conditional rule on a date field, because conditions apply to every shape', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $consent = addFormField($version, $this->user, 'consent', FieldType::YesNo, 0);
    $visit = addFormField($version, $this->user, 'visit_date', FieldType::Date, 1);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $visit->id,
        'related_form_field_id' => $consent->id,
        'rule_type' => ValidationRuleType::RequiredIf,
        'rule_value' => 'yes',
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});

it('reports a shape violation for every offending rule, not just the first', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    $start = addFormField($version, $this->user, 'start_date', FieldType::Date, 0);
    $end = addFormField($version, $this->user, 'end_date', FieldType::Date, 1);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $end->id,
        'related_form_field_id' => $start->id,
        'rule_type' => ValidationRuleType::GreaterThanField,
    ]);
    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $start->id,
        'rule_type' => ValidationRuleType::MaxValue,
        'rule_value' => '100',
    ]);

    try {
        $this->gate->assertPublishable($version->refresh());
        expect(false)->toBeTrue('the gate accepted two unsatisfiable rules');
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(2)
            ->and(array_column($e->violations(), 'field'))->toEqualCanonicalizing(['end_date', 'start_date']);
    }
});

it('leaves an expression-only validation row alone, because it carries no rule type at all', function (): void {
    // ⛔ REGRESSION PIN. `form_field_validations` has a DB CHECK enforcing `expression` XOR `rule_type`,
    // so every raw-expression rule has a NULL `rule_type`. The first draft of the shape check above passed
    // that null straight into `ValueShape::allows()`, whose parameter is not nullable — which escaped this
    // gate as a TypeError rather than a refusal and reddened `ExpressionValidationGateTest`. Whether an
    // expression suits its field is the EXPRESSION gate's question; this gate must not answer it.
    $version = makeDraftVersion(makeForm($this->user));
    $visit = addFormField($version, $this->user, 'visit_date', FieldType::Date, 0);

    FormFieldValidation::create([
        'form_version_id' => $version->id,
        'form_field_id' => $visit->id,
        'expression' => '${visit_date} != ""',
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});
