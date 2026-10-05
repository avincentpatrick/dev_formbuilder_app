<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\ConversionDropReason;
use App\Enums\ConversionFamily;
use App\Enums\ConversionWarning;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\LogicOperator;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\FormException;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Support\Forms\ConversionPlan;
use App\Support\Forms\DefaultFieldRules;
use App\Support\Forms\FieldTypeConversion;

// M121 — the type-conversion engine (R-495abf48, the overhaul plan's B5a, first half). No database and no
// container: tests/Pest.php binds TestCase to Feature only, so everything here runs on UNSAVED models and
// the engine must stay pure to be testable at all.
//
// ⛔ THE FAMILY TABLE BELOW IS TRANSCRIBED INDEPENDENTLY OF THE ENGINE, AND THAT IS THE POINT — the same
// device as ValueShapeTest. It records the user's decisions of 2026-10-01 (the D64 amendment in
// docs/claims/decisions.md): the choice family is the four OPTION-LIST types, note and hidden are two-way,
// calculated/duration/yes_no/cascading_select convert only to note or hidden, and page_break converts
// to and from nothing.
//
// ⚠️ Helpers are prefixed `fieldConversion*`: Pest loads every file into one process, so a same-named
// file-scope helper elsewhere is a fatal redeclaration.

/** @return array<string, list<string>> */
function fieldConversionFamilyTable(): array
{
    return [
        'text' => ['short_text', 'long_text', 'email', 'phone', 'url'],
        'number' => ['integer', 'decimal'],
        'temporal' => ['date', 'time', 'datetime'],
        'choice' => ['single_select', 'multi_select', 'dropdown', 'likert_scale'],
        'geo' => ['geopoint', 'geotrace', 'geoshape'],
        'attachment' => ['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature'],
        'grid' => ['matrix', 'likert_matrix'],
        'isolated' => ['calculated', 'duration', 'yes_no', 'cascading_select'],
        'parking' => ['note', 'hidden'],
        'fixed' => ['page_break'],
    ];
}

/** The transcribed family of one type, read from the table above rather than from the engine. */
function fieldConversionFamilyOf(FieldType $type): string
{
    foreach (fieldConversionFamilyTable() as $family => $members) {
        if (in_array($type->value, $members, true)) {
            return $family;
        }
    }

    throw new LogicException("untabulated type {$type->value}");
}

/**
 * The oracle, written in a DIFFERENT FORM from the engine: a sequence of early returns over the transcribed
 * table, where the engine is a `match` over the enum. Two shapes of the same rule must agree on all 961 pairs.
 */
function fieldConversionExpected(FieldType $from, FieldType $to): bool
{
    if ($from === $to) {
        return false;
    }
    $f = fieldConversionFamilyOf($from);
    $t = fieldConversionFamilyOf($to);
    if ($f === 'fixed' || $t === 'fixed') {
        return false;
    }
    if ($t === 'parking' || $f === 'parking') {
        return true;
    }
    if ($f === 'isolated') {
        return false;
    }

    return $f === $t;
}

/**
 * The per-type default config the SERVICE passes in (FormBuilderService::defaultConfig() is private and
 * stays where it is). Transcribed for the unit tests only; the service test proves the real defaults.
 *
 * @return array<string, mixed>
 */
function fieldConversionDefault(FieldType $type): array
{
    return match (true) {
        $type === FieldType::CascadingSelect => ['levels' => [], 'options' => []],
        $type === FieldType::Matrix => ['rows' => [], 'columns' => [], 'cells' => []],
        $type === FieldType::LikertMatrix => ['rows' => [], 'columns' => []],
        $type->hasOptions() => ['options' => []],
        default => [],
    };
}

/**
 * @param  list<FormFieldValidation>  $rows
 */
function fieldConversionPlan(FormField $field, array $rows, FieldType $to, bool $repeatable = false): ConversionPlan
{
    return FieldTypeConversion::plan($field, $rows, $to, fieldConversionDefault($to), $repeatable);
}

/** The rule_value of a type's shipped default pattern. */
function fieldConversionDefaultPattern(FieldType $type): string
{
    return DefaultFieldRules::for($type)[0]['rule_value'];
}

/**
 * @param  list<FormFieldValidation>  $rows
 * @return list<string>
 */
function fieldConversionIds(array $rows): array
{
    return array_map(static fn (FormFieldValidation $v): string => (string) $v->id, $rows);
}

/** @return list<string> */
function fieldConversionWarningCodes(ConversionPlan $plan): array
{
    return array_map(static fn (ConversionWarning $w): string => $w->value, $plan->warnings);
}

/** An email field carrying its shipped default, a length rule and a custom pattern. */
function fieldConversionEmailFixture(): array
{
    $field = makeSchemaField(['id' => 'f-email', 'key' => 'contact', 'field_type' => FieldType::Email, 'config' => []]);
    $rows = [
        makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::Pattern, 'rule_value' => fieldConversionDefaultPattern(FieldType::Email), 'error_message' => 'Enter a valid email address.', 'sequence' => 0]),
        makeValidationRow(['id' => 'v2', 'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 1]),
        makeValidationRow(['id' => 'v3', 'rule_type' => ValidationRuleType::Pattern, 'rule_value' => '[a-z]+@corp\.test', 'sequence' => 2]),
    ];

    return [$field, $rows];
}

// ── The table ────────────────────────────────────────────────────────────────────────────────────────

it('classifies every field type into exactly the family the transcribed table says', function (): void {
    foreach (fieldConversionFamilyTable() as $family => $members) {
        $actual = array_values(array_map(
            static fn (FieldType $t): string => $t->value,
            array_filter(FieldType::cases(), static fn (FieldType $t): bool => FieldTypeConversion::family($t)->value === $family),
        ));
        expect($actual)->toEqualCanonicalizing($members, "family {$family}");
    }
});

it('partitions all thirty-one types exactly once and leaves no family empty', function (): void {
    $table = fieldConversionFamilyTable();
    $flat = array_merge(...array_values($table));

    expect(FieldType::cases())->toHaveCount(31)
        ->and(ConversionFamily::cases())->toHaveCount(10)
        ->and($flat)->toHaveCount(31)
        ->and(array_unique($flat))->toHaveCount(31)
        ->and($flat)->toEqualCanonicalizing(array_map(static fn (FieldType $t): string => $t->value, FieldType::cases()))
        ->and(array_keys($table))->toEqualCanonicalizing(array_map(static fn (ConversionFamily $f): string => $f->value, ConversionFamily::cases()));

    foreach ($table as $family => $members) {
        expect($members)->not->toBeEmpty("family {$family}");
    }
});

it('allows exactly 182 ordered pairs', function (): void {
    // ⛔ THE ANTI-VACUITY NUMBER. 68 within families (text 20, number 2, temporal 6, choice 12, geo 6,
    // attachment 20, grid 2) + 58 into note/hidden (29 each) + 56 out of them (2 × 28). A matrix that
    // allowed nothing, or everything, cannot land on it.
    $allowed = 0;
    foreach (FieldType::cases() as $from) {
        foreach (FieldType::cases() as $to) {
            $allowed += FieldTypeConversion::compatible($from, $to) ? 1 : 0;
        }
    }

    expect($allowed)->toBe(182);
});

it('agrees with an independently written oracle on every one of the 961 pairs', function (): void {
    foreach (FieldType::cases() as $from) {
        foreach (FieldType::cases() as $to) {
            expect(FieldTypeConversion::compatible($from, $to))
                ->toBe(fieldConversionExpected($from, $to), "{$from->value} -> {$to->value}");
        }
    }
});

it('refuses the D64 cross-shape pairs by name', function (FieldType $from, FieldType $to): void {
    expect(FieldTypeConversion::compatible($from, $to))->toBeFalse();
})->with([
    'photo to short text (D64 own example)' => [FieldType::ImageCapture, FieldType::ShortText],
    'gps to short text' => [FieldType::Geopoint, FieldType::ShortText],
    'matrix to single choice' => [FieldType::Matrix, FieldType::SingleSelect],
    'short text to whole number' => [FieldType::ShortText, FieldType::Integer],
    'date to duration' => [FieldType::Date, FieldType::Duration],
    'whole number to calculated' => [FieldType::Integer, FieldType::Calculated],
    'calculated to whole number' => [FieldType::Calculated, FieldType::Integer],
    'duration to decimal' => [FieldType::Duration, FieldType::Decimal],
    'yes/no to single choice' => [FieldType::YesNo, FieldType::SingleSelect],
    'single choice to yes/no' => [FieldType::SingleSelect, FieldType::YesNo],
    'cascading to single choice' => [FieldType::CascadingSelect, FieldType::SingleSelect],
    'single choice to cascading' => [FieldType::SingleSelect, FieldType::CascadingSelect],
    'a type to itself' => [FieldType::ShortText, FieldType::ShortText],
]);

it('allows the in-family pairs by name', function (FieldType $from, FieldType $to): void {
    expect(FieldTypeConversion::compatible($from, $to))->toBeTrue();
})->with([
    'email to phone' => [FieldType::Email, FieldType::Phone],
    'whole to decimal' => [FieldType::Integer, FieldType::Decimal],
    'decimal to whole' => [FieldType::Decimal, FieldType::Integer],
    'dropdown to single choice' => [FieldType::Dropdown, FieldType::SingleSelect],
    'single choice to likert' => [FieldType::SingleSelect, FieldType::LikertScale],
    'likert to multiple choice' => [FieldType::LikertScale, FieldType::MultiSelect],
    'gps point to area' => [FieldType::Geopoint, FieldType::Geoshape],
    'photo to file upload' => [FieldType::ImageCapture, FieldType::FileUpload],
    'matrix to likert matrix' => [FieldType::Matrix, FieldType::LikertMatrix],
    'date to date and time' => [FieldType::Date, FieldType::Datetime],
]);

it('makes note and hidden two-way for every type but page_break', function (): void {
    foreach (FieldType::cases() as $type) {
        foreach ([FieldType::Note, FieldType::Hidden] as $parking) {
            if ($type === FieldType::PageBreak || $type === $parking) {
                continue;
            }
            expect(FieldTypeConversion::compatible($type, $parking))->toBeTrue("{$type->value} -> {$parking->value}")
                ->and(FieldTypeConversion::compatible($parking, $type))->toBeTrue("{$parking->value} -> {$type->value}");
        }
    }
});

it('never converts to or from page_break', function (): void {
    expect(FieldTypeConversion::targetsFor(FieldType::PageBreak))->toBe([]);

    foreach (FieldType::cases() as $type) {
        expect(FieldTypeConversion::targetsFor($type))->not->toContain(FieldType::PageBreak);
    }
});

it('offers calculated, duration, yes/no and cascading only note and hidden', function (FieldType $type): void {
    expect(FieldTypeConversion::targetsFor($type))->toBe([FieldType::Note, FieldType::Hidden]);
})->with([FieldType::Calculated, FieldType::Duration, FieldType::YesNo, FieldType::CascadingSelect]);

it('orders targets family first in catalog order, then note, then hidden', function (): void {
    expect(FieldTypeConversion::targetsFor(FieldType::Email))
        ->toBe([FieldType::ShortText, FieldType::LongText, FieldType::Phone, FieldType::Url, FieldType::Note, FieldType::Hidden])
        ->and(FieldTypeConversion::targetsFor(FieldType::Integer))
        ->toBe([FieldType::Decimal, FieldType::Note, FieldType::Hidden])
        ->and(FieldTypeConversion::targetsFor(FieldType::Dropdown))
        ->toBe([FieldType::SingleSelect, FieldType::MultiSelect, FieldType::LikertScale, FieldType::Note, FieldType::Hidden]);

    $fromNote = FieldTypeConversion::targetsFor(FieldType::Note);
    expect($fromNote)->toHaveCount(29)
        ->and($fromNote[0])->toBe(FieldType::ShortText)
        ->and($fromNote)->toContain(FieldType::Calculated)
        ->and($fromNote[27])->toBe(FieldType::Matrix)
        ->and($fromNote[28])->toBe(FieldType::Hidden);

    $fromHidden = FieldTypeConversion::targetsFor(FieldType::Hidden);
    expect($fromHidden)->toHaveCount(29)
        ->and(end($fromHidden))->toBe(FieldType::Note);

    foreach (FieldType::cases() as $from) {
        $expected = array_values(array_filter(FieldType::cases(), static fn (FieldType $to): bool => FieldTypeConversion::compatible($from, $to)));
        expect(FieldTypeConversion::targetsFor($from))->toEqualCanonicalizing($expected, $from->value);
    }
});

// ── Planning: refusal ────────────────────────────────────────────────────────────────────────────────

it('refuses to plan an incompatible pair, naming both types', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Geopoint, 'config' => []]);

    expect(fn () => fieldConversionPlan($field, [], FieldType::ShortText))
        ->toThrow(FormException::class, 'This question cannot be changed from '.FieldType::Geopoint->label().' to '.FieldType::ShortText->label().'.');
});

// ── Planning: the default pattern ────────────────────────────────────────────────────────────────────

it('swaps the email default for the phone default and keeps every other rule', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $plan = fieldConversionPlan($field, $rows, FieldType::Phone);

    expect(fieldConversionIds($plan->kept))->toBe(['v2', 'v3'])
        ->and(array_map(static fn (array $d): string => (string) $d['row']->id, $plan->dropped))->toBe(['v1'])
        ->and($plan->dropped[0]['reason'])->toBe(ConversionDropReason::TypeDefault)
        ->and($plan->added)->toHaveCount(1)
        ->and($plan->added[0]['rule_type'])->toBe(ValidationRuleType::Pattern)
        ->and($plan->added[0]['rule_value'])->toBe(fieldConversionDefaultPattern(FieldType::Phone))
        ->and($plan->added[0]['error_message'])->toBe(DefaultFieldRules::for(FieldType::Phone)[0]['error_message'])
        ->and($plan->added[0]['sequence'])->toBe(3)
        ->and($plan->lossless())->toBeFalse()
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('drops the email default on the way to short text and adds nothing', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $plan = fieldConversionPlan($field, $rows, FieldType::ShortText);

    expect(fieldConversionIds($plan->kept))->toBe(['v2', 'v3'])
        ->and($plan->dropped)->toHaveCount(1)
        ->and($plan->dropped[0]['reason'])->toBe(ConversionDropReason::TypeDefault)
        ->and($plan->added)->toBe([]);
});

it('adds the email default on the way from short text, after the rows already there', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ShortText, 'config' => []]);
    $rows = [makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 4])];
    $plan = fieldConversionPlan($field, $rows, FieldType::Email);

    expect(fieldConversionIds($plan->kept))->toBe(['v1'])
        ->and($plan->added)->toHaveCount(1)
        ->and($plan->added[0]['rule_value'])->toBe(fieldConversionDefaultPattern(FieldType::Email))
        ->and($plan->added[0]['sequence'])->toBe(5)
        ->and($plan->lossless())->toBeTrue()
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('recognises the default by its pattern, not by its message', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Email, 'config' => []]);
    $rows = [makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::Pattern, 'rule_value' => fieldConversionDefaultPattern(FieldType::Email), 'error_message' => 'Hand-edited wording.', 'sequence' => 0])];

    expect(fieldConversionPlan($field, $rows, FieldType::Url)->dropped)->toHaveCount(1);
});

it('does not add a default the field already carries', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ShortText, 'config' => []]);
    $rows = [makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::Pattern, 'rule_value' => fieldConversionDefaultPattern(FieldType::Phone), 'sequence' => 0])];
    $plan = fieldConversionPlan($field, $rows, FieldType::Phone);

    expect(fieldConversionIds($plan->kept))->toBe(['v1'])
        ->and($plan->added)->toBe([]);
});

// ── Planning: parking targets ────────────────────────────────────────────────────────────────────────

/** A short-text field carrying a length rule, a condition and an expression row, required conditionally. */
function fieldConversionBusyTextFixture(): array
{
    $field = makeSchemaField([
        'field_type' => FieldType::ShortText,
        'is_required' => RequiredMode::Conditional,
        'config' => ['x' => 1],
        'default_value' => 'hello',
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Text,
    ]);
    $rows = [
        makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 0]),
        makeValidationRow(['id' => 'v2', 'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'yes', 'related_form_field_id' => 'r-9', 'sequence' => 1]),
        makeValidationRow(['id' => 'v3', 'expression' => "\${contact} != ''", 'sequence' => 2]),
    ];

    return [$field, $rows];
}

it('drops every rule on the way to hidden, including the ones a text shape would allow', function (): void {
    [$field, $rows] = fieldConversionBusyTextFixture();
    $plan = fieldConversionPlan($field, $rows, FieldType::Hidden);

    expect($plan->kept)->toBe([])
        ->and($plan->dropped)->toHaveCount(3)
        ->and(array_unique(array_map(static fn (array $d): string => $d['reason']->value, $plan->dropped)))
        ->toBe([ConversionDropReason::TargetTakesNoRules->value]);
});

it('makes a field Optional on the way to hidden', function (): void {
    [$field, $rows] = fieldConversionBusyTextFixture();
    $plan = fieldConversionPlan($field, $rows, FieldType::Hidden);

    expect($plan->changes[0])->toBe(['column' => 'is_required', 'from' => RequiredMode::Conditional, 'to' => RequiredMode::Optional])
        ->and($plan->fieldAttributes()['is_required'])->toBe(RequiredMode::Optional);
});

it('gives a field arriving at hidden the hidden default config and names what it dropped', function (): void {
    [$field, $rows] = fieldConversionBusyTextFixture();
    $plan = fieldConversionPlan($field, $rows, FieldType::Hidden);

    expect($plan->config)->toBe([])
        ->and($plan->configDropped)->toBe(['x'])
        ->and(fieldConversionWarningCodes($plan))->toContain(ConversionWarning::HiddenNeedsPrefillSource->value);
});

it('keeps a hidden field default value and indexing, because hidden answers', function (): void {
    [$field, $rows] = fieldConversionBusyTextFixture();
    $columns = array_column(fieldConversionPlan($field, $rows, FieldType::Hidden)->changes, 'column');

    expect($columns)->toBe(['is_required']);
});

it('clears the default value, expression flag and indexing on the way to note', function (): void {
    [$field, $rows] = fieldConversionBusyTextFixture();
    $field->default_value_is_expression = true;
    $plan = fieldConversionPlan($field, $rows, FieldType::Note);

    expect(array_column($plan->changes, 'column'))->toBe(['is_required', 'default_value', 'default_value_is_expression', 'is_queryable', 'indexed_data_type'])
        ->and($plan->fieldAttributes())->toMatchArray([
            'default_value' => null,
            'default_value_is_expression' => false,
            'is_queryable' => false,
            'indexed_data_type' => null,
        ])
        ->and($plan->kept)->toBe([])
        ->and(fieldConversionWarningCodes($plan))->not->toContain(ConversionWarning::HiddenNeedsPrefillSource->value);
});

// ── Planning: rules within a family ──────────────────────────────────────────────────────────────────

it('never drops a governing rule within a family', function (): void {
    foreach (FieldType::cases() as $from) {
        foreach (FieldTypeConversion::targetsFor($from) as $to) {
            if (FieldTypeConversion::family($to) === ConversionFamily::Parking || FieldTypeConversion::family($from) === ConversionFamily::Parking) {
                continue;
            }
            $field = makeSchemaField(['field_type' => $from, 'is_required' => RequiredMode::Conditional, 'config' => fieldConversionDefault($from)]);
            $rows = [
                makeValidationRow(['id' => 'g1', 'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'y', 'related_form_field_id' => 'r', 'sequence' => 0]),
                makeValidationRow(['id' => 'g2', 'rule_type' => ValidationRuleType::RequiredWith, 'related_form_field_id' => 'r', 'sequence' => 1]),
            ];
            $plan = fieldConversionPlan($field, $rows, $to);

            expect(fieldConversionIds($plan->kept))->toBe(['g1', 'g2'], "{$from->value} -> {$to->value}");
        }
    }
});

it('does not rewrite a requiredness setting it did not break', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ShortText, 'is_required' => RequiredMode::Conditional, 'config' => []]);
    $plan = fieldConversionPlan($field, [], FieldType::LongText);

    expect($plan->changes)->toBe([])
        ->and($plan->lossless())->toBeTrue()
        ->and($plan->requiresConfirmation())->toBeFalse();
});

// ── Planning: config transfer ────────────────────────────────────────────────────────────────────────

it('drops the cells on the way from matrix to likert matrix', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Matrix, 'config' => ['rows' => [['value' => 'r']], 'columns' => [['value' => 'c']], 'cells' => [['value' => 'x']]]]);
    $plan = fieldConversionPlan($field, [], FieldType::LikertMatrix);

    expect($plan->config)->not->toHaveKey('cells')
        ->and($plan->config['rows'])->toBe([['value' => 'r']])
        ->and($plan->configDropped)->toBe(['cells']);
});

it('warns that a likert matrix arriving at matrix needs its cells', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::LikertMatrix, 'config' => ['rows' => [['value' => 'r']], 'columns' => [['value' => 'c']]]]);
    $plan = fieldConversionPlan($field, [], FieldType::Matrix);

    expect($plan->config['cells'])->toBe([])
        ->and($plan->configDropped)->toBe([])
        ->and(fieldConversionWarningCodes($plan))->toBe([ConversionWarning::NeedsSetup->value]);
});

/** @return array<string, mixed> */
function fieldConversionImageConfig(): array
{
    return ['accepted_types' => ['image/png'], 'capture_source' => 'camera', 'min_count' => 1, 'max_count' => 3, 'max_file_size_bytes' => 1000];
}

it('resets the accepted file types when media changes kind', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ImageCapture, 'config' => fieldConversionImageConfig()]);
    $plan = fieldConversionPlan($field, [], FieldType::AudioCapture);

    expect($plan->configDropped)->toBe(['accepted_types'])
        ->and($plan->config)->toBe(['capture_source' => 'camera', 'min_count' => 1, 'max_count' => 3, 'max_file_size_bytes' => 1000]);
});

it('drops a capture source the target cannot show', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ImageCapture, 'config' => fieldConversionImageConfig()]);
    $plan = fieldConversionPlan($field, [], FieldType::FileUpload);

    expect($plan->configDropped)->toBe(['accepted_types', 'capture_source'])
        ->and($plan->config)->toBe(['min_count' => 1, 'max_count' => 3, 'max_file_size_bytes' => 1000]);
});

it('treats an empty accepted-types list as nothing to lose', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ImageCapture, 'config' => ['accepted_types' => []]]);

    expect(fieldConversionPlan($field, [], FieldType::VideoCapture)->lossless())->toBeTrue();
});

it('carries options and their translations verbatim across the choice family', function (FieldType $to): void {
    $options = [
        ['value' => 'r', 'label' => 'Red', 'label_translations' => ['es' => 'Rojo', 'fil' => 'Pula'], 'extra' => 'kept'],
        ['value' => 'b', 'label' => 'Blue'],
    ];
    $field = makeSchemaField(['field_type' => FieldType::SingleSelect, 'config' => ['options' => $options]]);
    $plan = fieldConversionPlan($field, [], $to);

    expect($plan->config['options'])->toBe($options)
        ->and($plan->configDropped)->toBe([]);
})->with([FieldType::Dropdown, FieldType::MultiSelect, FieldType::LikertScale]);

it('drops the score bounds when a likert scale becomes a single choice', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::LikertScale, 'config' => ['options' => [['value' => '1', 'label' => 'Low']]]]);
    $rows = [
        makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '1', 'sequence' => 0]),
        makeValidationRow(['id' => 'v2', 'rule_type' => ValidationRuleType::MaxValue, 'rule_value' => '5', 'sequence' => 1]),
    ];
    $plan = fieldConversionPlan($field, $rows, FieldType::SingleSelect);

    expect($plan->kept)->toBe([])
        ->and(array_map(static fn (array $d): ConversionDropReason => $d['reason'], $plan->dropped))
        ->toBe([ConversionDropReason::NotAllowedForShape, ConversionDropReason::NotAllowedForShape]);
});

it('warns when a likert scale would score choices that are not numbers', function (): void {
    $words = makeSchemaField(['field_type' => FieldType::SingleSelect, 'config' => ['options' => [['value' => 'low', 'label' => 'Low']]]]);
    $digits = makeSchemaField(['field_type' => FieldType::SingleSelect, 'config' => ['options' => [['value' => '1', 'label' => 'Low'], ['value' => '2', 'label' => 'High']]]]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($words, [], FieldType::LikertScale)))->toBe([ConversionWarning::ScaleValuesNotNumeric->value])
        ->and(fieldConversionPlan($digits, [], FieldType::LikertScale)->warnings)->toBe([]);
});

it('warns that a reportable single choice cannot be indexed as a multiple choice', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::SingleSelect, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text, 'config' => ['options' => [['value' => 'a', 'label' => 'A']]]]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::MultiSelect)))->toBe([ConversionWarning::IndexingUnavailable->value])
        ->and(fieldConversionPlan($field, [], FieldType::Dropdown)->warnings)->toBe([]);
});

it('warns that an index type chosen on a multiple choice may not suit a single choice (M134)', function (): void {
    // Since M134 a multi-select is its own ValueShape (MultipleChoice), so leaving it for a single choice changes the
    // kind of value the author's index type was chosen for; before the split the two shared one shape, and no
    // warning was given. The reverse direction is not indexable at all, so it says nothing more than it did.
    $field = makeSchemaField(['field_type' => FieldType::MultiSelect, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text, 'config' => ['options' => [['value' => 'a', 'label' => 'A']]]]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::SingleSelect)))->toContain(ConversionWarning::IndexedTypeMayNotSuit->value)
        ->and(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::Dropdown)))->toContain(ConversionWarning::IndexedTypeMayNotSuit->value);
});

it('does not repeat an indexing warning the source already deserved', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Geopoint, 'is_queryable' => true, 'config' => []]);

    expect(fieldConversionPlan($field, [], FieldType::Geotrace)->warnings)->toBe([]);
});

// ── Planning: the lossless pairs (the palette-merge contract, R-a367bf9e) ─────────────────────────────

it('keeps short and long text, and whole and decimal numbers, lossless with no confirmation', function (FieldType $from, FieldType $to, ?ValidationRuleType $rule): void {
    $field = makeSchemaField(['field_type' => $from, 'config' => []]);
    $rows = $rule === null ? [] : [makeValidationRow(['id' => 'v1', 'rule_type' => $rule, 'rule_value' => '2', 'sequence' => 0])];
    $plan = fieldConversionPlan($field, $rows, $to);

    expect($plan->lossless())->toBeTrue()
        ->and($plan->requiresConfirmation())->toBeFalse()
        ->and(fieldConversionIds($plan->kept))->toBe($rule === null ? [] : ['v1']);
})->with([
    'short to long, bare' => [FieldType::ShortText, FieldType::LongText, null],
    'long to short, with a length rule' => [FieldType::LongText, FieldType::ShortText, ValidationRuleType::MinLength],
    'whole to decimal, bare' => [FieldType::Integer, FieldType::Decimal, null],
    'decimal to whole, with a bound' => [FieldType::Decimal, FieldType::Integer, ValidationRuleType::MinValue],
]);

it('warns that a decimal default may not suit a whole number', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Decimal, 'default_value' => '2.5', 'config' => []]);
    $bare = makeSchemaField(['field_type' => FieldType::Decimal, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::Integer)))->toBe([ConversionWarning::DefaultValueFormat->value])
        ->and(fieldConversionPlan($bare, [], FieldType::Integer)->warnings)->toBe([]);
});

it('warns that a date default may not suit a time, and is silent with no default', function (): void {
    $dated = makeSchemaField(['field_type' => FieldType::Date, 'default_value' => '2026-01-15', 'config' => []]);
    $bare = makeSchemaField(['field_type' => FieldType::Date, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($dated, [], FieldType::Time)))->toBe([ConversionWarning::DefaultValueFormat->value])
        ->and(fieldConversionPlan($bare, [], FieldType::Time)->warnings)->toBe([])
        ->and(fieldConversionPlan($bare, [], FieldType::Time)->lossless())->toBeTrue();
});

it('warns when an expression default arrives at hidden, where it would be stored verbatim', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::ShortText, 'default_value' => 'today()', 'default_value_is_expression' => true, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::Hidden)))->toContain(ConversionWarning::DefaultValueFormat->value);
});

it('warns that a reportable date indexed as a date will not suit a date and time', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Date, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Date, 'config' => []]);
    $text = makeSchemaField(['field_type' => FieldType::ShortText, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::Datetime)))->toBe([ConversionWarning::IndexedTypeMayNotSuit->value])
        ->and(fieldConversionPlan($text, [], FieldType::LongText)->warnings)->toBe([]);
});

// ── Planning: leaving note and hidden ────────────────────────────────────────────────────────────────

it('gives a field leaving hidden the target default config and names the prefill it dropped', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Hidden, 'config' => ['prefill_source' => 'url', 'url_param' => 'ref']]);
    $plan = fieldConversionPlan($field, [], FieldType::SingleSelect);

    expect($plan->config)->toBe(['options' => []])
        ->and($plan->configDropped)->toBe(['prefill_source', 'url_param'])
        ->and(fieldConversionWarningCodes($plan))->toBe([ConversionWarning::NeedsSetup->value]);
});

it('warns that a calculated field reached from note needs a formula', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Note, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::Calculated)))->toBe([ConversionWarning::CalculatedNeedsFormula->value]);
});

it('adds the email default to a note that becomes an email', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Note, 'config' => []]);
    $plan = fieldConversionPlan($field, [], FieldType::Email);

    expect($plan->added)->toHaveCount(1)
        ->and($plan->added[0]['sequence'])->toBe(0);
});

it('keeps a text rule on a hidden field that becomes short text', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Hidden, 'config' => []]);
    $rows = [makeValidationRow(['id' => 'v1', 'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 0])];

    expect(fieldConversionIds(fieldConversionPlan($field, $rows, FieldType::ShortText)->kept))->toBe(['v1']);
});

it('warns that a cascade reached from hidden needs its levels', function (): void {
    $field = makeSchemaField(['field_type' => FieldType::Hidden, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($field, [], FieldType::CascadingSelect)))->toContain(ConversionWarning::NeedsSetup->value);
});

it('warns when the target cannot be published inside a repeatable section', function (): void {
    $text = makeSchemaField(['field_type' => FieldType::ShortText, 'config' => []]);
    $geo = makeSchemaField(['field_type' => FieldType::Geopoint, 'config' => []]);

    expect(fieldConversionWarningCodes(fieldConversionPlan($text, [], FieldType::Hidden, true)))->toContain(ConversionWarning::RepeatableSectionRefusesType->value)
        ->and(fieldConversionWarningCodes(fieldConversionPlan($text, [], FieldType::Hidden, false)))->not->toContain(ConversionWarning::RepeatableSectionRefusesType->value)
        // Already refused there before the conversion, so the conversion adds nothing to warn about.
        ->and(fieldConversionPlan($geo, [], FieldType::Geotrace, true)->warnings)->toBe([]);
});

// ── The public array and the fingerprint ─────────────────────────────────────────────────────────────

it('carries no ids of any kind in its public array', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $rows[1]->related_form_field_id = 'rel-0001';
    $rows[1]->logic_group = 'grp-0001';
    $array = fieldConversionPlan($field, $rows, FieldType::Phone)->toArray();
    $json = json_encode($array, JSON_THROW_ON_ERROR);

    foreach (['v1', 'v2', 'v3', 'rel-0001', 'grp-0001', 'f-email'] as $id) {
        expect($json)->not->toContain("\"{$id}\"");
    }
    expect(array_keys($array['kept'][0]))->toBe(['sequence', 'rule_type', 'operator', 'rule_value', 'expression', 'error_message'])
        ->and(array_keys($array['dropped'][0]))->toBe(['sequence', 'rule_type', 'operator', 'rule_value', 'expression', 'error_message', 'reason', 'reason_message'])
        ->and(array_keys($array['added'][0]))->toBe(['sequence', 'rule_type', 'rule_value', 'error_message'])
        ->and($array['from'])->toBe('email')
        ->and($array['to'])->toBe('phone')
        ->and($array['fingerprint'])->toMatch('/^[0-9a-f]{64}$/');
});

it('moves the fingerprint when anything about a rule row changes', function (Closure $mutate): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $before = fieldConversionPlan($field, $rows, FieldType::Phone)->fingerprint();

    // The rows are objects, so the closure edits them in place; nothing is returned.
    [$field2, $rows2] = fieldConversionEmailFixture();
    $mutate($rows2);
    $after = fieldConversionPlan($field2, $rows2, FieldType::Phone)->fingerprint();

    expect($after)->not->toBe($before);
})->with([
    'a kept row value' => [function (array $rows): void {
        $rows[1]->rule_value = '3';
    }],
    'a kept row message' => [function (array $rows): void {
        $rows[1]->error_message = 'Too short';
    }],
    'a kept row related field' => [function (array $rows): void {
        $rows[1]->related_form_field_id = 'other';
    }],
    'a kept row logic group' => [function (array $rows): void {
        $rows[1]->logic_group = 'grp';
    }],
    'a kept row logic operator' => [function (array $rows): void {
        $rows[1]->logic_operator = LogicOperator::Or;
    }],
    'a kept row translations' => [function (array $rows): void {
        $rows[1]->error_message_translations = ['es' => 'Corto'];
    }],
    'a dropped row message' => [function (array $rows): void {
        $rows[0]->error_message = 'Changed';
    }],
]);

it('moves the fingerprint when a row is added or the target differs', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $base = fieldConversionPlan($field, $rows, FieldType::Phone)->fingerprint();

    $extra = [...$rows, makeValidationRow(['id' => 'v4', 'rule_type' => ValidationRuleType::MaxLength, 'rule_value' => '9', 'sequence' => 3])];

    expect(fieldConversionPlan($field, $extra, FieldType::Phone)->fingerprint())->not->toBe($base)
        ->and(fieldConversionPlan($field, $rows, FieldType::Url)->fingerprint())->not->toBe($base);
});

it('keeps the fingerprint when only labels, row ids or option labels change', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $base = fieldConversionPlan($field, $rows, FieldType::Phone)->fingerprint();

    [$relabelled, $renumbered] = fieldConversionEmailFixture();
    $relabelled->label = 'Something else';
    $relabelled->hint = 'A hint';
    $relabelled->placeholder = 'you@example.test';
    foreach ($renumbered as $i => $row) {
        $row->id = "new-{$i}";
    }

    expect(fieldConversionPlan($relabelled, $rows, FieldType::Phone)->fingerprint())->toBe($base)
        ->and(fieldConversionPlan($field, $renumbered, FieldType::Phone)->fingerprint())->toBe($base);

    $choice = makeSchemaField(['field_type' => FieldType::SingleSelect, 'config' => ['options' => [['value' => 'a', 'label' => 'A']]]]);
    $renamed = makeSchemaField(['field_type' => FieldType::SingleSelect, 'config' => ['options' => [['value' => 'a', 'label' => 'Apple']]]]);
    expect(fieldConversionPlan($choice, [], FieldType::Dropdown)->fingerprint())
        ->toBe(fieldConversionPlan($renamed, [], FieldType::Dropdown)->fingerprint());
});

it('canonicalises translation key order, which jsonb does not preserve', function (): void {
    [$field, $rows] = fieldConversionEmailFixture();
    $rows[1]->error_message_translations = ['es' => 'Corto', 'fil' => 'Maikli'];
    $one = fieldConversionPlan($field, $rows, FieldType::Phone)->fingerprint();

    [$field2, $rows2] = fieldConversionEmailFixture();
    $rows2[1]->error_message_translations = ['fil' => 'Maikli', 'es' => 'Corto'];

    expect(fieldConversionPlan($field2, $rows2, FieldType::Phone)->fingerprint())->toBe($one);
});

it('moves the fingerprint when a config key it will drop is added', function (): void {
    $bare = makeSchemaField(['field_type' => FieldType::ShortText, 'config' => []]);
    $keyed = makeSchemaField(['field_type' => FieldType::ShortText, 'config' => ['x' => 1]]);

    expect(fieldConversionPlan($keyed, [], FieldType::Note)->fingerprint())
        ->not->toBe(fieldConversionPlan($bare, [], FieldType::Note)->fingerprint());
});

it('gives every warning and every drop reason a sentence', function (): void {
    expect(ConversionWarning::cases())->toHaveCount(8)
        ->and(ConversionDropReason::cases())->toHaveCount(3);

    foreach ([...ConversionWarning::cases(), ...ConversionDropReason::cases()] as $case) {
        expect($case->message())->toBeString()->not->toBe('');
    }
});
