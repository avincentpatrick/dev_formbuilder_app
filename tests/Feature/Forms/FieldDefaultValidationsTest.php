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

it('ships an email pattern that actually accepts and rejects the right strings', function (): void {
    // ⛔ THE CASE THAT MATTERS. The one above proves a row exists; this proves the row works, through the
    // real StructuredRuleEvaluator rather than a hand-rolled preg_match.
    expect(passesDefaultPattern(FieldType::Email, 'nurse@pitahc.gov.ph'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Email, 'a.b+tag@sub.example.co.uk'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Email, 'bob'))->toBeFalse()
        ->and(passesDefaultPattern(FieldType::Email, 'bob@'))->toBeFalse()
        ->and(passesDefaultPattern(FieldType::Email, 'bob@localhost'))->toBeFalse()
        ->and(passesDefaultPattern(FieldType::Email, 'two words@example.com'))->toBeFalse();
});

it('ships a url pattern that requires an explicit scheme', function (): void {
    expect(passesDefaultPattern(FieldType::Url, 'https://example.com/path?q=1'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Url, 'http://example.com'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Url, 'example.com'))->toBeFalse()
        ->and(passesDefaultPattern(FieldType::Url, 'ftp://example.com'))->toBeFalse();
});

it('ships a phone pattern that accepts real numbers from more than one country', function (): void {
    // This is a multi-tenant product: a national format would be the wrong assertion to ship as a default.
    expect(passesDefaultPattern(FieldType::Phone, '+639171234567'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Phone, '(02) 8123 4567'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Phone, '555-0100'))->toBeTrue()
        ->and(passesDefaultPattern(FieldType::Phone, 'call me'))->toBeFalse()
        ->and(passesDefaultPattern(FieldType::Phone, '123'))->toBeFalse();
});

it('passes an empty answer for every default, so a default never makes a field required', function (): void {
    // Both engines short-circuit `pattern` on an empty answer. Requiredness is RequiredMode's job, and a
    // format default that silently also meant "required" would be a much worse surprise than no default.
    foreach ([FieldType::Email, FieldType::Url, FieldType::Phone] as $type) {
        expect(passesDefaultPattern($type, ''))->toBeTrue("an empty answer should pass {$type->value}'s default");
    }
});

it('defines a default for exactly the three types with a format worth asserting', function (): void {
    $withDefaults = array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => DefaultFieldRules::for($t) !== []),
    ));

    expect($withDefaults)->toEqualCanonicalizing(['email', 'url', 'phone']);
});
