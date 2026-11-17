<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
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

it('maps required yes/blank to required/optional, and a formula to Required when… (M244)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'text', 'name' => 'must', 'label' => 'M', 'required' => 'yes'],
        ['type' => 'text', 'name' => 'may', 'label' => 'O', 'required' => ''],
        ['type' => 'text', 'name' => 'nope', 'label' => 'N', 'required' => 'false()'],
        ['type' => 'select_one cuts', 'name' => 'cut', 'label' => 'Cut'],
        ['type' => 'text', 'name' => 'why', 'label' => 'Why', 'required' => '${cut} = \'change\'', 'required_message' => 'Say why'],
    ], [
        ['list_name' => 'cuts', 'name' => 'keep', 'label' => 'Keep'],
        ['list_name' => 'cuts', 'name' => 'change', 'label' => 'Change'],
    ]));

    $why = xlsformField($plan, 'why');
    expect(xlsformField($plan, 'must')->isRequired)->toBe(RequiredMode::Required)
        ->and(xlsformField($plan, 'may')->isRequired)->toBe(RequiredMode::Optional)
        ->and(xlsformField($plan, 'nope')->isRequired)->toBe(RequiredMode::Optional)
        ->and($why->isRequired)->toBe(RequiredMode::Conditional)
        ->and($why->validations)->toHaveCount(1)
        ->and($why->validations[0]->ruleType)->toBe(ValidationRuleType::RequiredIf)
        ->and($why->validations[0]->relatedField?->key)->toBe('cut')
        ->and($why->validations[0]->ruleValue)->toBe('change')
        ->and($why->validations[0]->errorMessage)->toBe('Say why')
        ->and($plan->warnings)->toBe([]);
});

it('imports a required formula it cannot read as optional, naming it (M244)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'integer', 'name' => 'n', 'label' => 'N'],
        ['type' => 'text', 'name' => 'why', 'label' => 'Why', 'required' => '${n} * 2 > 3'],
    ]));

    expect(xlsformField($plan, 'why')->isRequired)->toBe(RequiredMode::Optional)
        ->and($plan->warnings)->toHaveCount(1)
        ->and($plan->warnings[0])->toContain('“why”')->toContain('${n} * 2 > 3')->toContain('optional');
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

it('keeps a constraint it cannot convert as one expression validation row', function (): void {
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

it('turns or_other into an Other choice and a Please specify question right after it (M177, R-71509975)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [
            ['type' => 'select_one jobs or_other', 'name' => 'job', 'label' => 'Job'],
            ['type' => 'text', 'name' => 'notes', 'label' => 'Notes'],
        ],
        choices: [
            ['list_name' => 'jobs', 'name' => 'farmer', 'label' => 'Farmer'],
        ],
    ));

    expect(array_map(static fn (FieldSpec $f): string => $f->key, $plan->fields))->toBe(['job', 'job_other', 'notes']);
    $job = xlsformField($plan, 'job');
    expect($job->config['options'])->toBe([['value' => 'farmer', 'label' => 'Farmer'], ['value' => 'other', 'label' => 'Other']])
        ->and($job->config['other_specify_key'])->toBe('job_other');
    $other = xlsformField($plan, 'job_other');
    expect($other->fieldType)->toBe(FieldType::ShortText)
        ->and($other->label)->toBe('Please specify')
        ->and($other->isRequired)->toBe(RequiredMode::Required)
        ->and($other->relevantExpression)->toBe("selected(\${job}, 'other')");
});

it('keeps the or_other pair linked when the choice question’s name is sanitised (M177)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb(
        survey: [['type' => 'select_multiple jobs or_other', 'name' => 'Job!', 'label' => 'Job']],
        choices: [['list_name' => 'jobs', 'name' => 'farmer', 'label' => 'Farmer']],
    ));

    [$job, $other] = $plan->fields;
    expect($job->key)->toBe('job_')
        ->and($job->config['other_specify_key'])->toBe($other->key)
        ->and($other->relevantExpression)->toBe("selected(\${job_}, 'other')");
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

    // M183: a typed grid's cells come back as questions of their columns' types, so the warning no longer says single-select.
    expect(xlsformField($plan, 'grid_q1_c1')->fieldType)->toBe(FieldType::SingleSelect)
        ->and(implode(' ', $plan->warnings))->toContain('not importable')
        ->and(implode(' ', $plan->warnings))->toContain('its cells were imported as separate questions, each of its column\'s type');
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

it('names the row the author sees, past blank rows the reader skipped (M244)', function (): void {
    $wb = new RawWorkbook([
        'survey' => ['headers' => [], 'rows' => [
            ['type' => 'text', 'name' => 'a', 'label' => 'A'],
            ['type' => 'rank', 'name' => 'b', 'label' => 'B'],
        ], 'row_numbers' => [2, 7]],
    ]);

    try {
        xlsformParser()->parse($wb);
        $this->fail('expected XlsformImportException');
    } catch (XlsformImportException $e) {
        expect($e->details())->toBe(['row' => 7, 'type' => 'rank']);
    }
});

it('refuses every cell too long to store, once, naming each (M244)', function (): void {
    $wb = new RawWorkbook([
        'survey' => ['headers' => [], 'rows' => [
            ['type' => 'begin group', 'name' => 'g', 'label' => str_repeat('s', 256)],
            ['type' => 'text', 'name' => 'q', 'label' => str_repeat('q', 501), 'label::fr' => str_repeat('f', 501), 'hint' => str_repeat('h', 2001)],
            ['type' => 'note', 'name' => 'n', 'label' => 'Hello ${q} '.str_repeat('n', 500)],
            ['type' => 'select_one l', 'name' => 'c', 'label' => 'C', 'constraint_message' => str_repeat('m', 501)],
            ['type' => 'end group', 'name' => 'g'],
        ], 'row_numbers' => [2, 3, 5, 6, 7]],
        'choices' => ['headers' => [], 'rows' => [
            ['list_name' => 'l', 'name' => 'x', 'label' => str_repeat('c', 501)],
        ], 'row_numbers' => [4]],
        'settings' => ['headers' => [], 'rows' => [['form_title' => str_repeat('t', 256)]]],
    ]);

    try {
        xlsformParser()->parse($wb);
        $this->fail('expected XlsformImportException');
    } catch (XlsformImportException $e) {
        $cells = array_map(static fn (array $cell): string => "{$cell['sheet']}:{$cell['row']}:{$cell['column']}:{$cell['length']}/{$cell['limit']}", $e->details()['cells']);
        expect($e->code())->toBe('xlsform_cell_too_long')
            ->and($cells)->toBe([
                'choices:4:label:501/500',
                'survey:2:label:256/255',
                'survey:3:label:501/500',
                'survey:3:label::fr:501/500',
                'survey:3:hint:2001/2000',
                'survey:5:label:511/500',
                'survey:6:constraint_message:501/500',
                'settings:2:form_title:256/255',
            ])
            ->and($e->getMessage())->toStartWith('8 cells are longer than this app can store: choices row 4, label (501 of 500 characters);');
    }
});

it('turns a long or formatted note into content and keeps a plain short one as its label (M244)', function (): void {
    $long = str_repeat('Long note words. ', 60);
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'note', 'name' => 'plain', 'label' => 'Just words.'],
        ['type' => 'note', 'name' => 'styled', 'label' => "# Welcome\n\nSome **bold** words."],
        ['type' => 'note', 'name' => 'long', 'label' => $long],
        ['type' => 'note', 'name' => 'piped', 'label' => 'Hello **${plain}**'],
        ['type' => 'note', 'name' => 'translated', 'label' => 'Some **bold**', 'label::fil' => 'Ilang **makapal**'],
    ]));

    $styled = xlsformField($plan, 'styled');
    $longNote = xlsformField($plan, 'long');

    expect(xlsformField($plan, 'plain')->label)->toBe('Just words.')
        ->and(xlsformField($plan, 'plain')->config)->toBe([])
        ->and($styled->config['content'])->toBe([
            ['type' => 'heading', 'level' => 1, 'text' => 'Welcome'],
            ['type' => 'paragraph', 'spans' => [['text' => 'Some '], ['text' => 'bold', 'bold' => true], ['text' => ' words.']]],
        ])
        ->and($styled->label)->toBe("Welcome\nSome bold words.")
        ->and(mb_strlen($longNote->label))->toBeLessThanOrEqual(500)
        ->and($longNote->label)->toEndWith('…')
        ->and($longNote->config['content'][0]['spans'][0]['text'])->toBe(trim($long))
        ->and(xlsformField($plan, 'piped')->label)->toBe('Hello ${plain}')
        ->and(xlsformField($plan, 'piped')->config)->toBe([])
        ->and(xlsformField($plan, 'translated')->label)->toBe('Some bold')
        ->and(xlsformField($plan, 'translated')->labelTranslations)->toBe(['fil' => 'Ilang makapal'])
        ->and(xlsformField($plan, 'translated')->config)->toBe([])
        ->and($plan->warnings)->toHaveCount(2);
});

it('takes formatting out of question labels, hints and choices with one warning each (M244)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'select_one yn', 'name' => 'q', 'label' => 'Do you **agree**?', 'hint' => 'See [the guide](https://example.org)'],
        ['type' => 'text', 'name' => 'n', 'label' => '1. Name'],
    ], [
        ['list_name' => 'yn', 'name' => 'y', 'label' => '*Yes*'],
        ['list_name' => 'yn', 'name' => 'n', 'label' => 'No'],
    ]));

    $q = xlsformField($plan, 'q');
    expect($q->label)->toBe('Do you agree?')
        ->and($q->hint)->toBe('See the guide (https://example.org)')
        ->and($q->config['options'][0]['label'])->toBe('Yes')
        ->and(xlsformField($plan, 'n')->label)->toBe('1. Name')
        ->and($plan->warnings)->toHaveCount(3);
});

it('reads Kobo\'s label::English (en) columns as the base text and codes (M244)', function (): void {
    $wb = new RawWorkbook([
        'survey' => ['headers' => ['type', 'name', 'label::English (en)', 'label::Filipino (fil)', 'hint::English (en)'], 'rows' => [
            ['type' => 'text', 'name' => 'q', 'label::English (en)' => 'Name', 'label::Filipino (fil)' => 'Pangalan', 'hint::English (en)' => 'Full name'],
            ['type' => 'select_one yn', 'name' => 'c', 'label::English (en)' => 'OK?'],
        ]],
        'choices' => ['headers' => [], 'rows' => [
            ['list_name' => 'yn', 'name' => 'y', 'label::English (en)' => 'Yes', 'label::Filipino (fil)' => 'Oo'],
        ]],
        'settings' => ['headers' => [], 'rows' => [['default_language' => 'English (en)']]],
    ]);

    $plan = xlsformParser()->parse($wb);

    $q = xlsformField($plan, 'q');
    expect($q->label)->toBe('Name')
        ->and($q->labelTranslations)->toBe(['fil' => 'Pangalan'])
        ->and($q->hint)->toBe('Full name')
        ->and(xlsformField($plan, 'c')->config['options'][0])->toBe(['value' => 'y', 'label' => 'Yes', 'label_translations' => ['fil' => 'Oo']])
        ->and($plan->settings->defaultLanguage)->toBe('en')
        ->and($plan->warnings)->toBe([]);
});

it('renames count-selected, converts a whole regex, and names what it keeps (M244, D228)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'select_multiple l', 'name' => 'pick', 'label' => 'Pick', 'constraint' => 'count-selected(.) <= 2', 'relevant' => 'string-length(${essay}) > 0'],
        ['type' => 'text', 'name' => 'essay', 'label' => 'Essay', 'constraint' => 'regex(., \'^[a-z ]+$\')', 'constraint_message' => 'Letters only'],
        ['type' => 'integer', 'name' => 'n', 'label' => 'N', 'constraint' => 'regex(., \'^[0-9]+$\')'],
    ], [['list_name' => 'l', 'name' => 'a', 'label' => 'A']]));

    $pick = xlsformField($plan, 'pick');
    $essay = xlsformField($plan, 'essay');
    expect($pick->validations[0]->expression)->toBe('count(.) <= 2')
        ->and($essay->validations)->toHaveCount(1)
        ->and($essay->validations[0]->ruleType)->toBe(ValidationRuleType::Pattern)
        ->and($essay->validations[0]->ruleValue)->toBe('[a-z ]+')
        ->and($essay->validations[0]->errorMessage)->toBe('Letters only')
        ->and(xlsformField($plan, 'n')->validations[0]->expression)->toBe('regex(., \'^[0-9]+$\')')
        ->and($plan->warnings)->toBe([
            'The relevant of “pick” uses “string-length”, which this app does not have. It was kept as written, so the form will not publish until it is changed.',
            'The constraint of “n” uses “regex”, which this app does not have. It was kept as written, so the form will not publish until it is changed.',
        ]);
});

it('names each picture, sound or video a row names, which cannot come with the workbook (M244)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'note', 'name' => 'diagram', 'label' => 'See below', 'media::image' => 'nhete-diagram.png'],
        ['type' => 'select_one l', 'name' => 'c', 'label' => 'C', 'audio' => 'question.mp3'],
    ], [['list_name' => 'l', 'name' => 'a', 'label' => 'A', 'media::image::English (en)' => 'a.png']]));

    expect($plan->warnings)->toBe([
        'Choices row 2: “a” names the file “a.png”, which an import cannot bring in. Upload it in the builder.',
        'Row 2: “diagram” names the file “nhete-diagram.png”, which an import cannot bring in. Upload it in the builder.',
        'Row 3: “c” names the file “question.mp3”, which an import cannot bring in. Upload it in the builder.',
    ]);
});

it('shortens a key past the column\'s 150 characters, suffix included (M244)', function (): void {
    $plan = xlsformParser()->parse(xlsformWb([
        ['type' => 'text', 'name' => str_repeat('a', 160), 'label' => 'One'],
        ['type' => 'text', 'name' => str_repeat('a', 170), 'label' => 'Two'],
    ]));

    expect(strlen($plan->fields[0]->key))->toBe(150)
        ->and(strlen($plan->fields[1]->key))->toBe(150)
        ->and($plan->fields[1]->key)->toEndWith('_2');
});
