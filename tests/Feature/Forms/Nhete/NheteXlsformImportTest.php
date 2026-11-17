<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Nhete\NheteAnswers;
use Tests\Support\Nhete\NhetePlan;
use Tests\Support\Nhete\NheteWorkbook;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M237 (`nhete-stress-test`) — the NHETE module by Kobo-style XLSForm import.
|--------------------------------------------------------------------------
| Two workbooks from the one plan (NheteWorkbook's docblock): the MERIDIAN dialect, which the importer is documented to
| take, is imported, published and answered; and every KOBO habit the module would carry is met on its own, so one
| habit's failure cannot hide another's.
|
| ⚠️ THE CASES PINNED TODAY'S DEFECTS, EACH NAMING ITS ROW. They are measurements, not wishes: the increment that fixes
| a row flips its assertion in the same pull request, on purpose, so the fix is proven by the case that recorded it.
| M244 flipped the import's seven (long notes, markdown, `count-selected`, `regex`, `required` formulas, pictures, the
| language name), and the whole module in the Kobo dialect now imports, publishes and refuses what the original does.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array{0: Tenant, 1: User} */
function nheteWorkspace(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$tenant, $admin];
}

/** @param  array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>  $sheets */
function nheteUpload(array $sheets): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'nhete').'.xlsx';
    file_put_contents($path, app(XlsformWorkbookWriter::class)->writeToString($sheets));

    return new UploadedFile($path, 'nhete.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Import a workbook into a new form through the web route, then publish it through the web route.
 *
 * @param  array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>  $sheets
 * @return array{import_status: int, import_toast: mixed, warnings: mixed, fields: int, published: bool, publish_toast: mixed, form: Form}
 */
function nheteImportAndPublish(Tenant $tenant, User $admin, array $sheets, string $title = 'NHETE import'): array
{
    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, $title);
    $import = test()->actingAs($admin)->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => nheteUpload($sheets)]);
    $outcome = [
        'import_status' => $import->status(),
        'import_toast' => session('toast'),
        'warnings' => session('xlsformWarnings'),
    ];
    enterTenant($tenant->id, $admin->id);
    $outcome['fields'] = FormField::query()->where('form_version_id', $form->draft_version_id)->count();

    test()->actingAs($admin)->post("http://acme.meridian.test/forms/{$form->id}/publish");
    $outcome['publish_toast'] = session('toast');
    enterTenant($tenant->id, $admin->id);
    $form = Form::query()->findOrFail($form->id);
    $outcome['published'] = $form->current_published_version_id !== null;
    $outcome['form'] = $form;

    return $outcome;
}

/**
 * A one-section workbook holding just the rows a probe is about.
 *
 * @param  list<array<string, scalar|null>>  $rows
 * @param  list<array<string, scalar|null>>  $choices
 * @return array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>
 */
function nheteProbe(array $rows, array $choices = []): array
{
    return [
        'survey' => ['headers' => ['type', 'name', 'label', 'hint', 'required', 'relevant', 'constraint', 'constraint_message', 'calculation', 'appearance', 'media::image'], 'rows' => [
            ['type' => 'begin group', 'name' => 'probe', 'label' => 'Probe', 'appearance' => 'field-list'],
            ...$rows,
            ['type' => 'end group', 'name' => 'probe'],
        ]],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => $choices],
        'settings' => ['headers' => ['form_title', 'form_id'], 'rows' => [['form_title' => 'Probe', 'form_id' => 'probe']]],
    ];
}

/** @return Collection<string, FormField> the fields of a form's newest version, by key */
function nheteFieldsOf(Tenant $tenant, User $admin, Form $form): Collection
{
    enterTenant($tenant->id, $admin->id);

    return FormField::query()->where('form_version_id', $form->current_published_version_id ?? $form->draft_version_id)->get()->keyBy('key');
}

/**
 * Share a published form for guest submissions; returns a submitter and a reader of which questions a refusal names.
 *
 * @return array{0: Closure(array<string, mixed>): TestResponse, 1: Closure(TestResponse): list<string>}
 */
function nheteSubmitter(Tenant $tenant, User $admin, Form $form, string $slug): array
{
    test()->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/share", [
        'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null, 'public_slug' => $slug,
    ]);
    enterTenant($tenant->id, $admin->id);
    $token = shareTokenFor(Form::query()->findOrFail($form->id));

    return [
        static fn (array $answers) => test()->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", ['answers' => $answers]),
        static fn ($response): array => $response->status() === 422 ? array_column($response->json('error.details.fields') ?? [], 'field') : [],
    ];
}

it('imports the Meridian-dialect NHETE workbook, publishes it, and keeps the answer rules it carries', function (): void {
    [$tenant, $admin] = nheteWorkspace();
    $plan = new NhetePlan;
    $sheets = (new NheteWorkbook($plan, NheteWorkbook::MERIDIAN))->sheets();

    $outcome = nheteImportAndPublish($tenant, $admin, $sheets);
    expect($outcome['import_status'])->toBe(302)
        ->and($outcome['warnings'])->toBe([])
        ->and($outcome['published'])->toBeTrue('publish refused: '.json_encode($outcome['publish_toast']));

    $form = $outcome['form'];
    // M244 (`R-49262cca`): the settings sheet's `default_language: English` is stored as its code, so the guest page
    // writes a valid `<html lang>`. Until M244 it was stored as the name.
    expect($form->default_locale)->toBe('en');
    [$submit, $refusedOn] = nheteSubmitter($tenant, $admin, $form, 'nhete');
    $base = NheteAnswers::scenario($plan);

    expect($submit($base)->status())->toBe(201);

    // Kept: "up to two" and "No view or opinion stands alone", written as constraints this product reads.
    expect($refusedOn($submit([...$base, 'q3_1' => ['access', 'results', 'use']])))->toBe(['q3_1'])
        ->and($refusedOn($submit([...$base, 'q3_2' => ['noview', 'ip']])))->toBe(['q3_2']);

    // Kept since M244: "required when", written in the `required` column and read as "Required when…" (`R-ea0cc482`).
    // Until M244 the column was read as yes or blank, and a missing comment passed.
    expect($refusedOn($submit(array_diff_key($base, ['a07_comment' => 1]))))->toBe(['a07_comment']);

    // LOST in this dialect: the 1,500-word cap, which it does not write (the Kobo dialect's `regex()` carries it below).
    expect($submit([...$base, 'q1_1' => NheteAnswers::words(1501)])->status())->toBe(201);
})->group('nhete');

it('imports the whole module in the Kobo dialect, publishes it, and refuses what the original refuses', function (): void {
    [$tenant, $admin] = nheteWorkspace();
    $plan = new NhetePlan;

    // M244: long markdown notes become note content, `count-selected` is `count`, the word cap's `regex()` is a Pattern
    // rule (`D228`), the `required` formulas are "Required when…", and the three diagrams are named in warnings.
    $outcome = nheteImportAndPublish($tenant, $admin, (new NheteWorkbook($plan, NheteWorkbook::KOBO))->sheets(), 'kobo module');
    expect($outcome['import_status'])->toBe(302)
        ->and($outcome['import_toast']['type'] ?? null)->toBe('success')
        ->and($outcome['warnings'])->toHaveCount(3)
        ->and(implode(' ', $outcome['warnings']))->toContain('nhete-diagram.png')
        ->and($outcome['published'])->toBeTrue('publish refused: '.json_encode($outcome['publish_toast']));

    $fields = nheteFieldsOf($tenant, $admin, $outcome['form']);
    $notes = $fields->filter(static fn (FormField $field): bool => $field->field_type->value === 'note');
    expect($outcome['form']->default_locale)->toBe('en')
        ->and($notes->filter(static fn (FormField $note): bool => ($note->config['content'] ?? []) !== [])->count())->toBeGreaterThan(100)
        ->and($notes->every(static fn (FormField $note): bool => mb_strlen($note->label) <= NheteWorkbook::LABEL_MAX))->toBeTrue()
        ->and($notes->every(static fn (FormField $note): bool => ! str_contains($note->label, '**') && ! str_starts_with($note->label, '#')))->toBeTrue();

    [$submit, $refusedOn] = nheteSubmitter($tenant, $admin, $outcome['form'], 'nhete-kobo');
    $base = NheteAnswers::scenario($plan);

    expect($submit($base)->status())->toBe(201)
        ->and($refusedOn($submit([...$base, 'q1_1' => NheteAnswers::words(1501)])))->toBe(['q1_1'])
        ->and($refusedOn($submit([...$base, 'q3_1' => ['access', 'results', 'use']])))->toBe(['q3_1'])
        ->and($refusedOn($submit([...$base, 'q3_2' => ['noview', 'ip']])))->toBe(['q3_2'])
        ->and($refusedOn($submit(array_diff_key($base, ['a07_comment' => 1]))))->toBe(['a07_comment']);
})->group('nhete');

it('meets each Kobo habit the NHETE module would carry, one at a time', function (): void {
    [$tenant, $admin] = nheteWorkspace();
    $choices = [['list_name' => 'l', 'name' => 'a', 'label' => 'A'], ['list_name' => 'l', 'name' => 'b', 'label' => 'B'], ['list_name' => 'l', 'name' => 'change', 'label' => 'Change']];

    // A note longer than the 500-character label column becomes note content, and the label keeps its words, cut to fit
    // (`R-24ec82a6`). Until M244 the INSERT failed with `22001`, and the author saw a server error.
    $long = str_repeat('Long note text that a Kobo author keeps in one cell. ', 12);
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'note', 'name' => 'long_note', 'label' => $long]]), 'long note');
    $note = nheteFieldsOf($tenant, $admin, $out['form'])->get('long_note');
    expect($out['published'])->toBeTrue()
        ->and(mb_strlen($note->label))->toBeLessThanOrEqual(NheteWorkbook::LABEL_MAX)
        ->and($note->config['content'][0]['spans'][0]['text'])->toBe(trim($long));

    // Markdown in a note becomes a heading and a paragraph with bold and a link (`R-5957b273`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'note', 'name' => 'md', 'label' => "# Heading\n\nSome **bold** words and a [link](https://example.org)."]]), 'markdown');
    $note = nheteFieldsOf($tenant, $admin, $out['form'])->get('md');
    expect($out['published'])->toBeTrue()
        ->and($note->label)->toBe("Heading\nSome bold words and a link.")
        ->and($note->config['content'])->toEqual([
            ['type' => 'heading', 'level' => 1, 'text' => 'Heading'],
            ['type' => 'paragraph', 'spans' => [
                ['text' => 'Some '], ['text' => 'bold', 'bold' => true], ['text' => ' words and a '], ['text' => 'link', 'link' => 'https://example.org'], ['text' => '.'],
            ]],
        ]);

    // `count-selected()` is `count()`, which publish takes (`R-bb470c3a`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'select_multiple l', 'name' => 'pick', 'label' => 'Pick', 'constraint' => 'count-selected(.) <= 2']], $choices), 'count-selected');
    expect($out['import_status'])->toBe(302)->and($out['warnings'])->toBe([])->and($out['published'])->toBeTrue()
        ->and(nheteFieldsOf($tenant, $admin, $out['form'])->get('pick')->validations()->sole()->expression)->toBe('count(.) <= 2');

    // A whole `regex()` constraint is a Pattern rule, which publish takes (`D228`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'text', 'name' => 'essay', 'label' => 'Essay', 'appearance' => 'multiline', 'constraint' => "regex(., '^[a-z ]*$')"]]), 'regex');
    $rule = nheteFieldsOf($tenant, $admin, $out['form'])->get('essay')->validations()->sole();
    expect($out['published'])->toBeTrue()
        ->and($rule->rule_type?->value)->toBe('pattern')
        ->and($rule->rule_value)->toBe('[a-z ]*');

    // A `required` FORMULA is "Required when…", with no warning (`R-ea0cc482`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([
        ['type' => 'select_one l', 'name' => 'cut', 'label' => 'Cut'],
        ['type' => 'text', 'name' => 'why', 'label' => 'Why', 'required' => "\${cut} = 'change'"],
    ], $choices), 'required formula');
    $why = nheteFieldsOf($tenant, $admin, $out['form'])->get('why');
    expect($out['warnings'])->toBe([])
        ->and($out['published'])->toBeTrue()
        ->and($why->is_required->value)->toBe('conditional')
        ->and($why->validations()->sole()->rule_type?->value)->toBe('required_if');

    // `media::image` on a note cannot come with the workbook; the author is told which file to upload (`R-915e5d0c`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'note', 'name' => 'diagram', 'label' => 'Diagram', 'media::image' => 'diagram.png']]), 'media');
    expect($out['warnings'])->toBe(['Row 3: “diagram” names the file “diagram.png”, which an import cannot bring in. Upload it in the builder.']);

    // `begin_kobomatrix` is refused UPFRONT with a message naming the row, and nothing is written. Correct as built.
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([
        ['type' => 'begin_kobomatrix l', 'name' => 'grid', 'label' => 'Grid'],
        ['type' => 'select_one l', 'name' => 'col', 'label' => 'Column'],
        ['type' => 'end_kobomatrix', 'name' => 'grid'],
    ], $choices), 'kobomatrix');
    expect($out['import_toast']['type'] ?? null)->toBe('error')
        ->and($out['import_toast']['message'] ?? '')->toContain('not a supported XLSForm type')
        ->and($out['fields'])->toBe(0);
})->group('nhete');
