<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
    $this->publisher = app(PublishService::class);
    $this->gate = app(ExpressionValidationGate::class);
});

it('passes a draft whose expressions and structured rules all resolve', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer);
    addFormField($draft, $this->user, 'note', FieldType::ShortText, 1, ['relevant_expression' => '${age} > 17']);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $draft->fields()->where('key', 'age')->value('id'),
        'rule_type' => ValidationRuleType::MinValue,
        'rule_value' => '0',
    ]);

    $this->gate->assertExpressionsResolve($draft->refresh());
    $published = $this->publisher->publish($form->refresh(), $this->user);

    expect($published->status)->toBe(FormVersionStatus::Published);
});

it('rejects a relevant_expression referencing an unknown field, naming the field', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'visible', FieldType::ShortText, 0, [
        'relevant_expression' => '${ghost} = \'1\'',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'visible');
});

it('rejects an unparseable constraint expression at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'expression' => '${age} =',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'age');
});

it('rejects an uncompilable pattern rule_value at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $code = addFormField($draft, $this->user, 'code', FieldType::ShortText);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $code->id,
        'rule_type' => ValidationRuleType::Pattern,
        'rule_value' => '(',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'invalid_pattern');
});

it('rejects a non-numeric min_value threshold at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'rule_type' => ValidationRuleType::MinValue,
        'rule_value' => 'eighteen',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'non_numeric_threshold');
});

it('publishes a calculated field whose grammar-v2.0 formula resolves (Increment G3)', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'a', FieldType::Integer, 0);
    addFormField($draft, $this->user, 'b', FieldType::Integer, 1);
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 2, [
        'config' => ['calculated_formula' => 'if(${a} > ${b}, ${a} + ${b}, 0)'],
    ]);

    $published = $this->publisher->publish($form->refresh(), $this->user);

    expect($published->status)->toBe(FormVersionStatus::Published);
});

it('rejects a calculated formula referencing an unknown field, naming the field (Increment G3)', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'total', FieldType::Calculated, 0, [
        'config' => ['calculated_formula' => '${a} + ${ghost}'],
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'total');
});

it('rejects an unparseable calculated formula at publish (Increment G3)', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'total', FieldType::Calculated, 0, [
        'config' => ['calculated_formula' => '${a} + '],
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class);
});

/*
|--------------------------------------------------------------------------
| M113 — the expression gate collects every violation instead of stopping at the first.
|
| ⚠️ THE ROW THAT FILED THIS WARNED ABOUT THE WRONG GATE, AND THE CORRECTION MATTERS.
| It said hoisting the gates into one collector risks "the expression gate meeting a field whose
| section belongs to a foreign version". This gate NEVER JOINS A FIELD TO ITS SECTION — it builds a
| flat `$knownKeys` union from field keys and section keys and never reads `form_section_id`, so that
| risk cannot reproduce here. It is `TemplateValidationGate` that does the join, and a miss there
| resolves to `$sectionSequence ?? -1`, a real-looking position that is silently wrong.
|
| The conclusion survives and is stronger than its stated reason: each gate collects INTERNALLY and
| throws once, so `PublishService` still runs the three in sequence and is not edited at all.
|
| ⚠️ GRANULARITY IS DELIBERATELY UNCHANGED WITHIN ONE EXPRESSION. `check()` still stops at the first
| offending key inside a single expression, exactly as `StructuralValidationGate::collect()` stops at
| the first bad entry inside one option list. What is collected is violations ACROSS owners.
*/

it('reports an unparseable expression on two different fields in one refusal', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer, 1, ['relevant_expression' => '${age} =']);
    addFormField($draft, $this->user, 'city', FieldType::ShortText, 2, ['relevant_expression' => '${city} =']);

    try {
        $this->publisher->publish($form->refresh(), $this->user);
        expect(false)->toBeTrue('the expression gate accepted two unparseable expressions');
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(2)
            ->and(array_column($e->violations(), 'field'))->toEqualCanonicalizing(['age', 'city']);
    }
});

it('reports a dangling relevance and a dangling constraint together', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer, 1, ['relevant_expression' => '${ghost_one} = 1']);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'expression' => '${ghost_two} = 1',
    ]);

    try {
        $this->publisher->publish($form->refresh(), $this->user);
        expect(false)->toBeTrue('the expression gate accepted two dangling references');
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(2);
    }
});

it('keeps a single expression violation byte-identical to the pre-collection message', function (): void {
    // The property that keeps every pre-existing wording assertion in this file green: `several()` JOINS
    // the parts' sentences rather than summarising them, so one violation yields exactly the old string.
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1, ['relevant_expression' => '${age} =']);

    try {
        $this->publisher->publish($form->refresh(), $this->user);
        expect(false)->toBeTrue('the expression gate accepted an unparseable expression');
    } catch (PublishValidationException $e) {
        expect($e->violations())->toHaveCount(1)
            ->and($e->getMessage())->toBe($e->violations()[0]['message']);
    }
});

/*
|--------------------------------------------------------------------------
| M122 — a calculated question with no formula is refused at publish (R-244d53dc).
|
| `check()` treats a blank expression as "no expression", which is right for relevance and constraints and
| wrong for a formula: a calculated question exists ONLY to compute, so a blank formula is a question that
| silently computes nothing in every submission. Three ways in, none refused before this: adding a Calculated
| question (its default config is empty), converting a note or hidden field to one (M121), and importing an
| XLSForm `calculate` row with no `calculation`. The trim matches the runtime's own skip
| (`SemanticValidator`), so what publish refuses is exactly what the runtime would have ignored.
|--------------------------------------------------------------------------
*/

it('refuses a calculated question with no formula, naming it', function (array $config): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 0, ['config' => $config]);

    try {
        $this->gate->assertExpressionsResolve($draft->refresh());
        $this->fail('a calculated question with no formula passed the gate');
    } catch (PublishValidationException $e) {
        expect(array_map(static fn (array $v): array => [$v['field'], $v['code']], $e->violations()))
            ->toBe([['total', 'calculated_formula_missing']]);
    }
})->with([
    'no formula key' => [[]],
    'null' => [['calculated_formula' => null]],
    'empty' => [['calculated_formula' => '']],
    'whitespace only' => [['calculated_formula' => "  \t "]],
    'not a string' => [['calculated_formula' => 42]],
]);

it('refuses the blank formula at publish alongside every other broken expression', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 0, ['config' => []]);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 1, ['relevant_expression' => '${ghost} = 1']);

    try {
        $this->publisher->publish($form->refresh(), $this->user);
        $this->fail('a draft with a blank formula published');
    } catch (PublishValidationException $e) {
        $blank = array_values(array_filter($e->violations(), static fn (array $v): bool => $v['code'] === 'calculated_formula_missing'));
        expect($e->violations())->toHaveCount(2)
            ->and(array_column($blank, 'field'))->toBe(['total']);
    }
});

it('leaves a formula with surrounding whitespace, and every non-calculated question, alone', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'a', FieldType::Integer, 0, ['config' => []]);
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 1, ['config' => ['calculated_formula' => '  ${a} + 1 ']]);
    addFormField($draft, $this->user, 'remark', FieldType::Note, 2, ['config' => []]);

    $this->gate->assertExpressionsResolve($draft->refresh());

    expect($this->publisher->publish($form->refresh(), $this->user)->status)->toBe(FormVersionStatus::Published);
});
