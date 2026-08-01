<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Validation\SemanticInput;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| F3 SemanticValidator — the Stage-3 orchestration (pure, no database).
|--------------------------------------------------------------------------
| Driven from unsaved models through SemanticInput. Covers relevance masking, the section→field cascade,
| the bounded fixed-point settle (upstream-hidden field flips a downstream one), skip rules, the
| effective-required matrix, constraint gating, error aggregation, localization, and the empty computed slot.
*/

/**
 * @param  list<FormField>  $fields
 * @param  list<FormSection>  $sections
 * @param  list<FormFieldValidation>  $validations
 * @param  array<string, mixed>  $answers
 */
function semInput(array $fields, array $sections, array $validations, array $answers, string $locale = 'en'): SemanticInput
{
    return new SemanticInput(new Collection($fields), new Collection($sections), new Collection($validations), $answers, $locale);
}

it('masks an irrelevant field and prunes its answer', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'trigger']),
        makeSchemaField(['id' => 'f2', 'key' => 'dependent', 'relevant_expression' => '${trigger} = \'yes\'']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['trigger' => 'no', 'dependent' => 'hi']));

    expect($result->fieldRelevance)->toBe(['trigger' => true, 'dependent' => false]);
    expect($result->effectiveAnswers)->toBe(['trigger' => 'no']);
    expect($result->passed())->toBeTrue();
    expect($result->computed)->toBe([]);
});

it('cascades an irrelevant section to hide its fields', function (): void {
    $sections = [makeSchemaSection(['id' => 's1', 'key' => 'sec', 'relevant_expression' => '${show} = \'1\''])];
    $fields = [
        makeSchemaField(['id' => 'f0', 'key' => 'show']),
        makeSchemaField(['id' => 'f1', 'key' => 'inside', 'form_section_id' => 's1']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, $sections, [], ['show' => '0', 'inside' => 'x']));

    expect($result->sectionRelevance)->toBe(['sec' => false]);
    expect($result->fieldRelevance['inside'])->toBeFalse();
    expect($result->effectiveAnswers)->toBe(['show' => '0']);
});

it('settles a chained relevance to a fixed point (upstream-hidden field reads empty downstream)', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'gate']),
        makeSchemaField(['id' => 'fa', 'key' => 'a', 'relevant_expression' => '${gate} = \'1\'']),
        makeSchemaField(['id' => 'fb', 'key' => 'b', 'relevant_expression' => '${a} = \'\'']),
    ];

    // gate=0 ⇒ a hidden ⇒ a pruned ⇒ b sees a empty ⇒ b relevant. A single pass would wrongly keep b hidden.
    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['gate' => '0', 'a' => 'something', 'b' => 'hi']));

    expect($result->fieldRelevance)->toBe(['gate' => true, 'a' => false, 'b' => true]);
    expect($result->effectiveAnswers)->toBe(['gate' => '0', 'b' => 'hi']);
});

it('treats a fired skip_if rule as irrelevance', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fs', 'key' => 'src']),
        makeSchemaField(['id' => 'fx', 'key' => 'target']),
    ];
    $validations = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'fx', 'related_form_field_id' => 'fs',
        'rule_type' => ValidationRuleType::SkipIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'skip',
    ])];

    $skipped = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['src' => 'skip', 'target' => 'val']));
    expect($skipped->fieldRelevance['target'])->toBeFalse();
    expect($skipped->effectiveAnswers)->toBe(['src' => 'skip']);

    $kept = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['src' => 'keep', 'target' => 'val']));
    expect($kept->fieldRelevance['target'])->toBeTrue();
    expect($kept->effectiveAnswers)->toBe(['src' => 'keep', 'target' => 'val']);
});

it('enforces a required field but not an optional one', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'req', 'is_required' => RequiredMode::Required]),
        makeSchemaField(['id' => 'f2', 'key' => 'opt', 'is_required' => RequiredMode::Optional]),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], []));

    expect($result->passed())->toBeFalse();
    expect($result->errorsFor('req'))->toHaveCount(1);
    expect($result->errorsFor('req')[0]->rule)->toBe('field_required');
    expect($result->errorsFor('opt'))->toBe([]);
});

it('resolves conditional requiredness against current answers', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'gate']),
        makeSchemaField(['id' => 'fc', 'key' => 'cond', 'is_required' => RequiredMode::Conditional]),
    ];
    $validations = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'fc', 'related_form_field_id' => 'fg',
        'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'yes',
    ])];

    $required = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['gate' => 'yes']));
    expect($required->errorsFor('cond'))->toHaveCount(1);
    expect($required->errorsFor('cond')[0]->rule)->toBe('field_required');

    $notRequired = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['gate' => 'no']));
    expect($notRequired->passed())->toBeTrue();
});

it('does not enforce a required field that is irrelevant', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'g']),
        makeSchemaField(['id' => 'fr', 'key' => 'r', 'is_required' => RequiredMode::Required, 'relevant_expression' => '${g} = \'1\'']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['g' => '0']));

    expect($result->passed())->toBeTrue();
    expect($result->fieldRelevance['r'])->toBeFalse();
});

it('checks a constraint only on a relevant, answered field', function (): void {
    $fields = [makeSchemaField(['id' => 'f', 'key' => 'age', 'field_type' => FieldType::Integer])];
    $validations = [makeValidationRow(['id' => 'v', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '18'])];
    $validator = makeSemanticValidator();

    expect($validator->evaluate(semInput($fields, [], $validations, ['age' => 10]))->errorsFor('age')[0]->rule)->toBe('min_value');
    expect($validator->evaluate(semInput($fields, [], $validations, ['age' => 20]))->passed())->toBeTrue();
    expect($validator->evaluate(semInput($fields, [], $validations, []))->passed())->toBeTrue(); // empty ⇒ constraint skipped
});

it('aggregates errors across fields', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'a', 'field_type' => FieldType::Integer]),
        makeSchemaField(['id' => 'f2', 'key' => 'b', 'is_required' => RequiredMode::Required]),
    ];
    $validations = [makeValidationRow(['id' => 'v', 'form_field_id' => 'f1', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5'])];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['a' => 1]));

    expect($result->errors)->toHaveCount(2);
    expect($result->errorsFor('a')[0]->rule)->toBe('min_value');
    expect($result->errorsFor('b')[0]->rule)->toBe('field_required');
});

it('resolves the error message by locale, falling back to the base message then a default', function (): void {
    $fields = [makeSchemaField(['id' => 'f', 'key' => 'a', 'field_type' => FieldType::Integer])];
    $localized = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5',
        'error_message' => 'Too small', 'error_message_translations' => ['fr' => 'Trop petit'],
    ])];
    $bare = [makeValidationRow(['id' => 'v2', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5'])];
    $validator = makeSemanticValidator();

    expect($validator->evaluate(semInput($fields, [], $localized, ['a' => 1], 'fr'))->errorsFor('a')[0]->message)->toBe('Trop petit');
    expect($validator->evaluate(semInput($fields, [], $localized, ['a' => 1], 'en'))->errorsFor('a')[0]->message)->toBe('Too small');
    expect($validator->evaluate(semInput($fields, [], $bare, ['a' => 1], 'en'))->errorsFor('a')[0]->message)->toBe('This value is not valid.');
});

/*
|--------------------------------------------------------------------------
| Increment H21a — the defects H1f recorded against the settle loop (Doc #27 §3.2, §3.3, §3.4, §4.3).
|--------------------------------------------------------------------------
| Every one of these lives inside `evaluate()`, which IS the golden runners' entry point. No CURRENT vector
| discriminates them — a far weaker guarantee than "the runners cannot see them", and exactly why each has a
| hand-written twin in `resources/public-runtime/engine/__tests__/engine.test.ts` asserting the same
| expectations against the TypeScript engine.
|
| EVERY `${` LITERAL IS SINGLE-QUOTED: PHP 8.3 removed `${var}` interpolation, so a double-quoted literal
| loses its holes before the engine sees it and the assertion tests nothing.
*/

it('resolves count() over a repeatable section inside a relevant_expression', function (): void {
    // Doc #27 §3.3. Before H21a the settle context was `array_intersect_key($answers, $relevant)` over
    // top-level FIELD keys only, so the repeat array was pruned before any relevance expression saw it:
    // count(${hh}) read ABSENT and returned 0 forever — while the publish gate explicitly whitelists the
    // reference and the identical expression works correctly in a `calculated` formula.
    $sections = [
        makeSchemaSection(['id' => 's_hh', 'key' => 'hh', 'sequence' => 1, 'is_repeatable' => true]),
        makeSchemaSection(['id' => 's_sum', 'key' => 'summary', 'sequence' => 2, 'relevant_expression' => 'count(${hh}) > 0']),
    ];
    $fields = [
        makeSchemaField(['id' => 'f_m', 'key' => 'member_name', 'form_section_id' => 's_hh', 'sequence' => 1]),
        makeSchemaField(['id' => 'f_n', 'key' => 'note_text', 'form_section_id' => 's_sum', 'sequence' => 2]),
    ];
    $validator = makeSemanticValidator();

    $withMembers = $validator->evaluate(semInput($fields, $sections, [], ['hh' => [['member_name' => 'Ana']]]));
    $empty = $validator->evaluate(semInput($fields, $sections, [], ['hh' => []]));

    expect($withMembers->sectionRelevance['summary'])->toBeTrue();
    expect($empty->sectionRelevance['summary'])->toBeFalse();
});

it('resolves count() over the group inside a repeat MEMBER relevant_expression', function (): void {
    // The identical trap at a SECOND scope: `settleInstanceRelevance()` builds its context from the top-level
    // effective answers captured BEFORE the repeat arrays are merged in, so the group's own key read ABSENT
    // there too. "Ask this only when the household has more than one member" is the idiom that hits it.
    $sections = [makeSchemaSection(['id' => 's_hh', 'key' => 'hh', 'sequence' => 1, 'is_repeatable' => true])];
    $fields = [
        makeSchemaField(['id' => 'f_m', 'key' => 'member_name', 'form_section_id' => 's_hh', 'sequence' => 1]),
        makeSchemaField(['id' => 'f_g', 'key' => 'guardian', 'form_section_id' => 's_hh', 'sequence' => 2, 'relevant_expression' => 'count(${hh}) > 1']),
    ];
    $validator = makeSemanticValidator();

    $two = $validator->evaluate(semInput($fields, $sections, [], ['hh' => [
        ['member_name' => 'Ana', 'guardian' => 'Y'],
        ['member_name' => 'Ben', 'guardian' => 'Z'],
    ]]));
    $one = $validator->evaluate(semInput($fields, $sections, [], ['hh' => [['member_name' => 'Ana', 'guardian' => 'Y']]]));

    expect($two->repeatFieldRelevance['hh'][0]['guardian'])->toBeTrue();
    expect($one->repeatFieldRelevance['hh'][0]['guardian'])->toBeFalse();
    // The prune follows the mask, so the answer really is dropped rather than merely flagged.
    expect($one->effectiveAnswers['hh'][0])->toBe(['member_name' => 'Ana']);
});

it('does not seed a section key that collides with a field key', function (): void {
    // Doc #27 amendment A7. `form_sections` and `form_fields` carry INDEPENDENT
    // `(tenant_id, form_version_id, key)` unique indexes and every application-level enforcer is
    // table-scoped, so a collision is reachable — `ExpressionValidationGate`'s "globally unique per version,
    // so there is no key collision" is false. Seeding the section key blindly would re-admit the answer of a
    // field that relevance had just pruned.
    $sections = [
        makeSchemaSection(['id' => 's_hh', 'key' => 'roster', 'sequence' => 1, 'is_repeatable' => true]),
        makeSchemaSection(['id' => 's_x', 'key' => 'later', 'sequence' => 2, 'relevant_expression' => '${roster} = \'secret\'']),
    ];
    $fields = [
        makeSchemaField(['id' => 'f_gate', 'key' => 'gate', 'sequence' => 1]),
        // A FIELD also called `roster`, gated off. Its answer must stay pruned.
        makeSchemaField(['id' => 'f_r', 'key' => 'roster', 'sequence' => 2, 'relevant_expression' => '${gate} = \'yes\'']),
        makeSchemaField(['id' => 'f_m', 'key' => 'member_name', 'form_section_id' => 's_hh', 'sequence' => 3]),
        makeSchemaField(['id' => 'f_l', 'key' => 'later_field', 'form_section_id' => 's_x', 'sequence' => 4]),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, $sections, [], [
        'gate' => 'no',
        'roster' => 'secret',
    ]));

    expect($result->fieldRelevance['roster'])->toBeFalse();
    expect($result->effectiveAnswers)->not->toHaveKey('roster');
    // The downstream gate must NOT see the pruned value leak back in under the section's name.
    expect($result->sectionRelevance['later'])->toBeFalse();
});

it('returns a section mask and a field mask that agree even when the settle exhausts its bound', function (): void {
    // Doc #27 §3.2 (amendment A3). The loop assigns the newer FIELD mask while the section mask still holds
    // the verdict computed from the PREVIOUS one, so on bound exhaustion the two were returned one iteration
    // apart. The step model is the first consumer to read BOTH — membership from the section mask, contents
    // and the effective-answer prune from the field mask — so a disagreement is a step hidden from the rail
    // whose fields nonetheless survive into the submitted document, or the reverse.
    //
    // An oscillating pair: `a` is relevant iff `b` reads blank and `b` iff `a` does. Each iteration prunes one
    // and un-prunes the other, so the fixed point is never reached and the loop runs to its bound.
    //
    // THE FOURTH FIELD IS NOT PADDING. The bound is `fields + sections + 2`, so the field count decides which
    // phase of the oscillation the loop stops on, and only ONE phase exposes the artifact: the one whose final
    // field mask still contains `inside` while the section mask recomputed from it says `sec` is false.
    // Verified empirically across three field counts — at 3 and 5 fields the loop lands on the harmless phase
    // and the tightening is genuinely a no-op, so a fixture built without this field would pass either way.
    // That is the H6b caution in practice: an expectation reachable by more than one path pins nothing.
    $sections = [makeSchemaSection(['id' => 's1', 'key' => 'sec', 'sequence' => 1, 'relevant_expression' => '${a} = \'x\''])];
    $fields = [
        makeSchemaField(['id' => 'fa', 'key' => 'a', 'sequence' => 1, 'relevant_expression' => '${b} = \'\'']),
        makeSchemaField(['id' => 'fb', 'key' => 'b', 'sequence' => 2, 'relevant_expression' => '${a} = \'\'']),
        makeSchemaField(['id' => 'fc', 'key' => 'inside', 'form_section_id' => 's1', 'sequence' => 3]),
        makeSchemaField(['id' => 'fk', 'key' => 'keep', 'sequence' => 4]),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, $sections, [], [
        'a' => 'x', 'b' => 'y', 'inside' => 'kept', 'keep' => 'k',
    ]));

    // Asserted directly rather than as a two-branch invariant, because a branching expectation is satisfied
    // by BOTH the fixed and the broken behaviour. Without the post-loop tightening this fixture returns
    // `sec => false` alongside `inside => true`, and the answer is PERSISTED — a step hidden from the rail
    // whose fields nonetheless survive into the submitted document.
    expect($result->sectionRelevance['sec'])->toBeFalse();
    expect($result->fieldRelevance['inside'])->toBeFalse();
    expect($result->effectiveAnswers)->not->toHaveKey('inside');
});

it('stops enforcing min_instances on a repeatable section the respondent never sees', function (): void {
    // Doc #27 §4.3 (amendment A4). The server enforced bounds on any repeatable section whose SECTION
    // relevance was true and consulted nothing else, while the client drops a section from the step list when
    // no member renders a question. So a repeatable section could vanish from the step list while the server
    // still demanded an instance: a permanent blocker that is INVISIBLE, because the error-summary banner
    // iterates the visible step list and the step is not in it.
    $sections = [makeSchemaSection([
        'id' => 's_hh', 'key' => 'hh', 'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 2,
    ])];
    $hidden = [makeSchemaField([
        'id' => 'f_h', 'key' => 'token', 'form_section_id' => 's_hh', 'field_type' => FieldType::Hidden, 'sequence' => 1,
    ])];
    $visible = [makeSchemaField(['id' => 'f_m', 'key' => 'member_name', 'form_section_id' => 's_hh', 'sequence' => 1])];
    $validator = makeSemanticValidator();

    // Nothing in the group renders a question ⇒ no step ⇒ no blocker.
    expect($validator->evaluate(semInput($hidden, $sections, [], []))->errors)->toBe([]);

    // A group that DOES render still blocks — zero instances is vacuously visible, because the step is what
    // lets the respondent add the first one. Without this arm the narrowing would silently disable the rule.
    $enforced = $validator->evaluate(semInput($visible, $sections, [], []));
    expect($enforced->errorsFor('hh'))->toHaveCount(1);
    expect($enforced->errorsFor('hh')[0]->rule)->toBe('min_instances');
});

it('keeps enforcing max_instances ahead of the per-instance settle', function (): void {
    // `max` is the abuse guard — a huge array must be rejected BEFORE anything settles it — so it keeps its
    // position and takes no step-visibility narrowing: a max violation means instances were submitted, so the
    // respondent was shown the group.
    $sections = [makeSchemaSection([
        'id' => 's_hh', 'key' => 'hh', 'sequence' => 1, 'is_repeatable' => true, 'max_instances' => 1,
    ])];
    $fields = [makeSchemaField(['id' => 'f_m', 'key' => 'member_name', 'form_section_id' => 's_hh', 'sequence' => 1])];

    $result = makeSemanticValidator()->evaluate(semInput($fields, $sections, [], [
        'hh' => [['member_name' => 'Ana'], ['member_name' => 'Ben']],
    ]));

    expect($result->errorsFor('hh')[0]->rule)->toBe('max_instances');
});

it('evaluates today() against the injected clock in a section relevant_expression', function (): void {
    // Doc #27 §3.4. PHP has always stamped a clock; the SPA passed none, so `today()`/`now()` returned ABSENT
    // there. H21a threads one through `toSemanticInput()` — this is the PHP side of the contract the
    // TypeScript twin now has to match, format included.
    $sections = [makeSchemaSection([
        'id' => 's1', 'key' => 'sec', 'sequence' => 1, 'relevant_expression' => '${d} = today()',
    ])];
    $fields = [
        makeSchemaField(['id' => 'fd', 'key' => 'd', 'field_type' => FieldType::Date, 'sequence' => 1]),
        makeSchemaField(['id' => 'fi', 'key' => 'inside', 'form_section_id' => 's1', 'sequence' => 2]),
    ];

    $input = new SemanticInput(
        new Collection($fields),
        new Collection($sections),
        new Collection,
        ['d' => '2026-07-11'],
        'en',
        '2026-07-11T09:30:00+00:00',
    );

    expect(makeSemanticValidator()->evaluate($input)->sectionRelevance['sec'])->toBeTrue();
});
