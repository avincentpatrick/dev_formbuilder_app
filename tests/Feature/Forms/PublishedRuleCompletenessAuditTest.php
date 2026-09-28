<?php

declare(strict_types=1);

use App\Enums\FormVersionStatus;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// M118 — `R-43a7d121`. M116's publish gate refuses these shapes PROSPECTIVELY, so the population this
// audit exists to find can only have been created before that gate merged. The whole difficulty is
// constructing that state, and `M117` concluded on these same tables that it could not be done: the three
// content child tables are `draft_child` under FORCE ROW LEVEL SECURITY, so a published version's rules are
// immutable to every role.
//
// ⛔ THE ROUTE M117 DID NOT NEED AND THIS TEST DOES: PUBLISH A VERSION THAT ALREADY HOLDS THE BAD ROW.
// That is not a trick, it is the real history — a pre-M116 PublishService had no gate, so the row was
// written while the version was a draft (permitted) and the version then moved to `published`. So the
// fixture reproduces the history rather than faking a failure, which is the distinction `M117` drew when it
// declined to write one.
//
// ⛔ AND THE FIRST ATTEMPT AT THAT FIXTURE WAS REFUSED BY A TRIGGER NOBODY HAD MENTIONED, WHICH IS WHY
// EVERY RULE IS CREATED BEFORE THE SINGLE PUBLISH. `..._000202_create_form_versions_table`'s comment says
// "UPDATE unconstrained by status so publish can move draft->published->superseded", which reads as though
// the transition itself were unguarded — it is not. `form_versions_published_immutable_fn()` raises
// `SQLSTATE 23001 — status may only move published to superseded`, with the hint "a published version can
// never return to draft". So there is no un-publish-and-append step available to a fixture, and the
// protection is strictly stronger than the sentence describing it. Rules are therefore composed as a list
// up front and the version is published exactly once.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

/**
 * A published version holding exactly the rules the callable asks for, built the way history built them:
 * draft first, every rule next, status last and once.
 *
 * @param  callable(FormField): list<array<string, mixed>>  $rules  receives the version's one field, so a
 *                                                                  rule can point `related_form_field_id`
 *                                                                  at it; each array is merged over the
 *                                                                  validation factory's defaults
 */
function publishedVersionHolding(callable $rules): FormField
{
    $form = Form::factory()->create();
    $version = FormVersion::factory()->create([
        'form_id' => $form->id,
        'status' => FormVersionStatus::Draft,
    ]);
    $field = FormField::factory()->create([
        'form_version_id' => $version->id,
        'key' => 'consent',
    ]);

    foreach ($rules($field) as $rule) {
        FormFieldValidation::factory()->create(array_merge([
            'form_version_id' => $version->id,
            'form_field_id' => $field->id,
        ], $rule));
    }

    // The one move history made. Asserted rather than assumed: an UPDATE that matches zero rows under RLS
    // reports SUCCESS (M117 measured exactly that on these tables), so a silent no-op here would leave the
    // version a draft and every assertion below would pass vacuously against an empty published set.
    $moved = DB::table('form_versions')
        ->where('id', $version->id)
        ->update(['status' => FormVersionStatus::Published->value, 'published_at' => now()]);

    expect($moved)->toBe(1, 'the draft could not be moved to published, so the fixture never existed');
    expect(DB::table('form_versions')->where('id', $version->id)->value('status'))
        ->toBe(FormVersionStatus::Published->value);

    return $field;
}

/** The two defect shapes, as rule attribute arrays. */
function ruleNamingNoComparedField(): array
{
    return ['rule_type' => ValidationRuleType::RequiredIf, 'rule_value' => 'yes', 'operator' => 'eq', 'related_form_field_id' => null];
}

it('finds a published rule that names no compared field, and fails', function (): void {
    publishedVersionHolding(fn (FormField $f): array => [ruleNamingNoComparedField()]);

    // ONE substring spanning both facts, and that is a harness constraint rather than a style choice.
    // Each `expectsOutputToContain` registers its own Mockery expectation on `doWrite`, and Mockery lets
    // only the FIRST matching expectation consume a given write — so two substrings that both occur on the
    // SAME output line can never both be satisfied, and the second fails with "Output does not contain",
    // which reads as the text being absent when it is present. Measured here on the first run. Spanning
    // them in one contiguous match is also the stronger assertion: it pins the field to its REASON rather
    // than asserting the two merely co-occur somewhere in the output.
    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('field `consent` rule `required_if` — names no compared field')
        ->assertFailed();
});

it('finds a published rule that carries no operator where the absence is not "is answered", and fails', function (): void {
    publishedVersionHolding(fn (FormField $f): array => [[
        'rule_type' => ValidationRuleType::SkipIf,
        'rule_value' => 'yes',
        'operator' => null,
        'related_form_field_id' => $f->id,
    ]]);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('carries no operator')
        ->assertFailed();
});

it('does NOT count a complete rule', function (): void {
    publishedVersionHolding(fn (FormField $f): array => [[
        'rule_type' => ValidationRuleType::RequiredIf,
        'rule_value' => 'yes',
        'operator' => 'eq',
        'related_form_field_id' => $f->id,
    ]]);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('No published rule is unevaluable')
        ->assertSuccessful();
});

// ⛔ THE ARM THAT STOPS THIS AUDIT BEING WRONG IN THE EXPENSIVE DIRECTION. `required_with` / `skip_with`
// with a null operator is READ by `lowerCondition()` as `isNotNull(relatedKey)` — "when that question is
// answered at all" — which is a legitimate and probably the commonest authoring choice. An audit that
// flagged it would send an operator hunting a defect in the most ordinary rule in the product.
it('does NOT count required_with or skip_with with a null operator, because that absence is "is answered"', function (): void {
    publishedVersionHolding(fn (FormField $f): array => [
        ['rule_type' => ValidationRuleType::RequiredWith, 'rule_value' => null, 'operator' => null, 'related_form_field_id' => $f->id, 'sequence' => 0],
        ['rule_type' => ValidationRuleType::SkipWith, 'rule_value' => null, 'operator' => null, 'related_form_field_id' => $f->id, 'sequence' => 1],
    ]);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('No published rule is unevaluable')
        ->assertSuccessful();
});

it('ignores a DRAFT version, because M116 already refuses those at publish', function (): void {
    $form = Form::factory()->create();
    $version = FormVersion::factory()->create(['form_id' => $form->id, 'status' => FormVersionStatus::Draft]);
    $field = FormField::factory()->create(['form_version_id' => $version->id, 'key' => 'consent']);

    FormFieldValidation::factory()->create(array_merge([
        'form_version_id' => $version->id,
        'form_field_id' => $field->id,
    ], ruleNamingNoComparedField()));

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('No published rule is unevaluable')
        ->assertSuccessful();
});

it('ignores an expression row, which carries no rule_type to be incomplete', function (): void {
    $form = Form::factory()->create();
    $version = FormVersion::factory()->create(['form_id' => $form->id, 'status' => FormVersionStatus::Draft]);
    $field = FormField::factory()->create(['form_version_id' => $version->id, 'key' => 'consent']);

    FormFieldValidation::factory()->expression("consent = 'yes'")->create([
        'form_version_id' => $version->id,
        'form_field_id' => $field->id,
    ]);

    DB::table('form_versions')->where('id', $version->id)
        ->update(['status' => FormVersionStatus::Published->value, 'published_at' => now()]);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('No published rule is unevaluable')
        ->assertSuccessful();
});

// ⛔ ANTI-VACUITY OVER THE DERIVATION ITSELF, NOT OVER THE QUERY. The command derives both kind lists from
// the enum's own predicates, so an empty derivation would make every case above pass while auditing
// nothing. These assertions pin what the enum currently says AND that the audit's two lists differ — the
// property a single combined list would silently destroy.
it('derives both rule-kind lists from the enum, and they are neither empty nor identical', function (): void {
    $needsRelated = array_map(
        fn (ValidationRuleType $t): string => $t->value,
        array_filter(ValidationRuleType::cases(), fn (ValidationRuleType $t): bool => $t->takesRelatedField())
    );
    $needsOperator = array_map(
        fn (ValidationRuleType $t): string => $t->value,
        array_filter(
            ValidationRuleType::cases(),
            fn (ValidationRuleType $t): bool => $t->takesOperator() && ! $t->operatorMayBeEmpty()
        )
    );

    expect(array_values($needsRelated))->toEqualCanonicalizing([
        'required_if', 'required_with', 'skip_if', 'skip_with', 'greater_than_field', 'less_than_field',
    ]);
    expect(array_values($needsOperator))->toEqualCanonicalizing(['required_if', 'skip_if']);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('kinds needing a compared field:')
        ->expectsOutputToContain('kinds needing an operator:')
        ->assertSuccessful();
});

it('reports every finding across more than one tenant, so the loop is not counted once', function (): void {
    publishedVersionHolding(fn (FormField $f): array => [ruleNamingNoComparedField()]);

    $second = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    TenantContext::flush();
    enterTenant($second->id, $this->user->id);

    publishedVersionHolding(fn (FormField $f): array => [ruleNamingNoComparedField()]);

    $this->artisan('forms:audit-published-rules')
        ->expectsOutputToContain('2 unevaluable published rule(s) across 2 tenant(s)')
        ->assertFailed();
});
