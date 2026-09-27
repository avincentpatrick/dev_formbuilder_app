<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Expressions\EvaluationContext;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Validation\StructuredRuleEvaluator;
use App\Support\Forms\DefaultFieldRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// M112 — a new field of each type arrives already validating what its type implies. Two halves, and the
// second is the one that matters: the first only proves a ROW EXISTS, the second proves the pattern in it
// actually accepts and rejects the right strings. A default that exists and does nothing is the defect
// this row was filed about, one layer down.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->builder = app(FormBuilderService::class);
});

/** Drive the SHIPPED rule_value through the real evaluator, exactly as a submission would. */
function passesDefaultPattern(FieldType $type, string $answer): bool
{
    $rows = DefaultFieldRules::for($type);
    expect($rows)->not->toBeEmpty("no default rule is defined for {$type->value}");

    $row = new FormFieldValidation([
        'rule_type' => $rows[0]['rule_type'],
        'rule_value' => $rows[0]['rule_value'],
    ]);

    return app(StructuredRuleEvaluator::class)->passesConstraint(
        $row,
        'subject',
        $answer,
        new EvaluationContext(['subject' => $answer], $answer),
        [],
    );
}

it('gives a new email field a pattern rule, in the same transaction as the field', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $field = $this->builder->addField($form->refresh(), $this->user, FieldType::Email, null);

    $rules = $field->validations()->get();

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->rule_type)->toBe(ValidationRuleType::Pattern)
        ->and($rules[0]->rule_value)->not->toBeEmpty()
        ->and($rules[0]->error_message)->not->toBeEmpty()
        ->and($rules[0]->form_version_id)->toBe($field->form_version_id);
});

it('gives a new short_text field nothing at all', function (): void {
    // The negative control. Without it, `for()` returning a pattern for EVERY type would pass the case
    // above and ship a regex onto twenty-eight types that have no format to assert.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $field = $this->builder->addField($form->refresh(), $this->user, FieldType::ShortText, null);

    expect($field->validations()->count())->toBe(0);
});

it('leaves a hidden field unvalidated, which the publish gate would otherwise refuse', function (): void {
    // ⛔ A REAL INTERACTION, NOT A HYPOTHETICAL. `hidden` shares ValueShape::Text with the format types,
    // and StructuralValidationGate::assertHiddenFieldAnswerable() refuses ANY hidden field carrying a
    // validation rule. Had the registry been keyed on the shape instead of the type, every new hidden
    // field would have arrived unpublishable.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $field = $this->builder->addField($form->refresh(), $this->user, FieldType::Hidden, null);

    expect($field->validations()->count())->toBe(0);
});

/**
 * The shared accept/reject table (Increment M115, `R-2a4f6afe`). ONE copy, TWO engines: this file drives it
 * through PHP and `resources/public-runtime/engine/__tests__/default-field-patterns.test.ts` drives the same
 * rows through a real V8 `RegExp`. The samples used to live inline here, which made them a copy the JS half
 * would have had to duplicate — and a table that disagrees with itself cannot prove a parity claim.
 *
 * ⚠️ IT THROWS RATHER THAN `expect()`s, because this runs at Pest COLLECTION time, before the container
 * exists. A plain path for the same reason — not `base_path()`.
 *
 * @return list<array{name: string, field_type: string, answer: string, expected: bool}>
 */
function defaultPatternCases(): array
{
    $path = dirname(__DIR__, 2).'/fixtures/default-field-patterns.json';
    $raw = @file_get_contents($path);

    if ($raw === false) {
        throw new RuntimeException("default-field-patterns.json must be readable at {$path}");
    }

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    return $decoded['cases'];
}

dataset('default pattern samples', function (): iterable {
    foreach (defaultPatternCases() as $case) {
        yield $case['name'] => [$case];
    }
});

it('agrees with the shipped pattern on', function (array $case): void {
    // ⛔ THE CASES THAT MATTER. The ones above prove a row EXISTS; these prove the row WORKS, through the
    // real StructuredRuleEvaluator rather than a hand-rolled preg_match — and through the same table the
    // JavaScript engine is driven with, so "both engines agree" is a measured statement rather than a note.
    expect(passesDefaultPattern(FieldType::from($case['field_type']), $case['answer']))->toBe($case['expected']);
})->with('default pattern samples');

it('drives a table that covers both verdicts for all three types, so no case list can go vacuous', function (): void {
    // ⛔ ANTI-VACUITY. A fixture that failed to load would have thrown at collection; one that SHRANK, or
    // drifted to one-sided expectations, would leave the dataset above green while asserting almost nothing.
    // The empty-answer rows are part of the floor: both engines short-circuit `pattern` on an empty answer,
    // and a format default that silently also meant "required" would be a much worse surprise than none.
    $cases = defaultPatternCases();
    $names = array_column($cases, 'name');

    expect(count($cases))->toBeGreaterThanOrEqual(15)
        ->and(count(array_unique($names)))->toBe(count($cases));

    foreach (['email', 'url', 'phone'] as $type) {
        $mine = array_values(array_filter($cases, static fn (array $c): bool => $c['field_type'] === $type));
        $verdicts = array_column($mine, 'expected');

        expect($verdicts)->toContain(true)
            ->and($verdicts)->toContain(false);
        expect(array_column($mine, 'answer'))->toContain('');
    }
});

it('defines a default for exactly the three types with a format worth asserting', function (): void {
    $withDefaults = array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => DefaultFieldRules::for($t) !== []),
    ));

    expect($withDefaults)->toEqualCanonicalizing(['email', 'url', 'phone']);
});
