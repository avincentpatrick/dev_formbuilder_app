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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
| ⚠️ SEVERAL CASES PIN TODAY'S DEFECTS, EACH NAMING ITS ROW. They are measurements, not wishes: the increment that fixes
| a row flips its assertion in the same pull request, on purpose, so the fix is proven by the case that recorded it.
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

it('imports the Meridian-dialect NHETE workbook, publishes it, and keeps only the answer rules XLSForm carries here', function (): void {
    [$tenant, $admin] = nheteWorkspace();
    $plan = new NhetePlan;
    $sheets = (new NheteWorkbook($plan, NheteWorkbook::MERIDIAN))->sheets();

    $outcome = nheteImportAndPublish($tenant, $admin, $sheets);
    expect($outcome['import_status'])->toBe(302)
        ->and($outcome['warnings'])->toBe([])
        ->and($outcome['published'])->toBeTrue('publish refused: '.json_encode($outcome['publish_toast']));

    $form = $outcome['form'];
    // TODAY'S DEFECT (`xlsform-default-language-name`, found by `M239`'s walk): the settings sheet's `default_language`
    // is a language NAME, stored as the locale as it stands, so the guest page writes `<html lang="English">`.
    expect($form->default_locale)->toBe('English');
    $this->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/share", [
        'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null, 'public_slug' => 'nhete',
    ]);
    enterTenant($tenant->id, $admin->id);
    $token = shareTokenFor(Form::query()->findOrFail($form->id));
    $submit = fn (array $answers) => $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/submissions", ['answers' => $answers]);
    $refusedOn = static fn ($response): array => $response->status() === 422 ? array_column($response->json('error.details.fields') ?? [], 'field') : [];
    $base = NheteAnswers::scenario($plan);

    expect($submit($base)->status())->toBe(201);

    // Kept: "up to two" and "No view or opinion stands alone", written as constraints this product reads.
    expect($refusedOn($submit([...$base, 'q3_1' => ['access', 'results', 'use']])))->toBe(['q3_1'])
        ->and($refusedOn($submit([...$base, 'q3_2' => ['noview', 'ip']])))->toBe(['q3_2']);

    // LOST: the 1,500-word cap. Expressions here have no `regex()` or `string-length()` (`xlsform-import-odk-functions`).
    expect($submit([...$base, 'q1_1' => NheteAnswers::words(1501)])->status())->toBe(201);

    // LOST: "required when". The importer reads `required` as yes or blank only, and the stand-in constraint
    // `not(<when>) or . != ''` never runs on a blank answer, so a missing comment passes (`xlsform-required-when`).
    expect($submit(array_diff_key($base, ['a07_comment' => 1]))->status())->toBe(201);
})->group('nhete');

it('meets each Kobo habit the NHETE module would carry, one at a time', function (): void {
    [$tenant, $admin] = nheteWorkspace();
    $choices = [['list_name' => 'l', 'name' => 'a', 'label' => 'A'], ['list_name' => 'l', 'name' => 'b', 'label' => 'B'], ['list_name' => 'l', 'name' => 'change', 'label' => 'Change']];

    // A note longer than the 500-character label column is not refused: the INSERT fails, and the request is a server
    // error with no message (`xlsform-import-long-label`). Asserted with exception handling off, because rendering the
    // debug error page alone takes about two minutes here; the draft is untouched either way.
    foreach ([
        'a note over 500 characters' => nheteProbe([['type' => 'note', 'name' => 'long_note', 'label' => str_repeat('Long note text that a Kobo author keeps in one cell. ', 12)]]),
        'the whole module in the Kobo dialect' => (new NheteWorkbook(new NhetePlan, NheteWorkbook::KOBO))->sheets(),
    ] as $what => $sheets) {
        enterTenant($tenant->id, $admin->id);
        $form = app(FormService::class)->create($tenant, $admin, $what);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => nheteUpload($sheets)]);
            $thrown = null;
        } catch (QueryException $e) {
            $thrown = (string) $e->getCode();
        }
        $this->withExceptionHandling();
        expect($thrown)->toBe('22001', "{$what}: expected the database to refuse a label over 500 characters")
            ->and(nheteFieldsOf($tenant, $admin, $form))->toHaveCount(0);
    }

    // Markdown in a note is kept as literal characters (`xlsform-import-markdown`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'note', 'name' => 'md', 'label' => "# Heading\n\nSome **bold** words and a [link](https://example.org)."]]), 'markdown');
    $note = nheteFieldsOf($tenant, $admin, $out['form'])->get('md');
    expect($out['published'])->toBeTrue()
        ->and($note->label)->toContain('**bold**')->toContain('# Heading')
        ->and($note->config['content'] ?? [])->toBe([]);

    // `count-selected()` imports, then publish refuses it with a parser token, not its name (`xlsform-import-odk-functions`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'select_multiple l', 'name' => 'pick', 'label' => 'Pick', 'constraint' => 'count-selected(.) <= 2']], $choices), 'count-selected');
    expect($out['import_status'])->toBe(302)->and($out['published'])->toBeFalse()
        ->and($out['publish_toast']['message'] ?? '')->toContain('unexpected_token');

    // `regex()` imports, then publish refuses it as an unknown function (`xlsform-import-odk-functions`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'text', 'name' => 'essay', 'label' => 'Essay', 'appearance' => 'multiline', 'constraint' => "regex(., '^[a-z ]*$')"]]), 'regex');
    expect($out['published'])->toBeFalse()->and($out['publish_toast']['message'] ?? '')->toContain('unknown_function');

    // A `required` FORMULA imports as optional, with no warning (`xlsform-required-when`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([
        ['type' => 'select_one l', 'name' => 'cut', 'label' => 'Cut'],
        ['type' => 'text', 'name' => 'why', 'label' => 'Why', 'required' => "\${cut} = 'change'"],
    ], $choices), 'required formula');
    expect($out['warnings'])->toBe([])
        ->and(nheteFieldsOf($tenant, $admin, $out['form'])->get('why')->is_required->value)->toBe('optional');

    // `media::image` on a note is dropped, with no warning (`xlsform-import-media`).
    $out = nheteImportAndPublish($tenant, $admin, nheteProbe([['type' => 'note', 'name' => 'diagram', 'label' => 'Diagram', 'media::image' => 'diagram.png']]), 'media');
    expect($out['warnings'])->toBe([])
        ->and(nheteFieldsOf($tenant, $admin, $out['form'])->get('diagram')->config['content'] ?? [])->toBe([]);

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
