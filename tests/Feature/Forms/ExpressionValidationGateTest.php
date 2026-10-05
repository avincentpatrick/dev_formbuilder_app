<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
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

/*
|--------------------------------------------------------------------------
| M126 — an expression that reads a question as a NUMBER is refused when that question's answers never are one
| (`R-87160c81`, sub-claim 2). M134 — a date or a time is judged against what it is compared WITH (`R-62b638e1`).
|
| An operand that is not numeric-like makes `> < >= <=` false and arithmetic NaN. So `${remark} > 3` on a note and
| `${hobbies} > 2` on a multi-select published clean and then never changed with the answer — in a constraint,
| every answer was refused. Refused only where the TYPE guarantees it: text, hidden, calculated and single choice
| can hold numeric strings and stay allowed. Since M134 both engines ORDER dates and times chronologically, so a
| date orders against a date, a date-time, `today()` or a date literal, and a time against a time; arithmetic,
| `now()` (`D89`) and every pair of different kinds stay refused, each by name.
|--------------------------------------------------------------------------
*/

/** The [field, code] pairs the gate refuses the draft with, or [] when it passes. */
function orderingGateRefusals(ExpressionValidationGate $gate, FormVersion $draft): array
{
    try {
        $gate->assertExpressionsResolve($draft->refresh());

        return [];
    } catch (PublishValidationException $e) {
        return array_map(static fn (array $v): array => [$v['field'], $v['code']], $e->violations());
    }
}

/** The refusal messages the gate gives the draft, or [] when it passes. */
function orderingGateMessages(ExpressionValidationGate $gate, FormVersion $draft): array
{
    try {
        $gate->assertExpressionsResolve($draft->refresh());

        return [];
    } catch (PublishValidationException $e) {
        return array_column($e->violations(), 'message');
    }
}

it('refuses a relevance whose comparison can never change with the answer, naming the question it governs', function (FieldType $type, string $expression, string $code): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'subject', $type);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 1, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([['shown', $code]]);
})->with([
    'a note' => [FieldType::Note, '${subject} > 3', 'expression_orders_non_number'],
    'a page break' => [FieldType::PageBreak, '${subject} >= 1', 'expression_orders_non_number'],
    'a yes/no' => [FieldType::YesNo, '${subject} > 0', 'expression_orders_non_number'],
    'a multi-select' => [FieldType::MultiSelect, '${subject} > 2', 'expression_orders_non_number'],
    'a cascading select' => [FieldType::CascadingSelect, '${subject} < 1', 'expression_orders_non_number'],
    'a photo' => [FieldType::ImageCapture, '${subject} > 0', 'expression_orders_non_number'],
    'a date through arithmetic' => [FieldType::Date, '${subject} + 1 > 3', 'expression_orders_date'],
    'a date through int()' => [FieldType::Date, 'int(${subject}) > 3', 'expression_orders_date'],
    'a date against now()' => [FieldType::Date, '${subject} < now()', 'expression_orders_date'],
    'a time against now()' => [FieldType::Time, '${subject} > now()', 'expression_orders_date'],
    'a date-time against now()' => [FieldType::Datetime, '${subject} >= now()', 'expression_orders_date'],
    'a date against a value with a time zone' => [FieldType::Date, "\${subject} > '2026-01-01T00:00Z'", 'expression_orders_date'],
    'a date against a number' => [FieldType::Date, '${subject} > 5', 'expression_orders_temporal_mismatch'],
    'a date against a number on the left' => [FieldType::Date, '5 < ${subject}', 'expression_orders_temporal_mismatch'],
    'a date against text that is not a date' => [FieldType::Date, "\${subject} > '01/02/2026'", 'expression_orders_temporal_mismatch'],
    'a date against a date that does not exist' => [FieldType::Date, "\${subject} > '2026-02-30'", 'expression_orders_temporal_mismatch'],
    'a date against a time' => [FieldType::Date, "\${subject} > '09:00'", 'expression_orders_temporal_mismatch'],
    'a date against int()' => [FieldType::Date, '${subject} > int(5)', 'expression_orders_temporal_mismatch'],
    'a date against a calculation' => [FieldType::Date, '${subject} > 1 + 1', 'expression_orders_temporal_mismatch'],
    'a time against today()' => [FieldType::Time, '${subject} <= today()', 'expression_orders_temporal_mismatch'],
    'a time against a date' => [FieldType::Time, "\${subject} > '2026-01-01'", 'expression_orders_temporal_mismatch'],
]);

it('accepts a date constraint written against the question itself, as ODK writes it, and refuses one against now()', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $dob = addFormField($draft, $this->user, 'dob', FieldType::Date);
    $seen = addFormField($draft, $this->user, 'seen', FieldType::Datetime, 1);
    FormFieldValidation::create(['form_version_id' => $draft->id, 'form_field_id' => $dob->id, 'expression' => '. <= today()']);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([]);

    FormFieldValidation::create(['form_version_id' => $draft->id, 'form_field_id' => $seen->id, 'expression' => '. <= now()']);

    try {
        $this->gate->assertExpressionsResolve($draft->refresh());
        $this->fail('a date-time ordered against now() passed the gate');
    } catch (PublishValidationException $e) {
        expect(array_map(static fn (array $v): array => [$v['field'], $v['code']], $e->violations()))->toBe([['seen', 'expression_orders_date']])
            ->and($e->getMessage())->toContain('not supported yet')
            ->and($e->getMessage())->toContain('time zone')
            ->and($e->getMessage())->toContain('today()')
            ->and($e->getMessage())->toContain('“seen”');
    }
});

it('names what a date was compared with, and what it can be compared with instead', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'dob', FieldType::Date);
    addFormField($draft, $this->user, 'opens', FieldType::Time, 1);
    addFormField($draft, $this->user, 'a', FieldType::ShortText, 2, ['relevant_expression' => "\${dob} > '01/02/2026'"]);
    addFormField($draft, $this->user, 'b', FieldType::ShortText, 3, ['relevant_expression' => '${opens} > 9']);

    expect(orderingGateMessages($this->gate, $draft))->toBe([
        'The expression on “a” compares the '.FieldType::Date->label().' question “dob” with the text “01/02/2026”, which it can never be ordered against, so the comparison never holds. Compare it with another date question, a date typed as YYYY-MM-DD, or today().',
        'The expression on “b” compares the '.FieldType::Time->label().' question “opens” with the number 9, which it can never be ordered against, so the comparison never holds. Compare it with another time question or a time typed as HH:MM.',
    ]);
});

it('reports two date or time questions of different kinds once, from either side of the comparison', function (string $expression): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'dob', FieldType::Date);
    addFormField($draft, $this->user, 'opens', FieldType::Time, 1);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 2, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([['shown', 'expression_orders_temporal_mismatch']]);
})->with([
    'date first' => ['${dob} > ${opens}'],
    'time first' => ['${opens} < ${dob}'],
]);

it('refuses a date ordered against a number question, once, from either side', function (string $expression): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'dob', FieldType::Date);
    addFormField($draft, $this->user, 'age', FieldType::Integer, 1);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 2, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([['shown', 'expression_orders_temporal_mismatch']]);
})->with([
    'date first' => ['${dob} > ${age}'],
    'number first' => ['${age} < ${dob}'],
]);

it('refuses the same uses in a section condition, a calculated formula and a constraint', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'consent', FieldType::YesNo);
    addFormField($draft, $this->user, 'dob', FieldType::Date, 1);
    addFormField($draft, $this->user, 'age_next_year', FieldType::Calculated, 2, ['config' => ['calculated_formula' => '${dob} + 1']]);
    addFormField($draft, $this->user, 'overdue', FieldType::Calculated, 3, ['config' => ['calculated_formula' => 'if(${dob} < now(), 1, 0)']]);
    $opens = addFormField($draft, $this->user, 'opens', FieldType::Time, 4);
    FormFieldValidation::create(['form_version_id' => $draft->id, 'form_field_id' => $opens->id, 'expression' => '. >= ${dob}']);
    $section = $draft->sections()->create(['key' => 'followup', 'label' => 'Follow-up', 'sequence' => 1, 'relevant_expression' => '${consent} > 0']);
    $later = $draft->sections()->create(['key' => 'later', 'label' => 'Later', 'sequence' => 2, 'relevant_expression' => '${dob} > 5']);

    expect(orderingGateRefusals($this->gate, $draft))->toEqualCanonicalizing([
        ['age_next_year', 'expression_orders_date'],
        ['overdue', 'expression_orders_date'],
        ['opens', 'expression_orders_temporal_mismatch'],
        [$section->key, 'expression_orders_non_number'],
        [$later->key, 'expression_orders_temporal_mismatch'],
    ]);
});

it('leaves every use that can hold, and every question that can hold a number or a date, alone', function (string $expression): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer);
    addFormField($draft, $this->user, 'name', FieldType::ShortText, 1);
    addFormField($draft, $this->user, 'choice', FieldType::SingleSelect, 2);
    addFormField($draft, $this->user, 'hobbies', FieldType::MultiSelect, 3);
    addFormField($draft, $this->user, 'dob', FieldType::Date, 4);
    addFormField($draft, $this->user, 'consent', FieldType::YesNo, 5);
    addFormField($draft, $this->user, 'took', FieldType::Duration, 6);
    addFormField($draft, $this->user, 'secret', FieldType::Hidden, 7);
    addFormField($draft, $this->user, 'until', FieldType::Date, 8);
    addFormField($draft, $this->user, 'visit', FieldType::Datetime, 9);
    addFormField($draft, $this->user, 'opens', FieldType::Time, 10);
    addFormField($draft, $this->user, 'total', FieldType::Calculated, 11, ['config' => ['calculated_formula' => '1']]);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 12, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([]);
})->with([
    'a number' => ['${age} > 18'],
    'text that may hold a number' => ['${name} > 3'],
    'a single choice with numeric codes' => ['${choice} > 2'],
    'a hidden prefill' => ['${secret} > 1'],
    'a duration' => ['${took} > 60'],
    'a count of a list' => ['count(${hobbies}) > 2'],
    'a date compared for equality' => ['${dob} = today()'],
    'a date tested for an answer' => ["\${dob} != ''"],
    'a membership test' => ["selected(\${hobbies}, 'a')"],
    'a yes/no compared for equality' => ["\${consent} = 'yes'"],
    'a date against today()' => ['${dob} <= today()'],
    'a date against a date' => ["\${dob} > '2020-01-01'"],
    'a date on the right' => ["'2020-01-01' < \${dob}"],
    'a date against another date question' => ['${dob} < ${until}'],
    'a date against a date-time question' => ['${dob} < ${visit}'],
    'a date-time against today()' => ['${visit} >= today()'],
    'a date-time against a date-time without a time zone' => ["\${visit} > '2026-01-01T08:00'"],
    'a time against a time' => ["\${opens} > '09:00'"],
    'a time against a time with seconds' => ["\${opens} <= '17:30:00'"],
    'a date against text that may hold a date' => ['${dob} > ${name}'],
    'a date against a hidden prefill' => ['${dob} > ${secret}'],
    'a date against a calculation question' => ['${dob} > ${total}'],
    'a date against if()' => ["\${dob} > if(\${consent} = 'yes', '2020-01-01', '2021-01-01')"],
]);

it('reports a grid reference and an unparseable expression once each, never as an ordering as well', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'grid', FieldType::Matrix);
    addFormField($draft, $this->user, 'dob', FieldType::Date, 1);
    addFormField($draft, $this->user, 'a', FieldType::ShortText, 2, ['relevant_expression' => '${grid} > 1']);
    addFormField($draft, $this->user, 'b', FieldType::ShortText, 3, ['relevant_expression' => '${dob} >']);
    addFormField($draft, $this->user, 'c', FieldType::ShortText, 4, ['relevant_expression' => '${dob} > ${grid}']);

    expect(orderingGateRefusals($this->gate, $draft))->toEqualCanonicalizing([
        ['a', 'expression_references_composite'],
        ['b', 'unexpected_token'],
        ['c', 'expression_references_composite'],
    ]);
});

it('classifies every field type an ordering may name', function (FieldType $type): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'subject', $type, 0, $type === FieldType::Calculated ? ['config' => ['calculated_formula' => '1']] : []);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 1, ['relevant_expression' => '${subject} > 1']);

    $expected = match ($type) {
        FieldType::Date, FieldType::Time, FieldType::Datetime => [['shown', 'expression_orders_temporal_mismatch']],
        FieldType::Note, FieldType::PageBreak, FieldType::YesNo, FieldType::MultiSelect, FieldType::CascadingSelect,
        FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture,
        FieldType::Signature => [['shown', 'expression_orders_non_number']],
        FieldType::Matrix, FieldType::LikertMatrix => [['shown', 'expression_references_composite']],
        FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => [['shown', 'expression_references_geo']],
        FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
        FieldType::Integer, FieldType::Decimal, FieldType::Calculated, FieldType::Duration, FieldType::SingleSelect,
        FieldType::Dropdown, FieldType::LikertScale, FieldType::Hidden => [],
    };

    expect(orderingGateRefusals($this->gate, $draft))->toBe($expected);
})->with(FieldType::cases());

it('refuses an equality between a list question and one value, which never changes with the answer (M134)', function (FieldType $type, string $expression): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'subject', $type);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 1, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([['shown', 'expression_equals_list']]);
})->with([
    'a multi-select, equals' => [FieldType::MultiSelect, "\${subject} = 'reading'"],
    'a multi-select, does not equal' => [FieldType::MultiSelect, "\${subject} != 'reading'"],
    'a multi-select, on the right' => [FieldType::MultiSelect, "'reading' = \${subject}"],
    'a cascading select' => [FieldType::CascadingSelect, "\${subject} = 'north'"],
    'a photo' => [FieldType::ImageCapture, "\${subject} = 'x'"],
]);

it('names the list question and offers selected() and the emptiness test instead', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'hobbies', FieldType::MultiSelect);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 1, ['relevant_expression' => "\${hobbies} = 'reading'"]);

    expect(orderingGateMessages($this->gate, $draft))->toBe([
        'The expression on “shown” compares the '.FieldType::MultiSelect->label().' question “hobbies” with a single value using = or !=, but its answer is a list, so the comparison never changes with the answer. Use selected(${hobbies}, \'value\') to ask whether a choice was picked, or = \'\' to ask whether it was answered.',
    ]);
});

it('still lets an expression test a list for an answer, for a choice, or for how many', function (string $expression): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'hobbies', FieldType::MultiSelect);
    addFormField($draft, $this->user, 'photo', FieldType::ImageCapture, 1);
    addFormField($draft, $this->user, 'colour', FieldType::SingleSelect, 2);
    addFormField($draft, $this->user, 'shown', FieldType::ShortText, 3, ['relevant_expression' => $expression]);

    expect(orderingGateRefusals($this->gate, $draft))->toBe([]);
})->with([
    'answered' => ["\${hobbies} != ''"],
    'unanswered' => ["\${hobbies} = ''"],
    'a choice picked' => ["selected(\${hobbies}, 'reading')"],
    'a choice not picked' => ["not(selected(\${hobbies}, 'reading'))"],
    'how many' => ['count(${hobbies}) >= 2'],
    'a photo taken' => ["\${photo} != ''"],
    'a single choice still equals one value' => ["\${colour} = 'red'"],
]);
