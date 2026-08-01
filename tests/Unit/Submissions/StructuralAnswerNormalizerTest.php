<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PrefillSource;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Submissions\AnswersContentChecksum;
use App\Services\Submissions\StructuralAnswerNormalizer;

/**
 * Stage-1 structural normalisation, driven purely over UNSAVED FormField models (casts apply without a
 * database — the F3 SemanticValidatorTest pattern). No container, no Postgres.
 */

/**
 * @param  array<int, FormField>  $fields
 * @param  array<string, mixed>  $answers
 * @param  array<int, FormSection>  $sections
 * @return array<string, mixed>
 */
function normalizeAnswers(array $fields, array $answers, array $sections = []): array
{
    return (new StructuralAnswerNormalizer)->normalize(collect($fields), collect($sections), $answers);
}

function normalizerField(string $key, FieldType $type): FormField
{
    return makeSchemaField(['key' => $key, 'field_type' => $type]);
}

/** A repeatable section + a member field belonging to it (member carries the section id). */
function repeatSection(string $key): FormSection
{
    return makeSchemaSection(['id' => $key, 'key' => $key, 'is_repeatable' => true]);
}

function repeatMember(string $key, string $sectionId, FieldType $type = FieldType::ShortText): FormField
{
    return makeSchemaField(['key' => $key, 'field_type' => $type, 'form_section_id' => $sectionId]);
}

it('rejects an unknown key with a structural error', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['name' => 'Ada', 'ghost' => 'x']);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural')
            ->and($e->fieldErrors())->toBe([
                ['field' => 'ghost', 'rule' => 'unknown_field', 'message' => 'Unknown field: ghost.'],
            ]);
    }
});

it('keeps text as a string and casts scalars to string', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText), normalizerField('code', FieldType::ShortText)];

    expect(normalizeAnswers($fields, ['name' => 'Ada', 'code' => 42]))
        ->toBe(['name' => 'Ada', 'code' => '42']);
});

it('rejects an array in a scalar field as a type mismatch', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['name' => ['a', 'b']]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('expected_scalar');
    }
});

it('accepts numeric-like values for numeric fields and rejects non-numeric', function (): void {
    $fields = [normalizerField('age', FieldType::Integer), normalizerField('rate', FieldType::Decimal)];

    expect(normalizeAnswers($fields, ['age' => '30', 'rate' => '3.14']))
        ->toBe(['age' => '30', 'rate' => '3.14']);

    try {
        normalizeAnswers([normalizerField('age', FieldType::Integer)], ['age' => 'thirty']);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('not_a_number');
    }
});

it('normalises yes/no to a real boolean including the string "no"', function (): void {
    $fields = [
        normalizerField('a', FieldType::YesNo), normalizerField('b', FieldType::YesNo),
        normalizerField('c', FieldType::YesNo), normalizerField('d', FieldType::YesNo),
        normalizerField('e', FieldType::YesNo),
    ];

    expect(normalizeAnswers($fields, ['a' => true, 'b' => 'no', 'c' => 'yes', 'd' => '0', 'e' => 1]))
        ->toBe(['a' => true, 'b' => false, 'c' => true, 'd' => false, 'e' => true]);
});

it('normalises multi-select to a list of strings', function (): void {
    $fields = [normalizerField('tags', FieldType::MultiSelect), normalizerField('one', FieldType::MultiSelect)];

    expect(normalizeAnswers($fields, ['tags' => [1, 'b'], 'one' => 'solo']))
        ->toBe(['tags' => ['1', 'b'], 'one' => ['solo']]);
});

it('drops empty answers as unanswered', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText), normalizerField('tags', FieldType::MultiSelect)];

    expect(normalizeAnswers($fields, ['name' => '', 'tags' => []]))->toBe([]);
});

it('drops display-only and computed field types', function (): void {
    $fields = [
        normalizerField('intro', FieldType::Note),
        normalizerField('brk', FieldType::PageBreak),
        normalizerField('total', FieldType::Calculated),
        normalizerField('name', FieldType::ShortText),
    ];

    expect(normalizeAnswers($fields, ['intro' => 'hi', 'brk' => 'x', 'total' => '5', 'name' => 'Ada']))
        ->toBe(['name' => 'Ada']);
});

it('aggregates every structural error rather than throwing on the first', function (): void {
    $fields = [normalizerField('age', FieldType::Integer), normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['age' => 'x', 'name' => ['array'], 'ghost' => 1]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors())->toHaveCount(3);
    }
});

/*
|--------------------------------------------------------------------------
| Repeat groups (Increment G1) — a repeatable section's instances nest under the SECTION key.
|--------------------------------------------------------------------------
*/

it('nests a repeatable section and coerces each instance with the same per-field rules', function (): void {
    $section = repeatSection('hh');
    $fields = [
        repeatMember('name', 'hh'),
        repeatMember('age', 'hh', FieldType::Integer),
        repeatMember('sub', 'hh', FieldType::YesNo),
    ];

    $result = normalizeAnswers($fields, ['hh' => [
        ['name' => 'Bob', 'age' => 40, 'sub' => 'yes'],
        ['name' => 'Cleo', 'age' => 12, 'sub' => 'no'],
    ]], [$section]);

    expect($result)->toBe(['hh' => [
        ['name' => 'Bob', 'age' => 40, 'sub' => true],
        ['name' => 'Cleo', 'age' => 12, 'sub' => false],
    ]]);
});

it('drops empty inner answers and fully-empty instances, and an empty group entirely', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh'), repeatMember('age', 'hh', FieldType::Integer)];

    $result = normalizeAnswers($fields, [
        'hh' => [['name' => 'Bob', 'age' => ''], ['name' => '', 'age' => '']],
        'other' => [], // an empty repeat group is dropped whole
    ], [$section, repeatSection('other')]);

    // instance 0 keeps only name; instance 1 is fully empty → dropped; 'other' group dropped.
    expect($result)->toBe(['hh' => [['name' => 'Bob']]]);
});

it('rejects a non-list value for a repeatable section', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => ['name' => 'Bob']], [$section]); // object, not a list
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh')
            ->and($e->fieldErrors()[0]['rule'])->toBe('expected_instance_array');
    }
});

it('rejects a non-object instance element', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => ['scalar-not-object']], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0]')
            ->and($e->fieldErrors()[0]['rule'])->toBe('expected_instance_object');
    }
});

it('rejects an unknown key inside an instance addressed by the instance path', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => [['name' => 'Bob', 'ghost' => 'x']]], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0].ghost')
            ->and($e->fieldErrors()[0]['rule'])->toBe('unknown_field');
    }
});

it('rejects a repeat-member field submitted at the top level', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['name' => 'Bob'], [$section]); // must be inside hh[]
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('name')
            ->and($e->fieldErrors()[0]['rule'])->toBe('misplaced_repeat_field');
    }
});

it('propagates an inner type mismatch with the instance path', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('age', 'hh', FieldType::Integer)];

    try {
        normalizeAnswers($fields, ['hh' => [['age' => 'not-a-number']]], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0].age')
            ->and($e->fieldErrors()[0]['rule'])->toBe('not_a_number');
    }
});

// ── Hidden-field prefill (Increment H7) ─────────────────────────────────────────────────────────────
// The one field type whose value is not the client's to decide. `fixed` is server-authoritative, `url` is
// untrusted-but-accepted under a byte cap, and an undeclared source holds nothing.

function hiddenField(string $key, ?string $source, ?string $literal = null, ?string $param = null): FormField
{
    $config = [];
    if ($source !== null) {
        $config['prefill_source'] = $source;
    }
    if ($param !== null) {
        $config['url_param'] = $param;
    }

    return makeSchemaField([
        'key' => $key,
        'field_type' => FieldType::Hidden,
        'config' => $config,
        'default_value' => $literal,
    ]);
}

it('injects a fixed hidden value the payload never mentioned', function (): void {
    $fields = [hiddenField('campaign', 'fixed', 'newsletter')];

    expect(normalizeAnswers($fields, []))->toBe(['campaign' => 'newsletter']);
});

it('overwrites a client-supplied value for a fixed hidden field', function (): void {
    $fields = [hiddenField('campaign', 'fixed', 'newsletter')];

    // The whole point of `fixed`: no channel, however authenticated, can steer this value.
    expect(normalizeAnswers($fields, ['campaign' => 'attacker-supplied']))->toBe(['campaign' => 'newsletter']);
});

it('injects nothing for a fixed hidden field with a blank literal', function (): void {
    expect(normalizeAnswers([hiddenField('campaign', 'fixed', null)], []))->toBe([])
        ->and(normalizeAnswers([hiddenField('campaign', 'fixed', '')], []))->toBe([]);
});

it('keeps a url-sourced hidden value the client submitted', function (): void {
    $fields = [hiddenField('promo', 'url')];

    expect(normalizeAnswers($fields, ['promo' => 'SPRING']))->toBe(['promo' => 'SPRING']);
});

it('drops a submitted value for a hidden field with no declared source', function (): void {
    expect(normalizeAnswers([hiddenField('promo', null)], ['promo' => 'SPRING']))->toBe([])
        ->and(normalizeAnswers([hiddenField('promo', 'nonsense')], ['promo' => 'SPRING']))->toBe([]);
});

it('rejects a url-sourced hidden value over the byte cap', function (): void {
    $fields = [hiddenField('promo', 'url')];
    $tooLong = str_repeat('x', PrefillSource::MAX_VALUE_BYTES + 1);

    try {
        normalizeAnswers($fields, ['promo' => $tooLong]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('promo')
            ->and($e->fieldErrors()[0]['rule'])->toBe('prefill_too_long');
    }
});

it('accepts a url-sourced hidden value exactly at the byte cap', function (): void {
    $fields = [hiddenField('promo', 'url')];
    $atCap = str_repeat('x', PrefillSource::MAX_VALUE_BYTES);

    expect(normalizeAnswers($fields, ['promo' => $atCap]))->toBe(['promo' => $atCap]);
});

it('measures the hidden byte cap in BYTES, not characters', function (): void {
    $fields = [hiddenField('promo', 'url')];
    // 1000 two-byte characters = 2000 bytes exactly; one more overflows. `mb_strlen` would see 1001 of
    // 2000 and wave it through, which is the divergence lib/prefill.ts's TextEncoder mirror exists to avoid.
    $atCap = str_repeat('é', PrefillSource::MAX_VALUE_BYTES / 2);

    expect(normalizeAnswers($fields, ['promo' => $atCap]))->toBe(['promo' => $atCap]);

    try {
        normalizeAnswers($fields, ['promo' => $atCap.'é']);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('prefill_too_long');
    }
});

it('drops rather than rejects a malformed value for a fixed hidden field', function (): void {
    // The distinction this pins: a `fixed` field's client value is not merely overwritten, it is never
    // INSPECTED. Routing it through the scalar arm instead would 422 on an array — a rejection triggered by
    // a value the server was always going to ignore, on a field the client has no say in.
    $fields = [hiddenField('campaign', 'fixed', 'newsletter')];

    expect(normalizeAnswers($fields, ['campaign' => ['a', 'b']]))->toBe(['campaign' => 'newsletter']);
});

it('rejects an array for a url-sourced hidden field', function (): void {
    try {
        normalizeAnswers([hiddenField('promo', 'url')], ['promo' => ['a', 'b']]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('expected_scalar');
    }
});

it('never writes a fixed hidden value flat when the field belongs to a repeatable section', function (): void {
    // The publish gate refuses this shape, but it is not retroactive — a version published before H7 can
    // still carry one, and a flat write would land outside the instance list where nothing reads it.
    $section = repeatSection('hh');
    $member = hiddenField('campaign', 'fixed', 'newsletter');
    $member->form_section_id = 'hh';

    expect(normalizeAnswers([$member], ['hh' => [['campaign' => 'x']]], [$section]))->toBe([]);
});

it('normalises identically whether or not the payload carries the fixed value', function (): void {
    // The G8c content checksum is taken over this document, so a replay that omits the injected value and
    // one that includes it must hash the same or they false-conflict against each other.
    $fields = [hiddenField('campaign', 'fixed', 'newsletter'), normalizerField('name', FieldType::ShortText)];

    $without = normalizeAnswers($fields, ['name' => 'Maria']);
    $with = normalizeAnswers($fields, ['name' => 'Maria', 'campaign' => 'newsletter']);

    // Asserting equality ALONE would pass vacuously if injection stopped happening at all (neither document
    // would carry the key) — found by mutation, so the presence assertion is part of the test, not decoration.
    expect($without)->toHaveKey('campaign')
        ->and($with)->toHaveKey('campaign')
        ->and(AnswersContentChecksum::of($without))->toBe(AnswersContentChecksum::of($with));
});
