<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Expressions\Coercion;
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

it('sizes the long-text box from its max_length: three lines at least, ten at most (layout 3, R-d696ba9e)', function (): void {
    // The user's second comment on layout 2: a paragraph had nowhere to go in a fixed 60pt box. The box is
    // now `lines` tall — about 45 hand-printed block capitals to a line, never fewer than three (a long text
    // with no max_length is the common case) and never more than ten (`.q` cannot split across pages). The
    // template turns a line into 20pt, so three lines IS layout 2's 60pt. An open `line` box carries no
    // count at all: a short text's 255 is a sanity cap, not a promise of six lines of writing.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('plain', 'long_text', ['sequence' => 0]),
            printField('brief', 'long_text', ['sequence' => 1, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '50'],
            ]]),
            printField('story', 'long_text', ['sequence' => 2, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '200'],
            ]]),
            printField('full_page', 'long_text', ['sequence' => 3, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '450'],
            ]]),
            printField('essay', 'long_text', ['sequence' => 4, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '600'],
            ]]),
            printField('nickname', 'short_text', ['sequence' => 5, 'validations' => [
                ['rule_type' => 'max_length', 'rule_value' => '255'],
            ]]),
        ],
    ]);

    $fields = $this->present->present($form, $version)['blocks'][0]['fields'];

    expect(array_map(static fn (array $f): ?int => $f['lines'], $fields))->toBe([3, 3, 5, 10, 10, null])
        ->and($fields[5]['area'])->toBe('line');
});

it('gives short text, email, url, a phone and a number one open box, and keeps the calendar combed (layout 3, D98)', function (): void {
    // The user's finding on the first printed paper: a box per letter limits respondents. Text moved to
    // an open box in layout 2; a phone and a number followed in layout 3 (D98 A), because the reader
    // parses a digit string from free text as well as from boxes. A date stays combed: DD MM YYYY under
    // captions is the one comb that buys correctness rather than convenience.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('full_name', 'short_text', ['sequence' => 0]),
            printField('email', 'email', ['sequence' => 1]),
            printField('website', 'url', ['sequence' => 2]),
            printField('remarks', 'long_text', ['sequence' => 3]),
            printField('mobile', 'phone', ['sequence' => 4]),
            printField('age', 'integer', ['sequence' => 5, 'validations' => [
                ['rule_type' => 'max_value', 'rule_value' => '120'],
            ]]),
            printField('weight', 'decimal', ['sequence' => 6]),
            printField('visit', 'date', ['sequence' => 7]),
        ],
    ]);

    $fields = $this->present->present($form, $version)['blocks'][0]['fields'];

    expect(array_column($fields, 'area'))->toBe(['line', 'line', 'line', 'ruled', 'line', 'line', 'line', 'comb'])
        ->and(array_map(static fn (array $f): mixed => $f['comb'], array_slice($fields, 0, 7)))->toBe(array_fill(0, 7, null))
        ->and($fields[3]['lines'])->toBe(3)
        ->and($fields[7]['comb'])->toBe([
            ['cells' => 2, 'caption' => 'DD'],
            ['cells' => 2, 'caption' => 'MM'],
            ['cells' => 4, 'caption' => 'YYYY'],
        ]);
});

it('numbers every question across blocks and repeat instances, skipping prose and page breaks (layout 2)', function (): void {
    // The number is its own key, never folded into `label`: the matcher anchors a scan on the label
    // and the bake-off names its columns by it. A repeatable section's copies number on through, so
    // "3." on the paper means one question however many copies print.
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'members', 'label' => 'Household member', 'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 2],
        ],
        'fields' => [
            printField('intro', 'note', ['section_key' => null, 'sequence' => 0]),
            printField('respondent', 'short_text', ['section_key' => null, 'sequence' => 1]),
            printField('new_page', 'page_break', ['section_key' => 'members', 'section_sequence' => 0]),
            printField('member_age', 'integer', ['section_key' => 'members', 'section_sequence' => 1]),
        ],
    ]);

    $model = $this->present->present($form, $version);

    $numbers = [];
    foreach ($model['blocks'] as $block) {
        foreach ($block['fields'] as $field) {
            $numbers[] = $field['number'];
        }
    }

    expect(printedKeys($model))->toBe(['intro', 'respondent', 'new_page', 'member_age', 'new_page', 'member_age'])
        ->and($numbers)->toBe([null, 1, null, 2, null, 3])
        ->and($model['blocks'][0]['fields'][1]['label'])->toBe('Respondent')
        ->and($model['layout'])->toBe(3);
});

it('says whether the form accepts scans, so the footer can promise only what is true (R-6bbf9d73)', function (): void {
    // `ocr_compatible` is a fact about the VERSION; whether scanning is switched on is a fact about
    // the FORM. The footer needs both. A fresh or unsaved form has the setting off.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [printField('name', 'short_text')],
    ]);

    expect($this->present->present($form, $version)['accepts_scans'])->toBeFalse();

    $form->allow_ocr_single = true;

    expect($this->present->present($form, $version)['accepts_scans'])->toBeTrue();
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

it('gives a cascading select one captioned comb run per level', function (): void {
    // The review fix. Its option pool is flat across every level, so it is WRITTEN per level rather
    // than picked — the shape of a paper address block. Captions come from the level KEY, which is
    // the identifier an extraction stage maps back to and is short enough to sit over its group.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('address', 'cascading_select', ['config' => [
                'levels' => [['key' => 'province'], ['key' => 'city'], ['key' => 'barangay']],
                'options' => [
                    ['value' => 'ncr', 'label' => 'NCR', 'level' => 'province'],
                    ['value' => 'manila', 'label' => 'Manila', 'level' => 'city', 'parent' => 'ncr'],
                ],
            ]]),
        ],
    ]);

    $field = $this->present->present($form, $version)['blocks'][0]['fields'][0];

    expect($field['area'])->toBe('comb')
        // 23 cells (layout 2's page-derived ceiling) split three ways.
        ->and($field['comb'])->toBe([
            ['cells' => 7, 'caption' => 'PROVINCE'],
            ['cells' => 7, 'caption' => 'CITY'],
            ['cells' => 7, 'caption' => 'BARANGAY'],
        ])
        // ...and emphatically NOT a tick-list that would set Manila beside NCR as its sibling.
        ->and($field['options'])->toBe([]);
});

it('still gives a level-less cascading select somewhere to write', function (): void {
    // StructuralValidationGate refuses to publish one, so this is the hand-built-snapshot path.
    // Zero groups would render a labelled question with no answer area at all.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [printField('address', 'cascading_select')],
    ]);

    expect($this->present->present($form, $version)['blocks'][0]['fields'][0]['comb'])
        ->toBe([['cells' => 23, 'caption' => null]]);
});

it('truncates a comb caption without cutting a multibyte character in half', function (): void {
    // `substr` would split the UTF-8 sequence, and Blade's e() is htmlspecialchars(..., 'UTF-8')
    // with no ENT_SUBSTITUTE — which returns the EMPTY STRING on invalid input. The caption would
    // not error, it would silently disappear.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('address', 'cascading_select', ['config' => [
                'levels' => [['key' => 'rehiyonnglungsod']],
            ]]),
        ],
    ]);

    $caption = $this->present->present($form, $version)['blocks'][0]['fields'][0]['comb'][0]['caption'];

    expect($caption)->toBe('REHIYONNGL')
        ->and(mb_check_encoding((string) $caption, 'UTF-8'))->toBeTrue();
});

it('marks a conditional SECTION on its heading, not only conditional fields', function (): void {
    // Review fix. A `relevant_expression` on a section lives on the section row and its member
    // fields carry nothing, so the per-field marker cannot see it — a whole block that may not apply
    // printed with no marker anywhere on the page.
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'maybe', 'label' => 'Pregnancy', 'sequence' => 1, 'is_repeatable' => false, 'relevant_expression' => '${sex} = "f"'],
            ['key' => 'always', 'label' => 'Always', 'sequence' => 2, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('weeks', 'integer', ['section_key' => 'maybe']),
            printField('name', 'short_text', ['section_key' => 'always']),
        ],
    ]);

    $blocks = $this->present->present($form, $version)['blocks'];

    expect(array_column($blocks, 'conditional'))->toBe([true, false])
        // The member field itself carries no expression, which is exactly why the section flag is
        // needed — without it this block would print entirely unmarked.
        ->and($blocks[0]['fields'][0]['conditional'])->toBeFalse();
});

it('prints an orphaned field rather than silently losing it', function (): void {
    // ⚠️ Review fix, and the worst failure this increment could have: a field whose `section_key`
    // matches no section was grouped into a bucket no loop read, so the QUESTION VANISHED FROM THE
    // INSTRUMENT with nothing anywhere to say so. The publish path cannot currently produce this
    // shape — the guard is here because the cost of being wrong is a lost question.
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'real', 'label' => 'Real', 'sequence' => 1, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('kept', 'short_text', ['section_key' => 'real', 'sequence' => 0]),
            printField('orphan', 'short_text', ['section_key' => 'no_such_section', 'sequence' => 1]),
        ],
    ]);

    // Printed last, under no heading — but printed.
    expect(printedKeys($this->present->present($form, $version)))->toBe(['kept', 'orphan']);
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
        ->and($lead['area'])->toBe('line')
        ->and($lead['number'])->toBe(1)
        ->and($model['layout'])->toBe(3)
        // Created through FormService, which leaves scanning off.
        ->and($model['accepts_scans'])->toBeFalse();
});

// Increment M124 (`R-8c517fb6`) — a page break prints, but it is not a question. Until M124 a section holding only
// a break printed its heading over nothing, and a section opening with one printed its heading at the foot of a
// page, stranded above its own questions on the next.

it('prints no heading over a section that holds nothing but a page break', function (): void {
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'one', 'label' => 'One', 'sequence' => 1, 'is_repeatable' => false],
            ['key' => 'breaker', 'label' => 'Breaker', 'sequence' => 2, 'is_repeatable' => false],
            ['key' => 'two', 'label' => 'Two', 'sequence' => 3, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('q1', 'short_text', ['section_key' => 'one']),
            printField('cut', 'page_break', ['section_key' => 'breaker']),
            printField('q2', 'short_text', ['section_key' => 'two']),
        ],
    ]);

    $model = $this->present->present($form, $version);

    expect(array_column($model['blocks'], 'label'))->toBe(['One', null, 'Two'])
        ->and(printedKeys($model))->toBe(['q1', 'cut', 'q2']);
});

it('prints a section\'s leading page break before its heading, never after it', function (): void {
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'one', 'label' => 'One', 'sequence' => 1, 'is_repeatable' => false],
            ['key' => 'two', 'label' => 'Two', 'sequence' => 2, 'is_repeatable' => false],
        ],
        'fields' => [
            printField('q1', 'short_text', ['section_key' => 'one']),
            printField('cut', 'page_break', ['section_key' => 'two', 'section_sequence' => 0]),
            printField('q2', 'short_text', ['section_key' => 'two', 'section_sequence' => 1]),
        ],
    ]);

    $model = $this->present->present($form, $version);

    expect(array_column($model['blocks'], 'label'))->toBe(['One', null, 'Two'])
        ->and(printedKeys($model))->toBe(['q1', 'cut', 'q2']);
});

it('opens and closes the sheet on a question, and prints one break for a run of them', function (): void {
    // A break before the first question opens on a blank page, one after the last closes on one, and two with
    // nothing between them leave a blank page between their neighbours. The one between q1 and q2 is real.
    [$form, $version] = printFixture([
        'sections' => [],
        'fields' => [
            printField('opening', 'page_break', ['sequence' => 0]),
            printField('q1', 'short_text', ['sequence' => 1]),
            printField('first_of_run', 'page_break', ['sequence' => 2]),
            printField('second_of_run', 'page_break', ['sequence' => 3]),
            printField('q2', 'short_text', ['sequence' => 4]),
            printField('closing', 'page_break', ['sequence' => 5]),
        ],
    ]);

    expect(printedKeys($this->present->present($form, $version)))->toBe(['q1', 'second_of_run', 'q2']);
});

it('starts every repeat instance on a new page without stranding its numbered heading', function (): void {
    [$form, $version] = printFixture([
        'sections' => [
            ['key' => 'members', 'label' => 'Household member', 'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 2],
        ],
        'fields' => [
            printField('respondent', 'short_text', ['section_key' => null]),
            printField('new_page', 'page_break', ['section_key' => 'members', 'section_sequence' => 0]),
            printField('member_name', 'short_text', ['section_key' => 'members', 'section_sequence' => 1]),
        ],
    ]);

    $blocks = $this->present->present($form, $version)['blocks'];

    expect(array_column($blocks, 'instance'))->toBe([null, null, 1, null, 2])
        ->and(array_column($blocks, 'label'))->toBe([null, null, 'Household member', null, 'Household member'])
        ->and(printedKeys(['blocks' => $blocks]))->toBe(['respondent', 'new_page', 'member_name', 'new_page', 'member_name']);
});

it('gives a yes/no question its two tick boxes, not the write-in box of a broken choice list (M128)', function (): void {
    // ⚠️ A yes/no answer has no stored options (its two values are fixed), so the old option read was
    // EMPTY and the template's empty branch printed a ruled "write it in" box — on the paper the OCR
    // samples are printed from. Measured on the seeded `Patient Intake`: `consent | choices | options=0`.
    [$form, $version] = printFixture(['sections' => [], 'fields' => [
        printField('consent', 'yes_no', ['sequence' => 1]),
        printField('colour', 'single_select', ['sequence' => 2, 'config' => ['options' => [['value' => 'r', 'label' => 'Red']]]]),
    ]]);

    [$consent, $colour] = $this->present->present($form, $version)['blocks'][0]['fields'];

    expect($consent['area'])->toBe('choices')
        ->and($consent['options'])->toBe([['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']])
        // A real option list is untouched by the yes/no arm.
        ->and($colour['options'])->toBe([['value' => 'r', 'label' => 'Red']]);

    // The printed values are the literals the server already reads as the canonical booleans, so a mark
    // on "Yes" lands on the same `true` a screen answer does.
    expect(Coercion::yesNoAnswer($consent['options'][0]['value']))->toBeTrue()
        ->and(Coercion::yesNoAnswer($consent['options'][1]['value']))->toBeFalse();
});
