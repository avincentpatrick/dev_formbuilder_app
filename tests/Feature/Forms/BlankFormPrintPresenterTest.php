<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// I12's render model. Most cases drive HAND-BUILT canonical snapshots on unsaved models, because the
// snapshot IS the contract this class consumes and building one by hand is the only way to pin an
// ordering, a locale fallback or a malformed entry precisely.
//
// The last case in the file does the opposite and goes through a REAL publish, which is the only
// thing that can catch the failure mode hand-built fixtures are blind to: assuming a key name the
// serializer does not actually emit. Both halves are needed; neither substitutes for the other.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->present = app(BlankFormPrintPresenter::class);
});

/**
 * An unsaved form + version carrying a hand-built snapshot. Unsaved on purpose: nothing this class
 * reads is persisted state, so a round trip through the database would only slow the suite down and
 * add RLS to the list of things a failure could mean.
 *
 * @param  array<string, mixed>  $snapshot
 */
function printFixture(array $snapshot, string $locale = 'en', string $title = 'Household Survey'): array
{
    $form = new Form(['title' => $title, 'description' => null, 'default_locale' => $locale]);
    $version = new FormVersion(['version_number' => 2, 'schema_snapshot' => $snapshot]);

    return [$form, $version];
}

/**
 * A canonical-shaped field entry. Only the keys under test are passed in; the serializer emits many
 * more and the presenter must not depend on them being present.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function printField(string $key, string $type = 'short_text', array $extra = []): array
{
    return array_merge([
        'key' => $key,
        'section_key' => null,
        'field_type' => $type,
        'config' => [],
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'label_translations' => null,
        'hint' => null,
        'hint_translations' => null,
        'is_required' => RequiredMode::Optional->value,
        'relevant_expression' => null,
        'sequence' => 0,
        'section_sequence' => 0,
        'validations' => [],
    ], $extra);
}

/** @return list<string> the field keys of every emitted block, flattened in printed order */
function printedKeys(array $model): array
{
    $keys = [];
    foreach ($model['blocks'] as $block) {
        foreach ($block['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }

    return $keys;
}

it('prints fields in AUTHORED order, not in the snapshot\'s alphabetical key order', function (): void {
    // ⚠️ THE CASE THIS FILE EXISTS FOR, and the one defect here most likely to ship looking correct.
    //
    // SchemaSnapshotSerializer sorts both lists by `key` (SORT_STRING) so the checksum is stable
    // across row-id churn — it MUST, and that order is alphabetical rather than authored. A
    // presenter that renders the list as it arrives produces a shuffled form, which is entirely
    // plausible on any single example.
    //
    // So this fixture's key order is the EXACT REVERSE of its sequence order. A presence-only
    // assertion ("all four keys appear") passes against the broken implementation; an order
    // assertion cannot.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('alpha', 'short_text', ['sequence' => 3, 'section_sequence' => 3]),
            printField('bravo', 'short_text', ['sequence' => 2, 'section_sequence' => 2]),
            printField('charlie', 'short_text', ['sequence' => 1, 'section_sequence' => 1]),
            printField('delta', 'short_text', ['sequence' => 0, 'section_sequence' => 0]),
        ],
    ]);

    expect(printedKeys($this->present->present($form, $version)))
        ->toBe(['delta', 'charlie', 'bravo', 'alpha']);
});

it('orders sections by sequence and leads with the ungrouped fields', function (): void {
    // Same trap one level up, plus StepProjection's LEAD_STEP_KEY convention carried onto paper:
    // fields with no section come FIRST, whatever their section-relative sequence says.
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'a_last', 'label' => 'Last', 'sequence' => 9, 'is_repeatable' => false],
            ['key' => 'z_first', 'label' => 'First', 'sequence' => 1, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('in_last', 'short_text', ['section_key' => 'a_last', 'sequence' => 5]),
            printField('in_first', 'short_text', ['section_key' => 'z_first', 'sequence' => 6]),
            printField('ungrouped', 'short_text', ['section_key' => null, 'sequence' => 7]),
        ],
    ]);

    $model = $this->present->present($form, $version);

    expect(printedKeys($model))->toBe(['ungrouped', 'in_first', 'in_last'])
        ->and(array_column($model['blocks'], 'label'))->toBe([null, 'First', 'Last']);
});

it('drops a section that holds nothing printable, heading included', function (): void {
    // StepProjection's predicate 2, on paper. A heading over an empty panel tells the person holding
    // the sheet that they missed something.
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'ghost', 'label' => 'Internal', 'sequence' => 1, 'is_repeatable' => false],
            ['key' => 'real', 'label' => 'Real', 'sequence' => 2, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('secret', 'hidden', ['section_key' => 'ghost']),
            printField('total', 'calculated', ['section_key' => 'ghost']),
            printField('name', 'short_text', ['section_key' => 'real']),
        ],
    ]);

    $model = $this->present->present($form, $version);

    expect(array_column($model['blocks'], 'label'))->toBe(['Real'])
        ->and(printedKeys($model))->toBe(['name']);
});

it('combs a date into captioned DD / MM / YYYY groups', function (): void {
    // The layout decision the whole increment turns on: a free run of eight boxes cannot distinguish
    // the 3rd of April from the 4th of March, and no recognizer can recover that from the ink.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [printField('visit_date', 'date')],
    ]);

    $comb = $this->present->present($form, $version)['blocks'][0]['fields'][0]['comb'];

    expect($comb)->toBe([
        ['cells' => 2, 'caption' => 'DD'],
        ['cells' => 2, 'caption' => 'MM'],
        ['cells' => 4, 'caption' => 'YYYY'],
    ]);
});

it('narrows a comb to an authored max_length and clamps a page-breaking one', function (): void {
    // The clamp is not cosmetic: dompdf CLIPS an over-wide table rather than wrapping it, so an
    // authored max_length of 255 would silently lose the right-hand end of the row in the PDF while
    // every model-level assertion stayed green.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('code', 'short_text', ['sequence' => 0, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '6'],
            ]]),
            printField('essay', 'short_text', ['sequence' => 1, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '255'],
            ]]),
            printField('plain', 'short_text', ['sequence' => 2]),
        ],
    ]);

    $fields = $this->present->present($form, $version)['blocks'][0]['fields'];

    expect($fields[0]['comb'])->toBe([['cells' => 6, 'caption' => null]])
        ->and($fields[1]['comb'])->toBe([['cells' => 30, 'caption' => null]])
        ->and($fields[2]['comb'])->toBe([['cells' => 24, 'caption' => null]]);
});

it('marks a field a pen cannot answer instead of dropping it or boxing it', function (): void {
    // Decided with the user 2026-08-09. Omitting these leaves an enumerator with no prompt to capture
    // the reading by another means; boxing them invites writing into an area nothing will ever read.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('where', 'geopoint', ['sequence' => 0]),
            printField('photo', 'image_capture', ['sequence' => 1]),
            printField('sign_here', 'signature', ['sequence' => 2]),
        ],
    ]);

    $fields = $this->present->present($form, $version)['blocks'][0]['fields'];

    expect(array_column($fields, 'area'))->toBe(['unavailable', 'unavailable', 'signature_line'])
        ->and(array_column($fields, 'key'))->toBe(['where', 'photo', 'sign_here']);
});

it('prints a grid with both axes resolved, though the version is not OCR eligible', function (): void {
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('satisfaction', 'likert_matrix', ['config' => [
                'rows' => [['value' => 'svc', 'label' => 'Service'], ['value' => 'fac', 'label' => 'Facility']],
                'columns' => [['value' => '1', 'label' => 'Low'], ['value' => '2', 'label' => 'High']],
            ]]),
        ],
    ]);

    $model = $this->present->present($form, $version);
    $grid = $model['blocks'][0]['fields'][0]['grid'];

    expect(array_column($grid['rows'], 'label'))->toBe(['Service', 'Facility'])
        ->and(array_column($grid['columns'], 'label'))->toBe(['Low', 'High'])
        // The paper prints it; the footer tells the printer the scans still cannot be auto-read.
        ->and($model['ocr_compatible'])->toBeFalse();
});

it('resolves field labels and option labels into the form default locale', function (): void {
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('color', 'single_select', [
                'label' => 'Colour',
                'label_translations' => ['fil' => 'Kulay'],
                'config' => ['options' => [
                    ['value' => 'r', 'label' => 'Red', 'label_translations' => ['fil' => 'Pula']],
                    // No variant: falls back to the base label, never to the raw value.
                    ['value' => 'b', 'label' => 'Blue'],
                ]],
            ]),
        ],
    ], locale: 'fil');

    $field = $this->present->present($form, $version)['blocks'][0]['fields'][0];

    expect($field['label'])->toBe('Kulay')
        ->and(array_column($field['options'], 'label'))->toBe(['Pula', 'Blue']);
});

it('numbers a repeatable section and honours min_instances up to the printed cap', function (): void {
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'members', 'label' => 'Household member', 'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 3],
            ['key' => 'roster', 'label' => 'Roster', 'sequence' => 2, 'is_repeatable' => true, 'min_instances' => 40],
            ['key' => 'once', 'label' => 'Once', 'sequence' => 3, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('member_name', 'short_text', ['section_key' => 'members']),
            printField('roster_name', 'short_text', ['section_key' => 'roster']),
            printField('plain', 'short_text', ['section_key' => 'once']),
        ],
    ]);

    $blocks = $this->present->present($form, $version)['blocks'];

    // 3 numbered + 5 (capped from 40) + 1 unnumbered.
    expect(count($blocks))->toBe(9)
        ->and(array_column($blocks, 'instance'))->toBe([1, 2, 3, 1, 2, 3, 4, 5, null]);
});

it('skips a field type from the future rather than throwing', function (): void {
    // CapabilityFlags' `tryFrom` reasoning, carried here: a snapshot written by a newer schema than
    // the reading code is a row from the future, not a 500. It must not take the whole print with it.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('mystery', 'quantum_slider', ['sequence' => 0]),
            printField('name', 'short_text', ['sequence' => 1]),
        ],
    ]);

    expect(printedKeys($this->present->present($form, $version)))->toBe(['name']);
});

it('emits no blocks for a draft version, whose snapshot is empty by construction', function (): void {
    // The route refuses a draft outright; this pins WHY that refusal is load-bearing rather than
    // decorative — without it the request would 200 with a titled document containing no questions.
    [$form, $version] = printFixture([]);

    expect($this->present->present($form, $version)['blocks'])->toBe([]);
});

it('reads the key names a REAL publish actually writes', function (): void {
    // ⚠️ The one case a hand-built fixture cannot cover. Every assertion above would pass unchanged
    // if this presenter read `section` where the serializer emits `section_key`, or `required` where
    // it emits `is_required` — the fixtures would simply carry the wrong names too. Only driving the
    // real SchemaSnapshotSerializer output can falsify that.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Intake');
    $draft = $form->draftVersion;

    $section = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'contact',
        'label' => 'Contact details',
        'sequence' => 1,
        'created_by' => $this->user->id,
    ]);

    addFormField($draft, $this->user, 'full_name', FieldType::ShortText, 0, [
        'is_required' => RequiredMode::Required,
        'hint' => 'As written on the ID.',
    ]);
    addFormField($draft, $this->user, 'email', FieldType::Email, 1, [
        'form_section_id' => $section->id,
        'section_sequence' => 0,
    ]);
    addFormField($draft, $this->user, 'internal_ref', FieldType::Hidden, 2);

    $published = app(PublishService::class)->publish($form->refresh(), $this->user);
    $model = $this->present->present($form->refresh(), $published);

    expect($model['form_title'])->toBe('Intake')
        ->and($model['version_number'])->toBe(1)
        // Eight characters of the checksum, so a loose scanned sheet ties back to this exact schema.
        ->and($model['schema_stamp'])->toBe(substr((string) $published->checksum, 0, 8))
        ->and($model['ocr_compatible'])->toBeTrue()
        // `internal_ref` is Omitted; the section heading and its member resolved through the real
        // `section_key` FK-by-key the serializer writes.
        ->and(printedKeys($model))->toBe(['full_name', 'email'])
        ->and(array_column($model['blocks'], 'label'))->toBe([null, 'Contact details']);

    $lead = $model['blocks'][0]['fields'][0];
    expect($lead['required'])->toBeTrue()
        ->and($lead['hint'])->toBe('As written on the ID.')
        ->and($lead['area'])->toBe('comb');
});
