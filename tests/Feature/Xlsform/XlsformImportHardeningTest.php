<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Services\Xlsform\XlsformColumnLimits;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Api\ApiAbilities;
use App\Support\Forms\FormSlug;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M244 — a Kobo/ODK workbook through the real reader, importer, exporter and publish gate.
|--------------------------------------------------------------------------
| The unit tests hold each class to its rules; these hold the pieces together against Postgres: a cell too long is
| refused upfront on both surfaces with nothing written (`R-24ec82a6`), the row named is the sheet's own, the limits
| are the columns' real widths, "Required when…" and note content survive export and re-import (`R-ea0cc482`,
| `R-0ceefbb4`, `R-7e134c37`), a function the grammar lacks is named at publish (`R-bb470c3a`), a language name
| becomes a code (`R-49262cca`), and a slug with its suffix fits its column.
|
| Helpers are prefixed `hardening*`: Pest loads every test file into one process.
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

/** Sheets as a real .xlsx upload. */
function hardeningUpload(array $sheets): UploadedFile
{
    $sheets += [
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => []],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Imported']]],
    ];
    $path = tempnam(sys_get_temp_dir(), 'm244').'.xlsx';
    file_put_contents($path, app(XlsformWorkbookWriter::class)->writeToString($sheets));

    return new UploadedFile($path, 'form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** A signed-in owner and an empty draft for the import to fill. @return array{0: User, 1: Form, 2: FormVersion} */
function hardeningTarget(string $title = 'Hardening Target'): array
{
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = makeForm($owner, $title);
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    return [$owner, $form->refresh(), $draft];
}

it('caps at the real widths of the columns the import writes, read from the database', function (): void {
    $width = static fn (string $table, string $column): int => (int) DB::selectOne(
        'select character_maximum_length as width from information_schema.columns
         where table_schema = current_schema() and table_name = ? and column_name = ?',
        [$table, $column],
    )->width;

    expect($width('form_fields', 'label'))->toBe(XlsformColumnLimits::FIELD_LABEL)
        ->and($width('form_sections', 'label'))->toBe(XlsformColumnLimits::SECTION_LABEL)
        ->and($width('form_field_validations', 'error_message'))->toBe(XlsformColumnLimits::MESSAGE)
        ->and($width('forms', 'title'))->toBe(XlsformColumnLimits::FORM_TITLE)
        ->and($width('form_versions', 'title'))->toBe(XlsformColumnLimits::FORM_TITLE)
        ->and($width('form_fields', 'key'))->toBe(XlsformColumnLimits::KEY)
        ->and($width('form_sections', 'key'))->toBe(XlsformColumnLimits::KEY)
        ->and($width('forms', 'public_slug'))->toBe(FormSlug::MAX_LENGTH)
        ->and($width('forms', 'default_locale'))->toBe(10);
});

it('names the row the author sees in a real file, past a blank spacer row', function (): void {
    [$owner, $form] = hardeningTarget();

    $upload = hardeningUpload(['survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [
        ['type' => 'text', 'name' => 'a', 'label' => 'A'],
        ['type' => '', 'name' => '', 'label' => ''],
        ['type' => '', 'name' => '', 'label' => ''],
        ['type' => 'rank', 'name' => 'b', 'label' => 'B'],
    ]]]);

    try {
        app(XlsformImporter::class)->import($form, $upload, $owner);
        $this->fail('expected XlsformImportException');
    } catch (XlsformImportException $e) {
        expect($e->details())->toBe(['row' => 5, 'type' => 'rank']);
    }
});

it('refuses a cell too long to store on the web with a toast, writing nothing', function (): void {
    [$owner, $form, $draft] = hardeningTarget();
    addFormField($draft, $owner, 'existing', FieldType::ShortText, 0);

    $upload = hardeningUpload(['survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [
        ['type' => 'text', 'name' => 'q', 'label' => str_repeat('q', 501)],
    ]]]);

    $this->actingAs($owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => $upload])
        ->assertRedirect()
        ->assertSessionHas('toast', ['type' => 'error', 'message' => 'A cell is longer than this app can store: survey row 2, label (501 of 500 characters). Shorten them and import the file again.']);

    enterTenant($form->tenant_id, $owner->id);
    expect(FormField::query()->where('form_version_id', $draft->id)->pluck('key')->all())->toBe(['existing']);
});

it('refuses a cell too long to store over the API with the structured envelope', function (): void {
    [$owner, $form] = hardeningTarget();
    $token = $owner->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $upload = hardeningUpload([
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [['type' => 'select_one l', 'name' => 'q', 'label' => 'Q']]],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => [['list_name' => 'l', 'name' => 'a', 'label' => str_repeat('c', 600)]]],
    ]);

    $this->withToken($token)
        ->post("http://acme.meridian.test/api/v1/forms/{$form->id}/draft/xlsform-import", ['file' => $upload])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'xlsform_cell_too_long')
        ->assertJsonPath('error.details.cells.0', ['sheet' => 'choices', 'row' => 2, 'column' => 'label', 'length' => 600, 'limit' => 500, 'unit' => 'characters']);
});

it('imports a Kobo note too long for a label as content, which publish accepts', function (): void {
    [$owner, $form, $draft] = hardeningTarget();
    $note = "# Before you begin\n\n".str_repeat('This module asks about your office. ', 30)."\n\n**Thank you** for your time. See [the guide](https://example.org/guide).";

    $result = app(XlsformImporter::class)->import($form, hardeningUpload(['survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [
        ['type' => 'note', 'name' => 'intro', 'label' => $note],
        ['type' => 'text', 'name' => 'q', 'label' => 'Name'],
    ]]]), $owner);

    $intro = FormField::query()->where('form_version_id', $draft->id)->where('key', 'intro')->sole();

    expect($result->warnings)->toBe([])
        ->and(mb_strlen($intro->label))->toBeLessThanOrEqual(500)
        ->and($intro->label)->toStartWith("Before you begin\nThis module asks")
        // `toEqual`: Postgres keeps `jsonb` keys in its own order.
        ->and($intro->config['content'][0])->toEqual(['type' => 'heading', 'level' => 1, 'text' => 'Before you begin'])
        ->and($intro->config['content'][2]['spans'])->toEqual([
            ['text' => 'Thank you', 'bold' => true],
            ['text' => ' for your time. See '],
            ['text' => 'the guide', 'link' => 'https://example.org/guide'],
            ['text' => '.'],
        ]);

    expect(app(PublishService::class)->publish($form->refresh(), $owner)->status)->toBe(FormVersionStatus::Published);
});

it('reads Kobo\'s English (en) columns into labels and an en locale', function (): void {
    [$owner, $form, $draft] = hardeningTarget();

    app(XlsformImporter::class)->import($form, hardeningUpload([
        'survey' => ['headers' => ['type', 'name', 'label::English (en)', 'label::Filipino (fil)'], 'rows' => [
            ['type' => 'text', 'name' => 'q', 'label::English (en)' => 'Name', 'label::Filipino (fil)' => 'Pangalan'],
        ]],
        'settings' => ['headers' => ['form_title', 'default_language'], 'rows' => [['form_title' => 'Kobo', 'default_language' => 'English (en)']]],
    ]), $owner);

    $q = FormField::query()->where('form_version_id', $draft->id)->where('key', 'q')->sole();

    expect($q->label)->toBe('Name')
        ->and($q->label_translations)->toBe(['fil' => 'Pangalan'])
        ->and($form->refresh()->default_locale)->toBe('en');
});

it('names a function the grammar lacks when publish refuses it', function (string $constraint, string $relevant, string $named): void {
    [$owner, $form] = hardeningTarget();

    $result = app(XlsformImporter::class)->import($form, hardeningUpload(['survey' => ['headers' => ['type', 'name', 'label', 'constraint', 'relevant'], 'rows' => [
        ['type' => 'integer', 'name' => 'n', 'label' => 'N', 'constraint' => $constraint, 'relevant' => $relevant],
    ]]]), $owner);

    expect(implode(' ', $result->warnings))->toContain("“{$named}”");

    try {
        app(PublishService::class)->publish($form->refresh(), $owner);
        $this->fail('expected PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain("uses “{$named}”, which this app does not have");
    }
})->with([
    'regex on a number stays an expression' => ["regex(., '^[0-9]+\$')", '', 'regex'],
    'a hyphenated ODK name' => ['', 'string-length(${n}) > 0', 'string-length'],
]);

it('keeps Required when…, note content and Pattern and length rules through export and re-import', function (): void {
    [$owner, $source, $draft] = hardeningTarget('Round Trip Source');

    $cut = addFormField($draft, $owner, 'cut', FieldType::Dropdown, 0, ['config' => ['options' => [
        ['value' => 'keep', 'label' => 'Keep'], ['value' => 'change', 'label' => 'Change'],
    ]]]);
    $worry = addFormField($draft, $owner, 'worry', FieldType::MultiSelect, 1, ['config' => ['options' => [
        ['value' => 'cost', 'label' => 'Cost'], ['value' => 'other', 'label' => 'Something else'],
    ]]]);
    $comment = addFormField($draft, $owner, 'comment', FieldType::LongText, 2, ['is_required' => RequiredMode::Conditional]);
    $content = [
        ['type' => 'heading', 'level' => 2, 'text' => 'How to answer'],
        ['type' => 'paragraph', 'spans' => [['text' => 'Pick '], ['text' => 'one', 'bold' => true], ['text' => ' per row.']]],
        ['type' => 'callout', 'tone' => 'info', 'spans' => [['text' => 'Unsure? Choose the closest.']]],
    ];
    addFormField($draft, $owner, 'intro', FieldType::Note, 3, ['label' => 'Intro', 'config' => ['content' => $content]]);
    $code = addFormField($draft, $owner, 'code', FieldType::ShortText, 4);

    foreach ([[$cut, ComparisonOperator::Eq, 'change'], [$worry, ComparisonOperator::Contains, 'other']] as $i => [$related, $operator, $value]) {
        FormFieldValidation::create([
            'form_version_id' => $draft->id, 'form_field_id' => $comment->id, 'related_form_field_id' => $related->id,
            'rule_type' => ValidationRuleType::RequiredIf, 'operator' => $operator, 'rule_value' => $value,
            'error_message' => 'Tell us why', 'sequence' => $i,
        ]);
    }
    FormFieldValidation::create(['form_version_id' => $draft->id, 'form_field_id' => $code->id, 'rule_type' => ValidationRuleType::Pattern, 'rule_value' => '[A-Z]{2}\d{3}', 'error_message' => 'Like AB123', 'sequence' => 0]);
    FormFieldValidation::create(['form_version_id' => $draft->id, 'form_field_id' => $code->id, 'rule_type' => ValidationRuleType::MaxLength, 'rule_value' => '5', 'error_message' => 'Like AB123', 'sequence' => 1]);

    $sheets = app(XlsformExporter::class)->build($source->refresh(), $draft);
    $commentRow = collect($sheets['survey']['rows'])->firstWhere('name', 'comment');
    $codeRow = collect($sheets['survey']['rows'])->firstWhere('name', 'code');

    expect($commentRow['required'])->toBe("\${cut} = 'change' or selected(\${worry}, 'other')")
        ->and($commentRow['required_message'])->toBe('Tell us why')
        ->and($codeRow['constraint'])->toBe("regex(., '^(?:[A-Z]{2}\\d{3})\$') and string-length(.) <= 5");

    $target = makeForm($owner, 'Round Trip Target');
    $targetDraft = makeDraftVersion($target);
    $target->forceFill(['draft_version_id' => $targetDraft->id])->save();
    $result = app(XlsformImporter::class)->import($target->refresh(), hardeningUpload($sheets), $owner);

    $fields = FormField::query()->where('form_version_id', $targetDraft->id)->get()->keyBy('key');
    $rules = FormFieldValidation::query()->where('form_version_id', $targetDraft->id)->orderBy('sequence')->get();
    $commentRules = $rules->where('form_field_id', $fields['comment']->id)->values();
    $codeRules = $rules->where('form_field_id', $fields['code']->id)->values();

    expect($result->warnings)->toBe([])
        ->and($fields['comment']->is_required)->toBe(RequiredMode::Conditional)
        ->and($commentRules->map(fn (FormFieldValidation $rule): array => [
            $rule->rule_type, $rule->operator, $rule->rule_value, $rule->related_form_field_id, $rule->error_message,
        ])->all())->toBe([
            [ValidationRuleType::RequiredIf, ComparisonOperator::Eq, 'change', $fields['cut']->id, 'Tell us why'],
            [ValidationRuleType::RequiredIf, ComparisonOperator::Contains, 'other', $fields['worry']->id, 'Tell us why'],
        ])
        ->and($fields['intro']->config['content'])->toEqual($content)
        ->and($codeRules->map(fn (FormFieldValidation $rule): array => [$rule->rule_type, $rule->rule_value, $rule->error_message])->all())->toBe([
            [ValidationRuleType::Pattern, '[A-Z]{2}\d{3}', 'Like AB123'],
            [ValidationRuleType::MaxLength, '5', 'Like AB123'],
        ]);

    expect(app(PublishService::class)->publish($target->refresh(), $owner)->status)->toBe(FormVersionStatus::Published);
});

it('keeps a slug of full length with its suffix inside the column', function (): void {
    [$owner, $form] = hardeningTarget();
    $taken = makeForm($owner, 'Taken');
    $taken->forceFill(['public_slug' => str_repeat('a', FormSlug::MAX_LENGTH)])->save();

    app(XlsformImporter::class)->import($form, hardeningUpload([
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [['type' => 'text', 'name' => 'q', 'label' => 'Q']]],
        'settings' => ['headers' => ['form_title', 'form_id'], 'rows' => [['form_title' => 'Long', 'form_id' => str_repeat('a', 200)]]],
    ]), $owner);

    expect($form->refresh()->public_slug)->toBe(str_repeat('a', FormSlug::MAX_LENGTH - 2).'-2');
});
