<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Services\Xlsform\CascadeResolver;
use App\Services\Xlsform\Dto\FieldSpec;
use App\Services\Xlsform\Dto\ImportPlan;
use App\Services\Xlsform\Dto\RawWorkbook;
use App\Services\Xlsform\GeoWireConverter;
use App\Services\Xlsform\XlsformImportParser;
use App\Services\Xlsform\XlsformTypeMap;

/*
|--------------------------------------------------------------------------
| Increment G7b — the pure, DB-free XLSForm import parser (docs/xlsform-interop-spec.md §2–§6): upfront
| validation, per-column mapping, lossy downgrades + warnings, key sanitization, and both cascade shapes.
|--------------------------------------------------------------------------
*/

/** A parser wired from the G7a foundation (no container — Unit does not boot the app). */
function xlsformParser(): XlsformImportParser
{
    return new XlsformImportParser(new XlsformTypeMap, new GeoWireConverter, new CascadeResolver);
}

/**
 * Build a RawWorkbook from header-keyed row lists (the reader's output shape); `headers` are unused by the
 * parser, which reads columns case-insensitively off each row.
 *
 * @param  list<array<string, ?string>>  $survey
 * @param  list<array<string, ?string>>  $choices
 * @param  list<array<string, ?string>>  $settings
 */
function xlsformWb(array $survey, array $choices = [], array $settings = []): RawWorkbook
{
    return new RawWorkbook([
        'survey' => ['headers' => [], 'rows' => $survey],
        'choices' => ['headers' => [], 'rows' => $choices],
        'settings' => ['headers' => [], 'rows' => $settings],
    ]);
}

/** Find a parsed field by key. */
function xlsformField(ImportPlan $plan, string $key): ?FieldSpec
{
    foreach ($plan->fields as $field) {
        if ($field->key === $key) {
            return $field;
        }
    }

    return null;
}

it('rejects an unmapped type UPFRONT with row + type details', function (): void {
    $wb = xlsformWb([
        ['type' => 'text', 'name' => 'a', 'label' => 'A'],
        ['type' => 'rank', 'name' => 'b', 'label' => 'B'],
    ]);

    try {
        xlsformParser()->parse($wb);
        $this->fail('expected XlsformImportException');
    } catch (XlsformImportException $e) {
        expect($e->code())->toBe('xlsform_unsupported_field_type')
            ->and($e->details())->toBe(['row' => 3, 'type' => 'rank']); // header row 1 + 2nd data row
    }
});

it('rejects a workbook with no survey sheet', function (): void {
    $wb = new RawWorkbook(['choices' => ['headers' => [], 'rows' => []]]);

    expect(fn () => xlsformParser()->parse($wb))
        ->toThrow(XlsformImportException::class);

    try {
        xlsformParser()->parse($wb);
    } catch (XlsformImportException $e) {
        expect($e->code())->toBe('xlsform_missing_survey_sheet');
    }
});

it('maps required yes/blank to required/optional and never conditional', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'text', 'name' => 'must', 'label' => 'M', 'required' => 'yes'],
        ['type' => 'text', 'name' => 'may', 'label' => 'O', 'required' => ''],
    ]));

    expect(xlsformField($plan, 'must')->isRequired)->toBe(RequiredMode::Required)
        ->and(xlsformField($plan, 'may')->isRequired)->toBe(RequiredMode::Optional);
});

it('routes a calculate field formula to config and a calculation on a plain field to an expression default', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'calculate', 'name' => 'total', 'calculation' => '${a} + 1'],
        ['type' => 'text', 'name' => 'greeting', 'calculation' => 'concat("hi")'],
    ]));

    $calc = xlsformField($plan, 'total');
    expect($calc->fieldType)->toBe(FieldType::Calculated)
        ->and($calc->config['calculated_formula'])->toBe('${a} + 1')
        ->and($calc->defaultValue)->toBeNull();

    $greeting = xlsformField($plan, 'greeting');
    expect($greeting->defaultValue)->toBe('concat("hi")')
        ->and($greeting->defaultValueIsExpression)->toBeTrue();
});

it('deserialises a geo default through the wire converter (lat/lon flip)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'geopoint', 'name' => 'home', 'default' => '14.6 121 0 0'],
    ]));

    $envelope = json_decode((string) xlsformField($plan, 'home')->defaultValue, true);
    expect($envelope['type'])->toBe('Point')
        ->and($envelope['coordinates'])->toEqual([121.0, 14.6]); // longitude-first internal order (JSON re-casts 121.0→121)
});

it('stores a constraint as one expression validation row (never structured)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'integer', 'name' => 'age', 'constraint' => '. >= 0', 'constraint_message' => 'Too small'],
    ]));

    $validations = xlsformField($plan, 'age')->validations;
    expect($validations)->toHaveCount(1)
        ->and($validations[0]->expression)->toBe('. >= 0')
        ->and($validations[0]->errorMessage)->toBe('Too small');
});

it('imports a dynamic repeat_count as an unbounded repeat with a warning', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'begin repeat', 'name' => 'roster', 'label' => 'Roster', 'repeat_count' => '${n}'],
        ['type' => 'text', 'name' => 'who', 'label' => 'Who'],
        ['type' => 'end repeat', 'name' => 'roster'],
    ]));

    expect($plan->sections[0]->isRepeatable)->toBeTrue()
        ->and($plan->sections[0]->maxInstances)->toBeNull()
        ->and(implode(' ', $plan->warnings))->toContain('dynamic repeat_count');
});

it('sanitises an illegal name into a valid key with a warning', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'text', 'name' => 'My Field!', 'label' => 'X'],
    ]));

    expect($plan->fields[0]->key)->toBe('my_field_')
        ->and(implode(' ', $plan->warnings))->toContain('not a valid key');
});

it('never infers yes_no from a two-option select_one (conservative, §3)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [['type' => 'select_one yn', 'name' => 'ok', 'label' => 'OK']],
        choices: [
            ['list_name' => 'yn', 'name' => 'yes', 'label' => 'Yes'],
            ['list_name' => 'yn', 'name' => 'no', 'label' => 'No'],
        ],
    ));

    expect(xlsformField($plan, 'ok')->fieldType)->toBe(FieldType::SingleSelect);
});

it('builds select options from the choices sheet and drops a synthetic appearance', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'select_one colors', 'name' => 'color', 'label' => 'Colour'],
            ['type' => 'text', 'name' => 'bio', 'label' => 'Bio', 'appearance' => 'multiline'],
        ],
        choices: [
            ['list_name' => 'colors', 'name' => 'r', 'label' => 'Red'],
            ['list_name' => 'colors', 'name' => 'b', 'label' => 'Blue'],
        ],
    ));

    $color = xlsformField($plan, 'color');
    expect($color->fieldType)->toBe(FieldType::SingleSelect)
        ->and($color->config['options'])->toBe([
            ['value' => 'r', 'label' => 'Red'],
            ['value' => 'b', 'label' => 'Blue'],
        ]);

    // A `text` + `multiline` LongText must NOT keep the synthetic `multiline` appearance.
    $bio = xlsformField($plan, 'bio');
    expect($bio->fieldType)->toBe(FieldType::LongText)
        ->and($bio->appearance)->toBeNull();
});

it('reconstructs our own cascading export (marker + level/parent columns)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'select_one region', 'name' => 'region', 'label' => 'Region', '#meridian' => 'meridian:cascading'],
        ],
        choices: [
            ['list_name' => 'region', 'name' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => ''],
            ['list_name' => 'region', 'name' => 'manila', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
        ],
    ));

    $field = xlsformField($plan, 'region');
    expect($field->fieldType)->toBe(FieldType::CascadingSelect)
        ->and($field->config['levels'])->toBe([['key' => 'region'], ['key' => 'province']]);

    $manila = collect($field->config['options'])->firstWhere('value', 'manila');
    expect($manila)->toMatchArray(['level' => 'province', 'parent' => 'ncr', 'label' => 'Manila']);

    $ncr = collect($field->config['options'])->firstWhere('value', 'ncr');
    expect($ncr['parent'])->toBeNull(); // root option
});

it('collapses a foreign multi-question choice_filter cascade into one field', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'select_one states', 'name' => 'state', 'label' => 'State'],
            ['type' => 'select_one counties', 'name' => 'county', 'label' => 'County', 'choice_filter' => 'state=${state}'],
        ],
        choices: [
            ['list_name' => 'states', 'name' => 'ca', 'label' => 'California'],
            ['list_name' => 'counties', 'name' => 'la', 'label' => 'Los Angeles', 'state' => 'ca'],
        ],
    ));

    // The two questions collapse to ONE cascading field, keyed by the root.
    expect($plan->fields)->toHaveCount(1);
    $field = xlsformField($plan, 'state');
    expect($field->fieldType)->toBe(FieldType::CascadingSelect)
        ->and($field->config['levels'])->toBe([['key' => 'state'], ['key' => 'county']]);

    $la = collect($field->config['options'])->firstWhere('value', 'la');
    expect($la)->toMatchArray(['level' => 'county', 'parent' => 'ca'])
        ->and(implode(' ', $plan->warnings))->toContain('Collapsed');
});

it('downgrades a matrix grid marker to single selects with a warning', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'select_one grid_cells', 'name' => 'grid_q1_c1', 'label' => 'Q1 — C1', '#meridian' => 'meridian:matrix:grid'],
        ],
        choices: [
            ['list_name' => 'grid_cells', 'name' => 'y', 'label' => 'Yes'],
        ],
    ));

    expect(xlsformField($plan, 'grid_q1_c1')->fieldType)->toBe(FieldType::SingleSelect)
        ->and(implode(' ', $plan->warnings))->toContain('not importable');
});

it('nests fields under a group and maps settings without a version', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'text', 'name' => 'top', 'label' => 'Top'],
            ['type' => 'begin group', 'name' => 'sect', 'label' => 'Section'],
            ['type' => 'text', 'name' => 'inner', 'label' => 'Inner'],
            ['type' => 'end group', 'name' => 'sect'],
        ],
        settings: [
            ['form_title' => 'My Form', 'form_id' => 'my-form', 'version' => '99', 'default_language' => 'en'],
        ],
    ));

    expect($plan->sections)->toHaveCount(1)
        ->and(xlsformField($plan, 'top')->sectionKey)->toBeNull()
        ->and(xlsformField($plan, 'inner')->sectionKey)->toBe('sect')
        ->and($plan->settings->formTitle)->toBe('My Form')
        ->and($plan->settings->formId)->toBe('my-form')
        ->and($plan->settings->defaultLanguage)->toBe('en');
});
