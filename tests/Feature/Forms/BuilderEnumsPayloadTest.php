<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Services\Validation\SemanticValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The builder's enum payload — Increment M115, and the FIRST assertion of its shape in this repository.
|--------------------------------------------------------------------------
| `BuilderPresenter::enums()` is how the builder learns what may be asserted about a field. Until now
| nothing checked it at all: no Pest test read the key, and the three Vitest fixtures that construct the
| TypeScript interface literally all ship `validation_rule_types: []` and `comparison_operators: []`.
|
| ⛔ THE EXPECTED SETS BELOW ARE WRITTEN OUT RATHER THAN DERIVED, AND THAT IS THE WHOLE POINT. The payload
| is built from `ValueShape::allows()` / `allowsOperator()`, so asserting it by calling those same methods
| would be a tautology that no mutation could redden — it would pass just as happily if the table were
| wrong. An independent census can fail. These sets are also the readable form of a 13×11 table, which is
| the reason `ValueShape` is keyed on a shape and not on 31 field types.
|
| ⚠️ A CHANGE HERE IS EITHER A DELIBERATE PRODUCT DECISION OR A BUG, NEVER A FIXTURE CHORE. Widening a rule
| to a shape whose evaluator fails CLOSED makes the field unanswerable — that is what `M113` measured and
| refused at publish, and `ValueShape`'s docblock records the two splits that came out of it.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $this->payload = app(BuilderPresenter::class)->present($form->refresh());
});

/** Every shape that can hold an answer — the conditional family's domain. */
function answerableShapes(): array
{
    return ['text', 'number', 'temporal', 'duration', 'choice', 'multiple_choice', 'scale', 'boolean', 'hierarchy', 'geo', 'attachment', 'grid'];
}

/**
 * @return array<string, array<string, mixed>>
 */
function ruleTypeOptions(array $payload): array
{
    $byValue = [];
    foreach ($payload['enums']['validation_rule_types'] as $option) {
        $byValue[$option['value']] = $option;
    }

    return $byValue;
}

it('ships every rule type with a label, its shapes and the three column facts', function (): void {
    $options = ruleTypeOptions($this->payload);

    expect($options)->toHaveCount(11);

    foreach (ValidationRuleType::cases() as $type) {
        $option = $options[$type->value] ?? null;

        expect($option)->not->toBeNull("the payload is missing {$type->value}");
        expect($option)->toHaveKeys(['value', 'label', 'shapes', 'takes_operator', 'takes_related_field', 'operator_may_be_empty']);
        expect($option['label'])->not->toBeEmpty()
            // The defect the row filed: a label DERIVED from the enum value. `humanize()` produced
            // "Min value" and "Required if" here, and `Gt` / `Lte` / `Neq` next door.
            ->and($option['label'])->not->toBe(ucfirst(str_replace('_', ' ', $type->value)));
        expect($option['shapes'])->toBeArray();
    }
});

it('offers the length and format rules to text and nothing else', function (): void {
    $options = ruleTypeOptions($this->payload);

    foreach (['min_length', 'max_length', 'pattern'] as $rule) {
        expect($options[$rule]['shapes'])->toEqualCanonicalizing(['text'], "{$rule} escaped the text shape");
    }
});

it('offers the value bounds only to the three shapes whose stored answer really is a number', function (): void {
    // ⛔ NOT TIDINESS. `min_value` on a date fails CLOSED — `Coercion::NUMERIC_RE` does not match
    // `2026-01-15`, so every non-empty answer is invalid and the field is unanswerable. `temporal` is
    // absent here for that measured reason, and `duration` is present because `XlsformTypeMap` maps it
    // to `decimal`: its stored answer is a number.
    $options = ruleTypeOptions($this->payload);

    expect($options['min_value']['shapes'])->toEqualCanonicalizing(['number', 'duration', 'scale'])
        ->and($options['max_value']['shapes'])->toEqualCanonicalizing(['number', 'duration', 'scale'])
        ->and($options['greater_than_field']['shapes'])->toEqualCanonicalizing(['number', 'duration'])
        ->and($options['less_than_field']['shapes'])->toEqualCanonicalizing(['number', 'duration']);
});

it('offers the conditional family to every shape that can hold an answer, and to no_answer never', function (): void {
    $options = ruleTypeOptions($this->payload);

    foreach (['required_if', 'required_with', 'skip_if', 'skip_with'] as $rule) {
        expect($options[$rule]['shapes'])->toEqualCanonicalizing(answerableShapes())
            ->and($options[$rule]['shapes'])->not->toContain('no_answer');
    }
});

it('marks exactly the four conditional rules as reading an operator, and the six that name a field', function (): void {
    $options = ruleTypeOptions($this->payload);

    $withOperator = array_keys(array_filter($options, static fn (array $o): bool => $o['takes_operator'] === true));
    $withRelated = array_keys(array_filter($options, static fn (array $o): bool => $o['takes_related_field'] === true));
    $emptyOk = array_keys(array_filter($options, static fn (array $o): bool => $o['operator_may_be_empty'] === true));

    expect($withOperator)->toEqualCanonicalizing(['required_if', 'required_with', 'skip_if', 'skip_with'])
        ->and($withRelated)->toEqualCanonicalizing([
            'required_if', 'required_with', 'skip_if', 'skip_with', 'greater_than_field', 'less_than_field',
        ])
        ->and($emptyOk)->toEqualCanonicalizing(['required_with', 'skip_with']);
});

it('marks exactly the two rules that can make a field required, which is NOT the conditional four', function (): void {
    // ⛔ THE SKIP PAIR IS THE WHOLE POINT OF THIS CENSUS. All four conditionals read an operator and name a
    // field, so every other flag on this payload groups them together — but `skip_if`/`skip_with` make a
    // field IRRELEVANT, never required. The builder's Basics reveal and the publish gate's
    // conditional-requiredness arm both partition on this flag, so a payload that lumped the four together
    // would put a skip rule under "Required when…" and would let a field whose only rule is a skip publish
    // as conditionally required — which is the silent-optional defect M116 closed.
    $options = ruleTypeOptions($this->payload);

    $governs = array_keys(array_filter($options, static fn (array $o): bool => $o['governs_requiredness'] === true));

    expect($governs)->toEqualCanonicalizing(['required_if', 'required_with']);
});

it('ships the related comparison of each rule exactly as the gate judges a compared question by it', function (): void {
    // M131 (`R-57711a3a`). The editor filters the compared-question list by this fact; publish refuses by
    // `ValidationRuleType::relatedComparison()`. Transmitted rather than mirrored, so the two cannot disagree.
    $options = ruleTypeOptions($this->payload);

    foreach (ValidationRuleType::cases() as $type) {
        expect($options[$type->value]['related_comparison'])
            ->toBe($type->relatedComparison(null)?->value, $type->value);

        // A rule that READS an operator compares with whatever operator it stores; every other rule's
        // comparison is constant, so the one transmitted value is the whole truth for it.
        foreach (ComparisonOperator::cases() as $stored) {
            if ($type->takesOperator()) {
                expect($type->relatedComparison($stored))->toBe($stored, "{$type->value} / {$stored->value}");
            } else {
                expect($type->relatedComparison($stored))->toBe($type->relatedComparison(null), "{$type->value} / {$stored->value}");
            }
        }
    }

    expect($options['greater_than_field']['related_comparison'])->toBe('gt')
        ->and($options['less_than_field']['related_comparison'])->toBe('lt')
        ->and($options['required_with']['related_comparison'])->toBe('is_null')
        ->and($options['required_if']['related_comparison'])->toBeNull()
        ->and($options['min_length']['related_comparison'])->toBeNull();
});

it('marks exactly the skip pair as governing relevance, and partitions the rules as the validator folds them', function (): void {
    // M131 (`R-799d60f5`). A rule group is folded WITHIN one family, so the all/any switch must know which family
    // a row is in. Held to `SemanticValidator::family()` itself, read by reflection, so the payload cannot drift
    // from the engine it describes.
    $options = ruleTypeOptions($this->payload);
    $family = new ReflectionMethod(SemanticValidator::class, 'family');
    $validator = app(SemanticValidator::class);

    $relevance = array_keys(array_filter($options, static fn (array $o): bool => $o['governs_relevance'] === true));
    expect($relevance)->toEqualCanonicalizing(['skip_if', 'skip_with']);

    foreach (ValidationRuleType::cases() as $type) {
        $option = $options[$type->value];
        $fromPayload = $option['governs_requiredness'] ? 'required' : ($option['governs_relevance'] ? 'skip' : 'constraint');

        expect($family->invoke($validator, new FormFieldValidation(['rule_type' => $type])))->toBe($fromPayload, $type->value);
    }
});

it('ships every operator with the row rendering and the shapes it may compare', function (): void {
    $byValue = [];
    foreach ($this->payload['enums']['comparison_operators'] as $option) {
        $byValue[$option['value']] = $option;
    }

    expect($byValue)->toHaveCount(8);

    foreach (ComparisonOperator::cases() as $operator) {
        expect($byValue[$operator->value])->toHaveKeys(['value', 'label', 'shapes']);
        // The SENTENCE rendering is deliberately absent: nothing on the client reads it, so transmitting it
        // would be decorative. What holds it to the two client copies is ConditionLabelMirrorDriftTest.
        expect($byValue[$operator->value])->not->toHaveKey('sentence_label');
        expect($byValue[$operator->value]['label'])->not->toBeEmpty()
            // `Gt`, `Lte` and `Neq` by name — the three the row was filed about.
            ->and($byValue[$operator->value]['label'])->not->toBe(ucfirst(str_replace('_', ' ', $operator->value)));
    }

    // The symbol rides INSIDE the label, so no caller can render one without the words (`D59`).
    expect($byValue['lte']['label'])->toContain('≤')
        ->and($byValue['gte']['label'])->toContain('≥')
        ->and($byValue['neq']['label'])->toContain('≠')
        ->and($byValue['lte']['label'])->toContain('at most');

    // Ordered comparison carries the same fail-closed restriction as the value bounds; emptiness applies wherever
    // there is an answer; equality only where the answer is ONE value (M134 — a list or an object never equals one);
    // `contains` is meaningful only where the answer is text or a list.
    expect($byValue['gt']['shapes'])->toEqualCanonicalizing(['number', 'duration', 'scale'])
        ->and($byValue['lte']['shapes'])->toEqualCanonicalizing(['number', 'duration', 'scale'])
        ->and($byValue['eq']['shapes'])->toEqualCanonicalizing(['text', 'number', 'temporal', 'duration', 'choice', 'scale', 'boolean'])
        ->and($byValue['neq']['shapes'])->toEqualCanonicalizing(['text', 'number', 'temporal', 'duration', 'choice', 'scale', 'boolean'])
        ->and($byValue['is_null']['shapes'])->toEqualCanonicalizing(answerableShapes())
        ->and($byValue['contains']['shapes'])->toEqualCanonicalizing(['text', 'choice', 'multiple_choice', 'hierarchy']);
});

it('gives every palette entry its value shape, so the panel can filter without a second table', function (): void {
    $shapeByType = [];
    foreach ($this->payload['palette'] as $group) {
        foreach ($group['types'] as $type) {
            $shapeByType[$type['value']] = $type['value_shape'];
        }
    }

    expect($shapeByType)->toHaveCount(count(FieldType::cases()));

    // Spot-pinned at the boundaries that were measured rather than assumed: `yes_no` is Boolean and not
    // Choice (its options are fixed and stored nowhere), `duration` is split from the temporal types, and
    // `note`/`page_break` carry no answer at all — which is what removes the Validation tab for them.
    expect($shapeByType['short_text'])->toBe('text')
        ->and($shapeByType['hidden'])->toBe('text')
        ->and($shapeByType['integer'])->toBe('number')
        ->and($shapeByType['date'])->toBe('temporal')
        ->and($shapeByType['duration'])->toBe('duration')
        ->and($shapeByType['yes_no'])->toBe('boolean')
        ->and($shapeByType['likert_scale'])->toBe('scale')
        ->and($shapeByType['single_select'])->toBe('choice')
        ->and($shapeByType['multi_select'])->toBe('multiple_choice')
        ->and($shapeByType['cascading_select'])->toBe('hierarchy')
        ->and($shapeByType['note'])->toBe('no_answer')
        ->and($shapeByType['page_break'])->toBe('no_answer');

    // Anti-vacuity: every value is a real shape, so a typo cannot pass as a filter nobody matches.
    foreach ($shapeByType as $fieldType => $shape) {
        expect(ValueShape::tryFrom($shape))->not->toBeNull("{$fieldType} carries an unknown shape {$shape}");
    }
});

it('marks the four palette variants, one primary per group, and nothing else', function (): void {
    // M125 (R-a367bf9e). Written out rather than derived from FieldVariantGroup, for the reason the file header
    // gives: a census computed by the code under test cannot fail.
    $variantByType = [];
    foreach ($this->payload['palette'] as $group) {
        foreach ($group['types'] as $type) {
            $variantByType[$type['value']] = $type['variant'];
        }
    }

    // Additive: the entries are still all 31, so the three client maps that read them lose nothing.
    expect($variantByType)->toHaveCount(count(FieldType::cases()))
        ->and($variantByType['short_text'])->toBe(['group' => 'text', 'label' => 'Text', 'primary' => true])
        ->and($variantByType['long_text'])->toBe(['group' => 'text', 'label' => 'Text', 'primary' => false])
        ->and($variantByType['integer'])->toBe(['group' => 'number', 'label' => 'Number', 'primary' => true])
        ->and($variantByType['decimal'])->toBe(['group' => 'number', 'label' => 'Number', 'primary' => false]);

    $grouped = array_filter($variantByType, fn (?array $variant): bool => $variant !== null);
    expect(array_keys($grouped))->toEqualCanonicalizing(['short_text', 'long_text', 'integer', 'decimal']);
});

it('gives every palette entry the layouts an author may choose, and only the two list types any', function (): void {
    // M130 (R-6c76bed2). Written out, for the same reason as above: the list is what the Options tab offers, and a
    // census computed by FieldAppearance::for() itself could not fail.
    $layoutsByType = [];
    foreach ($this->payload['palette'] as $group) {
        foreach ($group['types'] as $type) {
            $layoutsByType[$type['value']] = $type['appearances'];
        }
    }

    $offered = [['value' => 'columns-pack', 'label' => 'Side by side'], ['value' => 'columns', 'label' => 'In columns']];

    expect($layoutsByType)->toHaveCount(count(FieldType::cases()))
        ->and($layoutsByType['single_select'])->toBe($offered)
        ->and($layoutsByType['multi_select'])->toBe($offered)
        // Dropdown is the one that matters most: `minimal` is forced on its export, so a layout here would be lost.
        ->and($layoutsByType['dropdown'])->toBe([]);

    $withLayouts = array_filter($layoutsByType, fn (array $layouts): bool => $layouts !== []);
    expect(array_keys($withLayouts))->toEqualCanonicalizing(['single_select', 'multi_select']);
});

it('gives every palette entry the kind an expression reads it as, written out type by type (M134)', function (): void {
    $kindByType = [];
    foreach ($this->payload['palette'] as $group) {
        foreach ($group['types'] as $type) {
            $kindByType[$type['value']] = $type['operand_kind'];
        }
    }

    // Transcribed independently of OperandKind::for(), so this census can fail.
    expect($kindByType)->toEqualCanonicalizing([
        'short_text' => 'value', 'long_text' => 'value', 'email' => 'value', 'phone' => 'value', 'url' => 'value',
        'hidden' => 'value', 'single_select' => 'value', 'dropdown' => 'value',
        'integer' => 'number', 'decimal' => 'number', 'duration' => 'number', 'likert_scale' => 'number',
        'calculated' => 'computed',
        'date' => 'date', 'time' => 'time', 'datetime' => 'datetime',
        'yes_no' => 'boolean',
        'multi_select' => 'list', 'cascading_select' => 'list',
        'file_upload' => 'attachment', 'image_capture' => 'attachment', 'audio_capture' => 'attachment',
        'video_capture' => 'attachment', 'signature' => 'attachment',
        'matrix' => 'object', 'likert_matrix' => 'object', 'geopoint' => 'object', 'geotrace' => 'object', 'geoshape' => 'object',
        'note' => 'none', 'page_break' => 'none',
    ]);
});

it('transmits what a condition may compare each kind with, written out row by row (M134)', function (): void {
    $row = static fn (string $value, bool $offered, bool $orders, bool $equals, bool $includes, array $ordersWith, ?string $literal): array => [
        'value' => $value, 'offered' => $offered, 'orders' => $orders, 'equals' => $equals,
        'includes' => $includes, 'orders_with' => $ordersWith, 'literal_input' => $literal,
    ];
    $numbers = ['number', 'computed', 'value'];

    expect($this->payload['enums']['operand_kinds'])->toBe([
        $row('number', true, true, true, false, $numbers, null),
        $row('computed', true, true, true, false, $numbers, null),
        $row('date', true, true, true, false, ['date', 'datetime'], 'date'),
        $row('time', true, true, true, false, ['time'], 'time'),
        $row('datetime', true, true, true, false, ['date', 'datetime'], 'datetime-local'),
        $row('value', true, true, true, true, $numbers, null),
        $row('boolean', true, false, true, true, [], null),
        $row('list', true, false, false, true, [], null),
        $row('attachment', true, false, false, false, [], null),
        $row('object', false, false, false, false, [], null),
        $row('none', false, false, false, false, [], null),
    ]);
});
